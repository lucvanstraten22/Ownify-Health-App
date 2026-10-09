<?php
/**
 * Training, drawn (lib/hydrate-training.php, docs/TRAINING.md), instead of
 * the numbers it shows:
 *
 *   1  the latest sessions beside the sessions per day, two halves
 *   2  Stappen + Afstand, Actieve + Totale calorieën, Verdiepingen and
 *      Actieve minuten, two by two
 *   3  the heart rate, across the page
 *   4  HRV and Hartbelasting, side by side
 *
 * Every chart but the heart rate is one of Ownify's area charts
 * (components/area-chart.php), opening its own page large.
 */
declare(strict_types=1);

$view   = $data['view'];
$charts = array_column($view['charts'], null, 'id');
$pick   = static fn (array $ids): array => array_values(array_filter(array_map(static fn ($id) => $charts[$id] ?? null, $ids)));
$perDay = $charts[$view['layout']['per_day']] ?? null;
?>
<div class="area-charts-frame">
    <div class="area-charts training-top">
        <?php component('training-sessions', $data + ['sessions' => $view['sessions']]); ?>
        <?php if ($perDay !== null): ?>
            <?php component('area-chart', $data + ['area_chart' => $perDay, 'chart_mode' => 'mini', 'chart_area' => 'training']); ?>
        <?php endif; ?>
    </div>
</div>

<?php component('area-charts-grid', $data + ['grid_charts' => $pick($view['layout']['grid']), 'chart_area' => 'training']); ?>

<?php component('heart-chart', $data + ['heart' => $view['heart']]); ?>

<?php component('area-charts-grid', $data + ['grid_charts' => $pick($view['layout']['lower']), 'chart_area' => 'training']); ?>
