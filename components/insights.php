<?php
/** Useful insights — calmer than the score block, prepared for real patterns. */
declare(strict_types=1);

$insights = $data['insights'];
?>
<section class="card card--insights reveal" aria-labelledby="insights-title">

    <div class="card__head card__head--compact">
        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('pulse') ?></span>
        <div class="card__headings">
            <h2 class="card__eyebrow" id="insights-title"><?= e($insights['title']) ?></h2>
            <p class="card__meta card__meta--small"><?= e($insights['subtitle']) ?></p>
        </div>
    </div>

    <ul class="insights" role="list">
        <?php foreach ($insights['items'] as $item):
            $empty = ($item['state'] ?? 'empty') === 'empty';
            ?>
            <li class="insights__item<?= $empty ? ' is-empty' : '' ?>" data-accent="<?= e($item['accent']) ?>">
                <span class="insights__marker" aria-hidden="true"><?= icon_solid($item['icon']) ?></span>
                <div class="insights__body">
                    <p class="insights__title"><?= e($item['title']) ?></p>
                    <p class="insights__text"><?= e($item['body']) ?></p>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

</section>
