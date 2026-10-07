<?php
/**
 * Fills the Scorekompas for the signed-in user (includes/score-compass.php).
 *
 * The history is the Health Score as it was recorded each day (daily_scores,
 * via health_score_history()) over the longest period the compass shows, a
 * year — never recalculated from today's records — and today's score as it
 * stands. The categories are named and coloured as Overzicht names them; the
 * lines are drawn here, once, for the website and the app alike.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/score-compass.php';
require_once __DIR__ . '/health.php';

if (!function_exists('hydrate_compass')) {

    /**
     * @param array $copy  config/compass.php
     * @param array $data  app_page_data() so far: the ring's legend (scores.contributors)
     *                     and the Gezondheid areas (health.areas), already filled
     */
    function hydrate_compass(array $copy, array $data, int $userId): array
    {
        $days    = max(
            2 * (int) $copy['rules']['period_days'],
            ...array_map(static fn ($p) => (int) $p['days'], $copy['history']['periods'])
        );
        $history = db_available() ? health_score_history($userId, $days) : [];
        $compass = score_compass($history, $copy, hydrate_compass_areas($data));

        /* The 30 days of the direction (what an older app draws), and each
           period's line, in the same 300 × 120 box as Gezondheid's trend. */
        $compass['trend']['chart'] = hydrate_compass_chart($compass['trend']['values']);

        foreach ($compass['trend']['periods'] as $i => $period) {
            $compass['trend']['periods'][$i]['chart'] = hydrate_compass_chart($period['values']);
        }

        return $compass;
    }

    /**
     * One line: the paths, and every day's place in it in % of the box —
     * x left to right, y top to bottom, null for a day without a score — so
     * a finger on the line can find the day under it.
     */
    function hydrate_compass_chart(array $values): array
    {
        $width  = 300.0;
        $height = 120.0;
        $chart  = health_chart($values, $width, $height, 100.0, 0.0);
        $count  = count($values);

        $at = [];
        foreach ($values as $i => $value) {
            $point = $chart['points'][$i] ?? null;
            /* Where health_chart() put it: a single day at the left edge. */
            $x     = $count > 1 ? $i / ($count - 1) * 100 : 0.0;
            $at[]  = [round($x, 2), $point === null ? null : round($point[1] / $height * 100, 2)];
        }

        unset($chart['points']);

        return $chart + [
            'width'    => $width,
            'height'   => $height,
            'has_data' => health_series_has_data($values),
            'at'       => $at,
        ];
    }

    /**
     * Gezondheid's Verloop: Slaap, Voeding and Training as they were recorded
     * each day — the Scorekompas's history, its periods and its days, drawn
     * as three lines instead of the one Health Score. Nothing is scored or
     * carried here: every value is a category's score of
     * health_score_history() as the Scorekompas reads it that day — stored,
     * carried as long as its `valid_until` allows, or none (a gap, never a
     * 0) — and every date is the Scorekompas's.
     *
     * Each period keeps the Scorekompas's window and its place in the
     * Scorekompas's own list of days (`start`, in compass.trend.days, which
     * the reading reads: its dates, its notes, each category's score); its
     * lines are drawn here, once, for the website and the app alike, in the
     * same 300 × 120 box.
     *
     * @param array $trend    hydrate_compass()['trend']: its periods and days
     * @param array $compass  config/compass.php (the periods' spoken names, "Vandaag")
     * @param array $copy     config/health.php `history`
     * @param array $areas    Gezondheid's areas: their names and colours, in order
     */
    function hydrate_health_history(array $trend, array $compass, array $copy, array $areas): array
    {
        $days = $trend['days'] ?? [];
        $year = $days === [] ? '' : substr((string) $days[count($days) - 1]['date'], 0, 4);
        $ids  = array_keys($areas);

        $categories = [];
        foreach ($ids as $id) {
            $categories[] = ['id' => (string) $id, 'label' => (string) $areas[$id]['label'], 'accent' => (string) $areas[$id]['accent']];
        }

        $names = array_column($categories, 'label');
        $which = count($names) > 1
            ? implode(', ', array_slice($names, 0, -1)) . ' ' . ($copy['and'] ?? 'en') . ' ' . $names[count($names) - 1]
            : implode('', $names);

        $spoken = [];
        foreach ($compass['history']['periods'] as $period) {
            $spoken[(int) $period['days']] = (string) ($period['spoken'] ?? $period['label']);
        }

        $periods = [];
        foreach ($trend['periods'] ?? [] as $period) {
            $length = (int) $period['days'];
            $slice  = array_slice($days, (int) $period['start'], count($period['values']));
            $ticks  = (int) ($copy['ticks'][$length] ?? 0);

            $lines = [];
            $x     = [];
            $has   = false;
            foreach ($ids as $id) {
                $chart = hydrate_compass_chart(array_map(static fn ($day) => hydrate_health_history_score($day, (string) $id), $slice));
                $x     = array_column($chart['at'], 0);
                $has   = $has || $chart['has_data'];

                $lines[] = [
                    'id'     => (string) $id,
                    'accent' => (string) $areas[$id]['accent'],
                    'line'   => $chart['line'],
                    'y'      => array_column($chart['at'], 1),
                ];
            }

            $periods[] = [
                'key'   => (string) $period['key'],
                'label' => (string) $period['label'],
                'days'  => $length,
                'start' => (int) $period['start'],
                'since' => $period['since'],
                'dots'  => (string) ($copy['dots'][$length] ?? 'alone'),
                /* A week names every day, each under its own dots — today
                   by its date too, as wide as the others; longer periods name
                   as many dates as the Scorekompas does. */
                'axis'  => $ticks > 0 && $slice !== []
                    ? score_compass_ticks(
                        array_column($slice, 'date'),
                        $ticks,
                        $year,
                        score_compass_date_in((string) $slice[count($slice) - 1]['date'], $year, true)
                    )
                    : $period['axis'],
                'every' => $ticks > 0 && $ticks >= count($slice),
                'aria'  => sprintf((string) ($copy['aria'] ?? '%1$s, %2$s'), $which, $spoken[$length] ?? $period['label']),
                'chart' => [
                    'width'    => 300.0,
                    'height'   => 120.0,
                    'has_data' => $has,
                    'x'        => $x,
                    'lines'    => $lines,
                ],
            ];
        }

        return [
            'title'      => (string) ($copy['title'] ?? ''),
            'switch'     => (string) ($copy['switch'] ?? ''),
            'default'    => (string) ($trend['default'] ?? ''),
            'empty'      => (string) ($copy['empty'] ?? ''),
            'hint'       => (string) ($copy['hint'] ?? ''),
            'categories' => $categories,
            'periods'    => $periods,
        ];
    }

    /** One category's score on a day of the Scorekompas's list; null when it had none. */
    function hydrate_health_history_score(array $day, string $id): ?int
    {
        foreach ($day['categories'] ?? [] as $category) {
            if ((string) $category['id'] === $id) {
                return $category['value'] === null ? null : (int) $category['value'];
            }
        }

        return null;
    }

    /**
     * The three categories as Overzicht shows them — its legend's names, in
     * its order — with Gezondheid's icon, colour and empty states.
     *
     * @return array<string,array{label: string, accent: string, icon: string, empty: string, collecting: string}>
     */
    function hydrate_compass_areas(array $data): array
    {
        $areas = [];

        foreach ($data['scores']['contributors'] ?? [] as $row) {
            $id   = (string) $row['area'];
            $area = $data['health']['areas'][$id] ?? [];

            $areas[$id] = [
                'label'      => (string) $row['label'],
                'accent'     => (string) ($row['accent'] ?? $area['accent'] ?? $id),
                'icon'       => (string) ($area['icon'] ?? ''),
                'empty'      => (string) ($area['empty'] ?? ''),
                'collecting' => (string) ($area['collecting'] ?? ''),
            ];
        }

        return $areas;
    }
}
