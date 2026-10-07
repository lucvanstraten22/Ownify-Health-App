<?php
/**
 * Gezondheid's Verloop — Slaap, Voeding and Training as they were recorded
 * each day, over 7 days, 30 days, 90 days or a year: the Scorekompas's
 * history (components/compass-history.php), three lines instead of its one
 * Health Score (lib/hydrate-compass.php, hydrate_health_history()).
 *
 *   the switch    the four periods, the week first — at the head's far end
 *                 where it fits, on a row of its own on a phone
 *   a period      the three lines in the categories' own colours — a gap
 *                 where a category had no score, never a 0 — a dot for
 *                 every day where the days can be told apart (a ring where
 *                 an earlier score was carried), and the dates under them:
 *                 every day of a week, each under its own dots
 *   the reading   a finger, a cursor or the arrow keys on the chart show a
 *                 day: its date and each category's score that day, above
 *                 the chart (compass-history.js, as the Scorekompas's line
 *                 is read)
 *
 * Every period is drawn here at once and one is shown, so switching is a
 * class toggle (health-trend.js). Every word and number is the server's,
 * the same the app's Gezondheid shows.
 */
declare(strict_types=1);

$history    = $data['health']['history'];
$periods    = $history['periods'];
$categories = $history['categories'];
$days       = $data['compass']['trend']['days'] ?? [];    // the Scorekompas's list: `start` points into it
$default    = (string) $history['default'];
$filled     = array_filter($periods, static fn ($p) => $p['chart']['has_data']) !== [];
?>
<section class="card card--trend reveal compass-history health-history <?= $filled ? 'is-filled' : 'is-empty' ?>"
         data-health-history aria-labelledby="health-history-title">
    <div class="card__head health-history__head">
        <div class="card__head-group">
            <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('chart') ?></span>
            <h2 class="card__eyebrow" id="health-history-title"><?= e($history['title']) ?></h2>
        </div>

        <div class="range-switch range-switch--wide health-history__switch" role="group" aria-label="<?= e($history['switch']) ?>">
            <?php foreach ($periods as $period): ?>
                <button type="button"
                        class="range-switch__option<?= $period['key'] === $default ? ' is-active' : '' ?>"
                        data-range-option="<?= e($period['key']) ?>"
                        aria-pressed="<?= $period['key'] === $default ? 'true' : 'false' ?>"><?= e($period['label']) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="chart compass-history__chart" data-chart>
        <?php foreach ($periods as $period):
            $chart = $period['chart'];
            $x     = $chart['x'];
            $y     = array_column($chart['lines'], 'y', 'id');
            ?>
            <div class="chart__range<?= $period['key'] === $default ? ' is-active' : '' ?>" data-range="<?= e($period['key']) ?>"
                 data-start="<?= (int) $period['start'] ?>" data-x="<?= e((string) json_encode($x)) ?>"
                 data-y="<?= e((string) json_encode((object) $y)) ?>">

                <?php if ($period['since'] !== null): ?>
                    <p class="compass-history__since health-history__since"><?= e($period['since']) ?></p>
                <?php endif; ?>

                <div class="compass-plot"<?php if ($chart['has_data']): ?>
                     data-history-plot data-gesture-own tabindex="0" role="group" aria-roledescription="grafiek"
                     aria-label="<?= e($period['aria']) ?>" aria-describedby="health-history-hint"<?php else: ?>
                     role="img" aria-label="<?= e($period['aria']) ?>"<?php endif; ?>>
                    <svg class="chart__svg compass-plot__svg" viewBox="0 0 <?= (int) $chart['width'] ?> <?= (int) $chart['height'] ?>"
                         preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <?php foreach ([0.25, 0.5, 0.75] as $line): ?>
                            <line class="chart__grid" x1="0" x2="<?= (int) $chart['width'] ?>"
                                  y1="<?= round(12 + $line * ($chart['height'] - 24), 1) ?>"
                                  y2="<?= round(12 + $line * ($chart['height'] - 24), 1) ?>"/>
                        <?php endforeach; ?>

                        <?php /* Lines only: one wash reads as depth, three stack into a block. */ ?>
                        <?php foreach ($chart['lines'] as $series): ?>
                            <g class="chart__series" data-accent="<?= e($series['accent']) ?>">
                                <?php foreach ($series['line'] as $path): ?>
                                    <path class="chart__line" d="<?= e($path) ?>" data-draw/>
                                <?php endforeach; ?>
                            </g>
                        <?php endforeach; ?>
                    </svg>

                    <?php /* Dots are HTML, not SVG circles: the SVG stretches to the card,
                             and a stretched circle is an ellipse. Every day where the
                             days can be told apart; otherwise only a day with no
                             neighbour to draw a line to. A day whose scores were
                             carried from an earlier one is a ring. */ ?>
                    <?php foreach ($chart['lines'] as $series):
                        $ys = $series['y'];
                        foreach ($ys as $i => $top):
                            $alone = ($ys[$i - 1] ?? null) === null && ($ys[$i + 1] ?? null) === null;
                            if ($top === null || ($period['dots'] !== 'every' && !$alone)) { continue; }
                            $state = (string) ($days[$period['start'] + $i]['state'] ?? '');
                            $small = $period['dots'] === 'every' && count($ys) > 7;
                            ?>
                            <span class="compass-plot__dot<?= $small ? ' is-small' : '' ?><?= $state === 'carried' ? ' is-carried' : '' ?>"
                                  data-accent="<?= e($series['accent']) ?>" aria-hidden="true"
                                  style="left: <?= e((string) $x[$i]) ?>%; top: <?= e((string) $top) ?>%;"></span>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <?php if ($chart['has_data']): ?>
                        <?php /* The reading: the Scorekompas's (goals.css), a dot on each line. */ ?>
                        <span class="goal-chart__cross" data-reading-cross aria-hidden="true" hidden></span>
                        <?php foreach ($categories as $category): ?>
                            <span class="goal-chart__focus" data-reading-focus="<?= e($category['id']) ?>"
                                  data-accent="<?= e($category['accent']) ?>" aria-hidden="true" hidden></span>
                        <?php endforeach; ?>
                        <div class="goal-chart__tip health-history__tip" data-reading-tip aria-hidden="true" hidden>
                            <span class="goal-chart__tip-date" data-tip-date></span>
                            <span class="health-history__tip-rows">
                                <?php foreach ($categories as $category): ?>
                                    <span class="health-history__tip-row" data-tip-row="<?= e($category['id']) ?>"
                                          data-accent="<?= e($category['accent']) ?>" hidden>
                                        <span class="health-history__tip-key" aria-hidden="true"></span>
                                        <span class="health-history__tip-label"><?= e($category['label']) ?></span>
                                        <strong class="goal-chart__tip-value" data-tip-value></strong>
                                    </span>
                                <?php endforeach; ?>
                            </span>
                            <span class="health-history__tip-none" data-tip-none hidden></span>
                        </div>
                    <?php endif; ?>
                </div>

                <ul class="chart__axis<?= $period['every'] ? ' chart__axis--days' : '' ?>" role="list" aria-hidden="true">
                    <?php foreach ($period['axis'] as $tick): ?>
                        <li class="chart__tick" style="left: <?= e((string) $tick['x']) ?>%;"><?= e($tick['label']) ?></li>
                    <?php endforeach; ?>
                </ul>

                <?php if (!$chart['has_data']): ?>
                    <p class="chart__empty"><?= e($history['empty']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <ul class="legend health-history__legend" role="list">
        <?php foreach ($categories as $category): ?>
            <li class="legend__item" data-accent="<?= e($category['accent']) ?>">
                <span class="legend__dot" aria-hidden="true"></span>
                <span class="legend__label"><?= e($category['label']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($filled): ?>
        <p class="compass-history__hint" id="health-history-hint"><?= e($history['hint']) ?></p>
        <p class="sr-only" aria-live="polite" data-reading-live></p>

        <?php /* Each day as the reading needs it: [its date, its note, and each
                 category's score in the order above]. */ ?>
        <script type="application/json" data-history-days><?= json_encode(
            array_map(static fn ($day) => array_merge(
                [$day['label'], $day['note']],
                array_map(static fn ($c) => hydrate_health_history_score($day, $c['id']), $categories)
            ), $days),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
    <?php endif; ?>
</section>
