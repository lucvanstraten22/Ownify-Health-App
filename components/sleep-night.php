<?php
/**
 * Slaapverloop — the night, stage by stage, and the nights over a period
 * (lib/hydrate-sleep.php, docs/SLEEP.md). Training's heart-rate switch
 * (components/heart-chart.php), its arrows and its script (training.js):
 *
 *   Vandaag   one night: this morning's and the six before it, an arrow
 *             either side and a swipe over its name, opened on the newest
 *             night there is.
 *     the head    the night's name, and how long was slept and how much of
 *                 the time in bed
 *     the rows    Wakker, Rusteloosheid, REM, Licht and Diep, top to bottom,
 *                 each named on the left with its time over the night
 *     the blocks  each recorded period of a stage on its row, from bedtime at
 *                 the left edge to wake time at the right — the night's own
 *                 times, never a fixed clock
 *     the reading a finger, a cursor or the arrow keys on the night show the
 *                 period there: its stage and when it began and ended, above
 *                 the chart (sleep.js)
 *   7 dagen … 1 jaar   each night's stages as one bar on Ownify's time axis,
 *             Diep at the bottom to Wakker at the top, in the stages' own
 *             colours; read as every chart over time is (compass-history.js).
 *
 * A night without recorded stages keeps its times and empty rows, and says
 * so; no night at all, the rows and a line. Nothing is guessed.
 */
declare(strict_types=1);

$night   = $data['night'];
$nights  = $night['nights'] ?? [$night + ['key' => 'd0', 'heading' => $night['date']]];
$periods = $night['periods'] ?? [];
$default = (string) ($night['default'] ?? 'd0');
$last    = count($nights) - 1;
$filled  = array_filter($nights, static fn ($n) => !empty($n['staged'])) !== []
    || array_filter($periods, static fn ($p) => $p['has_data']) !== [];
