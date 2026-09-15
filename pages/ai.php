<?php
/**
 * The assistant — a sheet that pulls up over whichever page is showing.
 *
 * It is not a destination on the rail: the page underneath keeps its state
 * and its scroll position, so dismissing the sheet always returns the user
 * exactly where they were.
 *
 * Still intentionally empty: no model, no API, no conversation, no input.
 */
declare(strict_types=1);

$ai = $data['ai'];
?>
<!-- Starts closed and unreachable; ai-sheet.js flips this on open, so the
     state is correct even before (or without) JavaScript. -->
<section class="screen sheet sheet--ai" data-sheet="ai" aria-label="<?= e($ai['title']) ?>"
         aria-hidden="true" inert>

    <div class="screen__scroll screen__scroll--ai" data-scroller>

        <!-- The dismissal area. Vertical movement here is the sheet's, not
             the scroller's, which leaves the rest free for a future
             conversation to scroll normally. -->
        <header class="ai-top" data-ai-dismiss>
            <span class="sheet-handle" aria-hidden="true"></span>

            <div class="ai-top__row shell">
                <button type="button" class="pill pill--close press"
                        data-ai-close aria-label="<?= e($ai['close']['aria']) ?>">
                    <?= icon('chevron-down', 'pill__icon') ?>
                    <span class="pill__label"><?= e($ai['close']['label']) ?></span>
                </button>
            </div>
        </header>

        <main class="ai-main shell">
            <?php component('ai-empty-state', $data); ?>
        </main>

        <?php component('ai-composer', $data); ?>

    </div>

</section>
