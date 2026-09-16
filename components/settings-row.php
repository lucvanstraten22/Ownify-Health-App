<?php
/**
 * One settings row: icon · label · current value · chevron.
 *
 * The value sits under the label rather than beside it. That is what lets
 * "Apparaten & Gezondheid" and "Eerste dag van de week" keep their full names
 * on a 320px phone instead of wrapping around a right-aligned value.
 *
 * Rows live inside a group card and are separated by hairlines rather than
 * each being a card of their own — a page of forty glass panels would be
 * noise, not hierarchy.
 */
declare(strict_types=1);

$row  = $data['row'];
$meta = $row['value'] ?? $row['hint'] ?? null;
?>
<button type="button" class="settings-row press"
        data-detail-open="settings-<?= e($row['id']) ?>"
        aria-label="<?= e($row['label']) ?><?= $meta !== null ? ' — ' . e((string) $meta) : '' ?>. Open instellingen.">

    <span class="settings-row__mark" aria-hidden="true"><?= icon($row['icon']) ?></span>

    <span class="settings-row__text">
        <span class="settings-row__label"><?= e($row['label']) ?></span>
        <?php if ($meta !== null): ?>
            <span class="settings-row__value"><?= e((string) $meta) ?></span>
        <?php endif; ?>
    </span>

    <?= icon('chevron-right', 'settings-row__chevron') ?>
</button>
