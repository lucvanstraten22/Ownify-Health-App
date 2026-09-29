<?php
/**
 * The pairing code, shown once.
 *
 * Same panel as the delete confirmation and the field editor — same scrim,
 * card and buttons — because it is the same kind of moment and the page has
 * already taught what that looks like.
 *
 * The code is fetched when the panel opens rather than rendered into the page,
 * because a code that sits in the HTML of every settings visit is a code that
 * is minted whether or not anyone wanted one, and it expires in ten minutes.
 */
declare(strict_types=1);
?>
<div class="confirm" data-overlay data-settings-pairing hidden>

    <div class="confirm__scrim" data-pairing-close></div>

    <div class="confirm__panel card pairing" role="dialog" aria-modal="true"
         aria-labelledby="pairing-title">

        <h2 class="confirm__title" id="pairing-title">Koppelcode</h2>
        <p class="confirm__body">Open de Ownify-app op je telefoon en voer deze code in.</p>

        <p class="pairing__code" data-pairing-code aria-live="polite">••••••••</p>
        <p class="pairing__expiry" data-pairing-expiry></p>

        <p class="field-editor__error" role="alert" data-pairing-error hidden></p>

        <div class="confirm__row">
            <button type="button" class="btn press" data-pairing-close>Sluiten</button>
            <button type="button" class="btn confirm__yes press" data-pairing-refresh>Nieuwe code</button>
        </div>

    </div>
</div>
