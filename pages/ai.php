<?php
/**
 * Screen 2 — AI extension layer.
 *
 * Intentionally empty. There is no model, no API, no conversation and no
 * input field: only the room the assistant will live in, built from the same
 * tokens and components as the dashboard.
 */
declare(strict_types=1);

$ai = $data['ai'];
?>
<!-- Starts closed and unreachable; swipe-navigation.js flips this on open,
     so the state is correct even before (or without) JavaScript. -->
<section class="screen screen--ai" data-screen="ai" aria-label="<?= e($ai['title']) ?>"
         aria-hidden="true" inert>

    <div class="screen__scroll screen__scroll--ai" data-scroller>

        <header class="ai-top shell">
            <button type="button" class="pill pill--back press"
                    data-navigate="overview"
                    aria-label="<?= e($ai['back']['aria']) ?>">
                <?= icon('chevron-left', 'pill__icon') ?>
                <span class="pill__label"><?= e($ai['back']['label']) ?></span>
            </button>
        </header>

        <main class="ai-main shell">
            <?php component('ai-empty-state', $data); ?>
        </main>

        <?php component('ai-composer', $data); ?>

    </div>

</section>
