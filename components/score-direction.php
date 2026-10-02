<?php
/**
 * Where the Health Score is heading — Stijgend, Stabiel or Dalend — as the
 * Scorekompas worked it out (includes/score-compass.php). Under the score on
 * Overzicht and on the Scorekompas itself. In the text colour, never a
 * score's or a category's: a direction is not a judgement.
 */
declare(strict_types=1);

$direction = $data['direction'];
?>
<p class="score-direction">
    <span class="chip chip--quiet score-direction__chip" data-direction="<?= e($direction['key']) ?>">
        <?= icon('arrow-up', 'score-direction__icon') ?><?= e($direction['label']) ?>
    </span>
</p>
