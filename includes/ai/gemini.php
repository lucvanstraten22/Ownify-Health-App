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
 *   timeout   no answer before the question's deadline (config/ai.php
 *             `timeout`, all of one question's requests together)
 *   blocked   Gemini would not answer this (safety)
 *   thinking  the model does not take the thinking setting (asked again without)
 *   config    the key, the model or the request was refused (401/403/404/400)
 *   invalid   an answer that is not what the API promises
 *   unavailable  anything else: 5xx, no connection
 *
 * Every answer also says whether asking again could help (`retryable`) —
 * only when Google is overloaded or down for a moment (500, 502, 503, 504)
 * or the request never reached Google (no name, no connection, no TLS) —
 * and how the request went (`transport`: the phase it ended in, the cURL
 * error, the timings), for the log and the diagnostic. Whether to ask again
 * is the assistant's decision (ai_gemini_retry_wait()): it holds the
 * minute's places, the daily count and the deadline.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/diagnostic.php';

if (!function_exists('ai_gemini_generate')) {

    /**
     * @param array<string,mixed> $body  a generateContent request body
     * @return array<string,mixed>       see the list above; always has 'ok' and 'outcome'
     */
    /**
     * @param float|null $deadline  microtime() by which the whole question must
     *                              be done; null: config `timeout` from now
     * @param int        $attempt   1 for the first try, 2… for a retry (for the log)
     */
    function ai_gemini_generate(array $body, ?float $deadline = null, int $attempt = 1): array
    {
        $status = 0;
        $json   = null;
        $deadline ??= microtime(true) + ai_gemini_budget();
        $answer = ai_gemini_exchange($body, $status, $json, $deadline);

        /* Off unless switched on (includes/ai/diagnostic.php). */
        ai_diagnostic_exchange($body, $status, is_array($json) ? $json : [], $answer, (string) (ai_model() ?? '?'), $attempt);

        return $answer;
    }

    /**
     * Seconds one question may take, every Gemini request and retry
     * together (config `timeout`). It must stay under the web server's own
     * limit: on Hestia, Apache gives PHP-FPM 30 s (`Timeout 30`) before it
     * answers the app 504 itself, and PHP then goes on for nobody.
     */
    function ai_gemini_budget(): float
    {
        return max(2.0, (float) (ai_config()['timeout'] ?? 25));
    }

    /**
     * The request itself, cut off at $deadline; $status and $json say what
     * Google answered, for the diagnostic.
     */
    function ai_gemini_exchange(array $body, int &$status, mixed &$json, ?float $deadline = null): array
    {
        $key   = ai_api_key();
        $model = ai_model();

        if ($key === null || $model === null) {
            return ['ok' => false, 'outcome' => 'config', 'retryable' => false];
        }

        $config = ai_config();
        $url    = rtrim((string) $config['api_base'], '/') . '/v1beta/models/' . $model . ':generateContent';

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            return ['ok' => false, 'outcome' => 'invalid', 'retryable' => false];
        }

        /* What is left of the question's time is this request's time; the
           connection gets a few seconds of it at most. */
        $deadline ??= microtime(true) + ai_gemini_budget();
        $timeoutMs = (int) floor(($deadline - microtime(true)) * 1000);

        if ($timeoutMs < 500) {
            error_log('[ownify] ai: no time left for a Gemini request (timeout ' . ai_gemini_budget() . ' s per question)');

            return ['ok' => false, 'outcome' => 'timeout', 'retryable' => false,
                'transport' => ['phase' => 'not sent', 'errno' => 0, 'error' => 'no time left', 'timeout_ms' => $timeoutMs]];
        }

        $connectMs = min($timeoutMs, max(1000, (int) round((float) ($config['connect_timeout'] ?? 5) * 1000)));

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $payload,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_HEADER            => false,
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $key,
                'Expect:',   // no 100-continue round trip before a large body
            ],
            CURLOPT_CONNECTTIMEOUT_MS => $connectMs,
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_NOSIGNAL          => true,   // millisecond timeouts without signals
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_HEADERFUNCTION    => static function ($h, string $line) use (&$retryAfter): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                }

                return strlen($line);
            },
        ]);

        $retryAfter = null;
        $started    = microtime(true);
        $raw        = curl_exec($handle);
        $elapsed    = microtime(true) - $started;
        $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno      = curl_errno($handle);
        $transport  = ai_gemini_transport($handle, $errno, $elapsed, $connectMs, $timeoutMs, strlen($payload), str_starts_with($url, 'https:'));
        unset($handle);

        if ($raw === false || $errno !== 0) {
            /* Never sent: nothing reached Google, nothing was counted there,
               so asking again costs nothing. Sent and no answer: Google may
               have counted it, so it is not asked again. */
            $sent    = !in_array($transport['phase'], ['resolving', 'connecting', 'tls'], true);
            $outcome = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable';

            error_log('[ownify] ai: Gemini request failed: ' . ($outcome === 'timeout' ? 'timed out' : 'no connection')
                . ' while ' . $transport['phase'] . ' after ' . number_format($elapsed, 1, '.', '') . ' s'
                . ' (curl ' . $errno . ' ' . $transport['error'] . '; connect ' . $connectMs . ' ms, request ' . $timeoutMs . ' ms)');

            return ['ok' => false, 'outcome' => $outcome, 'retryable' => !$sent, 'transport' => $transport];
        }

        $json = json_decode((string) $raw, true);

        if ($status !== 200) {
            $failure = ai_gemini_failure($status, is_array($json) ? $json : [], $model, ai_gemini_outline($body));
            $failure['status']    = $status;
            $failure['retryable'] = in_array($status, [500, 502, 503, 504], true);
            $failure['transport'] = $transport;
            if (is_string($retryAfter) && preg_match('/^\d{1,3}$/', $retryAfter) === 1) {
                $failure['retry_after'] ??= (int) $retryAfter;
            }

            return $failure;
        }

        if (!is_array($json)) {
            error_log('[ownify] ai: Gemini answered 200 with something that is not JSON');

            return ['ok' => false, 'outcome' => 'invalid'];
        }

        $answer = ai_gemini_answer($json);
        $answer['retryable'] = false;
        $answer['transport'] = $transport;

        /* What goes back to Gemini after a function call is its own content,
           decoded as objects this time: an empty `args` stays {} rather than
           turning into []. */
        if ($answer['ok']) {
            $object = json_decode((string) $raw);
            $answer['content'] = $object->candidates[0]->content ?? $answer['content'];
        }

        return $answer;
    }

    /**
     * How a request went, for the log and the diagnostic — never the key,
     * the URL's query or a byte of what was sent or received: the phase it
     * ended in, cURL's error, the timings, the timeouts it had, the address
     * family and HTTP version.
     *
     * Phases: resolving (the name), connecting (TCP), tls, sending (the
     * body), waiting (sent, no answer yet: Google is working on it),
     * receiving (the answer was arriving), done.
     */
    function ai_gemini_transport(\CurlHandle $handle, int $errno, float $elapsed, int $connectMs, int $timeoutMs, int $bytes, bool $https): array
    {
        $info = curl_getinfo($handle);
        $time = static fn (string $k): float => (float) ($info[$k] ?? 0);

        $phase = match (true) {
            $errno === 0                                            => 'done',
            $errno === 6                                            => 'resolving',
            $time('connect_time') <= 0.0                            => 'connecting',
            $https && $time('appconnect_time') <= 0.0               => 'tls',
            (int) ($info['size_upload'] ?? 0) < $bytes
                && $time('starttransfer_time') <= 0.0               => 'sending',
            $time('starttransfer_time') <= 0.0                      => 'waiting',
            default                                                 => 'receiving',
        };

        $error = $errno === 0 ? '' : (string) curl_error($handle);
        /* cURL names the host and the time, never the key (a header); still
           cut short and stripped of anything that looks like a query. */
        $error = mb_substr((string) preg_replace('/[?&][^\s]*/', '', $error), 0, 160);
        $ip    = (string) ($info['primary_ip'] ?? '');

        return [
            'phase'      => $phase,
            'errno'      => $errno,
            'error'      => $error,
            'elapsed'    => round($elapsed, 3),
            'connect'    => round($time('connect_time'), 3),
            'tls'        => round($time('appconnect_time'), 3),
            'first_byte' => round($time('starttransfer_time'), 3),
            'total'      => round($time('total_time'), 3),
            'connect_ms' => $connectMs,
            'timeout_ms' => $timeoutMs,
            'ip'         => $ip === '' ? '-' : (str_contains($ip, ':') ? 'v6' : 'v4'),
            'http'       => match ((int) ($info['http_version'] ?? 0)) { 1 => '1.0', 2 => '1.1', 3 => '2', 30 => '3', default => '-' },
        ];
    }

    /**
     * Milliseconds to wait before asking again, or null not to: only for an
     * answer marked retryable, at most config `retries` times for one
     * request, waiting a little longer each time (base, 3 × base, + a little
     * at random, or longer when Google says Retry-After), and only when
     * enough of the question's time is left afterwards for a real try — a
     * third of it. $retried: how often this request was already asked again.
     */
    function ai_gemini_retry_wait(array $answer, int $retried, float $deadline): ?int
    {
        if (($answer['retryable'] ?? false) !== true || $retried >= max(0, (int) (ai_config()['retries'] ?? 2))) {
            return null;
        }

        $base = max(10, (int) (ai_config()['retry_backoff_ms'] ?? 1000));
        $wait = $base * (3 ** $retried) + random_int(0, intdiv($base, 4));

        if (isset($answer['retry_after'])) {
            $wait = max($wait, (int) $answer['retry_after'] * 1000);
        }

        $left = ($deadline - microtime(true)) * 1000 - $wait;

        return $left >= ai_gemini_budget() * 1000 / 3 ? $wait : null;
    }

    /** "answered HTTP 503", "timed out while connecting": for the log, without Gemini's text. */
    function ai_gemini_failure_label(array $answer): string
    {
        $t = is_array($answer['transport'] ?? null) ? $answer['transport'] : [];

        if (($t['errno'] ?? 0) !== 0 || ($t['phase'] ?? '') === 'not sent') {
            return (($answer['outcome'] ?? '') === 'timeout' ? 'timed out' : 'could not be reached')
                . ' while ' . (string) ($t['phase'] ?? '?') . ' (curl ' . (int) ($t['errno'] ?? 0) . ')';
        }

        return 'answered HTTP ' . (string) ($answer['status'] ?? '?') . ' (' . (string) ($answer['outcome'] ?? '?') . ')';
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
            error_log('[ownify] ai: Gemini answered with nothing to show (finishReason ' . ($finish === '' ? 'none' : $finish) . ')'
                . ($finish === 'MAX_TOKENS'
                    ? ': it used the whole output ceiling (' . (int) ($json['usageMetadata']['thoughtsTokenCount'] ?? 0)
                        . ' tokens of thinking) before it wrote an answer — raise max_output_tokens in config/ai.php'
                    : ''));

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
