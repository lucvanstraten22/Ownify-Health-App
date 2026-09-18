<?php
/**
 * Tiny render layer — escaping, component includes and score formatting.
 * Deliberately dependency-free: no framework, no autoloader, no build step.
 */

declare(strict_types=1);

if (!function_exists('e')) {
    /** Escape for HTML text/attribute context. */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('component')) {
    /**
     * Render a component from /components. The full dashboard array is passed
     * in as $data so components stay stateless and never read globals.
     */
    function component(string $name, array $data = []): void
    {
        $file = dirname(__DIR__) . '/components/' . basename($name) . '.php';

        if (!is_file($file)) {
            return;
        }

        (static function (string $__file, array $data): void {
            require $__file;
        })($file, $data);
    }
}

if (!function_exists('page')) {
    /** Render a screen from /pages. Same contract as component(). */
    function page(string $name, array $data = []): void
    {
        $file = dirname(__DIR__) . '/pages/' . basename($name) . '.php';

        if (!is_file($file)) {
            return;
        }

        (static function (string $__file, array $data): void {
            require $__file;
        })($file, $data);
    }
}

if (!function_exists('has_value')) {
    /** True when a metric carries a real (non-placeholder) value. */
    function has_value(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}

if (!function_exists('score_text')) {
    /** Display value for a score: the number, or an em dash while empty. */
    function score_text(mixed $value): string
    {
        return has_value($value) ? (string) $value : '—';
    }
}

if (!function_exists('score_ratio')) {
    /** Progress ratio 0..1 for rings and bars. Empty values render as 0. */
    function score_ratio(mixed $value, int|float $max = 100): float
    {
        if (!has_value($value) || $max <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $value / (float) $max));
    }
}

if (!function_exists('state_class')) {
    /** `is-empty` / `is-filled` modifier used across every data component. */
    function state_class(mixed $value): string
    {
        return has_value($value) ? 'is-filled' : 'is-empty';
    }
}

if (!function_exists('today_parts')) {
    /**
     * Human date for the overview header, split so the weekday can be
     * dropped on very narrow screens: ['weekday' => 'dinsdag', 'date' => '15 september'].
     */
    function today_parts(?DateTimeInterface $date = null): array
    {
        $date = $date ?? new DateTimeImmutable('now');

        $days = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
        $months = [
            1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni',
            'juli', 'augustus', 'september', 'oktober', 'november', 'december',
        ];

        return [
            'weekday' => $days[(int) $date->format('w')],
            'date'    => sprintf('%d %s', (int) $date->format('j'), $months[(int) $date->format('n')]),
        ];
    }
}

if (!function_exists('today_label')) {
    /** Full human date, e.g. "dinsdag 15 september". */
    function today_label(?DateTimeInterface $date = null): string
    {
        $parts = today_parts($date);

        return $parts['weekday'] . ' ' . $parts['date'];
    }
}

if (!function_exists('asset')) {
    /**
     * An asset URL that changes when the file does.
     *
     * A browser told nothing about a stylesheet will keep the copy it already
     * has, which on a live site means a deploy lands on the server and the
     * person looking at it still sees last week's CSS. The query string makes
     * the URL itself change, so the old copy is never the one asked for.
     *
     * The stamp is the file's own modification time, which is exact and needs
     * nothing to maintain it. If the file cannot be read — it should always be
     * there, but a missing asset must not take the page down — the deployed
     * commit stands in, and failing that the URL goes out unversioned.
     */
    function asset(string $path): string
    {
        static $fallback = null;

        $full = dirname(__DIR__) . '/' . ltrim($path, '/');
        $time = @filemtime($full);

        if ($time !== false) {
            return $path . '?v=' . $time;
        }

        if ($fallback === null) {
            $deployed = @file_get_contents(dirname(__DIR__) . '/VERSION');
            $fallback = (is_string($deployed) && trim($deployed) !== '')
                ? substr(trim($deployed), 0, 7)
                : '';
        }

        return $fallback === '' ? $path : $path . '?v=' . $fallback;
    }
}
