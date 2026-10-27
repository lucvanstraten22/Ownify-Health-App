<?php
/**
 * The delete-account confirmation — two steps, and only then anything.
 *
 * Lives outside the deck, next to the account panel and the goal wizard, so
 * the pointer pipeline never sees it.
 *
 *   1  what goes: the account and everything on it, the Google link included
 *      when there is one. "Ja, verwijderen" moves on; it deletes nothing.
 *   2  "Weet je het zeker?" — the last word. Only "Definitief verwijderen"
 *      here calls api/profile/delete.php, and it sends the one confirmation
 *      the endpoint insists on.
 *
 * The buttons swap sides between the steps: the safe answer on step 2 sits
 * exactly where "Ja, verwijderen" was, so a double tap cancels instead of
 * deleting.
 *
 * Step 2 also asks the account to prove it is itself again (28.0,
 * docs/ACCOUNT-SECURITY.md §7): its password when it has one; otherwise
 * Google, through "Bevestig met Google" first (api/auth/reauth.php), after
 * which deleting may follow within 5 minutes.
 */
declare(strict_types=1);

$delete = $data['settings']['actions']['delete'];

/* How this account proves itself before it is deleted. */
$signin   = $data['auth']['signin'] ?? null;
$byPassword = $signin !== null && !empty($signin['password']);
$googleReady = !empty($data['auth']['reauth_ready']);

/* Whether this account has Google linked, which both steps mention. */
$hasGoogle = false;
foreach ($data['auth']['identities'] ?? [] as $identity) {
    if (($identity['provider'] ?? null) === 'google') {
        $hasGoogle = true;
    }
}
?>
<div class="confirm" data-overlay data-settings-confirm hidden>

    <div class="confirm__scrim" data-confirm-close></div>

    <!-- -------------------------------------------------- 1 · what goes -->
    <div class="confirm__panel card" role="alertdialog" aria-modal="true" data-confirm-step="1"
         aria-labelledby="confirm-title" aria-describedby="confirm-body">

        <span class="icon-tile confirm__mark" aria-hidden="true"><?= icon('trash') ?></span>

        <h2 class="confirm__title" id="confirm-title"><?= e($delete['title']) ?></h2>
        <p class="confirm__body" id="confirm-body">
            <?= e($delete['body']) ?>
            <?php if ($hasGoogle): ?><?= ' ' . e($delete['google']) ?><?php endif; ?>
        </p>

        <div class="confirm__row">
            <button type="button" class="btn press" data-confirm-close><?= e($delete['cancel']) ?></button>
            <button type="button" class="btn btn--caution confirm__yes press" data-confirm-next><?= e($delete['confirm']) ?></button>
        </div>

        <p class="confirm__note">
            <?= icon('lock', 'confirm__note-icon') ?>
            <span><?= e($delete['note']) ?></span>
        </p>
    </div>

    <!-- ------------------------------------------ 2 · are you sure -->
    <div class="confirm__panel card" role="alertdialog" aria-modal="true" data-confirm-step="2" hidden
         aria-labelledby="confirm-final-title" aria-describedby="confirm-final-body">

        <span class="icon-tile confirm__mark confirm__mark--final" aria-hidden="true"><?= icon('trash') ?></span>

        <h2 class="confirm__title" id="confirm-final-title"><?= e($delete['final_title']) ?></h2>
        <p class="confirm__body" id="confirm-final-body">
            <?= e($delete['final_body']) ?>
            <?php if ($hasGoogle): ?><?= ' ' . e($delete['final_google']) ?><?php endif; ?>
        </p>

        <?php if ($byPassword): ?>
            <div class="account__field field-editor__control">
                <label class="account__label" for="confirm-password"><?= e(t('Wachtwoord')) ?></label>
                <input class="field-editor__input" type="password" id="confirm-password" name="password"
                       autocomplete="current-password" maxlength="200" data-confirm-password>
            </div>
        <?php elseif (!$googleReady): ?>
            <div class="field-editor__control" data-confirm-google>
                <p class="confirm__body"><?= e(t('Bevestig eerst met Google. Daarna kun je je account binnen 5 minuten verwijderen.')) ?></p>
                <button type="button" class="btn press" data-confirm-reauth><?= e(t('Bevestig met Google')) ?></button>
            </div>
        <?php endif; ?>

        <p class="confirm__error" data-confirm-error role="alert" hidden></p>

        <?php /* Swapped on purpose: the safe answer is on the right, where the
                 previous step's confirmation was. */ ?>
        <div class="confirm__row">
            <button type="button" class="btn btn--caution confirm__yes press" data-confirm-delete
                    <?= !$byPassword && !$googleReady ? 'disabled' : '' ?>>
                <?= e($delete['final_yes']) ?>
            </button>
            <button type="button" class="btn press" data-confirm-close><?= e($delete['final_no']) ?></button>
        </div>
    </div>
</div>
