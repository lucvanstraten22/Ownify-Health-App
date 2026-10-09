<?php
/**
 * Small area charts two by two, each square, together as wide as one card
 * (components/area-chart.php, docs/CHARTS.md).
 *
 *   grid_charts  the area's charts to draw here, in their order
 *   chart_area   the area they belong to (sleep, training)
 */
declare(strict_types=1);
?>
<div class="area-charts-frame">
    <div class="area-charts">
        <?php foreach ($data['grid_charts'] as $chart): ?>
            <?php component('area-chart', $data + ['area_chart' => $chart, 'chart_mode' => 'mini']); ?>
        <?php endforeach; ?>
    </div>
</div>
