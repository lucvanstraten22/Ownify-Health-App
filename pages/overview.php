<?php
/**
 * Overzicht — the dashboard page on the horizontal rail.
 *
 * The page owns its own vertical scroller, so its scroll position survives
 * both a sideways move to another page and the assistant sheet opening on
 * top of it. The tab bar is not here: it lives in the dock, above the rail.
 */
declare(strict_types=1);
?>
<section class="screen page" data-page="overview" aria-label="Overzicht"
         <?= empty($data['page_active']) ? 'aria-hidden="true" inert' : '' ?>>

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

    <?php component('scroll-top', $data); ?>

</section>
