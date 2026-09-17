<?php
/**
 * Gezondheid — the entry point to the three physical-health areas.
 *
 * Deliberately compact: three scores and one trend. Everything else lives in
 * the detail page behind each card.
 */
declare(strict_types=1);

$health = $data['health'];
?>
<section class="screen page" data-page="health" aria-label="Gezondheid"
         <?= empty($data['page_active']) ? 'aria-hidden="true" inert' : '' ?>>

    <div class="screen__scroll" data-scroller>
        <?php component('header', $data); ?>

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

                <?php component('health-trend', $data); ?>

                <?php if ($data['disclaimer'] !== ''): ?>
                <p class="disclaimer reveal"><?= e($data['disclaimer']) ?></p>
                <?php endif; ?>

            </div>
        </main>
    </div>

</section>
