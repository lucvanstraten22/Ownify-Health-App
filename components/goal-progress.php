<?php
/** Personal goal progress — answers "how close am I to my goal?". */
declare(strict_types=1);

$goal    = $data['goal'];
$isSet   = $goal['state'] !== 'unset' && has_value($goal['progress']);
$ratio   = score_ratio($goal['progress'], 100);

/* What the bar says when you point at it, focus it or hold it: the goal's own
   numbers ("85 kg van 100 kg"), or its percentage when it has none. */
$reading = $isSet ? (string) ($goal['reading'] ?? ($goal['progress'] . '% van je doel')) : null;
$fillAt  = round(max(0.0, min(1.0, (float) $ratio)) * 100, 2);
?>
<section class="card card--goal reveal <?= $isSet ? 'is-filled' : 'is-empty' ?>" aria-labelledby="goal-title">

    <div class="card__head card__head--compact">
        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('chart') ?></span>
        <h2 class="card__eyebrow" id="goal-title"><?= e($goal['title']) ?></h2>
    </div>

    <div class="goal__headline">
        <p class="goal__name"><?= e($isSet ? (string) $goal['name'] : $goal['headline']) ?></p>
        <p class="goal__figure">
            <span class="goal__percent" data-count-to="<?= $isSet ? e((string) $goal['progress']) : '' ?>"><?= e(score_text($goal['progress'])) ?></span><?php if ($isSet): ?><span class="goal__unit-sign">%</span><?php endif; ?>
        </p>
    </div>

    <?php /* The bar, its reading and its stops. Set, it can be pointed at,
             focused or held (dashboard.js) to show the reading above the
             fill's end; unset there is nothing to read. */ ?>
    <div class="goal__track"<?= $isSet ? ' data-goal-track tabindex="0" aria-describedby="goal-reading"' : '' ?>>
        <div class="meter meter--goal" role="img"
             aria-label="<?= $isSet ? e((string) $goal['progress']) . '% — ' . e($reading) : 'Nog geen doel ingesteld' ?>">
            <span class="meter__fill" data-bar data-progress="<?= e((string) round($ratio, 4)) ?>"></span>
        </div>

        <?php if ($isSet): ?>
            <span class="goal__tip" id="goal-reading" role="tooltip" style="--p: <?= e((string) $fillAt) ?>"><?= e($reading) ?></span>
        <?php endif; ?>

        <?php /* Each stop at its own place on the bar: 0, 50, 85, 100. */ ?>
        <ol class="milestones" role="list">
            <?php foreach ($goal['milestones'] as $milestone): ?>
                <li class="milestones__item<?= $milestone['reached'] ? ' is-reached' : '' ?>"
                    style="--p: <?= e((string) (int) ($milestone['at'] ?? 0)) ?>">
                    <span class="milestones__dot" aria-hidden="true"></span>
                    <span class="milestones__label"><?= e($milestone['label']) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <?php if (!$isSet): ?>
        <p class="card__hint card__hint--plain"><?= e($goal['description']) ?></p>
    <?php endif; ?>

    <?php if (!$isSet): ?>
        <div class="goal__action">
            <?php /* Doelen is a real page now, so the button goes there. */ ?>
            <button type="button" class="btn btn--ghost press"
                    <?= empty($goal['cta']['enabled']) ? 'disabled' : 'data-nav="goals"' ?>><?= e($goal['cta']['label']) ?></button>
            <span class="goal__note"><?= e($goal['cta']['note']) ?></span>
        </div>
    <?php endif; ?>

</section>
