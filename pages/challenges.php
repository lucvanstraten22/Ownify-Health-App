<?php
/**
 * Uitdagingen — every challenge you are in or invited to, and the two ways
 * to start one: make it (Nieuwe uitdaging) or join one with the code you
 * were given (Code invoeren), two tiles side by side. Each opens one bottom
 * sheet (challenges.js).
 *
 *   Uitnodigingen   from friends; Bekijken shows what it is before Meedoen
 *   Samen           on now (or starting on Monday), working toward one target:
 *                   the bar in everybody's pieces, and who brought what
 *   Wedstrijd       on now, each for themselves: the top three
 *   Afgelopen       the last ten that finished
 *
 * With nothing at all, the empty target and three ways to begin, each
 * opening Nieuwe uitdaging with its kind and metric chosen.
 *
 * A challenge's own page (pages/challenge-detail.php) opens over this one.
 * docs/CHALLENGES.md.
 */
declare(strict_types=1);

$challenges = $data['challenges'];
$copy       = $challenges['copy'];
$page       = $copy['page'];
$running    = $challenges['running'];
$groups     = [
    'invites'  => [$page['invites'], $challenges['invites'], 'item'],
    'together' => [$page['together'], array_values(array_filter($running, static fn (array $v): bool => $v['kind'] !== 'versus')), 'card'],
    'versus'   => [$page['versus'], array_values(array_filter($running, static fn (array $v): bool => $v['kind'] === 'versus')), 'card'],
    'ended'    => [$page['ended'], $challenges['ended'], 'item'],
];
$empty   = $challenges['invites'] === [] && $running === [] && $challenges['ended'] === [];
$metrics = config_copy('challenges')['metrics'];
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

                <div class="reveal"><?= ch_actions($page) ?></div>

                <?php if ($empty): ?>
                    <div class="card ch-empty2 reveal">
                        <?= ch_target_art() ?>
                        <h2 class="ch-empty__title"><?= e($page['empty_title']) ?></h2>
                        <p class="ch-empty__body"><?= e($page['ideas_lede']) ?></p>
                        <div class="ch-ideas">
                            <?php foreach ($page['ideas'] as $idea): ?>
                                <?= ch_idea($idea, $metrics) ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($groups as $key => [$title, $list, $draw]): ?>
                    <?php if ($list !== []): ?>
                        <section class="ch-group reveal" aria-label="<?= e($title) ?>" data-challenge-group="<?= e($key) ?>">
                            <h2 class="ch-eyebrow"><?= e($title) ?></h2>
                            <div class="ch-list">
                                <?php foreach ($list as $view): ?>
                                    <?= $draw === 'card' ? ch_card($view) : ch_item($view) ?>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php endforeach; ?>

            </div>
        </main>

    </div>

</article>
