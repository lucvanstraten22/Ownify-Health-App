<?php
/**
 * Ownify AI: one question, from the moment it arrives to the stored answer.
 *
 *   1. who is asking            the endpoint (api_require_account_user())
 *   2. consent                  nothing goes to Gemini without it
 *   3. the question itself      text, not empty, not too long
 *   4. an answer to a proposal  "ja" / "nee" to a prepared change is carried
 *                               out here, without Gemini and without counting
 *   5. the daily limit          one of today's messages is taken (and given
 *                               back when no answer comes)
 *   6. the person's data        fetched now: the profile, the scores, the
 *                               goals and two weeks in brief every time, the
 *                               rest by the question's topic
 *   7. the conversation         the recent messages, the older ones in brief
 *   8. Gemini                   one request — or a few, when it looks things
 *                               up with its tools — from this server only
 *   9. stored                   the question and the answer, together
 *  10. usage                    requests and tokens, per person and in total
 *
 * Every failure is one of a few outcomes with a sentence the apps show as it
 * is (config/dashboard.php → 'ai' → 'errors'); Gemini's own error text never
 * reaches anyone.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/format.php';

if (!function_exists('ai_chat')) {

    /** The assistant's copy (config/dashboard.php → 'ai'). */
    function ai_copy(): array
    {
        static $copy = null;

        return $copy ??= (array) ((require dirname(__DIR__, 2) . '/config/dashboard.php')['ai'] ?? []);
    }

    function ai_error_text(string $code): string
    {
        $errors = ai_copy()['errors'] ?? [];

        return (string) ($errors[$code] ?? $errors['unavailable'] ?? 'De AI-assistent is tijdelijk niet beschikbaar. Probeer het later opnieuw.');
    }

    /** A failed question: HTTP status, a code the apps switch on, and the sentence to show. */
    function ai_fail(int $status, string $code, array $extra = []): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'error' => ai_error_text($code)] + $extra;
    }

    /**
     * Asks the assistant.
     *
     * @return array<string,mixed>  ok: conversation, messages (new ones), usage;
     *                              failed: status, code, error, usage
     */
    function ai_chat(int $userId, ?int $conversationId, string $message): array
    {
        error_log('[ownify] ai: request received');

        if (!ai_installed()) {
            error_log('[ownify] ai: not installed — import database/migrations/015-ai-assistant.sql');

            return ai_fail(503, 'unavailable');
        }

        if (!ai_consented($userId)) {
            return ai_fail(403, 'consent');
        }

        /* ------------------------------------------------------ the question */

        $message = trim(str_replace(["\r\n", "\r"], "\n", $message));
        $message = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);

        if ($message === '' || preg_match('//u', $message) !== 1) {
            return ai_fail(422, 'empty');
        }

        if (mb_strlen($message) > (int) ai_config()['max_message_chars']) {
            return ai_fail(422, 'too_long');
        }

        $conversation = null;

        if ($conversationId !== null) {
            $conversation = ai_conversation_get($userId, $conversationId);

            if ($conversation === null) {
                return ai_fail(404, 'not_found');
            }

            error_log('[ownify] ai: conversation loaded');
        }

        /* ---------------------------------- "ja" or "nee" to a proposal */

        if ($conversation !== null) {
            $pending = ai_action_pending($userId, (int) $conversation['id']);
            $reply   = $pending === null ? null : ai_reply_to_proposal($message);

            if ($reply !== null) {
                $userMessageId = ai_message_add($userId, (int) $conversation['id'], 'user', $message);
                $resolved      = ai_action_resolve($userId, (int) $pending['id'], $reply === 'yes');

                return [
                    'ok'           => true,
                    'conversation' => ai_conversation_view(ai_conversation_get($userId, (int) $conversation['id'])),
                    'messages'     => array_values(array_filter([
                        $userMessageId === null ? null : ai_message_view(ai_message_get($userId, $userMessageId)),
                        ...$resolved['messages'],
                    ])),
                    'usage'        => ai_usage_summary($userId),
                ];
            }
        }

        /* ------------------------------------------- can Gemini be asked */

        $ready = ai_readiness();

        if (!$ready['ok']) {
            error_log('[ownify] ai: not available: ' . $ready['reason']
                . ($ready['reason'] === 'no_key' ? ' — set GEMINI_API_KEY (docs/AI.md)' : ''));

            return ai_fail(503, 'unavailable', ['usage' => ai_usage_summary($userId)]);
        }

        if (ai_quota_blocked_until() !== null) {
            return ai_fail(503, 'quota', ['usage' => ai_usage_summary($userId)]);
        }

        if (ai_usage_global_calls() >= (int) ai_config()['global_daily_limit']) {
            error_log('[ownify] ai: Ownify\'s own daily total of Gemini requests is reached (global_daily_limit)');

            return ai_fail(503, 'quota', ['usage' => ai_usage_summary($userId)]);
        }

        if (!ai_usage_reserve($userId)) {
            ai_usage_add($userId, ['limit_hits' => 1]);

            return ai_fail(429, 'limit', ['usage' => ai_usage_summary($userId)]);
        }

        /* ------------------------------------------------ what goes along */

        $history = $conversation === null
            ? ['contents' => [], 'digest' => [], 'previous' => null]
            : ai_history($userId, (int) $conversation['id']);

        $context  = ai_context($userId, ai_topics($message, $history['previous']));
        $contents = ai_contents_append($history['contents'], 'user', [['text' => $message]]);

        $body = [
            'systemInstruction' => ['parts' => [['text' => ai_system_instruction($context, $history['digest'])]]],
            'tools'             => [['functionDeclarations' => ai_tool_declarations()]],
            'generationConfig'  => ['maxOutputTokens' => max(256, (int) ai_config()['max_output_tokens'])],
        ];

        $thinking = strtolower(trim((string) (ai_config()['thinking_level'] ?? '')));
        if ($thinking !== '' && $thinking !== 'none') {
            $body['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $thinking];
        }

        /* ---------------------------------------------------------- Gemini */

        $turn     = ['proposal' => null, 'tools' => []];
        $rounds   = max(1, (int) ai_config()['max_rounds']);
        $deadline = microtime(true) + 55;   // under the web server's own time limit
        $answer   = null;
        $calls    = 0;
        $tokens   = ['input_tokens' => 0, 'output_tokens' => 0];

        for ($round = 1; $round <= $rounds; $round++) {
            /* The last round must answer in words; so must one that would
               otherwise run past the time there is. */
            $last = $round === $rounds || microtime(true) > $deadline - 20;

            $body['contents']   = $contents;
            $body['toolConfig'] = ['functionCallingConfig' => ['mode' => $last ? 'NONE' : 'AUTO']];

            if ($round > 1 && ai_usage_global_calls() >= (int) ai_config()['global_daily_limit']) {
                $answer = ['ok' => false, 'outcome' => 'quota'];
                break;
            }

            $answer = ai_gemini_generate($body);
            $calls++;

            if (!$answer['ok'] && $answer['outcome'] === 'thinking' && isset($body['generationConfig']['thinkingConfig'])) {
                unset($body['generationConfig']['thinkingConfig']);
                $answer = ai_gemini_generate($body);
                $calls++;
            }

            $tokens['input_tokens']  += (int) ($answer['usage']['input_tokens'] ?? 0);
            $tokens['output_tokens'] += (int) ($answer['usage']['output_tokens'] ?? 0);

            if (!$answer['ok']) {
                break;
            }

            if ($answer['calls'] === [] || $last) {
                break;
            }

            /* Gemini asked for something: carry it out and let it go on. */
            $contents[] = $answer['content'];
            $responses  = [];

            foreach ($answer['calls'] as $call) {
                $result = ai_tool_run($userId, $call['name'], $call['args'], $turn);
                $part   = ['name' => $call['name'], 'response' => ['result' => $result]];
                if ($call['id'] !== null) {
                    $part['id'] = $call['id'];
                }
                $responses[] = ['functionResponse' => $part];
            }

            $contents[] = ['role' => 'user', 'parts' => $responses];
        }

        ai_usage_add($userId, ['gemini_calls' => $calls] + $tokens);

        if ($answer === null || !$answer['ok'] || trim((string) $answer['text']) === '') {
            $outcome = $answer === null ? 'unavailable' : ($answer['ok'] ? 'invalid' : $answer['outcome']);

            ai_usage_release($userId);
            ai_usage_add($userId, ['errors' => 1]);

            if ($outcome === 'quota') {
                ai_quota_block(ai_quota_until($answer ?? []), ($answer['daily'] ?? false) ? 'per day' : 'per minute');
            }

            $code = match ($outcome) {
                'quota'   => 'quota',
                'timeout' => 'timeout',
                'blocked' => 'blocked',
                default   => 'unavailable',
            };

            ai_diagnostic_write('question failed: outcome=' . $outcome . ' after ' . $calls
                . ' Gemini request(s); the person is told: ' . $code);

            return ai_fail($code === 'quota' ? 503 : ($code === 'blocked' ? 422 : 503), $code, ['usage' => ai_usage_summary($userId)]);
        }

        error_log('[ownify] ai: Gemini request successful (' . $calls . ' request' . ($calls === 1 ? '' : 's')
            . ($turn['tools'] === [] ? '' : ', tools: ' . implode(', ', array_unique($turn['tools']))) . ')');

        /* ---------------------------------------------------------- stored */

        $conversationId = $conversation === null
            ? ai_conversation_create($userId, $message)
            : (int) $conversation['id'];

        if ($conversationId === null) {
            ai_usage_release($userId);

            return ai_fail(500, 'unavailable', ['usage' => ai_usage_summary($userId)]);
        }

        /* Asked something else: a change still waiting for a yes is not done. */
        ai_actions_expire($userId, $conversationId);

        $meta = [
            'model'  => ai_model(),
            'calls'  => $calls,
            'tokens' => $tokens,
            'tools'  => array_values(array_unique($turn['tools'])),
        ];

        /* The answer as Gemini sent it, thought signature included: the next
           question sends it back unchanged (ai_history()). Only the answer's
           own parts — never a look-up's result, which holds health data. */
        $parts = ai_replay_parts($answer['content'] ?? null);
        if ($parts !== null && strlen((string) json_encode($meta + ['parts' => $parts], JSON_UNESCAPED_UNICODE)) < 60000) {
            $meta['parts'] = $parts;
        }

        ai_diagnostic_write('question answered after ' . $calls . ' Gemini request(s); kept for the next question: '
            . (isset($meta['parts']) ? '[model: ' . ai_gemini_part_kinds($meta['parts']) . ']' : 'the text only, no parts'));

        $userMessageId      = ai_message_add($userId, $conversationId, 'user', $message);
        $assistantMessageId = ai_message_add($userId, $conversationId, 'assistant', (string) $answer['text'], $turn['proposal'], $meta);
        ai_conversation_touch($userId, $conversationId);

        return [
            'ok'           => true,
            'conversation' => ai_conversation_view(ai_conversation_get($userId, $conversationId)),
            'messages'     => array_values(array_filter([
                $userMessageId === null ? null : ai_message_view(ai_message_get($userId, $userMessageId)),
                $assistantMessageId === null ? null : ai_message_view(ai_message_get($userId, $assistantMessageId)),
            ])),
            'usage'        => ai_usage_summary($userId),
        ];
    }

    /**
     * Until when not to ask Gemini again after a 429: a daily quota is back
     * at midnight Pacific time, when Google resets it; a per-minute one after
     * the delay Google gives, or a minute.
     */
    function ai_quota_until(array $answer): DateTimeImmutable
    {
        if (($answer['daily'] ?? false) === true) {
            $pacific = new DateTimeImmutable('tomorrow', new DateTimeZone('America/Los_Angeles'));

            return $pacific->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }

        $seconds = (int) ($answer['retry_after'] ?? 0);

        return new DateTimeImmutable('+' . max(30, min($seconds ?: 60, 3600)) . ' seconds');
    }

    /* ========================================================= proposals */

    /**
     * Whether a message is a plain yes or no to the change waiting for one —
     * "ja", "ja graag", "doe maar", "nee", "liever niet". Anything more ("ja,
     * maar dan 30 minuten") is a question for Gemini, and the change waits
     * no longer.
     *
     * @return 'yes'|'no'|null
     */
    function ai_reply_to_proposal(string $message): ?string
    {
        $text = mb_strtolower(trim($message));
        $text = (string) preg_replace('/[^\p{L}\p{N}\s\']+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || mb_strlen($text) > 40) {
            return null;
        }

        $yes = '(ja|jazeker|jawel|jep|yes|yep|yup|ok|oke|oké|okay|okido|prima|graag|akkoord|goed|is goed|klopt|zeker|sure|doe maar|go ahead|do it|toevoegen|voeg toe|voeg maar toe|voeg het toe|voeg het maar toe|pauzeren|hervatten|afronden|top|perfect)';
        $yesTail = '( (graag|hoor|please|dan|maar|doe maar|dat is goed|is goed|prima|toevoegen|voeg (het )?(maar )?toe|dank je|dankjewel|bedankt|thanks|top|perfect))*';
        $no = '(nee|neen|no|nope|niet doen|laat maar|liever niet|annuleer|annuleren|cancel|toch niet|niet nu|not now|nee dank je|nee bedankt|no thanks)';
        $noTail = '( (hoor|dank je|dankjewel|bedankt|thanks|toch niet|niet nu|laat maar))*';

        if (preg_match('/^' . $yes . $yesTail . '$/u', $text) === 1) {
            return 'yes';
        }

        if (preg_match('/^' . $no . $noTail . '$/u', $text) === 1) {
            return 'no';
        }

        return null;
    }

    /**
     * Confirms or declines the change on message $messageId — the person's
     * own, still waiting. Confirmed, it is checked again and carried out; a
     * note from Ownify says what happened.
     *
     * @return array{ok: bool, status: int, code: ?string, error: ?string, messages: array}
     */
    function ai_action_resolve(int $userId, int $messageId, bool $confirm): array
    {
        $claimed = ai_action_claim($userId, $messageId);

        if ($claimed === null) {
            $existing = ai_message_get($userId, $messageId);

            return [
                'ok'       => false,
                'status'   => $existing === null ? 404 : 409,
                'code'     => $existing === null ? 'not_found' : 'action_gone',
                'error'    => ai_error_text($existing === null ? 'not_found' : 'action_gone'),
                'messages' => $existing === null ? [] : [ai_message_view($existing)],
            ];
        }

        $action = json_decode((string) $claimed['action_json'], true);
        $conversationId = (int) $claimed['conversation_id'];

        if (!$confirm || !is_array($action)) {
            ai_action_finish($userId, $messageId, 'declined');
            $title  = (string) ($action['title'] ?? 'Het voorstel');
            $noteId = ai_message_add($userId, $conversationId, 'system',
                'Niet doorgevoerd: ' . mb_strtolower(mb_substr($title, 0, 1)) . mb_substr($title, 1) . '.');
        } else {
            $result = ai_action_execute($userId, $action);
            ai_action_finish($userId, $messageId, $result['ok'] ? 'done' : 'failed');
            $noteId = ai_message_add($userId, $conversationId, 'system', $result['message']);
        }

        ai_conversation_touch($userId, $conversationId);

        return [
            'ok'       => true,
            'status'   => 200,
            'code'     => null,
            'error'    => null,
            'messages' => array_values(array_filter([
                ai_message_view(ai_message_get($userId, $messageId)),
                $noteId === null ? null : ai_message_view(ai_message_get($userId, $noteId)),
            ])),
        ];
    }

    /* ======================================================= the history */

    /**
     * What Gemini is sent of the conversation so far: the most recent
     * messages as they were, the older questions in brief (for the system
     * instruction), and the last question (for what "that" refers to).
     *
     * @return array{contents: array, digest: string[], previous: ?string}
     */
    function ai_history(int $userId, int $conversationId): array
    {
        $rows   = ai_messages($userId, $conversationId, 500);
        $keep   = max(2, (int) ai_config()['history_messages']);
        $recent = array_slice($rows, -$keep);
        $older  = array_slice($rows, 0, max(0, count($rows) - $keep));

        $digest = [];
        foreach ($older as $row) {
            if ($row['role'] === 'user') {
                $line     = trim((string) preg_replace('/\s+/u', ' ', (string) $row['content']));
                $digest[] = mb_strlen($line) > 160 ? mb_substr($line, 0, 157) . '…' : $line;
            }
        }
        $digest = array_slice($digest, -max(0, (int) ai_config()['history_digest']));

        $contents = [];
        $previous = null;

        foreach ($recent as $row) {
            $text = (string) $row['content'];

            if ($row['role'] === 'user') {
                $previous = $text;
                $contents = ai_contents_append($contents, 'user', [['text' => $text]]);
            } elseif ($row['role'] === 'assistant') {
                /* Gemini 3 takes an earlier answer back only as it sent it, with
                   its thought signature; a model turn rebuilt from the text
                   alone is refused (HTTP 400). An answer stored before its
                   parts were kept goes along as a quote on the person's side. */
                $meta  = $row['meta_json'] === null ? null : json_decode((string) $row['meta_json'], true);
                $parts = is_array($meta) ? ai_replay_parts($meta['parts'] ?? null) : null;

                $contents = $parts !== null
                    ? ai_contents_append($contents, 'model', $parts)
                    : ai_contents_append($contents, 'user', [['text' => "[Your earlier answer in this conversation:]\n" . $text]]);

                $action = $row['action_json'] === null ? null : json_decode((string) $row['action_json'], true);
                if (is_array($action)) {
                    $contents = ai_contents_append($contents, 'user', [['text' => '[Ownify: proposal shown to the user: '
                        . ($action['title'] ?? '') . ' — ' . ($action['summary'] ?? '')
                        . '. Status: ' . ai_action_state_word((string) $row['action_state']) . '.]']]);
                }
            } else {
                $contents = ai_contents_append($contents, 'user', [['text' => '[Ownify: ' . $text . ']']]);
            }
        }

        /* A conversation sent to Gemini starts with the person speaking. */
        while ($contents !== [] && $contents[0]['role'] !== 'user') {
            array_shift($contents);
        }

        return ['contents' => $contents, 'digest' => $digest, 'previous' => $previous];
    }

    function ai_action_state_word(string $state): string
    {
        return match ($state) {
            'pending', 'running' => 'waiting for the user',
            'done'     => 'confirmed and carried out',
            'declined' => 'declined by the user',
            'failed'   => 'confirmed, but it could not be carried out',
            default    => 'not answered; no longer valid',
        };
    }

    /**
     * An answer's parts as they can go back to Gemini: text parts, with their
     * thought signature, exactly as received. Null when there is nothing to
     * send back, or when the answer holds anything but text (a function
     * call belongs to its own turn and is never replayed later).
     */
    function ai_replay_parts(mixed $content): ?array
    {
        $content = json_decode((string) json_encode($content), true);
        $parts   = is_array($content) && array_is_list($content) ? $content : ($content['parts'] ?? null);

        if (!is_array($parts) || $parts === []) {
            return null;
        }

        $text = false;
        foreach ($parts as $part) {
            if (!is_array($part) || !is_string($part['text'] ?? null) || array_diff(array_keys($part), ['text', 'thought', 'thoughtSignature']) !== []) {
                return null;
            }
            $text = $text || (($part['thought'] ?? false) !== true && trim($part['text']) !== '');
        }

        return $text ? $parts : null;
    }

    /** Adds parts to the conversation; two turns of the same role in a row become one. */
    function ai_contents_append(array $contents, string $role, array $parts): array
    {
        $last = count($contents) - 1;

        if ($last >= 0 && is_array($contents[$last]) && ($contents[$last]['role'] ?? null) === $role) {
            $contents[$last]['parts'] = [...$contents[$last]['parts'], ...$parts];

            return $contents;
        }

        $contents[] = ['role' => $role, 'parts' => $parts];

        return $contents;
    }

    /* ========================================================= for the apps */

    /** One message as the apps draw it. */
    function ai_message_view(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $view = [
            'id'         => (int) $row['id'],
            'role'       => (string) $row['role'],
            'text'       => (string) $row['content'],
            'blocks'     => $row['role'] === 'assistant' ? ai_format_blocks((string) $row['content']) : null,
            'created_at' => (string) $row['created_at'],
            'action'     => null,
        ];

        $action = $row['action_json'] === null ? null : json_decode((string) $row['action_json'], true);

        if (is_array($action)) {
            $state = (string) $row['action_state'];
            $view['action'] = [
                'title'   => (string) ($action['title'] ?? ''),
                'summary' => (string) ($action['summary'] ?? ''),
                'state'   => $state === 'running' ? 'pending' : $state,
                'confirm' => (string) ($action['confirm'] ?? 'Bevestigen'),
                'decline' => (string) ($action['decline'] ?? 'Niet nu'),
                'status'  => ai_action_status_text($state),
            ];
        }

        return $view;
    }

    function ai_action_status_text(string $state): ?string
    {
        return match ($state) {
            'done'     => 'Doorgevoerd',
            'declined' => 'Niet doorgevoerd',
            'failed'   => 'Mislukt',
            'expired'  => 'Verlopen',
            default    => null,
        };
    }

    function ai_conversation_view(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $at    = strtotime((string) $row['updated_at']) ?: time();
        $today = strtotime('today');
        $months = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

        $label = match (true) {
            $at >= $today              => 'Vandaag ' . date('H:i', $at),
            $at >= $today - 86400      => 'Gisteren',
            date('Y', $at) === date('Y') => (int) date('j', $at) . ' ' . $months[(int) date('n', $at) - 1],
            default                    => (int) date('j', $at) . ' ' . $months[(int) date('n', $at) - 1] . ' ' . date('Y', $at),
        };

        return [
            'id'    => (int) $row['id'],
            'title' => (string) $row['title'] !== '' ? (string) $row['title'] : 'Gesprek',
            'label' => $label,
        ];
    }

    /**
     * The assistant at a glance, without any conversation: whether the
     * person has said yes, whether a question can be answered now (and if
     * not, why, in words), today's limit, and whether they have any data at
     * all. The pages carry this (lib/app-data.php), so the sheet opens on the
     * right screen before anything is fetched.
     *
     * @return array<string,mixed>
     */
    function ai_summary(int $userId): array
    {
        $ready = ai_readiness();

        /* Why the assistant cannot answer right now, if it cannot: not set
           up (no key, no migration) or Gemini's free quota used up. */
        $unavailable = match (true) {
            !$ready['ok'] => 'unavailable',
            ai_quota_blocked_until() !== null,
            ai_usage_global_calls() >= (int) ai_config()['global_daily_limit'] => 'quota',
            default => null,
        };

        return [
            'available'   => $unavailable === null,
            'unavailable' => $unavailable,
            'notice'      => $unavailable === null ? null : ai_error_text($unavailable),
            'consent'     => ai_consent_state($userId),
            'usage'       => ai_usage_summary($userId),
            'has_data'    => ai_has_data($userId),
        ];
    }

    /**
     * Everything the assistant sheet needs to draw itself: ai_summary(), the
     * conversations, and one of them in full — the one asked for, the last
     * one used, or none for a new one.
     *
     * @return array<string,mixed>
     */
    function ai_state(int $userId, ?int $conversationId = null, bool $fresh = false): array
    {
        $installed = ai_installed();

        $state = ai_summary($userId) + [
            'conversations' => [],
            'conversation'  => null,
            'messages'      => [],
        ];

        if (!$installed || $state['consent'] !== 'accepted') {
            return $state;
        }

        $state['conversations'] = array_map('ai_conversation_view', ai_conversations($userId, 30));

        $conversation = null;
        if ($conversationId !== null) {
            $conversation = ai_conversation_get($userId, $conversationId);
        } elseif (!$fresh) {
            $conversation = ai_conversation_latest($userId);
        }

        if ($conversation !== null) {
            $state['conversation'] = ai_conversation_view($conversation);
            $state['messages']     = array_map('ai_message_view', ai_messages($userId, (int) $conversation['id'], 200));
        }

        return $state;
    }
}
