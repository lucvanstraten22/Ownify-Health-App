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

/* Four states, not two. Revoked and failed are different from never having
   connected, and a row that called them all "Niet verbonden" would be hiding
   the one thing the user needs to act on. */
$statusText = match ($item['status'] ?? 'disconnected') {
    'connected' => $labels['connected'],
    'revoked'   => $labels['revoked'],
    'error'     => $labels['error'],
    default     => $labels['disconnected'],
};

/* Connecting is offered only when it could actually complete. Where it
   cannot, the row says why instead — a button that fails on the far side
   teaches the user nothing. */
$canConnect = !empty($item['available']);
$needsRedo  = ($item['status'] ?? null) === 'revoked';
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
            <?= e($statusText) ?>
        </span>

        <?= icon('chevron-down', 'integration__chevron') ?>
    </button>

    <div class="integration__body" id="<?= e($id) ?>" hidden>

        <div class="metric-rows">
            <div class="metric-row">
                <span class="metric-row__label"><?= e($labels['status']) ?></span>
                <span class="metric-row__value"><?= e($statusText) ?></span>
            </div>
            <?php if (!empty($item['account'])): ?>
                <div class="metric-row">
                    <span class="metric-row__label"><?= e($labels['account']) ?></span>
                    <span class="metric-row__value"><?= e($item['account']) ?></span>
                </div>
            <?php endif; ?>
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

        <?php if (!empty($item['error'])): ?>
            <p class="integration__hint integration__hint--warn">
                <?= icon('info', 'card__hint-icon') ?><?= e($item['error']) ?>
            </p>
        <?php endif; ?>

        <div class="integration__actions">
            <?php if ($item['connected']): ?>
                <button type="button" class="btn press"
                        data-integration-sync="<?= e($item['provider']) ?>" disabled>
                    <?= e($labels['sync_now']) ?>
                </button>
                <button type="button" class="btn press"
                        data-integration-disconnect="<?= e($item['provider']) ?>">
                    <?= e($labels['disconnect']) ?>
                </button>
            <?php else: ?>
                <?php
                /* A phone source is paired with a code; a cloud source is
                   connected with OAuth. Different verbs, different buttons. */
                $hook = ($item['transport'] ?? 'cloud') === 'device'
                    ? 'data-integration-pair="' . e($item['provider']) . '"'
                    : 'data-integration-connect="' . e($item['provider']) . '"';
                ?>
                <button type="button" class="btn press"
                        <?= $canConnect ? $hook : 'disabled aria-disabled="true"' ?>>
                    <?= e($needsRedo ? $labels['reconnect'] : $labels['connect']) ?>
                </button>
            <?php endif; ?>
        </div>

        <?php if (!$canConnect && !$item['connected'] && !empty($item['blocked'])): ?>
            <p class="integration__hint">
                <?= icon('lock', 'card__hint-icon') ?><?= e($item['blocked']) ?>
            </p>
        <?php elseif ($item['connected']): ?>
            <p class="integration__hint">
                <?= icon('lock', 'card__hint-icon') ?><?= e($labels['disconnect_confirm']) ?>
            </p>
        <?php endif; ?>
    </div>

</div>
