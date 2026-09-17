<?php
/**
 * The profile field editor.
 *
 * Deliberately the same panel as the delete confirmation — same scrim, same
 * card, same two buttons — because it is the same kind of moment and the page
 * already taught the user what that looks like. Only the middle changes: an
 * input instead of a sentence.
 *
 * It is one panel reused for every field rather than one per field. What it
 * renders is decided in JavaScript from the field's own data attributes, so
 * adding a field to config/settings.php needs nothing here.
 *
 * Lives outside the deck, with the account panel and the goal wizard, so the
 * pointer pipeline never mistakes it for a swipe.
 */
declare(strict_types=1);
?>
<div class="confirm" data-overlay data-settings-editor hidden>

    <div class="confirm__scrim" data-editor-close></div>

    <form class="confirm__panel card field-editor" data-editor-form
          role="dialog" aria-modal="true" aria-labelledby="editor-title">

        <h2 class="confirm__title" id="editor-title" data-editor-title></h2>

        <!-- The one-time warning. Shown only for a field that cannot be
             changed again, so it never becomes background noise. -->
        <p class="confirm__body field-editor__once" data-editor-once hidden>
            Dit kun je één keer invullen. Daarna staat het vast.
        </p>

        <div class="field-editor__control" data-editor-control></div>

        <p class="field-editor__error" role="alert" data-editor-error hidden></p>

        <div class="confirm__row">
            <button type="button" class="btn press" data-editor-close>Annuleren</button>
            <button type="submit" class="btn confirm__yes press" data-editor-save>Opslaan</button>
        </div>

    </form>
</div>
