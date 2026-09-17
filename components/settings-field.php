<?php
/**
 * One profile field.
 *
 * Four behaviours, and the row shows which one it is without needing a
 * legend: a field you can change carries a chevron, a one-time field you have
 * not answered yet also carries one, the same field once answered carries a
 * lock, and a calculated one says where it comes from.
 *
 * The difference between the two chevrons is what happens when you tap: the
 * editor warns that a one-time answer is permanent before it saves it.
 */
declare(strict_types=1);

$field   = $data['field'];
$profile = $data['settings']['profile'];
$state   = settings_field_state($field, $profile);
$value   = settings_field_value($field, $profile);
$filled  = $value !== null;

/* Editable and not-yet-answered both open the editor; locked and derived are
   not interactive at all, so they are not buttons. */
$live = $state === true || $state === 'once';
$tag  = $live ? 'button' : 'div';

/* Two of these have had an endpoint since before the profile did, and they
   open the account panel rather than the field editor. */
$opens = $field['opens'] ?? null;
$input = $live && $opens === null ? settings_field_input($field, $profile) : null;
?>
<<?= $tag ?> class="settings-field<?= $live ? ' press' : '' ?> is-<?= e($state === true ? 'editable' : (string) $state) ?><?= $filled ? ' is-filled' : ' is-empty' ?>"
    <?php if ($live): ?>
        type="button"
        <?php if ($opens === 'account'): ?>
            data-account-open
        <?php else: ?>
            data-field-edit="<?= e($field['key']) ?>"
            data-field-label="<?= e($field['label']) ?>"
            data-field-input="<?= e(json_encode($input, JSON_UNESCAPED_UNICODE)) ?>"
            <?= $state === 'once' ? 'data-field-once="1"' : '' ?>
        <?php endif; ?>
    <?php endif; ?>>

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
        <span class="settings-field__value" data-field-value="<?= e($field['key']) ?>"><?= e($filled ? $value : settings_field_blank($field, $state)) ?></span>
    <?php endif; ?>

    <?php if ($state === 'locked'): ?>
        <?= icon('lock', 'settings-field__mark') ?>
    <?php elseif ($live): ?>
        <?= icon('chevron-right', 'settings-field__mark') ?>
    <?php endif; ?>

</<?= $tag ?>>
