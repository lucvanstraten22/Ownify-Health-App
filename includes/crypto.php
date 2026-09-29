<?php
/**
 * Encryption for the few secrets that have to be stored.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS FOR
 * ---------------------------------------------------------------------------
 * OAuth tokens. A refresh token is a standing key to somebody's health
 * account: it keeps working until it is revoked, and it is worth more than a
 * password because nobody ever notices it being used. Storing one in a column
 * you can read means a database dump, a backup on a laptop or one SQL
 * injection hands over every connected account's data.
 *
 * So the column holds ciphertext. The key lives outside the database, which is
 * the whole point — an attacker with the database still has nothing.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE KEY COMES FROM
 * ---------------------------------------------------------------------------
 * Three places, in this order, first one wins:
 *
 *   1. the process environment          getenv('OWNIFY_APP_KEY')
 *   2. the server environment           $_SERVER['OWNIFY_APP_KEY']
 *   3. config/app.local.php             ['app_key' => '...']
 *
 * The variable was called JOLU_APP_KEY before the app was renamed to Ownify.
 * A server that still sets it under that name keeps working — the old name is
 * read after the new one, in the same two places — and tools/check-config.php
 * says to rename it. Only the name changed: the key is the same key, and
 * changing it would make every stored token unreadable (see below).
 *
 * Never from a tracked file. A key in the checkout is a key on GitHub, a key
 * in every deploy and a key in every clone — so config/app.local.php is
 * git-ignored, and config/app.local.php.example holds the shape without the
 * secret. docs/DATABASE.md says exactly where to put it on the server.
 *
 * (2) exists because Apache's SetEnv and several FastCGI setups put the value
 * there rather than in the process environment. A request header can never
 * arrive that way: headers reach PHP prefixed with HTTP_, so HTTP_OWNIFY_APP_KEY
 * is the most a caller could set, and that is not read here.
 *
 * ---------------------------------------------------------------------------
 * IF THERE IS NO KEY
 * ---------------------------------------------------------------------------
 * These functions return null rather than falling back to storing the value in
 * the clear. A missing key must break connecting, loudly, instead of quietly
 * downgrading every user's security to nothing — the failure you notice is
 * better than the one you do not.
 *
 * "Loudly" means the server log. crypto_status() tells the difference between
 * a key that is absent and one that is present but wrong, because those have
 * different fixes, and crypto_check() writes that distinction to the error log
 * once per process. Neither ever contains the key itself: a secret in a log is
 * a secret in every backup of that log.
 */

declare(strict_types=1);

/** The environment variable that holds the key. */
if (!defined('CRYPTO_KEY_VARIABLE')) {
    define('CRYPTO_KEY_VARIABLE', 'OWNIFY_APP_KEY');
}

/** Its name before the app was called Ownify, still read after the current one. */
if (!defined('CRYPTO_KEY_VARIABLE_LEGACY')) {
    define('CRYPTO_KEY_VARIABLE_LEGACY', 'JOLU_APP_KEY');
}

