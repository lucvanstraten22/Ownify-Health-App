<?php
/**
 * One health source, with its settings folded inside it.
 *
 * The app has one detail layer, so a source's settings expand in place
 * rather than pushing a third screen onto a stack that does not exist —
 * and on a phone this is the better shape for it anyway.
 */
declare(strict_types=1);

$item   = $data['integration'];
$labels = $data['settings']['integration_labels'];
$id     = 'integration-' . $item['key'];
?>
<div class="integration<?= $item['connected'] ? ' is-connected' : '' ?>" data-integration>

    <button type="button" class="integration__head press" data-integration-toggle
            aria-expanded="false" aria-controls="<?= e($id) ?>"
            aria-label="<?= e(sprintf($labels['expand'], $item['label'])) ?>">

        <span class="icon-tile" aria-hidden="true"><?= icon($item['icon']) ?></span>

        <span class="integration__text">
            <span class="integration__label"><?= e($item['label']) ?></span>
            <span class="integration__note"><?= e($item['note']) ?></span>
        </span>

        <span class="integration__status">
            <span class="integration__dot" aria-hidden="true"></span>
            <?= e($item['connected'] ? $labels['connected'] : $labels['disconnected']) ?>
        </span>

        <?= icon('chevron-down', 'integration__chevron') ?>
    </button>

    <div class="integration__body" id="<?= e($id) ?>" hidden>

        <div class="metric-rows">
            <div class="metric-row">
                <span class="metric-row__label"><?= e($labels['status']) ?></span>
                <span class="metric-row__value"><?= e($item['connected'] ? $labels['connected'] : $labels['disconnected']) ?></span>
            </div>
            <div class="metric-row <?= $item['last_sync'] === null ? 'is-empty' : '' ?>">
                <span class="metric-row__label"><?= e($labels['last_sync']) ?></span>
                <span class="metric-row__value"><?= e($item['last_sync'] ?? $labels['never']) ?></span>
            </div>
            <div class="metric-row">
                <span class="metric-row__label"><?= e($labels['permissions']) ?></span>
                <span class="metric-row__value"><?= e($labels['permissions_note']) ?></span>
            </div>
        </div>

        <p class="integration__caption"><?= e($labels['categories']) ?></p>
        <ul class="chips" role="list">
            <?php foreach ($item['categories'] as $category): ?>
                <li><span class="chip chip--muted"><?= e($category) ?></span></li>
            <?php endforeach; ?>
        </ul>

        <div class="integration__actions">
            <button type="button" class="btn press" disabled>
                <?= e($item['connected'] ? $labels['sync_now'] : $labels['connect']) ?>
            </button>
            <?php if ($item['connected']): ?>
                <button type="button" class="btn press" disabled><?= e($labels['disconnect']) ?></button>
            <?php endif; ?>
        </div>

        <p class="integration__hint"><?= icon('lock', 'card__hint-icon') ?><?= e($labels['unavailable']) ?></p>
    </div>

</div>
