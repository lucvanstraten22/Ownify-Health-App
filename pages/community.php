<?php
/**
 * Community — what is happening with the people you know, content first.
 *
 * Two cards ("Twee vensters"), each a way in and a picture of what is
 * behind it:
 *
 *   Ranglijst     your friends' top three this month on a podium, and how
 *                 far the one directly ahead of you is; with nobody to
 *                 compare to, the open places add a friend. The card opens
 *                 the full board (pages/leaderboard.php) as it always was.
 *   Uitdagingen   invitations waiting for you, the challenge ending soonest
 *                 on top of a stack of the others, and Nieuwe uitdaging and
 *                 Code invoeren side by side. The card opens Uitdagingen
 *                 (pages/challenges.php).
 *
 * Challenges have nothing to do with points; the line under the cards says
 * so. docs/CHALLENGES.md.
 */
declare(strict_types=1);

$community  = $data['community'];
$preview    = $community['preview'];
$challenges = $data['challenges'] ?? ['available' => false];
$copy       = $challenges['copy'] ?? [];

/* The podium: first, second and third, wherever you are. A place nobody
   holds yet is an open spot that adds a friend. */
$podium = [];
foreach ($preview['rows'] as $entry) {
    $place = (int) ($entry['rank'] ?? 0);
    if ($place >= 1 && $place <= 3 && !isset($podium[$place])) {
        $podium[$place] = $entry;
    }
}
$alone = count($preview['rows']) <= 1;
?>
<section class="screen page" data-page="community" aria-label="<?= e(t('Community')) ?>"
         <?= empty($data['page_active']) ? 'aria-hidden="true" inert' : '' ?>>

    <div class="screen__scroll" data-scroller>
        <main class="app__main" data-pull-content>
            <div class="shell stack community-home" data-community-home>

                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($community['title']) ?></h1>
                </header>

                <!-- Ranglijst: the top three on a podium, you among them -->
                <section class="card cm-dest reveal" aria-labelledby="home-board-title">
                    <button type="button" class="cm-head press" data-detail-open="leaderboard">
                        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('ranking') ?></span>
                        <span class="cm-head__text">
                            <span class="cm-head__title" id="home-board-title"><?= e($preview['title']) ?></span>
                            <span class="cm-head__sub"><?= e($preview['sub']) ?></span>
                        </span>
                        <?= icon('chevron-right', 'cm-head__chev') ?>
                    </button>

                    <ol class="cm-podium" role="list" aria-label="<?= e(t('De top drie van je vrienden deze maand')) ?>">
                        <?php foreach ([2, 1, 3] as $place): ?>
                            <?php $entry = $podium[$place] ?? null; ?>
                            <?php if ($entry !== null): ?>
                                <li class="cm-podium__col<?= !empty($entry['self']) ? ' is-you' : '' ?>" style="--step: <?= ['1' => 84, '2' => 64, '3' => 50][(string) $place] ?>px">
                                    <span class="board-row__avatar cm-podium__av" data-accent="<?= e(community_avatar_accent($entry['name'])) ?>" aria-hidden="true">
                                        <?= e(community_initial($entry['name'])) ?>
                                        <?php if (has_value($entry['avatar'] ?? null)): ?>
                                            <img class="board-row__photo" src="<?= e((string) $entry['avatar']) ?>" alt="" loading="lazy" decoding="async">
                                        <?php endif; ?>
                                    </span>
                                    <span class="cm-podium__name"><?= e(!empty($entry['self']) ? t('Jij') : (string) $entry['name']) ?></span>
                                    <span class="cm-podium__pts"><?= e(community_points($entry['points'])) ?></span>
                                    <span class="cm-podium__step"><?= (int) $place ?></span>
                                </li>
                            <?php else: ?>
                                <li class="cm-podium__col is-open" style="--step: <?= ['1' => 84, '2' => 64, '3' => 50][(string) $place] ?>px">
                                    <button type="button" class="cm-podium__add press" data-account-open data-friends-add-open
                                            aria-label="<?= e(t('Vriend toevoegen')) ?>"><?= icon('plus') ?></button>
                                    <span class="cm-podium__step"><?= (int) $place ?></span>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>

                    <?php if ($alone): ?>
                        <button type="button" class="btn cm-btn press" data-account-open data-friends-add-open>
                            <?= icon('user-plus') ?><span><?= e(t('Vriend toevoegen')) ?></span>
                        </button>
                    <?php elseif ($preview['line'] !== ''): ?>
                        <p class="cm-line"><?= e($preview['line']) ?></p>
                    <?php endif; ?>
                </section>

                <?php if (!empty($challenges['available'])): ?>
                    <!-- Uitdagingen: what you are in, as a stack of cards, and the two ways to start one -->
                    <section class="card cm-dest reveal" aria-labelledby="home-challenges-title" data-home-challenges>
                        <button type="button" class="cm-head press" data-detail-open="challenges" data-challenge-from="home">
                            <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('target') ?></span>
                            <span class="cm-head__text">
                                <span class="cm-head__title" id="home-challenges-title"><?= e($copy['page']['title']) ?></span>
                                <span class="cm-head__sub"><?= e($challenges['summary']) ?></span>
                            </span>
                            <?= icon('chevron-right', 'cm-head__chev') ?>
                        </button>

                        <?php foreach ($challenges['invites'] as $invite): ?>
                            <?= ch_invite($invite, $copy['review']['see']) ?>
                        <?php endforeach; ?>

                        <?php if ($challenges['running'] !== []): ?>
                            <?php $front = $challenges['running'][0]; $behind = min(2, count($challenges['running']) - 1); ?>
                            <div class="cm-fan" style="--behind: <?= $behind ?>">
                                <?php for ($n = $behind; $n >= 1; $n--): ?>
                                    <span class="cm-fan__layer" style="--n: <?= $n ?>" aria-hidden="true"></span>
                                <?php endfor; ?>
                                <?= ch_front($front) ?>
                            </div>
                        <?php else: ?>
                            <div class="cm-none">
                                <?= ch_target_art() ?>
                                <p class="cm-none__title"><?= e($copy['page']['empty_title']) ?></p>
                            </div>
                        <?php endif; ?>

                        <?= ch_actions($copy['page']) ?>
                    </section>
                <?php endif; ?>

            </div>
        </main>
    </div>

</section>
