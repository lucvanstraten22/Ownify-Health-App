<?php
/**
 * The daily nutrition self-assessment: one cijfer, 1 to 10, for today.
 *
 * Built from exactly the parts of a goal's manual entry — the check card, its
 * icon tile and eyebrow, the input-and-button row, the error line and the
 * plain hint — so it reads as part of the app rather than as a new kind of
 * control. Saving goes to api/health/rating.php (assets/js/health-rating.js);
 * the hint line then says what the rating earned.
 */
declare(strict_types=1);

$copy  = $data['area']['rating'];
$today = $data['area']['rating_today'] ?? null;
?>
<section class="card card--check reveal" data-nutrition-rating aria-labelledby="nutrition-rating-title">
    <div class="card__head card__head--compact">
        <span class="icon-tile" aria-hidden="true"><?= icon('check') ?></span>
        <h2 class="card__eyebrow" id="nutrition-rating-title"><?= e($copy['title']) ?></h2>
    </div>

    <div class="goal-entry">
        <label class="sr-only" for="nutrition-rating-value"><?= e($copy['label']) ?></label>
        <input class="wizard__input goal-entry__input" type="number" min="1" max="10" step="1"
               inputmode="numeric" id="nutrition-rating-value" data-rating-value
               placeholder="<?= e($copy['placeholder']) ?>"
               value="<?= $today === null ? '' : e((string) $today) ?>">
        <button type="button" class="btn press" data-rating-save><?= e($copy['button']) ?></button>
    </div>

    <p class="field-editor__error" role="alert" data-rating-error hidden></p>
    <p class="card__hint card__hint--plain" role="status" data-rating-status><?= e($copy['hint']) ?></p>
</section>
