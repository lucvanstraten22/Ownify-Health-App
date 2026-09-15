<?php
/**
 * Screen 1 — Home / Overview.
 *
 * The screen owns its own vertical scroller so the whole layer can be
 * translated as one unit during the swipe transition. Header, tab bar and the
 * floating control sit outside that scroller and stay pinned to the screen.
 */
declare(strict_types=1);
?>
<section class="screen screen--overview" data-screen="overview" aria-label="Overzicht">

    <div class="screen__scroll" data-scroller>
        <?php component('header', $data); ?>

        <main class="app__main" id="main" tabindex="-1">
            <div class="shell stack">
                <?php
                component('health-score', $data);
                component('secondary-scores', $data);
                component('goal-progress', $data);
                component('insights', $data);
                component('patterns', $data);
                component('recommendation', $data);
                component('leaderboard', $data);
                ?>
                <p class="disclaimer reveal"><?= e($data['disclaimer']) ?></p>
            </div>
        </main>
    </div>

    <?php
    component('scroll-top', $data);
    component('edge-handle', $data);
    component('bottom-navigation', $data);
    ?>

    <!-- Depth cue: deepens as the assistant layer slides over this screen. -->
    <div class="screen__scrim" data-scrim aria-hidden="true"></div>

</section>
