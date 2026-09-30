<?php
/**
 * Week / month trend.
 *
 * Both ranges are drawn server-side and one is shown at a time, so switching
 * is a class toggle rather than a re-render. Pass `trend_area` to chart a
 * single area instead of all three.
 */
declare(strict_types=1);

$trend  = $data['health']['trend'];
$areas  = $data['health']['areas'];
$only   = $data['trend_area'] ?? null;
$keys   = $only !== null ? [$only] : array_keys($areas);
$suffix = $only !== null ? $only : 'all';

/* Does any visible series carry a reading? Decides chart vs empty state. */
$hasData = false;
foreach ($keys as $key) {
    foreach ($trend['ranges'] as $rangeKey => $range) {
        if (health_series_has_data($trend['series'][$key][$rangeKey]['values'] ?? [])) {
            $hasData = true;
        }
    }
}

$width  = 300.0;
$height = 120.0;
?>
<section class="card card--trend reveal <?= $hasData ? 'is-filled' : 'is-empty' ?>"
         aria-labelledby="trend-title-<?= e($suffix) ?>">

    <div class="card__head">
        <div class="card__head-group">
            <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('chart') ?></span>
            <h2 class="card__eyebrow" id="trend-title-<?= e($suffix) ?>"><?= e($trend['title']) ?></h2>
        </div>

        <div class="range-switch" role="group" aria-label="Periode kiezen">
            <?php foreach ($trend['ranges'] as $rangeKey => $range):
                $isFirst = $rangeKey === array_key_first($trend['ranges']);
                ?>
                <button type="button"
                        class="range-switch__option<?= $isFirst ? ' is-active' : '' ?>"
                        data-range-option="<?= e($rangeKey) ?>"
                        aria-pressed="<?= $isFirst ? 'true' : 'false' ?>"><?= e($range['label']) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="chart" data-chart>
        <?php foreach ($trend['ranges'] as $rangeKey => $range):
            $isFirst = $rangeKey === array_key_first($trend['ranges']);
            ?>
            <div class="chart__range<?= $isFirst ? ' is-active' : '' ?>" data-range="<?= e($rangeKey) ?>">

                <svg class="chart__svg" viewBox="0 0 <?= (int) $width ?> <?= (int) $height ?>"
                     preserveAspectRatio="none" role="img"
                     aria-label="<?= e($trend['title']) ?> — <?= e($range['label']) ?><?= $hasData ? '' : ': nog geen gegevens' ?>">

                    <?php foreach ([0.25, 0.5, 0.75] as $line): ?>
                        <line class="chart__grid" x1="0" x2="<?= (int) $width ?>"
                              y1="<?= round(12 + $line * ($height - 24), 1) ?>"
                              y2="<?= round(12 + $line * ($height - 24), 1) ?>"/>
                    <?php endforeach; ?>

                    <?php foreach ($keys as $key):
                        $series = $trend['series'][$key][$rangeKey]['values'] ?? [];
                        $chart  = health_chart($series, $width, $height, 100.0, 0.0);
                        if (!$chart['line'] && !$chart['dots']) { continue; }
                        ?>
                        <g class="chart__series" data-accent="<?= e($areas[$key]['accent']) ?>">
                            <?php /* One area reads as depth; three stack into a solid block, so
                                      the combined chart is lines only. */ ?>
                            <?php if ($only !== null): ?>
                                <?php foreach ($chart['area'] as $path): ?>
                                    <path class="chart__area" d="<?= e($path) ?>"/>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php foreach ($chart['line'] as $path): ?>
                                <path class="chart__line" d="<?= e($path) ?>" data-draw/>
                            <?php endforeach; ?>
                            <?php foreach ($chart['dots'] as $dot): ?>
                                <circle class="chart__dot" cx="<?= e((string) $dot[0]) ?>" cy="<?= e((string) $dot[1]) ?>" r="2.5"/>
                            <?php endforeach; ?>
                        </g>
                    <?php endforeach; ?>
                </svg>

                <ul class="chart__axis" role="list">
                    <?php
                    $ticks = count($range['points']);
                    foreach ($range['points'] as $tick => $point):
                        $left = $ticks > 1 ? ($tick / ($ticks - 1)) * 100 : 50;
                        ?>
                        <li class="chart__tick" style="left: <?= e((string) round($left, 2)) ?>%;"><?= e($point) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <?php if (!$hasData): ?>
            <p class="chart__empty"><?= e($trend['empty']) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($only === null): ?>
        <ul class="legend" role="list">
            <?php foreach ($keys as $key): ?>
                <li class="legend__item" data-accent="<?= e($areas[$key]['accent']) ?>">
                    <span class="legend__dot" aria-hidden="true"></span>
                    <span class="legend__label"><?= e($areas[$key]['label']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

</section>
