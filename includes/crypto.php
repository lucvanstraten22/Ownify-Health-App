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
 * IF THERE IS NO KEY
 * ---------------------------------------------------------------------------
 * These functions return null rather than falling back to storing the value in
 * the clear. A missing key must break connecting, loudly, instead of quietly
 * downgrading every user's security to nothing — the failure you notice is
 * better than the one you do not.
 */

declare(strict_types=1);

if (!function_exists('crypto_key')) {

    /**
     * The 32-byte key, from the environment or config/app.local.php.
     *
     * Never from config/app.php and never from the repository: a key in the
     * checkout is a key on GitHub and a key in every deploy.
     */
    function crypto_key(): ?string
    {
        static $key = false;      // false = not looked yet, null = not there

        if ($key !== false) {
            return $key;
        }

        $raw = getenv('JOLU_APP_KEY') ?: null;

        if ($raw === null) {
            $local = dirname(__DIR__) . '/config/app.local.php';

            if (is_file($local)) {
                $settings = (array) require $local;
                $raw = $settings['app_key'] ?? null;
            }
        }

        if (!is_string($raw) || $raw === '') {
            $key = null;
            return null;
        }

        /* Written as base64 so it survives an .env file and a copy-paste. */
        $decoded = base64_decode($raw, true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            crypto_note_failure('JOLU_APP_KEY is not 32 bytes of base64.');
            $key = null;
            return null;
        }

        $key = $decoded;

        return $key;
    }

    function crypto_available(): bool
    {
        return crypto_key() !== null;
    }

    /** Why encryption is unavailable — for the setup screen, never for a user. */
    function crypto_note_failure(?string $message = null): ?string
    {
        static $failure = null;

        if ($message !== null) {
            $failure = $message;
        }

        return $failure;
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

    /** Generates a key to put in config/app.local.php. Used by tooling, not at runtime. */
    function crypto_generate_key(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