if (!function_exists('crypto_key')) {

    /**
     * Finds the key once and remembers what happened.
     *
     * Returns the key alongside the state, because the caller that wants the
     * key and the caller that wants to explain the configuration are asking
     * about the same lookup, and doing it twice could answer differently.
     *
     * @return array{key: ?string, state: string, source: ?string, message: string}
     */
    function crypto_resolve(): array
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        /* Checked before any SODIUM_* constant is named. libsodium ships with
           PHP and is on by default, but a host can build without it, and an
           undefined-constant fatal on every page would be a strange way to
           find that out. */
        if (!function_exists('sodium_crypto_secretbox')) {
            return $resolved = [
                'key'     => null,
                'state'   => 'unsupported',
                'source'  => null,
                'message' => 'This PHP has no libsodium, so tokens cannot be encrypted at all. '
                    . 'Enable the sodium extension; the key is no use without it.',
            ];
        }

        $raw    = null;
        $source = null;

        /* The current name first, then the name from before the app was
           called Ownify, so renaming the variable on a server is never urgent. */
        foreach ([CRYPTO_KEY_VARIABLE, CRYPTO_KEY_VARIABLE_LEGACY] as $variable) {
            $suffix = $variable === CRYPTO_KEY_VARIABLE ? '' : ' (under its old name ' . $variable . ')';
            $env    = getenv($variable);

            if (is_string($env) && $env !== '') {
                $raw    = $env;
                $source = 'the process environment' . $suffix;
                break;
            }

            if (isset($_SERVER[$variable])
                && is_string($_SERVER[$variable])
                && $_SERVER[$variable] !== '') {
                $raw    = $_SERVER[$variable];
                $source = 'the server environment' . $suffix;
                break;
            }
        }

        if ($raw === null) {
            $local = dirname(__DIR__) . '/config/app.local.php';

            if (is_file($local)) {
                $settings  = (array) require $local;
                $candidate = $settings['app_key'] ?? null;

                if (is_string($candidate) && $candidate !== '') {
                    $raw    = $candidate;
                    $source = 'config/app.local.php';
                }
            }
        }

        if ($raw === null) {
            return $resolved = [
                'key'     => null,
                'state'   => 'missing',
                'source'  => null,
                'message' => CRYPTO_KEY_VARIABLE . ' is not set. Set it in the environment or in '
                    . 'config/app.local.php (see config/app.local.php.example). Until then '
                    . 'nothing that needs an encrypted token can be connected.',
            ];
        }

        /* Written as base64 so it survives an .env file, a pool config and a
           copy-paste between them. */
        $decoded = base64_decode(trim($raw), true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $resolved = [
                'key'    => null,
                'state'  => 'invalid',
                'source' => $source,
                /* The length is safe to say and is usually the whole diagnosis;
                   the value is never said at all. */
                'message' => sprintf(
                    '%s, from %s, is not %d bytes of base64 (it decodes to %s). '
                        . 'Generate one with: php tools/check-config.php --generate-key',
                    CRYPTO_KEY_VARIABLE,
                    $source,
                    SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                    $decoded === false ? 'nothing' : strlen($decoded) . ' bytes'
                ),
            ];
        }

        return $resolved = [
            'key'     => $decoded,
            'state'   => 'ok',
            'source'  => $source,
            'message' => CRYPTO_KEY_VARIABLE . ' is set and usable, from ' . $source . '.',
        ];
    }

    /** The 32-byte key, or null when there is not a usable one. */
    function crypto_key(): ?string
    {
        return crypto_resolve()['key'];
    }

    function crypto_available(): bool
    {
        return crypto_key() !== null;
    }

    /**
     * The configuration state, for a setup screen or a command — never a user.
     *
     * `state` is one of:
     *   ok           a 32-byte key was found and can be used
     *   missing      nothing is configured anywhere
     *   invalid      something is configured but it is not a usable key
     *   unsupported  this PHP cannot encrypt, so no key would help
     *
     * The key is removed from the copy that leaves this function, so no caller
     * can print it by accident.
     *
     * @return array{state: string, source: ?string, message: string}
     */
    function crypto_status(): array
    {
        $status = crypto_resolve();
        unset($status['key']);

        return $status;
    }

    /**
     * The startup check: says so in the server log when the key is unusable.
     *
     * Once per process rather than once per request, so a misconfigured server
     * says it often enough to be noticed and not often enough to bury the rest
     * of the log. Under PHP-FPM that is a line per worker; under `php -S`,
     * which forks per request, it is a line per request — which is the right
     * way round, because that is the machine you are fixing it on.
     *
     * Returns the status too, so a caller can both log and decide.
     *
     * @return array{state: string, source: ?string, message: string}
     */
    function crypto_check(): array
    {
        static $logged = false;

        $status = crypto_status();

        if (!$logged && $status['state'] !== 'ok') {
            $logged = true;
            error_log('[ownify] configuration: ' . $status['message']);
        }

        return $status;
    }

    /**
     * Encrypts a secret. Returns raw bytes for a BLOB column, or null when
     * there is no key — in which case the caller must not store anything.
     *
     * A fresh nonce per call, prepended to the ciphertext, so encrypting the
     * same token twice does not produce the same bytes and nobody can tell
     * from the database that two users hold the same token.
     */
    function crypto_seal(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }

        $key = crypto_key();

        if ($key === null) {
            return null;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
    }

    /**
     * Decrypts. Returns null for anything that does not authenticate, which
     * covers a rotated key, a truncated column and a tampered row alike —
     * the caller treats all three the same way: the connection needs redoing.
     */
    function crypto_open(?string $sealed): ?string
    {
        if ($sealed === null || $sealed === '') {
            return null;
        }

        $key = crypto_key();

        if ($key === null || strlen($sealed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce      = substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        return $plain === false ? null : $plain;
    }

    /** Generates a key to configure. Used by tooling, never at runtime. */
    function crypto_generate_key(): string
    {
        $bytes = defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')
            ? SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            : 32;

        return base64_encode(random_bytes($bytes));
    }
}
