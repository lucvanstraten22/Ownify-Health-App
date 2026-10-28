<?php
/**
 * Telefoon or Desktop: only on a screen wide enough for the second
 * (desktop.css, desktop.js). It changes the layout, never the page or
 * anything on it.
 *
 * Two places, one shown at a time: in the desktop layout it stands at the
 * sidebar's foot ($data['view_switch_place'] = 'side', app-dock.php); in
 * Telefoon on a wide screen it floats at the top right (header.php), so the
 * way back is always there. desktop.js paints and wires every copy.
 */
declare(strict_types=1);

$place = ($data['view_switch_place'] ?? 'header') === 'side' ? ' view-switch--side' : ' view-switch--header';
?>
<div class="range-switch view-switch<?= $place ?>" role="group" aria-label="<?= e(t('Weergave')) ?>" data-view-switch>
    <button type="button" class="range-switch__option" data-view-choice="phone" aria-pressed="false">
        <?= icon('phone', 'view-switch__icon') ?><span><?= e(t('Telefoon')) ?></span>
    </button>
    <button type="button" class="range-switch__option" data-view-choice="desktop" aria-pressed="false">
        <?= icon('desktop', 'view-switch__icon') ?><span><?= e(t('Desktop')) ?></span>
    </button>
</div>
