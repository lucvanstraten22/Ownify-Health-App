<?php
/**
 * Gemini's wording of the day's insights (docs/INSIGHTS.md) — optional, and
 * only with its own consent (user_profiles.ai_insights_consent; the chat's
 * ai_consent is a different question and is not read here).
 *
 * Every insight already has its own sentence, written by code from the
 * stored facts (includes/insights/render.php). Gemini may only say the same
 * thing in other words:
 *
 *   sent      per insight: its key, the claim id, the template sentence (the
 *             numbers are in it), the claim and its limitations. Never a
 *             name, a birth date, raw records or anything else.
 *   asked     structured JSON (responseMimeType + responseJsonSchema on the
 *             existing generateContent call), low thinking, no tools
 *   checked   in code, whatever Gemini says: only the keys and claim ids that
 *             were sent, exactly the numbers of the template (no more, no
 *             fewer), no words of cause, diagnosis, order or comparison that
 *             the template does not use itself, no link, the right language,
 *             not too long. Anything else is thrown away and the template is
 *             shown.
 *   when      after the response is sent (never while a page waits), at most
 *             once per person per day, within insights' share of the shared
 *             daily limit (config/insights.php gemini.global_daily_calls), the
 *             minute's pace and Google's own quota (includes/ai/pace.php,
 *             ai_service_state) — the same limits the chat respects.
 *
 * A reassessment later in the day never asks again: an insight whose
 * numbers changed simply shows its template.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/i18n.php';
require_once dirname(__DIR__) . '/ai/config.php';
require_once dirname(__DIR__) . '/ai/gemini.php';
require_once dirname(__DIR__) . '/ai/store.php';
require_once dirname(__DIR__) . '/ai/pace.php';
require_once dirname(__DIR__) . '/research.php';
require_once __DIR__ . '/facts.php';
require_once __DIR__ . '/render.php';

if (!function_exists('insights_phrase')) {

    /* ============================================================ consent */

    /**
     * What the person said about Gemini rewording insights, for the wording
     * in use now: 'accepted', 'declined' or 'unknown' (never asked, or yes to
     * an older wording — asked again rather than taken for a yes).
     */
    function insights_consent_state(int $userId): string
    {
        if (!function_exists('insights_installed') || !insights_installed()) {
            return 'unknown';
        }

        $row = db_one('SELECT ai_insights_consent, ai_insights_consent_version FROM user_profiles WHERE user_id = ?', [$userId]);

        if ($row === null || $row['ai_insights_consent'] === null) {
            return 'unknown';
        }
        if ((int) $row['ai_insights_consent'] === 1) {
            return (string) $row['ai_insights_consent_version'] === (string) insights_config()['consent_version'] ? 'accepted' : 'unknown';
        }

        return 'declined';
    }

    /** Records the answer. Saying no later is the same call: from then on nothing is sent. */
    function insights_consent_set(int $userId, bool $allowed): array
    {
        if (!function_exists('insights_installed') || !insights_installed()) {
            error_log('[ownify] insights: consent cannot be saved — import database/migrations/028-research-insights.sql');

            return ['ok' => false, 'error' => 'Deze instelling kan nog niet worden opgeslagen.'];
        }
        if ($allowed && !insights_gemini_enabled()) {
            return ['ok' => false, 'error' => 'Inzichten laten verwoorden staat tijdelijk uit.'];
        }

        db_run(
            'INSERT INTO user_profiles (user_id, ai_insights_consent, ai_insights_consent_version, ai_insights_consent_at)
                  VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE ai_insights_consent = VALUES(ai_insights_consent),
                                     ai_insights_consent_version = VALUES(ai_insights_consent_version),
                                     ai_insights_consent_at = VALUES(ai_insights_consent_at)',
            [$userId, $allowed ? 1 : 0, (string) insights_config()['consent_version']]
        );

        /* Withdrawn: Gemini's wording of today goes too; the templates stay. */
        if (!$allowed) {
            db_run('UPDATE user_insights SET phrased_body = NULL, phrased_locale = NULL, phrased_model = NULL WHERE user_id = ?', [$userId]);
        }

        return ['ok' => true, 'error' => null];
    }

    /* ======================================================== scheduling */

    /**
     * Rewords today's insights after the response has gone out, when this
     * person allows it and the day still may. Cheap checks first: most loads
     * end here without anything scheduled.
     */
    function insights_phrase_after_response(int $userId, string $date): void
    {
        static $scheduled = false;

        if ($scheduled || !insights_phrase_wanted($userId, $date)) {
            return;
        }
        $scheduled = true;

        register_shutdown_function(static function () use ($userId, $date): void {
            /* The page or the app's state is complete: let it go, and the
               session with it, so nobody waits for Gemini. */
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);

            try {
                insights_phrase($userId, $date);
            } catch (Throwable $e) {
                error_log('[ownify] insights: wording failed: ' . $e->getMessage());
            }
        });
    }

    /** Whether a wording request could be made today at all (no request, no lock). */
    function insights_phrase_wanted(int $userId, string $date): bool
    {
        $g = insights_config()['gemini'];

        if (!insights_gemini_enabled() || !insights_installed() || insights_consent_state($userId) !== 'accepted') {
            return false;
        }

        $day = db_one('SELECT state, gemini_calls, phrase_state FROM insight_days WHERE user_id = ? AND local_date = ?', [$userId, $date]);

        return $day !== null && $day['state'] === 'done'
            && (int) $day['gemini_calls'] < (int) $g['calls_per_user_day']
            && in_array($day['phrase_state'], ['none', 'skipped'], true)
            && insights_phrase_rows($userId, $date) !== [];
    }

    /** Today's live insights without a wording in the language shown now. */
    function insights_phrase_rows(int $userId, string $date): array
    {
        return db_all(
            "SELECT id, slot, rule_key, outcome, claim_id, strength, applicability, facts_json, fingerprint
               FROM user_insights
              WHERE user_id = ? AND local_date = ? AND slot <> 'suggestion' AND withdrawn_at IS NULL
                AND (phrased_body IS NULL OR phrased_locale <> ?)
           ORDER BY FIELD(slot, 'sleep', 'nutrition', 'training', 'body')",
            [$userId, $date, i18n_locale()]
        );
    }

    /* ============================================================ the call */

    /**
     * Asks Gemini once for today's wording and keeps what passes the checks.
     *
     * @return array{state: string, note: ?string, applied: int, tokens: array{input: int, output: int, cached: int}}
     */
    function insights_phrase(int $userId, string $date, ?DateTimeImmutable $now = null): array
    {
        $g      = insights_config()['gemini'];
        $none   = ['input' => 0, 'output' => 0, 'cached' => 0];
        $result = static fn (string $state, ?string $note, int $applied = 0, array $tokens = []) =>
            ['state' => $state, 'note' => $note, 'applied' => $applied, 'tokens' => $tokens + ['input' => 0, 'output' => 0, 'cached' => 0]];

        if (!insights_phrase_wanted($userId, $date)) {
            return $result('none', 'not_wanted', 0, $none);
        }

        $skip = static function (string $note) use ($userId, $date, $result): array {
            db_run("UPDATE insight_days SET phrase_state = 'skipped', phrase_note = ? WHERE user_id = ? AND local_date = ?",
                [$note, $userId, $date]);

            return $result('skipped', $note);
        };

        if (ai_readiness()['ok'] !== true) {
            return $skip('not_ready');
        }
        if (ai_quota_blocked_until() !== null) {
            return $skip('quota');
        }
        $insightCalls = (int) db_value('SELECT COALESCE(SUM(gemini_calls), 0) FROM insight_days WHERE local_date = ?', [$date]);
        if ($insightCalls >= (int) $g['global_daily_calls'] || ai_usage_global_calls() >= (int) ai_config()['global_daily_limit']) {
            return $skip('budget');
        }

        $rows = insights_phrase_rows($userId, $date);
        $sent = insights_phrase_items($rows);
        if ($sent === []) {
            return $result('none', 'nothing');
        }

        $pace = ai_pace_take(1);
        if (!$pace['ok']) {
            return $skip('pace');
        }

        try {
            /* The day's one request: taken in one statement, so two loads
               at the same moment cannot both make it. */
            $claimed = db_run(
                'UPDATE insight_days SET gemini_calls = gemini_calls + 1
                  WHERE user_id = ? AND local_date = ? AND gemini_calls < ?',
                [$userId, $date, (int) $g['calls_per_user_day']]
            );
            if ($claimed === null || $claimed->rowCount() !== 1 || !ai_pace_use($pace['tag'])) {
                return $result('none', 'taken');
            }

            $body   = insights_phrase_request($sent, i18n_locale());
            $status = 0;
            $json   = null;
            $answer = ai_gemini_exchange($body, $status, $json);
            ai_diagnostic_exchange($body, $status, is_array($json) ? $json : [], $answer, (string) (ai_model() ?? '?'));
        } finally {
            ai_pace_release($pace['tag']);
        }

        $usage  = is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [];
        $tokens = [
            'input'  => (int) ($usage['promptTokenCount'] ?? 0),
            'output' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            'cached' => (int) ($usage['cachedContentTokenCount'] ?? 0),
        ];
        db_run('UPDATE insight_days SET input_tokens = input_tokens + ?, output_tokens = output_tokens + ?, cached_tokens = cached_tokens + ?
                 WHERE user_id = ? AND local_date = ?',
            [$tokens['input'], $tokens['output'], $tokens['cached'], $userId, $date]);
        /* The shared count the chat's daily limit reads too — not a question
           of the person's own (messages stays as it is). */
        ai_usage_add($userId, ['gemini_calls' => 1, 'input_tokens' => $tokens['input'], 'output_tokens' => $tokens['output']]);

        $fail = static function (string $note) use ($userId, $date, $result, $tokens): array {
            ai_usage_add($userId, ['errors' => 1]);
            db_run("UPDATE insight_days SET phrase_state = 'failed', phrase_note = ? WHERE user_id = ? AND local_date = ?",
                [$note, $userId, $date]);

            return $result('failed', $note, 0, $tokens);
        };

        if (!$answer['ok']) {
            if ($answer['outcome'] === 'quota') {
                ai_quota_block(insights_quota_until($answer), ($answer['daily'] ?? false) ? 'per day' : 'per minute');
            }
            /* A 400 about the schema: this model takes no JSON schema on
               generateContent. Remembered, so later days ask without it. */
            if ($status === 400 && preg_match('/schema|response_?mime|responseJson/i', json_encode($json['error'] ?? '') ?: '')) {
                db_run("INSERT INTO ai_service_state (name, value) VALUES ('insight_schema', 'off')
                        ON DUPLICATE KEY UPDATE value = 'off'");
            }

            return $fail((string) $answer['outcome']);
        }

        $valid = insights_phrase_validate((string) $answer['text'], $sent, i18n_locale());
        if ($valid === []) {
            return $fail('invalid');
        }

        $applied = 0;
        foreach ($valid as $key => $text) {
            $done = db_run(
                'UPDATE user_insights SET phrased_body = ?, phrased_locale = ?, phrased_model = ?
                  WHERE id = ? AND user_id = ? AND fingerprint = ? AND withdrawn_at IS NULL',
                [$text, i18n_locale(), (string) ai_model(), $sent[$key]['id'], $userId, $sent[$key]['fingerprint']]
            );
            $applied += $done === null ? 0 : $done->rowCount();
        }

        db_run("UPDATE insight_days SET phrase_state = 'done', phrase_note = ? WHERE user_id = ? AND local_date = ?",
            [count($valid) < count($sent) ? 'partial' : null, $userId, $date]);

        return $result('done', count($valid) < count($sent) ? 'partial' : null, $applied, $tokens);
    }

    /** Until when Google's quota is used up, as the chat works it out. */
    function insights_quota_until(array $answer): DateTimeImmutable
    {
        if (($answer['daily'] ?? false) === true) {
            return (new DateTimeImmutable('tomorrow', new DateTimeZone('America/Los_Angeles')))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }

        return new DateTimeImmutable('+' . max(30, min((int) ($answer['retry_after'] ?? 0) ?: 60, 3600)) . ' seconds');
    }

    /* ======================================================= what is sent */

    /**
     * Per insight, what Gemini gets: the key, the claim id, the sentence and
     * the claim with its limits — in the language shown now. Keyed by slot.
     *
     * @return array<string, array<string, mixed>>
     */
    function insights_phrase_items(array $rows): array
    {
        $copy = insights_copy();
        $out  = [];

        foreach ($rows as $row) {
            $template = insights_template($row, $copy);
            $claim    = research_claim_by_id((int) $row['claim_id']);
            if ($template === null || $claim === null) {
                continue;
            }
            $text = i18n_tree(['statement' => $claim['statement'], 'limitations' => $claim['limitations']]);

            $out[(string) $row['slot']] = [
                'id'          => (int) $row['id'],
                'fingerprint' => (string) $row['fingerprint'],
                'claim_id'    => (int) $row['claim_id'],
                'template'    => $template,
                'statement'   => (string) $text['statement'],
                'limitations' => array_values((array) $text['limitations']),
            ];
        }

        return $out;
    }

    /** The generateContent body: instructions, the items, and the JSON shape asked for. */
    function insights_phrase_request(array $sent, string $locale): array
    {
        $g        = insights_config()['gemini'];
        $language = $locale === 'en' ? 'English' : 'Dutch';
        $max      = (int) $g['body_max_chars'];

        $items = [];
        foreach ($sent as $key => $item) {
            $items[] = [
                'key'         => $key,
                'claim_id'    => $item['claim_id'],
                'template'    => $item['template'],
                'claim'       => $item['statement'],
                'limitations' => $item['limitations'],
            ];
        }

        $instruction = "You reword short health insights for the Ownify app. Each item has a template sentence that is already correct, "
            . "and the approved research claim behind it with its limitations. For each item, write one body in {$language} that says "
            . "exactly the same as the template in clear, friendly words, in at most {$max} characters, addressing the reader as "
            . ($locale === 'en' ? '"you"' : '"je"') . ".\n"
            . "Rules:\n"
            . "- Use exactly the numbers in the template, written as there, and no other numbers, percentages, dates or units.\n"
            . "- Describe and compare only: never say why something happens, never name a cause.\n"
            . "- No diagnosis, illness or condition; no orders such as must or should; no promises of an outcome; no comparison with other people.\n"
            . "- Keep every limitation the template states, for example that Ownify only sees recorded workouts.\n"
            . "- Never add a source, study, author, link or citation.\n"
            . "- If you cannot follow these rules for an item, give it an empty body.\n"
            . "Answer with JSON only.";

        $config = [
            'maxOutputTokens'  => max(256, (int) $g['max_output_tokens']),
            'responseMimeType' => 'application/json',
        ];
        if (db_value("SELECT value FROM ai_service_state WHERE name = 'insight_schema'") !== 'off') {
            $config['responseJsonSchema'] = [
                'type'       => 'object',
                'properties' => [
                    'items' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'key'      => ['type' => 'string'],
                                'claim_id' => ['type' => 'integer'],
                                'body'     => ['type' => 'string'],
                            ],
                            'required'   => ['key', 'claim_id', 'body'],
                        ],
                    ],
                ],
                'required'   => ['items'],
            ];
        }
        $thinking = strtolower(trim((string) (ai_config()['thinking_level'] ?? '')));
        if ($thinking !== '' && $thinking !== 'none') {
            $config['thinkingConfig'] = ['thinkingLevel' => $thinking];
        }

        return [
            'systemInstruction' => ['parts' => [['text' => $instruction]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => json_encode(['items' => $items],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]]],
            'generationConfig'  => $config,
        ];
    }

    /* ======================================================== the checks */

    /** The numbers written in a text, as written: "6:40", "12,8", "1.000", "7". */
    function insights_phrase_numbers(string $text): array
    {
        preg_match_all('/\d+(?:[.,:]\d+)*/u', $text, $m);
        $numbers = $m[0];
        sort($numbers, SORT_STRING);

        return $numbers;
    }

    /**
     * What Gemini wrote, kept only where it passes every check. A JSON that
     * parses is not yet a correct insight.
     *
     * @return array<string, string> key => body
     */
    function insights_phrase_validate(string $text, array $sent, string $locale): array
    {
        $g      = insights_config()['gemini'];
        $answer = json_decode(trim($text), true);

        if (!is_array($answer) || !is_array($answer['items'] ?? null)) {
            return [];
        }

        $forbidden = (array) ($g['forbidden'][$locale] ?? []);
        $words = $locale === 'en'
            ? ['the', 'you', 'your', 'of', 'and', 'a', 'per', 'that', 'is', 'in', 'on', 'for', 'with', 'than']
            : ['de', 'het', 'een', 'je', 'van', 'en', 'per', 'dat', 'is', 'in', 'op', 'voor', 'met', 'dan', 'die'];
        $other = $locale === 'en'
            ? ['de', 'het', 'een', 'je', 'van', 'en', 'dat', 'voor', 'met', 'dan', 'die']
            : ['the', 'you', 'your', 'of', 'and', 'that', 'for', 'with', 'than'];

        $out = [];
        foreach ($answer['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key  = $item['key'] ?? null;
            $body = $item['body'] ?? null;

            /* Only what was sent, once each, with its own claim. */
            if (!is_string($key) || !isset($sent[$key]) || isset($out[$key])
                || (int) ($item['claim_id'] ?? 0) !== (int) $sent[$key]['claim_id'] || !is_string($body)) {
                continue;
            }

            $body = trim(preg_replace('/\s+/u', ' ', $body));
            $template = $sent[$key]['template'];
            $lower    = mb_strtolower($body);
            $tlower   = mb_strtolower($template);

            if ($body === '' || mb_strlen($body) > (int) $g['body_max_chars']) {
                continue;
            }
            /* Exactly the template's numbers. */
            if (insights_phrase_numbers($body) !== insights_phrase_numbers($template)) {
                continue;
            }
            /* No cause, diagnosis, order, comparison or link the template
               does not have. A term matches at the start of a word, so
               "veroorzaak" finds "veroorzaakt", and "anderen" never matches
               inside "veranderen". */
            foreach ($forbidden as $word) {
                $pattern = '/(?<!\p{L})' . preg_quote(mb_strtolower((string) $word), '/') . '/u';
                if (preg_match($pattern, $lower) && !preg_match($pattern, $tlower)) {
                    continue 2;
                }
            }
            /* No markup. */
            if (preg_match('/[*#`<>\[\]{}]|\]\(/u', $body)) {
                continue;
            }
            /* The language shown now. */
            $tokens = preg_split('/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $mine   = count(array_intersect($tokens, $words));
            $theirs = count(array_intersect($tokens, $other));
            if ($mine < 3 || $theirs >= $mine) {
                continue;
            }

            $out[$key] = $body;
        }

        return $out;
    }
}
