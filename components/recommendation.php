<?php
/**
 * One small recommendation.
 * No personal recommendation is invented — the section ships as a neutral
 * empty state that explains the tone it will use.
 */
declare(strict_types=1);

$rec = $data['recommendation'];
?>
<section class="card card--recommendation reveal is-empty" aria-labelledby="recommendation-title">

    <div class="card__head card__head--compact">
        <span class="icon-tile icon-tile--accent" aria-hidden="true"><?= icon('sparkle') ?></span>
        <h2 class="card__eyebrow" id="recommendation-title"><?= e($rec['title']) ?></h2>
    </div>

    <p class="card__subtitle card__subtitle--tight"><?= e($rec['headline']) ?></p>
    <p class="card__lede card__lede--small"><?= e($rec['description']) ?></p>

    <p class="card__hint card__hint--plain"><?= e($rec['note']) ?></p>

</section>
