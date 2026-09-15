<?php
/**
 * Reserved space for badges, milestones and personal records.
 * Deliberately last, deliberately quiet, and deliberately not built.
 */
declare(strict_types=1);

$badges = $data['community']['badges'];
$id     = 'badges-' . ($data['scope'] ?? 'x') . '-' . ($data['period'] ?? 'x');
?>
<section class="card card--badges is-empty" aria-labelledby="<?= e($id) ?>">
    <div class="card__head card__head--compact">
        <span class="icon-tile" aria-hidden="true"><?= icon('award') ?></span>
        <div class="card__headings">
            <h2 class="card__eyebrow" id="<?= e($id) ?>"><?= e($badges['title']) ?></h2>
            <p class="card__meta card__meta--small"><?= e($badges['body']) ?></p>
        </div>
    </div>
</section>
