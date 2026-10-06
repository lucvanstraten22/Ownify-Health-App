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
