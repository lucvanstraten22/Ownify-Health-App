<?php
/**
 * A section page that is not built yet.
 *
 * Deliberately thin: it exists so the horizontal navigation between the five
 * primary destinations is real, and it says what will live there rather than
 * pretending to be finished. Everything comes from `sections` in
 * config/dashboard.php.
 */
declare(strict_types=1);

$section = $data['section'];
?>
<section class="screen page" data-page="<?= e($section['id']) ?>" aria-label="<?= e($section['title']) ?>"
         <?= empty($data['page_active']) ? 'aria-hidden="true" inert' : '' ?>>

    <div class="screen__scroll" data-scroller>
        <?php component('header', $data); ?>

        <main class="app__main section-main">
            <div class="shell">
                <div class="card section-empty is-empty">
                    <span class="icon-tile" aria-hidden="true"><?= icon($section['icon']) ?></span>
                    <h1 class="section-empty__title"><?= e($section['title']) ?></h1>
                    <p class="section-empty__lede"><?= e($section['lede']) ?></p>

                    <ul class="chips chips--centered" role="list">
                        <?php foreach ($section['topics'] as $topic): ?>
                            <li><span class="chip chip--muted"><?= e($topic) ?></span></li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="card__hint"><?= icon('lock', 'card__hint-icon') ?><?= e($section['status']) ?></p>
                </div>
            </div>
        </main>
    </div>

</section>
