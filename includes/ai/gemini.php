<?php
/**
 * The one place Ownify talks to the Gemini API.
 *
 *     POST {api_base}/v1beta/models/{model}:generateContent
 *     x-goog-api-key: <the key>        (a header: never in a URL, never logged)
 *
 * One request in, one plain answer out. Whatever went wrong is reduced to a
 * handful of outcomes the assistant knows how to say to a person — Gemini's
 * own error text is for the server log, and only the parts that describe
 * the configuration (never a message, never health data).
 *
 *   ok        text and/or function calls, and the tokens it took
 *   quota     HTTP 429: the free quota is used up — per minute or per day
 *   timeout   no answer within config/ai.php `timeout`
 *   blocked   Gemini would not answer this (safety)
 *   thinking  the model does not take the thinking setting (asked again without)
 *   config    the key, the model or the request was refused (401/403/404/400)
 *   invalid   an answer that is not what the API promises
 *   unavailable  anything else: 5xx, no connection
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/diagnostic.php';

if (!function_exists('ai_gemini_generate')) {

    /**
     * @param array<string,mixed> $body  a generateContent request body
     * @return array<string,mixed>       see the list above; always has 'ok' and 'outcome'
     */
    function ai_gemini_generate(array $body): array
    {
        $status = 0;
        $json   = null;
        $answer = ai_gemini_exchange($body, $status, $json);

        /* Off unless switched on (includes/ai/diagnostic.php). */
        ai_diagnostic_exchange($body, $status, is_array($json) ? $json : [], $answer, (string) (ai_model() ?? '?'));

        return $answer;
    }

    /** The request itself; $status and $json say what Google answered, for the diagnostic. */
    function ai_gemini_exchange(array $body, int &$status, mixed &$json): array
    {
        $key   = ai_api_key();
        $model = ai_model();

        if ($key === null || $model === null) {
            return ['ok' => false, 'outcome' => 'config'];
        }

        $config = ai_config();
        $url    = rtrim((string) $config['api_base'], '/') . '/v1beta/models/' . $model . ':generateContent';

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            return ['ok' => false, 'outcome' => 'invalid'];
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $key,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(2, (int) $config['timeout']),
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw    = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($handle);
        unset($handle);

        if ($raw === false || $errno !== 0) {
            $outcome = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable';
            error_log('[ownify] ai: Gemini request failed: ' . ($outcome === 'timeout' ? 'timed out' : 'no connection (curl ' . $errno . ')'));

            return ['ok' => false, 'outcome' => $outcome];
        }

        $json = json_decode((string) $raw, true);

        if ($status !== 200) {
            return ai_gemini_failure($status, is_array($json) ? $json : [], $model, ai_gemini_outline($body));
        }

        if (!is_array($json)) {
            error_log('[ownify] ai: Gemini answered 200 with something that is not JSON');

            return ['ok' => false, 'outcome' => 'invalid'];
        }

        $answer = ai_gemini_answer($json);

        /* What goes back to Gemini after a function call is its own content,
           decoded as objects this time: an empty `args` stays {} rather than
           turning into []. */
        if ($answer['ok']) {
            $object = json_decode((string) $raw);
            $answer['content'] = $object->candidates[0]->content ?? $answer['content'];
        }

        return $answer;
    }

    /** A 200: the first candidate's text and function calls. */
    function ai_gemini_answer(array $json): array
    {
        $usage = [
            'input_tokens'  => (int) ($json['usageMetadata']['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($json['usageMetadata']['candidatesTokenCount'] ?? 0)
                             + (int) ($json['usageMetadata']['thoughtsTokenCount'] ?? 0),
        ];

        $candidate = $json['candidates'][0] ?? null;

        if (!is_array($candidate)) {
            if (isset($json['promptFeedback']['blockReason'])) {
                error_log('[ownify] ai: Gemini would not answer (' . (string) $json['promptFeedback']['blockReason'] . ')');

                return ['ok' => false, 'outcome' => 'blocked', 'usage' => $usage];
            }

            error_log('[ownify] ai: Gemini answered without a candidate');

            return ['ok' => false, 'outcome' => 'invalid', 'usage' => $usage];
        }

        $finish  = (string) ($candidate['finishReason'] ?? '');
        $content = is_array($candidate['content'] ?? null) ? $candidate['content'] : [];
        $parts   = is_array($content['parts'] ?? null) ? $content['parts'] : [];

        $text  = '';
        $calls = [];

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $name = $part['functionCall']['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $args = $part['functionCall']['args'] ?? [];
                    $calls[] = [
                        'name' => $name,
                        'args' => is_array($args) ? $args : [],
                        'id'   => is_string($part['functionCall']['id'] ?? null) ? $part['functionCall']['id'] : null,
                    ];
                }
                continue;
            }

            /* A thought summary is the model's working, not its answer. */
            if (($part['thought'] ?? false) === true) {
                continue;
            }

            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);

        if ($text === '' && $calls === []) {
            $blocked = in_array($finish, ['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'LANGUAGE'], true);
            error_log('[ownify] ai: Gemini answered with nothing to show (finishReason ' . ($finish === '' ? 'none' : $finish) . ')');

            return ['ok' => false, 'outcome' => $blocked ? 'blocked' : 'invalid', 'usage' => $usage];
        }

        return [
            'ok'      => true,
            'outcome' => 'ok',
            /* Sent back as it came when the conversation goes on after a
               function call: Gemini 3 models put a thought signature on the
               call that must travel with it. */
            'content' => ['role' => 'model', 'parts' => $parts],
            'text'    => $text,
            'calls'   => $calls,
            'finish'  => $finish,
            'usage'   => $usage,
        ];
    }

    /**
     * What a request held, for the server log when Gemini refuses it: each
     * turn's role and its parts' kinds — text with its length, a thought
     * signature, a function call or response by name. Never a word of the
     * text itself, never the key.
     *
     *   contents=3 [user: text(21) | model: text(212)+sig | user: text(18)]
     *   system=yes tools=9
     */
    function ai_gemini_outline(array $body): string
    {
        $turns = [];

        foreach ((array) ($body['contents'] ?? []) as $content) {
            $content = (array) $content;
            $turns[] = (string) ($content['role'] ?? '?') . ': ' . ai_gemini_part_kinds((array) ($content['parts'] ?? []));
        }

        $tools = 0;
        foreach ((array) ($body['tools'] ?? []) as $tool) {
            $tools += count((array) (((array) $tool)['functionDeclarations'] ?? []));
        }

        return 'contents=' . count($turns) . ' [' . implode(' | ', $turns) . ']'
            . ' system=' . (isset($body['systemInstruction']) ? 'yes' : 'no')
            . ' tools=' . $tools
            . ' config=' . json_encode($body['generationConfig'] ?? null);
    }

    /**
     * Each part's kind, never its content: text(212), thought(40), a call or
     * response by function name, `+sig` where a thought signature came along,
     * and any field this list does not know by name.
     */
    function ai_gemini_part_kinds(array $parts): string
    {
        $kinds = [];

        foreach ($parts as $part) {
            $part = (array) $part;
            $kind = match (true) {
                isset($part['functionCall'])     => 'call:' . (string) (((array) $part['functionCall'])['name'] ?? '?'),
                isset($part['functionResponse']) => 'response:' . (string) (((array) $part['functionResponse'])['name'] ?? '?'),
                isset($part['text'])             => (($part['thought'] ?? false) === true ? 'thought' : 'text')
                                                    . '(' . mb_strlen((string) $part['text']) . ')',
                default                          => 'other',
            };
            $extra = array_diff(array_keys($part), ['functionCall', 'functionResponse', 'text', 'thought', 'thoughtSignature']);
            $kinds[] = $kind . (isset($part['thoughtSignature']) ? '+sig' : '') . ($extra === [] ? '' : '{' . implode(',', $extra) . '}');
        }

        return $kinds === [] ? 'NO PARTS' : implode(', ', $kinds);
    }

    /** Anything but a 200, reduced to an outcome. */
    function ai_gemini_failure(int $status, array $json, string $model = '?', string $outline = ''): array
    {
        $error   = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code    = (string) ($error['status'] ?? '');
        /* Gemini's error messages describe the request's configuration — the
           model, a field, the key's validity — never what a person wrote.
           Still cut short, and never with the key (it is in a header, which
           Gemini does not repeat). */
        $message = mb_substr(preg_replace('/\s+/', ' ', (string) ($error['message'] ?? '')), 0, 200);

        if ($status === 429) {
            $retry = null;
            $daily = false;

            foreach ((array) ($error['details'] ?? []) as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                if (isset($detail['retryDelay']) && preg_match('/^(\d+(?:\.\d+)?)s$/', (string) $detail['retryDelay'], $m)) {
                    $retry = (int) ceil((float) $m[1]);
                }
                foreach ((array) ($detail['violations'] ?? []) as $violation) {
                    $quota = (string) (is_array($violation) ? ($violation['quotaId'] ?? $violation['quotaMetric'] ?? '') : '');
                    if (stripos($quota, 'PerDay') !== false || stripos($quota, 'per_day') !== false) {
                        $daily = true;
                    }
                }
            }

            error_log('[ownify] ai: Gemini request failed: quota exceeded (' . ($daily ? 'per day' : 'per minute') . ')');

            return ['ok' => false, 'outcome' => 'quota', 'daily' => $daily, 'retry_after' => $retry];
        }

        if ($status === 400 && stripos($message, 'thinking') !== false) {
            error_log('[ownify] ai: the model does not take thinking_level; asking again without it');

            return ['ok' => false, 'outcome' => 'thinking'];
        }

        if (in_array($status, [400, 401, 403, 404], true)) {
            error_log('[ownify] ai: Gemini refused the request: HTTP ' . $status . ' ' . $code
                . ($message === '' ? '' : ' — ' . $message)
                . ' (model ' . $model . '; check GEMINI_API_KEY and GEMINI_MODEL; see docs/AI.md)'
                . ($outline === '' ? '' : ' — request: ' . $outline));

            return ['ok' => false, 'outcome' => 'config'];
        }

        error_log('[ownify] ai: Gemini request failed: HTTP ' . $status . ($code === '' ? '' : ' ' . $code)
            . ($message === '' ? '' : ' — ' . $message) . ' (model ' . $model . ')'
            . ($outline === '' ? '' : ' — request: ' . $outline));

        return ['ok' => false, 'outcome' => 'unavailable'];
    }
}
