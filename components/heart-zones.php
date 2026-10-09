<?php
/**
 * The four heart-rate zones under a heart-rate chart: each one's colour,
 * name and range in bpm, so no zone is told by its colour alone — and what
 * they are based on (lib/hydrate-training.php, docs/TRAINING.md). Without a
 * known maximum heart rate, only why there are none.
 *
 *   zones   {labels, thresholds, note, bands: [{label, range}]}
 */
declare(strict_types=1);

$zones = $data['zones'];
?>
<div class="heart-zones">
    <?php if ($zones['bands'] !== []): ?>
        <ul class="heart-zones__list" role="list">
            <?php foreach ($zones['bands'] as $z => $band): ?>
                <li class="heart-zones__item" data-zone="<?= $z + 1 ?>">
                    <span class="heart-zones__key" aria-hidden="true"></span>
                    <span class="heart-zones__label"><?= e($band['label']) ?></span>
                    <span class="heart-zones__range"><?= e($band['range']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p class="heart-zones__note"><?= e($zones['note']) ?></p>
</div>
