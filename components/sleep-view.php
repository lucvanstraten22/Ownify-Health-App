<?php
/**
 * Slaap, drawn (lib/hydrate-sleep.php, docs/SLEEP.md): the night's stages,
 * then its four charts two by two — instead of the numbers and the bar of
 * stages they show.
 */
declare(strict_types=1);

$view = $data['view'];

component('sleep-night', $data + ['night' => $view['night']]);
component('area-charts-grid', $data + ['grid_charts' => $view['charts'], 'chart_area' => 'sleep']);
