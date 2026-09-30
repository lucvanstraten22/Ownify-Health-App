<?php
/**
 * Ownify AI: an answer, as the apps draw it.
 *
 * Gemini writes plain text with a little markdown — **bold**, "- " bullets,
 * "1." steps, a heading now and then. The apps do not render markdown or
 * HTML from a model. This turns the text into a few plain blocks both apps
 * draw the same way, with nothing in them but text and a bold flag:
 *
 *   {type: "p",  spans: [...]}               a paragraph
 *   {type: "h",  spans: [...]}               a short heading
 *   {type: "ul", items: [[spans], ...]}      bullets
 *   {type: "ol", items: [[spans], ...]}      numbered steps
 *
 *   span: {t: "text", b: true|false}
 *
 * Anything else — a table, a code fence, a link — arrives as the plain text
 * it is written in. No tag from the model ever reaches a page as a tag.
 */

declare(strict_types=1);

if (!function_exists('ai_format_blocks')) {

    /** @return array<int, array<string, mixed>> */
    function ai_format_blocks(string $text): array
    {
        $text   = str_replace(["\r\n", "\r"], "\n", trim($text));
        $blocks = [];
        $para   = [];
        $list   = null;   // ['type' => 'ul'|'ol', 'items' => [...]]

        $flushPara = static function () use (&$para, &$blocks): void {
            if ($para !== []) {
                $blocks[] = ['type' => 'p', 'spans' => ai_format_spans(implode("\n", $para))];
                $para = [];
            }
        };
        $flushList = static function () use (&$list, &$blocks): void {
            if ($list !== null) {
                $blocks[] = $list;
                $list = null;
            }
        };

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || preg_match('/^(```|~~~)/', $trimmed) === 1 || preg_match('/^[-*_]{3,}$/', $trimmed) === 1) {
                $flushPara();
                $flushList();
                continue;
            }

            if (preg_match('/^#{1,6}\s+(.+)$/u', $trimmed, $m) === 1
                || preg_match('/^\*\*([^*]{1,80})\*\*:?$/u', $trimmed, $m) === 1) {
                $flushPara();
                $flushList();
                $blocks[] = ['type' => 'h', 'spans' => ai_format_spans(rtrim($m[1], ':') . (str_ends_with($m[1], ':') || str_ends_with($trimmed, ':') ? ':' : ''))];
                continue;
            }

            if (preg_match('/^[-*•]\s+(.+)$/u', $trimmed, $m) === 1) {
                $flushPara();
                if ($list === null || $list['type'] !== 'ul') {
                    $flushList();
                    $list = ['type' => 'ul', 'items' => []];
                }
                $list['items'][] = ai_format_spans($m[1]);
                continue;
            }

            if (preg_match('/^\d{1,2}[.)]\s+(.+)$/u', $trimmed, $m) === 1) {
                $flushPara();
                if ($list === null || $list['type'] !== 'ol') {
                    $flushList();
                    $list = ['type' => 'ol', 'items' => []];
                }
                $list['items'][] = ai_format_spans($m[1]);
                continue;
            }

            $flushList();
            $para[] = $trimmed;
        }

        $flushPara();
        $flushList();

        return $blocks;
    }

    /**
     * "Je sliep **6 u 48 min**" → spans. Bold is the only emphasis kept;
     * single asterisks, underscores and backticks are dropped, their text
     * kept.
     *
     * @return array<int, array{t: string, b: bool}>
     */
    function ai_format_spans(string $text): array
    {
        $spans = [];
        $parts = preg_split('/(\*\*[^*]+\*\*|__[^_]+__)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($parts as $part) {
            $bold = (str_starts_with($part, '**') && str_ends_with($part, '**') && mb_strlen($part) > 4)
                 || (str_starts_with($part, '__') && str_ends_with($part, '__') && mb_strlen($part) > 4);

            $plain = $bold ? mb_substr($part, 2, -2) : $part;
            $plain = preg_replace('/(?<![\p{L}\p{N}])[*_`]+|[*_`]+(?![\p{L}\p{N}])/u', '', $plain) ?? $plain;
            $plain = str_replace('**', '', $plain);

            if ($plain === '') {
                continue;
            }

            $last = count($spans) - 1;
            if ($last >= 0 && $spans[$last]['b'] === $bold) {
                $spans[$last]['t'] .= $plain;
            } else {
                $spans[] = ['t' => $plain, 'b' => $bold];
            }
        }

        return $spans;
    }
}
