<?php
/**
 * Uitdagingen — every challenge you are in or invited to, and the two ways
 * to start one: make it (Nieuwe uitdaging) or join one with the code you
 * were given (Code invoeren). Each opens one bottom sheet (challenges.js).
 *
 *   Uitnodigingen   from friends; Bekijken shows what it is before Meedoen
 *   Bezig           on now, or starting on Monday, soonest to end first
 *   Afgelopen       the last ten that finished
 *
 * A challenge's own page (pages/challenge-detail.php) opens over this one.
 * docs/CHALLENGES.md.
 */
declare(strict_types=1);

$challenges = $data['challenges'];
$copy       = $challenges['copy'];
$page       = $copy['page'];
$groups     = [
    'invites' => [$page['invites'], $challenges['invites']],
    'running' => [$page['running'], $challenges['running']],
    'ended'   => [$page['ended'], $challenges['ended']],
];
$empty = $challenges['invites'] === [] && $challenges['running'] === [] && $challenges['ended'] === [];
?>
<article class="detail challenges-detail" data-detail="challenges" data-accent="training"
         aria-label="<?= e($page['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="<?= e(t('Terug naar Community')) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($page['back']) ?></span>
                </button>
                <span class="detail__title" aria-hidden="true"><?= e($page['title']) ?></span>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack" data-challenges-list>

                <header class="page-intro reveal">
                    <h1 class="page-intro__title" data-hero-name><?= e($page['title']) ?></h1>
                    <p class="page-intro__lede"><?= e($page['lede']) ?></p>
                </header>

                <div class="ch-actions reveal">
                    <button type="button" class="btn ch-primary press ch-wide" data-challenge-create>
                        <?= icon('plus') ?><span><?= e($page['create']) ?></span>
                    </button>
                    <button type="button" class="btn ch-primary press ch-wide" data-challenge-join>
                        <?= icon('hash') ?><span><?= e($page['join']) ?></span>
                    </button>
                </div>

                <?php if ($empty): ?>
                    <div class="card ch-empty is-empty reveal">
                        <span class="icon-tile" aria-hidden="true"><?= icon('flag') ?></span>
                        <h2 class="ch-empty__title"><?= e($page['empty_title']) ?></h2>
                        <p class="ch-empty__body"><?= e($page['empty_body']) ?></p>
                    </div>
                <?php endif; ?>

                <?php foreach ($groups as $key => [$title, $list]): ?>
                    <?php if ($list !== []): ?>
                        <section class="ch-group reveal" aria-label="<?= e($title) ?>" data-challenge-group="<?= e($key) ?>">
                            <h2 class="ch-eyebrow"><?= e($title) ?></h2>
                            <div class="ch-list">
                                <?php foreach ($list as $view): ?>
                                    <?= ch_item($view) ?>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php endforeach; ?>

            </div>
        </main>

    </div>

</article>
