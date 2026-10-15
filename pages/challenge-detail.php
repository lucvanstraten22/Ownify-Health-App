<?php
/**
 * One challenge — over Uitdagingen, or over Community when its tile was
 * tapped there (the back pill says which; challenges.js).
 *
 *   1  what it is            kind and metric, name, period and what is left
 *   2  where it stands       Samen: the shared total, everybody's piece of
 *                            the bar and the pace. Wedstrijd: your place,
 *                            your total and the gap to the one ahead.
 *   3  the people            Wie draagt wat bij / Stand, and who is invited
 *   4  bringing someone in   the code to copy or share, and inviting friends
 *                            — while it runs
 *   5  the small print       what counts, who made it, and leaving
 *
 * Participants see each other's username, picture and total for this
 * challenge, and nothing else (docs/CHALLENGES.md).
 */
declare(strict_types=1);

$view   = $data['challenge'];
$copy   = $data['challenges']['copy'];
$detail = $copy['detail'];
$id     = (int) $view['id'];
$versus = $view['kind'] === 'versus';
?>
<article class="detail challenge-detail" data-detail="challenge-<?= $id ?>" data-detail-parent="challenges"
         data-challenge-detail="<?= $id ?>" data-accent="<?= e($view['accent']) ?>"
         aria-label="<?= e($view['name']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="<?= e(t('Terug naar Uitdagingen')) ?>"
                        data-back-list="<?= e($detail['back']) ?>" data-back-list-aria="<?= e(t('Terug naar Uitdagingen')) ?>"
                        data-back-home="<?= e(t('Community')) ?>" data-back-home-aria="<?= e(t('Terug naar Community')) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($detail['back']) ?></span>
                </button>
                <span class="detail__title" aria-hidden="true"><?= e($view['name']) ?></span>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <!-- 1 -->
                <header class="ch-dhead reveal">
                    <?= ch_kind($view['icon'], $view['kind_line']) ?>
                    <h1 class="page-intro__title ch-dhead__name<?= mb_strlen($view['name']) > 32 ? ' is-long' : '' ?>" data-hero-name><?= e($view['name']) ?></h1>
                    <p class="page-intro__lede"><?= e($view['subtitle']) ?></p>
                </header>

                <!-- 2 -->
                <section class="card ch-progress reveal" aria-label="<?= e($view['bar']['label']) ?>">
                    <div class="ch-big">
                        <span class="ch-big__fig"><?= e($view['big']['figure']) ?></span>
                        <span class="ch-big__of"><?= e($view['big']['of']) ?></span>
                    </div>
                    <?= ch_bar($view, true) ?>
                    <p class="ch-pace"><span class="ch-dot ch-dot--<?= e($view['big']['tone']) ?>" aria-hidden="true"></span><?= e($view['big']['line']) ?></p>
                </section>

                <!-- 3 -->
                <section class="card reveal" aria-labelledby="challenge-people-<?= $id ?>">
                    <div class="ch-eyebrow-row">
                        <h2 class="card__eyebrow" id="challenge-people-<?= $id ?>"><?= e($view['people_title']) ?></h2>
                        <span class="ch-eyebrow-row__sub"><?= e($view['count_text']) ?></span>
                    </div>
                    <ol class="ch-people" role="list">
                        <?php foreach ($view['people'] as $person): ?>
                            <?= ch_person($person, $versus) ?>
                        <?php endforeach; ?>
                    </ol>
                    <?php if ($view['pending'] !== null): ?>
                        <div class="ch-pending">
                            <span class="ch-faces" aria-hidden="true">
                                <?php foreach (array_slice($view['pending']['people'], 0, 4) as $person): ?>
                                    <?= ch_avatar($person, 0, true) ?>
                                <?php endforeach; ?>
                            </span>
                            <span><?= e($view['pending']['text']) ?></span>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- 4 -->
                <?php if ($view['code'] !== null): ?>
                    <section class="card ch-share reveal" aria-labelledby="challenge-share-<?= $id ?>">
                        <h2 class="card__eyebrow" id="challenge-share-<?= $id ?>"><?= e($detail['share_title']) ?></h2>
                        <div class="ch-share__row">
                            <span class="ch-share__code" aria-label="<?= e(implode(' ', str_split($view['code']))) ?>"><?= e($view['code']) ?></span>
                            <button type="button" class="btn press ch-icon-btn" data-challenge-copy="<?= e($view['code']) ?>"
                                    aria-label="<?= e($detail['copy']) ?>"><?= icon('copy') ?></button>
                            <button type="button" class="btn press ch-icon-btn" data-challenge-share="<?= e($view['code']) ?>"
                                    data-share-text="<?= e((string) $view['share_text']) ?>"
                                    aria-label="<?= e($detail['share']) ?>"><?= icon('share') ?></button>
                        </div>
                        <button type="button" class="settings-row press ch-row" data-challenge-invite="<?= $id ?>">
                            <span class="settings-row__mark" aria-hidden="true"><?= icon('user-plus') ?></span>
                            <span class="settings-row__text">
                                <span class="settings-row__label"><?= e($detail['invite']) ?></span>
                                <span class="settings-row__value"><?= e($detail['invite_hint']) ?></span>
                            </span>
                            <?= icon('chevron-right', 'settings-row__chevron') ?>
                        </button>
                    </section>
                <?php endif; ?>

                <!-- 5 -->
                <p class="ch-note reveal"><?= e($view['note']) ?></p>

                <?php if ($view['can_leave']): ?>
                    <button type="button" class="btn btn--link press ch-leave reveal" data-challenge-leave="<?= $id ?>">
                        <?= e($detail['leave']) ?>
                    </button>
                <?php endif; ?>

                <script type="application/json" data-challenge-invitable><?=
                    json_encode($view['invitable'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                ?></script>
            </div>
        </main>

    </div>

</article>
