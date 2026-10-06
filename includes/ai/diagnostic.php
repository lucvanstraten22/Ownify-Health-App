<?php
/**
 * A temporary diagnostic for Ownify AI: one line per Gemini request, in a
 * file the person running the server can read without the web server's
 * error log (on Hestia, the File Manager cannot open that log: it is a link
 * out of the user's home).
 *
 * OFF unless config/ai.local.php says `'diagnostic_log' => true` (or a path),
 * or the environment sets AI_DIAGNOSTIC_LOG. Meant to be switched on to
 * catch a failure and off again afterwards.
 *
 * Where: `true` writes ownify-ai-diagnostic.log in PHP's upload_tmp_dir —
 * on Hestia /home/<user>/tmp, outside the web root and in the File Manager —
 * or the system temp directory. A path given instead is used as it is. A
 * directory inside the app (the web root) is refused, so the file can never
 * be downloaded from the site. At most 1 MB is written.
 *
 * What a line holds, and nothing else:
 *   the time, a tag shared by the requests of one question, the HTTP status,
 *   the outcome, Gemini's error status and message, the model, and the
 *   outline of what was sent and what came back — roles, part kinds, text
 *   lengths, whether a part carried a thought signature.
 * Never the key, never a word of the text (questions, answers, health data),
 * never a signature itself. Gemini's error message describes the request's
 * structure; should it ever quote part of the request, it is withheld.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('ai_diagnostic_path')) {

    /** The file to write to, or null when the diagnostic is off or has nowhere safe to write. */
    function ai_diagnostic_path(): ?string
    {
        static $path = false;

        if ($path !== false) {
            return $path;
        }

        $setting = ai_config()['diagnostic_log'] ?? false;

        if ($setting === true || $setting === 1 || $setting === '1' || $setting === 'true') {
            $dir = (string) ini_get('upload_tmp_dir');
            $dir = $dir !== '' && is_dir($dir) ? $dir : sys_get_temp_dir();
            $file = rtrim($dir, '/') . '/ownify-ai-diagnostic.log';
        } elseif (is_string($setting) && str_starts_with($setting, '/')) {
            $file = $setting;
        } else {
            return $path = null;
        }

        $dir  = realpath(dirname($file));
        $app  = realpath(dirname(__DIR__, 2));

        /* Never inside the app: everything there can be reached over the web. */
        if ($dir === false || $app === false || $dir === $app || str_starts_with($dir . '/', $app . '/')) {
            return $path = null;
        }

        return $path = $dir . '/' . basename($file);
    }

    /** A tag shared by every line of one PHP request (one question). */
    function ai_diagnostic_tag(): string
    {
        static $tag = null;

        return $tag ??= bin2hex(random_bytes(3));
    }

    /** Appends one line; never fails the request it describes. */
    function ai_diagnostic_write(string $line): void
    {
        $file = ai_diagnostic_path();

        if ($file === null || (is_file($file) && (int) @filesize($file) > 1_000_000)) {
            return;
        }

        $new = !is_file($file);
        $line = date('Y-m-d H:i:s') . '  [' . ai_diagnostic_tag() . ']  ' . str_replace(["\r", "\n"], ' ', $line) . "\n";

        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false && $new) {
            @chmod($file, 0600);
        }
    }

    /**
     * Gemini's error message as it may be written: whitespace folded, at most
     * 1000 characters, and withheld entirely if any 12 characters of it
     * repeat what the person wrote, an answer, or a look-up's arguments or
     * result. Identifiers in it — function and field names, paths, links —
     * are Gemini's words about the request's shape and are not compared, so
     * an error that names a function or a field is still written. A value
     * it quotes — a {…} object, or a "…" string of more than 24 characters —
     * is never written, whatever it holds: it becomes (value) or "…".
     */
    function ai_diagnostic_message(string $message, array $body): string
    {
        $message = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $message)), 0, 1000);

        for ($i = 0; $i < 5; $i++) {
            $message = (string) preg_replace('/\{[^{}]*\}/u', '(value)', $message);
        }
        $message = (string) preg_replace('/"[^"]{25,}"/u', '"…"', $message);

        $private = [];
        foreach ((array) ($body['contents'] ?? []) as $content) {
            foreach ((array) (((array) $content)['parts'] ?? []) as $part) {
                $part = (array) $part;
                if (isset($part['text'])) {
                    $private[] = (string) $part['text'];
                }
                if (isset($part['functionCall'])) {
                    $private[] = (string) json_encode(((array) $part['functionCall'])['args'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (isset($part['functionResponse'])) {
                    $private[] = (string) json_encode(((array) $part['functionResponse'])['response'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }
        $private = implode("\n", $private);

        $prose  = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\S*[_.\/:\[\]`]\S*/u', ' ', $message)));
        $length = mb_strlen($prose);

        for ($i = 0; $i + 12 <= $length; $i++) {
            $window = mb_substr($prose, $i, 12);
            if (trim($window) === $window && str_contains($private, $window)) {
                return '(withheld: it repeated part of the conversation)';
            }
        }

        return $message;
    }

    /** One Gemini request: what was sent, what came back, how it ended. */
    function ai_diagnostic_exchange(array $body, int $status, array $json, array $answer, string $model): void
    {
        if (ai_diagnostic_path() === null) {
            return;
        }

        $error   = is_array($json['error'] ?? null) ? $json['error'] : [];
        $parts   = $json['candidates'][0]['content']['parts'] ?? null;
        $finish  = (string) ($json['candidates'][0]['finishReason'] ?? '');

        $line = 'gemini  HTTP ' . ($status === 0 ? '-' : $status)
            . '  outcome=' . (string) ($answer['outcome'] ?? '?')
            . '  model=' . $model;

        if ($error !== []) {
            $line .= '  error=' . (string) ($error['code'] ?? '') . ' ' . (string) ($error['status'] ?? '')
                . '  message="' . ai_diagnostic_message((string) ($error['message'] ?? ''), $body) . '"';
        }

        $line .= '  sent: ' . ai_gemini_outline($body);

        if (is_array($parts)) {
            $line .= '  received: [model: ' . ai_gemini_part_kinds($parts) . ']'
                . ($finish === '' ? '' : ' finish=' . $finish);
        }

        ai_diagnostic_write($line);
    }
}