$stages  = $night['stages'] ?? [];
?>
<section class="card sleep-night reveal <?= $filled ? 'is-filled' : 'is-empty' ?>" data-sleep-night data-day-chart data-health-history
         aria-labelledby="sleep-night-title">

    <div class="card__head sleep-night__head">
        <div class="card__headings">
            <h2 class="card__eyebrow" id="sleep-night-title"><?= e($night['title']) ?></h2>
        </div>

        <?php if (!empty($night['options'])): ?>
            <div class="range-switch range-switch--wide health-history__switch heart-chart__switch" role="group" aria-label="<?= e($night['switch']) ?>">
                <?php foreach ($night['options'] as $i => $option): ?>
                    <button type="button"
                            class="range-switch__option<?= $option['key'] === $default ? ' is-active' : '' ?>"
                            data-range-option="<?= e($option['key']) ?>"<?= $i === 0 ? ' data-day-option' : '' ?>
                            aria-pressed="<?= $option['key'] === $default ? 'true' : 'false' ?>"><?= e($option['label']) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="chart sleep-night__ranges" data-chart>
        <?php foreach ($nights as $i => $one):
            $rows   = $one['rows'];
            $staged = !empty($one['staged']);
            $id     = 'sleep-night-' . $one['key'];
            ?>
            <div class="chart__range sleep-night__range<?= $one['key'] === $default ? ' is-active' : '' ?>"
                 data-range="<?= e($one['key']) ?>" data-heart-day="<?= $i ?>" data-night-range>

                <?php /* The night: older to the left, newer to the right. */ ?>
                <div class="heart-chart__day" data-day-swipe data-gesture-own>
                    <button type="button" class="heart-chart__step press" data-day-step="1"
                            aria-label="<?= e($night['prev']) ?>"<?= $i === $last ? ' disabled' : '' ?>><?= icon('chevron-left', 'heart-chart__step-icon') ?></button>
                    <p class="heart-chart__date"><?= e($one['heading']) ?></p>
                    <button type="button" class="heart-chart__step press" data-day-step="-1"
                            aria-label="<?= e($night['next']) ?>"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('chevron-right', 'heart-chart__step-icon') ?></button>
                </div>

                <?php if ($one['asleep'] !== null): ?>
                    <p class="sleep-night__sum">
                        <span class="sleep-night__value"><?= e($one['asleep']) ?><span class="sleep-night__unit"><?= e(t('u')) ?></span></span>
                        <span class="sleep-night__word"><?= e($one['asleep_label']) ?><?php if ($one['efficiency'] !== null): ?>
                            · <?= e((string) $one['efficiency']) ?>% <?= e($one['efficiency_label']) ?><?php endif; ?></span>
                    </p>
                <?php endif; ?>

                <div class="sleep-night__chart" style="--rows: <?= count($rows) ?>;">
                    <ul class="sleep-night__labels" role="list">
                        <?php foreach ($rows as $row): ?>
                            <li class="sleep-night__label" data-stage="<?= e($row['key']) ?>">
                                <span class="sleep-night__name"><?= e($row['label']) ?></span>
                                <?php if ($row['total'] !== null): ?>
                                    <span class="sleep-night__total"><?= e($row['total']) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="sleep-night__plot"<?php if ($staged): ?>
                         data-night-plot data-gesture-own tabindex="0" role="group" aria-roledescription="<?= e(t('grafiek')) ?>"
                         aria-label="<?= e($one['aria']) ?>" aria-describedby="sleep-night-hint"<?php else: ?>
                         role="img" aria-label="<?= e($one['aria']) ?>"<?php endif; ?>>
                        <?php foreach ($rows as $r => $row): ?>
                            <span class="sleep-night__lane" aria-hidden="true" style="--row: <?= $r ?>;"></span>
                        <?php endforeach; ?>

                        <?php foreach ($one['blocks'] as $b => [$r, $from, $to]): ?>
                            <span class="sleep-night__block" data-stage="<?= e($rows[$r]['key']) ?>" data-block="<?= $b ?>" aria-hidden="true"
                                  style="--row: <?= (int) $r ?>; left: <?= e((string) $from) ?>%; width: <?= e((string) max(0, $to - $from)) ?>%;"></span>
                        <?php endforeach; ?>

                        <?php if ($staged): ?>
                            <div class="goal-chart__tip sleep-night__tip" data-night-tip aria-hidden="true" hidden>
                                <strong class="goal-chart__tip-value" data-tip-stage></strong>
                                <span class="goal-chart__tip-date" data-tip-time></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($one['ticks'] !== []): ?>
                    <ul class="chart__axis sleep-night__axis" role="list" aria-hidden="true">
                        <?php foreach ($one['ticks'] as $tick): ?>
                            <li class="chart__tick" style="left: <?= e((string) $tick['x']) ?>%;"><?= e($tick['label']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($one['note'] !== null): ?>
                    <p class="sleep-night__note"><?= e($one['note']) ?></p>
                <?php endif; ?>

                <?php if ($staged): ?>
                    <?php /* Each block as the reading needs it: [its row, from, to (% of the
                             night), began, ended]; the rows' names in order. */ ?>
                    <script type="application/json" data-night-blocks><?= json_encode(
                        ['rows' => array_column($rows, 'label'), 'blocks' => $one['blocks']],
                        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    ) ?></script>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($periods as $period):
            $x     = $period['x'];
            $small = count($x) > 7;
            ?>
            <div class="chart__range sleep-night__range<?= $period['key'] === $default ? ' is-active' : '' ?>" data-range="<?= e($period['key']) ?>"
                 data-x="<?= e((string) json_encode($x)) ?>" data-y="<?= e((string) json_encode((object) $period['tops'])) ?>">

                <div class="compass-plot area-chart__plot sleep-night__stacks<?= $period['grid'] !== [] ? ' has-levels' : '' ?>"<?php if ($period['has_data']): ?>
                     data-history-plot data-gesture-own tabindex="0" role="group" aria-roledescription="<?= e(t('grafiek')) ?>"
                     aria-label="<?= e($period['aria']) ?>" aria-describedby="sleep-night-period-hint"<?php else: ?>
                     role="img" aria-label="<?= e($period['aria']) ?>"<?php endif; ?>>

                    <?php foreach ($period['grid'] as $level): ?>
                        <span class="compass-plot__level" aria-hidden="true" style="top: <?= e((string) $level['y']) ?>%;"><?= e($level['label']) ?></span>
                    <?php endforeach; ?>

                    <?php /* Each night one bar, a stage's part on the one below it. */ ?>
                    <?php foreach ($period['stacks'] as $i => $parts): ?>
                        <?php foreach ($parts as $p => [$stage, $top, $bottom]): ?>
                            <span class="area-chart__bar sleep-night__part<?= $p === count($parts) - 1 ? ' is-top' : '' ?>" data-stage="<?= e($stage) ?>" aria-hidden="true"
                                  style="left: <?= e((string) round($x[$i] - $period['w'] / 2, 3)) ?>%; width: <?= e((string) $period['w']) ?>%; top: <?= e((string) $top) ?>%; bottom: <?= e((string) round(100 - $bottom, 2)) ?>%;"></span>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <svg class="chart__svg compass-plot__svg" viewBox="0 0 <?= (int) $period['width'] ?> <?= (int) $period['height'] ?>"
                         preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <?php foreach ($period['grid'] as $level): ?>
                            <line class="chart__grid" x1="0" x2="<?= (int) $period['width'] ?>"
                                  y1="<?= round($level['y'] / 100 * $period['height'], 1) ?>" y2="<?= round($level['y'] / 100 * $period['height'], 1) ?>"/>
                        <?php endforeach; ?>
                        <?php if ($period['grid'] === []): ?>
                            <line class="chart__grid" x1="0" x2="<?= (int) $period['width'] ?>" y1="<?= (int) $period['height'] ?>" y2="<?= (int) $period['height'] ?>"/>
                        <?php endif; ?>
                    </svg>

                    <?php if ($period['has_data']): ?>
                        <span class="goal-chart__cross" data-reading-cross aria-hidden="true" hidden></span>
                        <div class="goal-chart__tip health-history__tip" data-reading-tip aria-hidden="true" hidden>
                            <span class="goal-chart__tip-date"><span data-tip-date></span><span class="compass-plot__detail" data-tip-detail hidden></span></span>
                            <span class="health-history__tip-rows">
                                <?php /* The night's whole time first: every stage together. */ ?>
                                <span class="health-history__tip-row sleep-night__tip-total" data-tip-row="total" hidden>
                                    <span class="health-history__tip-key sleep-night__tip-blank" aria-hidden="true"></span>
                                    <span class="health-history__tip-label"><?= e($night['period_total'] ?? t('Totaal')) ?></span>
                                    <strong class="goal-chart__tip-value" data-tip-value></strong>
                                </span>
                                <?php foreach (array_reverse($stages) as $s): ?>
                                    <span class="health-history__tip-row" data-tip-row="<?= e($s['key']) ?>" data-stage="<?= e($s['key']) ?>" hidden>
                                        <span class="health-history__tip-key sleep-night__key" aria-hidden="true"></span>
                                        <span class="health-history__tip-label"><?= e($s['label']) ?></span>
                                        <strong class="goal-chart__tip-value" data-tip-value></strong>
                                    </span>
                                <?php endforeach; ?>
                            </span>
                            <span class="health-history__tip-none" data-tip-none hidden></span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php component('chart-axis', ['ticks' => $period['axis'], 'class' => $period['grid'] !== [] ? 'has-levels' : null]); ?>

                <?php if (!$period['has_data']): ?>
                    <p class="chart__empty"><?= e($night['period_empty'] ?? '') ?></p>
                <?php else: ?>
                    <?php /* The stages named: no stage told by its colour alone. */ ?>
                    <ul class="legend sleep-night__legend" role="list">
                        <?php foreach (array_reverse($stages) as $s): ?>
                            <li class="legend__item" data-stage="<?= e($s['key']) ?>">
                                <span class="legend__dot sleep-night__key" aria-hidden="true"></span>
                                <span class="legend__label"><?= e($s['label']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <script type="application/json" data-history-points><?= json_encode(
                        $period['points'],
                        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    ) ?></script>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($filled): ?>
        <?php /* How far a wearable's stages can be trusted (docs/INSIGHTS.md). */
        $references = array_values(array_filter([
            $data['insights']['references']['sleep.stages'] ?? null,
            /* 31.0 (D9): deep sleep and efficiency change with age (AGE-03). */
            $data['insights']['references']['sleep.age'] ?? null,
        ]));
        if ($references !== [] && is_file(dirname(__DIR__) . '/components/research-evidence.php')) {
            component('research-evidence', $data + ['evidences' => $references]);
        } ?>
        <p class="compass-history__hint sleep-night__hint" id="sleep-night-hint" data-hint-for="night"><?= e($night['hint']) ?></p>
        <?php if ($periods !== []): ?>
            <p class="compass-history__hint sleep-night__hint" id="sleep-night-period-hint" data-hint-for="period" hidden><?= e($night['period_hint']) ?></p>
        <?php endif; ?>
        <p class="sr-only" aria-live="polite" data-night-live data-reading-live></p>
    <?php endif; ?>
</section>
