<?php
/**
 * One of Slaap's charts over time (lib/hydrate-sleep.php, docs/SLEEP.md):
 * Tijd in bed + Regelmaat, SpO₂, Huidtemperatuur or Hartslagvariabiliteit.
 *
 *   small (`sleep_mode` mini)  on the Slaap page, two by two: a square card —
 *               its name, its latest value, and its week on Ownify's time
 *               axis (docs/CHARTS.md), the dates in two rows. A tap or click
 *               opens its own page; a cursor, a sideways drag or the arrow
 *               keys read a day first.
 *   large (`sleep_mode` full)  on that page: the Verloop's card — its
 *               switch over 7 dagen, 30 dagen, 90 dagen and 1 jaar, the
 *               plot 160 px tall with a line's levels named, the reading
 *               above it.
 *
 * Drawn as Gezondheid's Verloop is (components/health-history.php) and read
 * by the same script (compass-history.js), the switch by health-trend.js:
 * a line a monotone curve with a dot for every point, a gap where a day had
 * no value — never a 0. Bars (Tijd in bed) stand on the bottom; beside them
 * the line (Regelmaat) has a height of its own, so no level is named for
 * either. Every word and number is the server's.
 */
declare(strict_types=1);

$chart  = $data['sleep_chart'];
$mini   = ($data['sleep_mode'] ?? 'mini') === 'mini';
$id     = 'sleep-' . $chart['id'];
$suffix = $id . ($mini ? '-mini' : '-full');
$series = $chart['series'];
$mixed  = count(array_unique(array_column($series, 'kind'))) > 1;
$periods = $mini ? array_slice($chart['periods'], 0, 1) : $chart['periods'];
$default = $mini ? (string) $periods[0]['key'] : (string) $chart['default'];
$filled  = array_filter($periods, static fn ($p) => $p['has_data']) !== [];
$tone    = static fn (array $s): string => $s['kind'] === 'bars' ? 'bars' : ($mixed ? 'light' : 'line');
?>
<section class="card sleep-chart <?= $mini ? 'sleep-chart--mini' : 'card--trend compass-history' ?> health-history reveal <?= $filled ? 'is-filled' : 'is-empty' ?><?= $mixed ? ' is-mixed' : '' ?>"
         data-health-history data-accent="sleep"<?php if ($mini): ?> data-detail-open="<?= e($id) ?>" data-sleep-mini<?php endif; ?>
         aria-labelledby="<?= e($suffix) ?>-title">

    <?php if ($mini): ?>
        <div class="sleep-chart__head">
            <h3 class="sleep-chart__title" id="<?= e($suffix) ?>-title">
                <button type="button" class="sleep-chart__open" data-detail-open="<?= e($id) ?>" aria-label="<?= e($chart['open']) ?>">
                    <span><?= e($chart['title']) ?></span><?= icon('chevron-right', 'sleep-chart__chevron') ?>
                </button>
            </h3>
            <?php $latest = $chart['latest']; ?>
            <p class="sleep-chart__now">
                <?php if ($latest === null): ?>
                    <span class="sleep-chart__none">—</span>
                <?php else: ?>
                    <?php foreach ($series as $k => $s): if (($latest['texts'][$k] ?? null) === null) { continue; } ?>
                        <?php if (count($series) > 1): ?><span class="sleep-chart__key" data-tone="<?= e($tone($s)) ?>" aria-hidden="true"></span><?php endif; ?>
                        <span class="sleep-chart__value"><?= e($latest['texts'][$k] ?? '—') ?></span>
                    <?php endforeach; ?>
                    <?php if ($latest['date'] !== null): ?>
                        <span class="sleep-chart__when"><?= e($latest['date']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <?php /* Its page is named by its title above the card; the card is the chart. */ ?>
        <h2 class="sr-only" id="<?= e($suffix) ?>-title"><?= e($chart['title']) ?></h2>
        <div class="card__head health-history__head sleep-chart__switch-row">
            <div class="range-switch range-switch--wide health-history__switch" role="group" aria-label="<?= e($chart['switch']) ?>">
                <?php foreach ($periods as $period): ?>
                    <button type="button"
                            class="range-switch__option<?= $period['key'] === $default ? ' is-active' : '' ?>"
                            data-range-option="<?= e($period['key']) ?>"
                            aria-pressed="<?= $period['key'] === $default ? 'true' : 'false' ?>"><?= e($period['label']) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="chart compass-history__chart sleep-chart__chart" data-chart>
        <?php foreach ($periods as $period):
            $x      = $period['x'];
            $ys     = [];
            foreach ($period['lines'] as $line) { $ys[$line['key']] = $line['y']; }
            foreach ($period['bars'] as $bar) { $ys[$bar['key']] = $bar['top']; }
            $levels = $mini ? [] : $period['grid'];
            $small  = count($x) > 7 && $period['group'] === 'day';
            $carried = array_flip($period['carried']);
            ?>
            <div class="chart__range<?= $period['key'] === $default ? ' is-active' : '' ?>" data-range="<?= e($period['key']) ?>"
                 data-x="<?= e((string) json_encode($x)) ?>" data-y="<?= e((string) json_encode((object) $ys)) ?>">

                <div class="compass-plot sleep-chart__plot<?= $levels !== [] ? ' has-levels' : '' ?>"<?php if ($period['has_data']): ?>
                     data-history-plot data-gesture-own tabindex="0" role="group" aria-roledescription="grafiek"
                     aria-label="<?= e($period['aria']) ?>" aria-describedby="<?= e($suffix) ?>-hint"<?php else: ?>
                     role="img" aria-label="<?= e($period['aria']) ?>"<?php endif; ?>>

                    <?php foreach ($levels as $level): ?>
                        <span class="compass-plot__level" aria-hidden="true" style="top: <?= e((string) $level['y']) ?>%;"><?= e($level['label']) ?></span>
                    <?php endforeach; ?>

                    <?php /* Bars are HTML, as the dots are: rounded ends that never
                             stretch with the SVG. Each over its point, from the bottom. */ ?>
                    <?php foreach ($period['bars'] as $bar): ?>
                        <?php foreach ($bar['top'] as $i => $top): if ($top === null) { continue; } ?>
                            <span class="sleep-chart__bar" aria-hidden="true"
                                  style="left: <?= e((string) round($x[$i] - $bar['w'] / 2, 3)) ?>%; width: <?= e((string) $bar['w']) ?>%; top: <?= e((string) $top) ?>%;"></span>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <svg class="chart__svg compass-plot__svg" viewBox="0 0 <?= (int) $period['width'] ?> <?= (int) $period['height'] ?>"
                         preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <?php foreach ($levels as $level): ?>
                            <line class="chart__grid" x1="0" x2="<?= (int) $period['width'] ?>"
                                  y1="<?= round($level['y'] / 100 * $period['height'], 1) ?>"
                                  y2="<?= round($level['y'] / 100 * $period['height'], 1) ?>"/>
                        <?php endforeach; ?>
                        <?php if ($levels === []): ?>
                            <line class="chart__grid" x1="0" x2="<?= (int) $period['width'] ?>" y1="<?= (int) $period['height'] ?>" y2="<?= (int) $period['height'] ?>"/>
                        <?php endif; ?>
                        <?php foreach ($period['lines'] as $k => $line):
                            $def = array_values(array_filter($series, static fn ($s) => $s['key'] === $line['key']))[0];
                            ?>
                            <g class="chart__series" data-tone="<?= e($tone($def)) ?>">
                                <?php foreach ($line['line'] as $path): ?>
                                    <path class="chart__line" d="<?= e($path) ?>" data-draw/>
                                <?php endforeach; ?>
                            </g>
                        <?php endforeach; ?>
                    </svg>

                    <?php foreach ($period['lines'] as $line):
                        $def = array_values(array_filter($series, static fn ($s) => $s['key'] === $line['key']))[0];
                        foreach ($line['y'] as $i => $top):
                            if ($top === null) { continue; }
                            $ring = isset($carried[$i]) && $line['key'] === 'regularity';
                            ?>
                            <span class="compass-plot__dot<?= $small ? ' is-small' : '' ?><?= $ring ? ' is-carried' : '' ?>"
                                  data-tone="<?= e($tone($def)) ?>" aria-hidden="true"
                                  style="left: <?= e((string) $x[$i]) ?>%; top: <?= e((string) $top) ?>%;"></span>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <?php if ($period['has_data']): ?>
                        <span class="goal-chart__cross" data-reading-cross aria-hidden="true" hidden></span>
                        <?php foreach ($series as $s): ?>
                            <span class="goal-chart__focus" data-reading-focus="<?= e($s['key']) ?>"
                                  data-tone="<?= e($tone($s)) ?>" aria-hidden="true" hidden></span>
                        <?php endforeach; ?>
                        <div class="goal-chart__tip health-history__tip" data-reading-tip aria-hidden="true" hidden>
                            <span class="goal-chart__tip-date"><span data-tip-date></span><span class="compass-plot__detail" data-tip-detail hidden></span></span>
                            <span class="health-history__tip-rows">
                                <?php foreach ($series as $s): ?>
                                    <span class="health-history__tip-row" data-tip-row="<?= e($s['key']) ?>" data-tone="<?= e($tone($s)) ?>" hidden>
                                        <span class="health-history__tip-key" aria-hidden="true"></span>
                                        <span class="health-history__tip-label"><?= e($s['label']) ?></span>
                                        <strong class="goal-chart__tip-value" data-tip-value></strong>
                                    </span>
                                <?php endforeach; ?>
                            </span>
                            <span class="health-history__tip-none" data-tip-none hidden></span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php component('chart-axis', ['ticks' => $mini ? ($period['axis_rows'] ?? $period['axis']) : $period['axis'], 'class' => $levels !== [] ? 'has-levels' : null]); ?>

                <?php if (!$period['has_data']): ?>
                    <p class="chart__empty"><?= e($chart['empty']) ?></p>
                <?php else: ?>
                    <script type="application/json" data-history-points><?= json_encode(
                        $period['points'],
                        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    ) ?></script>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php /* Two series: which is which. One is named by its title. */ ?>
    <?php if (!$mini && count($series) > 1): ?>
        <ul class="legend health-history__legend sleep-chart__legend" role="list">
            <?php foreach ($series as $s): ?>
                <li class="legend__item" data-tone="<?= e($tone($s)) ?>">
                    <span class="legend__dot" aria-hidden="true"></span>
                    <span class="legend__label"><?= e($s['label']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($filled): ?>
        <p class="compass-history__hint<?= $mini ? ' sr-only' : '' ?>" id="<?= e($suffix) ?>-hint"><?= e($chart['hint']) ?></p>
        <p class="sr-only" aria-live="polite" data-reading-live></p>
    <?php endif; ?>
</section>
