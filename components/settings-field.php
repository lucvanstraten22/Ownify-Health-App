<?php
/**
 * One profile field.
 *
 * Three behaviours, and the row shows which one it is without needing a
 * legend: an editable field gets a chevron, a field set once at onboarding
 * gets a lock, and a calculated one says where it comes from.
 */
declare(strict_types=1);

$field   = $data['field'];
$profile = $data['settings']['profile'];
$edit    = $field['edit'] ?? true;
$value   = settings_field_value($field, $profile);
$filled  = $value !== null;

$tag = $edit === true ? 'button' : 'div';

/* A field is live only when something behind it can actually save. The rest
   carry the same affordance and are plainly disabled, rather than moving and
   quietly discarding what you typed. */
$opens = $field['opens'] ?? null;
?>
<<?= $tag ?> class="settings-field<?= $edit === true ? ' press' : '' ?> is-<?= e($edit === true ? 'editable' : (string) $edit) ?><?= $filled ? ' is-filled' : ' is-empty' ?>"
    <?php if ($edit === true): ?>type="button"<?= $opens === 'account' ? ' data-account-open' : ' disabled aria-disabled="true"' ?><?php endif; ?>>

    <span class="settings-field__text">
        <span class="settings-field__label"><?= e($field['label']) ?></span>
        <?php if (!empty($field['note'])): ?>
            <span class="settings-field__note"><?= e($field['note']) ?></span>
        <?php endif; ?>
    </span>

    <?php if (($field['kind'] ?? null) === 'image'): ?>
        <span class="settings-field__avatar" aria-hidden="true">
            <?php if ($filled): ?>
                <img src="<?= e($value) ?>" alt="">
            <?php else: ?>
                <?= icon('user') ?>
            <?php endif; ?>
        </span>
    <?php else: ?>
        <span class="settings-field__value"><?= e($filled ? $value : settings_field_blank($field)) ?></span>
    <?php endif; ?>

    <?php if ($edit === 'locked'): ?>
        <?= icon('lock', 'settings-field__mark') ?>
    <?php elseif ($edit === true): ?>
        <?= icon('chevron-right', 'settings-field__mark') ?>
    <?php endif; ?>

</<?= $tag ?>>
