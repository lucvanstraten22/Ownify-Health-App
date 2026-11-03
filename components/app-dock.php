<?php
/**
 * The dock: everything pinned to the bottom of the app, above the page rail
 * and below the assistant sheet.
 *
 * It is also the assistant's grab area. `touch-action: none` here is what
 * makes the upward swipe possible at all — everywhere else vertical movement
 * belongs to the browser, which is how ordinary scrolling stays untouched.
 */
declare(strict_types=1);
?>
<div class="app-dock" data-ai-grab>

    <button type="button" class="ai-handle" data-ai-open
            aria-label="<?= e($data['ai']['open']['aria']) ?>">
        <span class="ai-handle__grip" aria-hidden="true"></span>
    </button>

    <?php component('bottom-navigation', $data); ?>

</div>
