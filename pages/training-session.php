<?php
/**
 * One training session on a page of its own (lib/hydrate-training.php,
 * docs/TRAINING.md), opened from Training's list over Training's page:
 * its kind, day and times, its heart rate minute by minute — its zones over
 * it — and every figure recorded for it. Only what the session has: no
 * figure is made up, none shown empty.
 */
declare(strict_types=1);

$session = $data['session'];
$heart   = $session['heart'];
$suffix  = $session['detail'];
?>
<article class="detail training-session-page" data-detail="<?= e($session['detail']) ?>" data-detail-parent="training" data-accent="training"
         aria-label="<?= e($session['title'] . ', ' . $session['date']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="Terug naar <?= e($session['back']) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($session['back']) ?></span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">
                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($session['title']) ?></h1>
                    <p class="page-intro__lede"><?= e($session['date']) ?> · <span class="training-session-page__time"><?= e($session['time']) ?></span></p>
                </header>

                <section class="card card--trend compass-history health-history heart-chart heart-chart--session reveal <?= $heart['has_data'] ? 'is-filled' : 'is-empty' ?>"
                         data-health-history data-accent="training" aria-labelledby="<?= e($suffix) ?>-title">
                    <div class="card__head">
                        <div class="card__head-group">
                            <span class="icon-tile icon-tile--solid" aria-hidden="true"><?= icon_solid('pulse') ?></span>
                            <h2 class="card__eyebrow" id="<?= e($suffix) ?>-title"><?= e($heart['title']) ?></h2>
                        </div>
                    </div>
                    <div class="chart compass-history__chart heart-chart__chart" data-chart>
                        <div class="chart__range is-active" data-range="session"
                             data-x="<?= e((string) json_encode($heart['x'])) ?>"
                             data-y="<?= e((string) json_encode((object) ['heart' => $heart['lines'][0]['y'] ?? []])) ?>">
                            <?php component('heart-plot', $data + ['plot' => $heart, 'plot_id' => 's' . $session['id'], 'series' => 'Hartslag', 'suffix' => $suffix, 'empty' => $heart['empty']]); ?>
                        </div>
                    </div>
                    <?php if ($heart['has_data']): ?>
                        <?php component('heart-zones', ['zones' => $data['zones']]); ?>
                        <p class="compass-history__hint" id="<?= e($suffix) ?>-hint"><?= e($data['hint']) ?></p>
                        <p class="sr-only" aria-live="polite" data-reading-live></p>
                    <?php endif; ?>
                </section>

                <?php if ($session['stats'] !== []): ?>
                    <section class="card card--tiles reveal" aria-labelledby="<?= e($suffix) ?>-stats">
                        <h2 class="card__eyebrow" id="<?= e($suffix) ?>-stats"><?= e($session['stats_title']) ?></h2>
                        <ul class="metric-tiles" role="list">
                            <?php foreach ($session['stats'] as $stat): ?>
                                <li class="metric-tile is-filled">
                                    <p class="metric-tile__label"><?= e($stat['label']) ?></p>
                                    <p class="metric-tile__value">
                                        <span><?= e($stat['value']) ?></span>
                                        <?php if ($stat['unit'] !== ''): ?>
                                            <span class="metric-tile__unit"><?= e($stat['unit']) ?></span>
                                        <?php endif; ?>
                                    </p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>

</article>
