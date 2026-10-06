<?php
/**
 * Scorekompas, question 2 — what is changing: the Health Score as it was
 * recorded each day, over 7 days, 30 days, 90 days or a year. One score,
 * seen over four periods; never four scores (includes/score-compass.php,
 * score_compass_periods()).
 *
 *   the switch    the four periods, the score's own week first
 *   a period      its direction (from 30 days), its sentences, where the
 *                 history begins when it is younger than the period, and
 *                 its line — a gap where a day had no score, never a 0
 *   the reading   a finger, a cursor or the arrow keys on the line show a
 *                 day: its date and score above the line (as on a goal's
 *                 Verloop), and below it the day's categories and their
 *                 parts — or that an earlier score still held that day
 *
 * Every period is drawn here at once and one is shown, so switching is a
 * class toggle (health-trend.js, as on Gezondheid); compass-history.js does
 * the reading. Every word and number is the server's, the same the app's
 * Scorekompas shows.
 */
declare(strict_types=1);

$trend   = $data['trend'];
$periods = $trend['periods'];
$days    = $trend['days'];
$readout = $trend['readout'];
$default = (string) $trend['default'];
$names   = array_column($readout['categories'], null, 'id');
$latest  = $days === [] ? null : $days[count($days) - 1];
$filled  = array_filter($periods, static fn ($p) => $p['chart']['has_data']) !== [];
?>
<section class="card card--trend reveal compass-card compass-history <?= $filled ? 'is-filled' : 'is-empty' ?>"
         data-compass-history aria-labelledby="compass-trend-title">
    <div class="card__head">
        <div class="card__head-group">
            <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('chart') ?></span>
            <h2 class="card__eyebrow" id="compass-trend-title"><?= e($trend['title']) ?></h2>
        </div>
        <?php foreach ($periods as $period): ?>
            <?php if ($period['direction'] !== null): ?>
                <span class="chip chip--quiet score-direction__chip" data-direction="<?= e($period['direction']['key']) ?>"
                      data-range-chip="<?= e($period['key']) ?>"<?= $period['key'] === $default ? '' : ' hidden' ?>>
                    <?= icon('arrow-up', 'score-direction__icon') ?><?= e($period['direction']['label']) ?>
                </span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="range-switch range-switch--wide compass-history__switch" role="group" aria-label="<?= e($trend['switch']) ?>">
        <?php foreach ($periods as $period): ?>
            <button type="button"
                    class="range-switch__option<?= $period['key'] === $default ? ' is-active' : '' ?>"
                    data-range-option="<?= e($period['key']) ?>"
                    aria-pressed="<?= $period['key'] === $default ? 'true' : 'false' ?>"><?= e($period['label']) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="chart compass-history__chart" data-chart>
        <?php foreach ($periods as $period):
            $chart = $period['chart'];
            $at    = $chart['at'];
            ?>
            <div class="chart__range<?= $period['key'] === $default ? ' is-active' : '' ?>" data-range="<?= e($period['key']) ?>"
                 data-start="<?= (int) $period['start'] ?>" data-at="<?= e((string) json_encode($at)) ?>">

                <?php if ($period['text'] !== [] || $period['since'] !== null): ?>
                    <div class="compass-text compass-history__text">
                        <?php foreach ($period['text'] as $sentence): ?>
                            <p><?= e($sentence) ?></p>
                        <?php endforeach; ?>
                        <?php if ($period['since'] !== null): ?>
                            <p class="compass-history__since"><?= e($period['since']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="compass-plot" data-accent="health"<?php if ($chart['has_data']): ?>
                     data-compass-plot data-gesture-own tabindex="0" role="group" aria-roledescription="grafiek"
                     aria-label="<?= e($period['aria']) ?>" aria-describedby="compass-history-hint"<?php else: ?>
                     role="img" aria-label="<?= e($period['aria']) ?>"<?php endif; ?>>
                    <svg class="chart__svg compass-plot__svg" viewBox="0 0 <?= (int) $chart['width'] ?> <?= (int) $chart['height'] ?>"
                         preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <?php foreach ([0.25, 0.5, 0.75] as $line): ?>
                            <line class="chart__grid" x1="0" x2="<?= (int) $chart['width'] ?>"
                                  y1="<?= round(12 + $line * ($chart['height'] - 24), 1) ?>"
                                  y2="<?= round(12 + $line * ($chart['height'] - 24), 1) ?>"/>
                        <?php endforeach; ?>

                        <g class="chart__series">
                            <?php foreach ($chart['area'] as $path): ?>
                                <path class="chart__area" d="<?= e($path) ?>"/>
                            <?php endforeach; ?>
                            <?php foreach ($chart['line'] as $path): ?>
                                <path class="chart__line" d="<?= e($path) ?>" data-draw/>
                            <?php endforeach; ?>
                        </g>
                    </svg>

                    <?php /* Dots are HTML, not SVG circles: the SVG stretches to the
                             card, and a stretched circle is an ellipse. Every day in
                             a week; otherwise only a day with no neighbour to draw a
                             line to. A day whose score was carried is a ring. */ ?>
                    <?php foreach ($at as $i => [$x, $y]):
                        $alone = ($at[$i - 1][1] ?? null) === null && ($at[$i + 1][1] ?? null) === null;
                        if ($y === null || (!$period['day_dots'] && !$alone)) { continue; }
                        $state = (string) ($days[$period['start'] + $i]['state'] ?? '');
                        ?>
                        <span class="compass-plot__dot<?= $state === 'carried' ? ' is-carried' : '' ?>" aria-hidden="true"
                              style="left: <?= e((string) $x) ?>%; top: <?= e((string) $y) ?>%;"></span>
                    <?php endforeach; ?>

                    <?php if ($chart['has_data']): ?>
                        <?php /* The reading: a goal's Verloop's (goals.css), the same look. */ ?>
                        <span class="goal-chart__cross" data-compass-cross aria-hidden="true" hidden></span>
                        <span class="goal-chart__focus" data-compass-focus aria-hidden="true" hidden></span>
                        <div class="goal-chart__tip" data-compass-tip aria-hidden="true" hidden>
                            <span class="goal-chart__tip-date" data-tip-date></span>
                            <strong class="goal-chart__tip-value" data-tip-value></strong>
                        </div>
                    <?php endif; ?>
                </div>

                <ul class="chart__axis" role="list" aria-hidden="true">
                    <?php foreach ($period['axis'] as $tick): ?>
                        <li class="chart__tick" style="left: <?= e((string) $tick['x']) ?>%;"><?= e($tick['label']) ?></li>
                    <?php endforeach; ?>
                </ul>

                <?php if (!$chart['has_data']): ?>
                    <p class="chart__empty"><?= e($period['empty']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($latest !== null): ?>
        <?php /* One day read closely — today until another is read. */ ?>
        <div class="compass-day" data-compass-day>
            <div class="compass-day__head">
                <span class="compass-day__text">
                    <span class="compass-day__date" data-day-date><?= e($latest['label']) ?></span>
                    <span class="compass-day__label"><?= e($readout['score']) ?></span>
                </span>
                <span class="compass-score" data-day-score data-score="<?= e((string) ($latest['band'] ?? '')) ?>">
                    <span class="compass-score__dot" aria-hidden="true"></span><span data-day-value><?= e(score_text($latest['value'])) ?></span>
                </span>
            </div>
            <p class="compass-day__note" data-day-note<?= $latest['note'] === null ? ' hidden' : '' ?>><?= e((string) $latest['note']) ?></p>
            <ul class="compass-day__cats" role="list">
                <?php foreach ($latest['categories'] as $category):
                    $name = $names[$category['id']] ?? ['label' => $category['id'], 'accent' => $category['id']];
                    ?>
                    <li class="compass-day__cat <?= state_class($category['value']) ?>" data-accent="<?= e($name['accent']) ?>"
                        data-day-cat="<?= e($category['id']) ?>">
                        <span class="legend__dot" aria-hidden="true"></span>
                        <span class="compass-day__cat-text">
                            <span class="compass-day__cat-label"><?= e($name['label']) ?></span>
                            <span class="compass-day__parts" data-cat-parts<?= $category['parts'] === null ? ' hidden' : '' ?>><?= e((string) $category['parts']) ?></span>
                        </span>
                        <span class="compass-score" data-cat-score data-score="<?= e((string) ($category['band'] ?? '')) ?>">
                            <span class="compass-score__dot" aria-hidden="true"></span><span data-cat-value><?= e(score_text($category['value'])) ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <p class="compass-history__hint" id="compass-history-hint"><?= e($readout['hint']) ?></p>
        <p class="sr-only" aria-live="polite" data-compass-live></p>

        <script type="application/json" data-compass-days><?= json_encode(
            $days,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
    <?php endif; ?>
</section>
