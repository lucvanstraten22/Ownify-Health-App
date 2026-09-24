<?php
/**
 * The quick look behind the header's devices button.
 *
 * What is linked right now, and one way on: "Apparaat koppelen" opens
 * Instellingen > Apparaten & Gezondheid, where everything is set up. No
 * health data and no settings here; the devices screen owns those.
 *
 * Every line is real. The list is read from the integrations lib/settings.php
 * has already resolved for the devices screen, with the id from the session,
 * so the two can never disagree. A paired phone is shown by the name it paired
 * with, under the source it feeds; a linked source without a phone of its own
 * (Google Health, in the cloud) by the source's name. Nothing linked, or
 * nobody signed in, is the empty state.
 *
 * Lives outside the deck, next to the account panel, so the pointer pipeline
 * never sees it.
 */
declare(strict_types=1);

$copy   = $data['header']['devices'];
$labels = $data['settings']['integration_labels'];

$lines = [];
foreach ($data['settings']['integrations'] as $source) {
    $status = $source['status'] ?? 'disconnected';

    /* Linked is connected, or connected with a last sync that failed: still
       linked, and saying so beats claiming nothing is. Revoked and
       disconnected sources are not linked, and are not shown. */
    if ($status !== 'connected' && $status !== 'error') {
        continue;
    }

    $line = [
        'icon'   => $source['icon'],
        'status' => $status,
        'state'  => $status === 'connected' ? $labels['connected'] : $labels['error'],
    ];

    if (!empty($source['devices'])) {
        foreach ($source['devices'] as $device) {
            $lines[] = $line + ['name' => $device['label'], 'source' => $source['label']];
        }
    } else {
        $lines[] = $line + ['name' => $source['label'], 'source' => null];
    }
}
?>
<div class="devices" id="devices-popup" data-overlay data-devices hidden
     role="dialog" aria-modal="true" aria-label="<?= e($copy['label']) ?>">

    <?php /* A tap anywhere else closes. A button, so a screen reader has a way
             out as well; Escape does the same from a keyboard. */ ?>
    <button type="button" class="devices__scrim" data-devices-close tabindex="-1" aria-label="Sluiten"></button>

    <div class="devices__frame shell">
        <div class="devices__panel card">

            <?php if ($lines === []): ?>
                <p class="devices__empty"><?= e($copy['empty']) ?></p>
            <?php else: ?>
                <ul class="devices__list" role="list">
                    <?php foreach ($lines as $line): ?>
                        <li class="devices__item<?= $line['status'] === 'connected' ? ' is-connected' : '' ?>">
                            <span class="icon-tile" aria-hidden="true"><?= icon($line['icon']) ?></span>

                            <span class="devices__text">
                                <span class="devices__name"><?= e($line['name']) ?></span>
                                <?php if ($line['source'] !== null): ?>
                                    <span class="devices__source"><?= e($line['source']) ?></span>
                                <?php endif; ?>
                            </span>

                            <span class="devices__status">
                                <span class="devices__dot" aria-hidden="true"></span>
                                <?= e($line['state']) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <button type="button" class="btn devices__add press" data-devices-add>
                <?= icon('plus', 'devices__add-icon') ?>
                <span><?= e($copy['add']) ?></span>
            </button>
        </div>
    </div>
</div>
