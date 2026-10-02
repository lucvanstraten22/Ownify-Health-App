<?php
/**
 * Fills the Scorekompas for the signed-in user (includes/score-compass.php).
 *
 * The history is the engine's own: the Health Score at the end of each of the
 * last 60 days — the trend's 30 and the 30 before them, for "Vergeleken met
 * jezelf" — recalculated from the records, never read back from a stored
 * number. The categories are named and coloured as Overzicht names them; the
 * line is drawn here, once, for the website and the app alike.
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
        $days    = 2 * (int) $copy['rules']['period_days'];
        $history = db_available() ? health_score_history($userId, $days) : [];
        $compass = score_compass($history, $copy, hydrate_compass_areas($data));

        /* The line in the same 300 × 120 box as Gezondheid's trend. */
        $values = $compass['trend']['values'];
        $compass['trend']['chart'] = health_chart($values, 300.0, 120.0, 100.0, 0.0) + [
            'width'    => 300.0,
            'height'   => 120.0,
            'has_data' => health_series_has_data($values),
        ];

        return $compass;
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
