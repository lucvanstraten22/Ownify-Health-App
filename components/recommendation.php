<?php
/**
 * Eén kleine suggestie (docs/INSIGHTS.md): the day's one, at most — from the
 * person's own data and approved research, always optional — with its
 * evidence. Without one, the card says so in its quiet empty state; a
 * suggestion that no longer fits newer data says that instead.
 */
declare(strict_types=1);

$rec    = $data['recommendation'];
$filled = ($rec['state'] ?? 'empty') === 'filled';
?>
<section class="card card--recommendation reveal<?= $filled ? '' : ' is-empty' ?>" aria-labelledby="recommendation-title">

    <div class="card__head card__head--compact">
        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('sparkle') ?></span>
        <h2 class="card__eyebrow" id="recommendation-title"><?= e($rec['title']) ?></h2>
    </div>

    <p class="card__subtitle card__subtitle--tight"><?= e($rec['headline']) ?></p>
    <p class="card__lede card__lede--small"><?= e($rec['description']) ?></p>

    <?php if ($filled && isset($rec['research'])): ?>
        <?php component('research-evidence', $data + ['evidence' => [
            'research' => $rec['research'],
            'strength' => $rec['strength'],
            'label'    => $rec['research_label'],
        ]]); ?>
    <?php endif; ?>

</section>
