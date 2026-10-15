<?php
/**
 * Community — what is happening with the people you know, content first.
 *
 * Two cards, each a way in and a preview of what is behind it:
 *
 *   Ranglijst     the top of your friends' board this month, you on it, and
 *                 how far the one directly ahead of you is. The card opens
 *                 the full board (pages/leaderboard.php): scopes, periods,
 *                 everything as it always was.
 *   Uitdagingen   invitations waiting for you, and the challenges you are in
 *                 on a carousel, each opening its own page; the last tile
 *                 makes a new one. The card opens Uitdagingen
 *                 (pages/challenges.php), where a code is entered.
 *
 * Challenges have nothing to do with points; the line under the cards says
 * so. docs/CHALLENGES.md.
 */
declare(strict_types=1);

$community  = $data['community'];
$preview    = $community['preview'];
$challenges = $data['challenges'] ?? ['available' => false];
$copy       = $challenges['copy'] ?? [];
?>
<section class="screen page" data-page="community" aria-label="<?= e(t('Community')) ?>"
         <?= empty($data['page_active']) ? 'aria-hidden="true" inert' : '' ?>>

    <div class="screen__scroll" data-scroller>
        <main class="app__main">
            <div class="shell stack community-home" data-community-home>

                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($community['title']) ?></h1>
                </header>

                <!-- Ranglijst -->
                <section class="card ch-card reveal" data-accent="health" aria-labelledby="home-board-title">
                    <button type="button" class="ch-head press" data-detail-open="leaderboard">
                        <span class="icon-tile" aria-hidden="true"><?= icon('ranking') ?></span>
                        <span class="ch-head__text">
                            <span class="ch-head__title" id="home-board-title"><?= e($preview['title']) ?></span>
                            <span class="ch-head__sub"><?= e($preview['sub']) ?></span>
                        </span>
                        <span class="ch-link"><?= e(t('Toon alles')) ?><?= icon('chevron-right') ?></span>
                    </button>

                    <?php if ($preview['rows'] !== []): ?>
                        <ol class="ch-rows" role="list">
                            <?php foreach ($preview['rows'] as $entry): ?>
                                <?php component('leaderboard-row', $data + ['entry' => $entry, 'entry_sticky' => false]); ?>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>

                    <?php if ($preview['line'] !== ''): ?>
                        <p class="ch-line"><span class="ch-dot" aria-hidden="true"></span><?= e($preview['line']) ?></p>
                    <?php endif; ?>
                </section>

                <?php if (!empty($challenges['available'])): ?>
                    <!-- Uitdagingen -->
                    <section class="card ch-card reveal" data-accent="training" aria-labelledby="home-challenges-title" data-home-challenges>
                        <button type="button" class="ch-head press" data-detail-open="challenges" data-challenge-from="home">
                            <span class="icon-tile" aria-hidden="true"><?= icon('flag') ?></span>
                            <span class="ch-head__text">
                                <span class="ch-head__title" id="home-challenges-title"><?= e($copy['page']['title']) ?></span>
                                <span class="ch-head__sub"><?= e($challenges['summary']) ?></span>
                            </span>
                            <span class="ch-link"><?= e(t('Toon alles')) ?><?= icon('chevron-right') ?></span>
                        </button>

                        <?php foreach ($challenges['invites'] as $invite): ?>
                            <?= ch_invite($invite, $copy['review']['see']) ?>
                        <?php endforeach; ?>

                        <div class="ch-rail<?= $challenges['running'] === [] ? ' is-alone' : '' ?>" role="list"
                             aria-label="<?= e(t('Lopende uitdagingen')) ?>" data-gesture-own>
                            <?php foreach ($challenges['running'] as $view): ?>
                                <div class="ch-rail__slot" role="listitem"><?= ch_tile($view) ?></div>
                            <?php endforeach; ?>
                            <div class="ch-rail__slot" role="listitem">
                                <button type="button" class="ch-tile ch-tile--new press" data-challenge-create>
                                    <?= icon('plus') ?><span><?= e($copy['page']['create']) ?></span>
                                </button>
                            </div>
                        </div>
                    </section>

                    <p class="ch-note reveal"><?= e($challenges['note']) ?></p>
                <?php endif; ?>

            </div>
        </main>
    </div>

</section>
