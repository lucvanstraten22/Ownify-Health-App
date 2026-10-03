<?php
/**
 * The first days, at the top of Overzicht (includes/setup.php): while
 * Ownify builds the baseline, the first score when it is there, and the
 * starting point after it. Gone after day 5, and never there for an account
 * from before the setup existed.
 *
 *   building     how far each category is, in its own unit, and one small
 *                fact from the newest data
 *   first_score  the first category that scored, as the Scorekompas shows
 *                it — the engine's own score with its real components — and
 *                the way into the Scorekompas
 *   baseline     each category with enough data, as the starting point; or,
 *                without any data by day 4, that there is none yet
 *
 * Every word and number is the server's (`calibration`, lib/hydrate-setup.php),
 * the same the app's CalibrationCard shows.
 */
declare(strict_types=1);

$cal = $data['calibration'] ?? null;

if (!is_array($cal)) {
    return;
}

$phase = (string) $cal['phase'];
?>
<section class="card card--calibration reveal" data-phase="<?= e($phase) ?>" aria-labelledby="calibration-title">

    <p class="card__caption"><?= e($cal['eyebrow']) ?></p>
    <h2 class="card__subtitle calibration__title" id="calibration-title"><?= e($cal['title']) ?></h2>
    <?php if (!empty($cal['lede'])): ?>
        <p class="calibration__lede"><?= e($cal['lede']) ?></p>
    <?php endif; ?>

    <?php if ($cal['progress'] !== []): ?>
        <ul class="calibration__rows" role="list">
            <?php foreach ($cal['progress'] as $row): ?>
                <li class="calibration-row" data-accent="<?= e($row['accent']) ?>">
                    <span class="icon-tile icon-tile--solid" aria-hidden="true"><?= icon_solid($row['icon']) ?></span>
                    <span class="calibration-row__text">
                        <span class="calibration-row__top">
                            <span class="calibration-row__label"><?= e($row['label']) ?></span>
                            <span class="calibration-row__count"><?= e($row['count']) ?></span>
                        </span>
                        <span class="calibration-row__steps" aria-hidden="true">
                            <?php for ($i = 1; $i <= (int) $row['needed']; $i++): ?>
                                <span class="calibration-row__step<?= $i <= (int) $row['days'] ? ' is-done' : '' ?>"></span>
                            <?php endfor; ?>
                        </span>
                        <?php if ($row['detail'] !== null): ?>
                            <span class="calibration-row__detail"><?= e($row['detail']) ?></span>
                        <?php elseif ($row['how'] !== null): ?>
                            <span class="calibration-row__how"><?= e($row['how']) ?></span>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($cal['first'] !== null): ?>
        <div class="calibration__first">
            <?php component('compass-category', ['category' => $cal['first']]); ?>
        </div>
    <?php endif; ?>

    <?php if ($cal['baseline'] !== []): ?>
        <ul class="calibration__baseline" role="list">
            <?php foreach ($cal['baseline'] as $row): ?>
                <li class="calibration-start" data-accent="<?= e($row['accent']) ?>">
                    <span class="icon-tile icon-tile--solid" aria-hidden="true"><?= icon_solid($row['icon']) ?></span>
                    <span class="calibration-start__text">
                        <span class="calibration-start__label"><?= e($row['label']) ?></span>
                        <?php if ($row['fact'] !== null): ?>
                            <span class="calibration-start__fact"><?= e($row['fact']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="compass-score" data-score="<?= e((string) ($row['band'] ?? '')) ?>">
                        <span class="compass-score__dot" aria-hidden="true"></span><?= e((string) $row['value']) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($cal['observation'] !== null): ?>
        <p class="card__hint card__hint--plain calibration__fact"><?= icon('sparkle', 'card__hint-icon') ?><?= e($cal['observation']) ?></p>
    <?php endif; ?>

    <?php if ($cal['note'] !== null): ?>
        <p class="calibration__note"><?= e($cal['note']) ?></p>
    <?php endif; ?>

    <?php if ($cal['open'] !== null && !empty($data['compass'])): ?>
        <button type="button" class="calibration__open press" data-detail-open="score-compass">
            <?= e($cal['open']) ?><?= icon('chevron-right', 'calibration__open-icon') ?>
        </button>
    <?php endif; ?>

</section>
