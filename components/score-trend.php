<?php
/**
 * Where the Health Score is heading — Stijgend, Stabiel or Dalend — in the
 * sync capsule's glass, between the score ring and its name: on Overzicht
 * (components/health-score.php) and, the same pill in the same place, on the
 * Gezondheidsscore page (pages/score-compass.php, 50.1). An arrow up, an
 * arrow down, a dash for Stabiel; the arrow comes in from the way the score
 * goes (compass.css). In the text colour, never a score's or a category's: a
 * direction is not a judgement.
 */
declare(strict_types=1);

$direction = $data['direction'];
?>
<p class="score-trend" data-direction="<?= e($direction['key']) ?>">
    <span class="glass score-trend__pill">
        <span class="score-trend__icon" aria-hidden="true">
            <?= icon(['up' => 'arrow-up', 'down' => 'arrow-down'][$direction['key']] ?? 'dash', 'score-trend__glyph') ?>
        </span>
        <span class="score-trend__label"><?= e($direction['label']) ?></span>
    </span>
</p>
