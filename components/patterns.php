<?php
/**
 * Patterns + research placeholder.
 * Intentionally contains no health claims: only a neutral, empty structure
 * ready to receive real trends and sourced research later.
 */
declare(strict_types=1);

$patterns = $data['patterns'];
?>
<section class="card card--patterns reveal is-empty" aria-labelledby="patterns-title">

    <div class="card__head card__head--compact">
        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('chart') ?></span>
        <div class="card__headings">
            <h2 class="card__eyebrow" id="patterns-title"><?= e($patterns['title']) ?></h2>
            <p class="card__meta card__meta--small"><?= e($patterns['range']) ?></p>
        </div>
    </div>

    <div class="trend" aria-hidden="true">
        <?php for ($i = 0; $i < 7; $i++): ?>
            <span class="trend__bar skeleton" style="--i: <?= $i ?>"></span>
        <?php endfor; ?>
    </div>

    <p class="card__subtitle card__subtitle--tight"><?= e($patterns['headline']) ?></p>
    <p class="card__lede card__lede--small"><?= e($patterns['description']) ?></p>

    <ul class="chips" role="list">
        <?php foreach ($patterns['topics'] as $topic): ?>
            <li><span class="chip chip--muted"><?= e($topic) ?></span></li>
        <?php endforeach; ?>
    </ul>

</section>
