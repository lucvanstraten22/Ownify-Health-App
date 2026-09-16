<?php
/**
 * The delete-account confirmation.
 *
 * Lives outside the deck, next to the account panel and the goal wizard, so
 * the pointer pipeline never sees it.
 *
 * It confirms and then stops: deletion is not implemented, and the panel says
 * so rather than reporting something that did not happen.
 */
declare(strict_types=1);

$delete = $data['settings']['actions']['delete'];
?>
<div class="confirm" data-overlay data-settings-confirm hidden>

    <div class="confirm__scrim" data-confirm-close></div>

    <div class="confirm__panel card" role="alertdialog" aria-modal="true"
         aria-labelledby="confirm-title" aria-describedby="confirm-body">

        <span class="icon-tile confirm__mark" aria-hidden="true"><?= icon('trash') ?></span>

        <h2 class="confirm__title" id="confirm-title"><?= e($delete['title']) ?></h2>
        <p class="confirm__body" id="confirm-body"><?= e($delete['body']) ?></p>

        <div class="confirm__row">
            <button type="button" class="btn press" data-confirm-close><?= e($delete['cancel']) ?></button>
            <button type="button" class="btn confirm__yes press" disabled aria-disabled="true"><?= e($delete['confirm']) ?></button>
        </div>

        <p class="confirm__note">
            <?= icon('lock', 'confirm__note-icon') ?>
            <span><?= e($delete['note']) ?></span>
        </p>

    </div>
</div>
