<?php
/**
 * Training's heart rate (lib/hydrate-training.php, docs/TRAINING.md): the
 * page's one large chart, across the page.
 *
 *   Vandaag   a whole day, 00:00 to 24:00, every five minutes; the day's
 *             name above it — Vandaag, Gisteren, Eergisteren, 5 oktober —
 *             an arrow either side, and a swipe (training.js), back over
 *             the last seven days
 *   7 dagen … 1 jaar   each day's average, Ownify's time axis
 *             (docs/CHARTS.md): a point per day up to 90 dagen, per month
 *             over a year
 *
 * The switch is the Verloop's (health-trend.js), the reading too
 * (compass-history.js): its time — or date — its zone, and its bpm. The four
 * zones' names and ranges under it, and what they are based on. Every word
 * and number is the server's.
 */
declare(strict_types=1);

$heart  = $data['heart'];
$zones  = $heart['zones'];
$days   = $heart['days'];
$last   = count($days) - 1;
$filled = array_filter($days, static fn ($d) => $d['has_data']) !== [] || array_filter($heart['periods'], static fn ($p) => $p['has_data']) !== [];
$suffix = 'heart-chart';
$y      = static fn (array $p): string => (string) json_encode((object) ['heart' => $p['lines'][0]['y'] ?? []]);
?>
<section class="card card--trend compass-history health-history heart-chart reveal <?= $filled ? 'is-filled' : 'is-empty' ?>"
         data-health-history data-heart-chart data-accent="training" aria-labelledby="<?= e($suffix) ?>-title">

    <div class="card__head health-history__head">
        <div class="card__head-group">
            <span class="icon-tile icon-tile--solid" aria-hidden="true"><?= icon_solid('pulse') ?></span>
            <h2 class="card__eyebrow" id="<?= e($suffix) ?>-title"><?= e($heart['title']) ?></h2>
        </div>

        <div class="range-switch range-switch--wide health-history__switch heart-chart__switch" role="group" aria-label="<?= e($heart['switch']) ?>">
            <?php foreach ($heart['options'] as $option): ?>
                <button type="button"
                        class="range-switch__option<?= $option['key'] === $heart['default'] ? ' is-active' : '' ?>"
                        data-range-option="<?= e($option['key']) ?>"
                        aria-pressed="<?= $option['key'] === $heart['default'] ? 'true' : 'false' ?>"><?= e($option['label']) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="chart compass-history__chart heart-chart__chart" data-chart>
        <?php foreach ($days as $i => $day): ?>
            <div class="chart__range heart-chart__range<?= $day['key'] === $heart['default'] ? ' is-active' : '' ?>"
                 data-range="<?= e($day['key']) ?>" data-heart-day="<?= $i ?>"
                 data-x="<?= e((string) json_encode($day['x'])) ?>" data-y="<?= e($y($day)) ?>">

                <?php /* The day: older to the left, newer to the right. */ ?>
                <div class="heart-chart__day" data-day-swipe data-gesture-own>
                    <button type="button" class="heart-chart__step press" data-day-step="1"
                            aria-label="<?= e($heart['prev']) ?>"<?= $i === $last ? ' disabled' : '' ?>><?= icon('chevron-left', 'heart-chart__step-icon') ?></button>
                    <p class="heart-chart__date"><?= e($day['title']) ?></p>
                    <button type="button" class="heart-chart__step press" data-day-step="-1"
                            aria-label="<?= e($heart['next']) ?>"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('chevron-right', 'heart-chart__step-icon') ?></button>
                </div>

                <?php component('heart-plot', $data + ['plot' => $day, 'plot_id' => $day['key'], 'series' => $heart['label'], 'suffix' => $suffix, 'empty' => $day['empty']]); ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($heart['periods'] as $period): ?>
            <div class="chart__range heart-chart__range<?= $period['key'] === $heart['default'] ? ' is-active' : '' ?>"
                 data-range="<?= e($period['key']) ?>"
                 data-x="<?= e((string) json_encode($period['x'])) ?>" data-y="<?= e($y($period)) ?>">
                <?php component('heart-plot', $data + ['plot' => $period, 'plot_id' => 'p' . $period['key'], 'series' => $heart['label'], 'suffix' => $suffix, 'empty' => $heart['empty']]); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php component('heart-zones', ['zones' => $zones]); ?>

    <?php if ($filled): ?>
        <p class="compass-history__hint" id="<?= e($suffix) ?>-hint"><?= e($heart['hint']) ?></p>
        <p class="sr-only" aria-live="polite" data-reading-live></p>
    <?php endif; ?>
</section>
