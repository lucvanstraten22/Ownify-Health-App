<?php
/**
 * Level 3 — the long tail.
 *
 * A group whose metrics all need a wearable is dimmed and labelled rather
 * than hidden, so the page is honest about what a connected device would add
 * without inventing a single value.
 */
declare(strict_types=1);

$registry = $data['health']['metrics'];
$group    = $data['group'];
$index    = $data['group_index'];
$areaId   = $data['area']['id'];

$metrics = array_map(
    static fn (array $entry): array => health_metric($entry, $registry),
    $group['metrics']
);

/* A group is "locked" when nothing in it can arrive without a device. */
$locked = true;
foreach ($metrics as $metric) {
    if ($metric['availability'] !== 'device') { $locked = false; break; }
}

$id = 'group-' . $areaId . '-' . $index;
?>
<section class="card card--group reveal<?= $locked ? ' is-locked' : '' ?>" aria-labelledby="<?= e($id) ?>">

    <div class="card__head card__head--compact">
        <div class="card__headings">
            <h2 class="card__eyebrow" id="<?= e($id) ?>"><?= e($group['title']) ?></h2>
            <?php if (!empty($group['hint'])): ?>
                <p class="card__meta card__meta--small"><?= e($group['hint']) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($locked): ?>
            <span class="metric-row__badge" aria-hidden="true"><?= icon('lock') ?></span>
        <?php endif; ?>
    </div>

    <ul class="metric-rows" role="list">
        <?php foreach ($metrics as $metric): ?>
            <li class="metric-row <?= state_class($metric['value']) ?>"
                data-availability="<?= e((string) ($metric['availability'] ?? 'always')) ?>">
                <span class="metric-row__label"><?= e($metric['label']) ?></span>
                <span class="metric-row__value">
                    <?= e(score_text($metric['value'])) ?><?php
                        if ($metric['unit'] !== '' && has_value($metric['value'])): ?><span class="metric-row__unit"><?= e($metric['unit']) ?></span><?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>

</section>
