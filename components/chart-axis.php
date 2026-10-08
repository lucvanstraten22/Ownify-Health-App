<?php
/**
 * The dates under a chart over time (docs/CHARTS.md, lib/time-axis.php):
 * each at its day, centred under it. Over 30 and 90 days in two rows — the
 * day over its month, where the month is named — so thirteen dates fit
 * across a phone without touching; otherwise one line ("8 okt", "okt").
 *
 *   ticks   [{label, x, day?, month?}] — x in % of the plot (goals: `left`)
 *   class   extra classes for the list
 */
declare(strict_types=1);

$ticks = $data['ticks'] ?? [];
$rows  = array_filter($ticks, static fn ($t) => isset($t['day'])) !== [];
?>
<ul class="chart__axis chart__axis--days<?= $rows ? ' chart__axis--rows' : '' ?><?= isset($data['class']) ? ' ' . e($data['class']) : '' ?>"
    role="list" aria-hidden="true">
    <?php foreach ($ticks as $tick): ?>
        <li class="chart__tick" style="left: <?= e((string) ($tick['x'] ?? $tick['left'] ?? 0)) ?>%;"><?php if (isset($tick['day'])): ?><?= e($tick['day']) ?><?php if (($tick['month'] ?? null) !== null): ?><span class="chart__tick-month"><?= e($tick['month']) ?></span><?php endif; ?><?php else: ?><?= e($tick['label']) ?><?php endif; ?></li>
    <?php endforeach; ?>
</ul>
