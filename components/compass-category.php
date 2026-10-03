<?php
/**
 * One category of the Health Score as the Scorekompas shows it: its tile,
 * its days, its score with its band's dot, and its components with the
 * weight each had (includes/score-compass.php). The Scorekompas lists the
 * three; the first days show the first one that scored (components/
 * calibration.php) — the same block, so a first score is explained exactly
 * as every later one.
 */
declare(strict_types=1);

$category = $data['category'];
?>
<div class="compass-cat <?= state_class($category['value']) ?>" data-accent="<?= e($category['accent']) ?>">
    <div class="compass-cat__head">
        <span class="icon-tile icon-tile--solid" aria-hidden="true"><?= icon_solid($category['icon']) ?></span>
        <span class="compass-cat__text">
            <span class="compass-cat__label"><?= e($category['label']) ?></span>
            <span class="compass-cat__meta"><?= e($category['meta']) ?></span>
        </span>
        <span class="compass-score" data-score="<?= e((string) ($category['band'] ?? '')) ?>">
            <span class="compass-score__dot" aria-hidden="true"></span><?= e(score_text($category['value'])) ?>
        </span>
    </div>

    <?php if ($category['summary'] !== null): ?>
        <p class="compass-cat__summary"><?= e($category['summary']) ?></p>
    <?php endif; ?>

    <?php if ($category['parts'] !== []): ?>
        <ul class="compass-rows" role="list">
            <?php foreach ($category['parts'] as $part): ?>
                <li class="compass-row <?= $part['value'] === null ? 'is-empty' : 'is-filled' ?><?= $part['counted'] ? '' : ' is-uncounted' ?>"
                    data-score="<?= e((string) ($part['band'] ?? '')) ?>">
                    <span class="compass-row__top">
                        <span class="compass-row__label"><?= e($part['label']) ?></span>
                        <?php if ($part['weight'] !== null): ?>
                            <span class="compass-row__weight"><?= e((string) $part['weight']) ?>%</span>
                        <?php endif; ?>
                        <span class="compass-row__value"><?= e(score_text($part['value'])) ?></span>
                    </span>
                    <span class="meter compass-row__meter" aria-hidden="true">
                        <span class="meter__fill" data-bar data-progress="<?= e((string) round(score_ratio($part['value']), 4)) ?>"></span>
                    </span>
                    <?php if ($part['note'] !== null): ?>
                        <span class="compass-row__note"><?= e($part['note']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
