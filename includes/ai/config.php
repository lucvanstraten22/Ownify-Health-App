<?php
/**
 * Ownify AI: its settings, its key, whether it is installed, and consent.
 *
 * The key is read here and handed to the one function that sends a request
 * (includes/ai/gemini.php). It is never returned to a page, an API answer or
 * a log line; ai_key_source() says where it came from without saying what it
 * is.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

if (!function_exists('ai_config')) {

    /** config/ai.php, read once. */
    function ai_config(): array
    {
        static $config = null;

        return $config ??= (array) require dirname(__DIR__, 2) . '/config/ai.php';
    }

    /**
     * The Gemini API key, or null when there is none: the environment first,
     * then config/ai.local.php. The example file's placeholder is not a key.
     */
    function ai_api_key(): ?string
    {
        return ai_key_lookup()['key'];
    }

    /** Where the key was found — 'the environment', 'config/ai.local.php' — or null. Never the key. */
    function ai_key_source(): ?string
    {
        return ai_key_lookup()['source'];
    }

    /** @return array{key: ?string, source: ?string} */
    function ai_key_lookup(): array
    {
        static $found = null;

        if ($found !== null) {
            return $found;
        }

        $env = getenv('GEMINI_API_KEY');
        if (is_string($env) && trim($env) !== '') {
            return $found = ['key' => trim($env), 'source' => 'the environment (GEMINI_API_KEY)'];
        }

        if (isset($_SERVER['GEMINI_API_KEY']) && is_string($_SERVER['GEMINI_API_KEY']) && trim($_SERVER['GEMINI_API_KEY']) !== '') {
            return $found = ['key' => trim($_SERVER['GEMINI_API_KEY']), 'source' => 'the server environment (GEMINI_API_KEY)'];
        }

        $local = dirname(__DIR__, 2) . '/config/ai.local.php';
        if (is_file($local)) {
            $settings = (array) require $local;
            $key      = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';

            if ($key !== '' && !str_starts_with($key, 'PUT-')) {
                return $found = ['key' => $key, 'source' => 'config/ai.local.php'];
            }
        }

        return $found = ['key' => null, 'source' => null];
    }

    /** The model name, only if it is one: letters, digits, dots and dashes. */
    function ai_model(): ?string
    {
        $model = trim((string) (ai_config()['model'] ?? ''));

        return preg_match('/^[a-z0-9][a-z0-9.\-]{1,80}$/i', $model) === 1 ? $model : null;
    }

    /** Whether migration 015 is in: its tables and the consent columns. */
    function ai_installed(): bool
    {
        static $installed = null;

        if ($installed !== null) {
            return $installed;
        }

        if (!db_available()) {
            return false;   // not remembered: the database may be back next time
        }

        $tables = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name IN ('ai_conversations', 'ai_messages', 'ai_usage', 'ai_service_state')"
        );
        $columns = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
                AND column_name IN ('ai_consent', 'ai_consent_version', 'ai_consent_at')"
        );

        return $installed = ($tables === 4 && $columns === 3);
    }

    /**
     * Whether the assistant can answer at all, and if not, why — for the
     * people who run Ownify, never shown as such to a user.
     *
     * @return array{ok: bool, reason: ?string}   reason: not_installed | no_key | bad_model
     */
    function ai_readiness(): array
    {
        if (!ai_installed()) {
            return ['ok' => false, 'reason' => 'not_installed'];
        }
        if (ai_api_key() === null) {
            return ['ok' => false, 'reason' => 'no_key'];
        }
        if (ai_model() === null) {
            return ['ok' => false, 'reason' => 'bad_model'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /* ------------------------------------------------------------ consent */

    /**
     * What the person said about Ownify AI, for the wording in use now:
     *
     *   'accepted'   yes, to this wording
     *   'declined'   no, or yes withdrawn later
     *   'unknown'    never asked — or said yes to an earlier wording, which
     *                is asked again rather than taken for a yes to new terms
     */
    function ai_consent_state(int $userId): string
    {
        if (!ai_installed()) {
            return 'unknown';
        }

        $row = db_one(
            'SELECT ai_consent, ai_consent_version FROM user_profiles WHERE user_id = ?',
            [$userId]
        );

        if ($row === null || $row['ai_consent'] === null) {
            return 'unknown';
        }

        if ((int) $row['ai_consent'] === 1) {
            return (string) $row['ai_consent_version'] === (string) ai_config()['consent_version']
                ? 'accepted'
                : 'unknown';   // said yes to other terms: asked again
        }

        return 'declined';
    }

    /** Whether Gemini may be sent this person's data now. */
    function ai_consented(int $userId): bool
    {
        return ai_consent_state($userId) === 'accepted';
    }

    /**
     * Records the answer. Saying no again later is the same call: from then
     * on nothing is sent. Conversations already held stay until they are
     * wiped (Instellingen → Privacy), which is the person's own choice.
     */
    function ai_consent_set(int $userId, bool $allowed): array
    {
        if (!ai_installed()) {
            error_log('[ownify] ai: consent cannot be saved — import database/migrations/015-ai-assistant.sql');

            return ['ok' => false, 'error' => 'Deze instelling kan nog niet worden opgeslagen.'];
        }

        db_run(
            'INSERT INTO user_profiles (user_id, ai_consent, ai_consent_version, ai_consent_at)
                  VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE ai_consent = VALUES(ai_consent),
                                     ai_consent_version = VALUES(ai_consent_version),
                                     ai_consent_at = VALUES(ai_consent_at)',
            [$userId, $allowed ? 1 : 0, (string) ai_config()['consent_version']]
        );

        return ['ok' => true, 'error' => null];
    }
}
