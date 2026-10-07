<?php
/**
 * Gezondheid — the entry point to the three physical-health areas.
 *
 * Deliberately compact: three scores and how they went (the Verloop:
 * components/health-history.php). Everything else lives in the detail page
 * behind each card.
 */
declare(strict_types=1);

$health = $data['health'];
?>
<section class="screen page" data-page="health" aria-label="Gezondheid"
         <?= empty($data['page_active']) ? 'aria-hidden="true" inert' : '' ?>>

    <div class="screen__scroll" data-scroller>
        <main class="app__main">
            <div class="shell stack">

                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($health['title']) ?></h1>
                    <p class="page-intro__lede"><?= e($health['lede']) ?></p>
                </header>

                <div class="grid grid--three reveal">
                    <?php foreach ($health['areas'] as $id => $area): ?>
                        <?php component('health-card', $data + ['area' => $area + ['id' => $id]]); ?>
                    <?php endforeach; ?>
                </div>

                <?php /* The Verloop; while a deploy is still landing its files, the week and month. */ ?>
                <?php if (isset($data['health']['history']['periods']) && is_file(dirname(__DIR__) . '/components/health-history.php')): ?>
                    <?php component('health-history', $data); ?>
                <?php else: ?>
                    <?php component('health-trend', $data); ?>
                <?php endif; ?>

                <?php if ($data['disclaimer'] !== ''): ?>
                <p class="disclaimer reveal"><?= e($data['disclaimer']) ?></p>
                <?php endif; ?>

            </div>
        </main>
    </div>

</section>
