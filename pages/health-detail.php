<?php
/**
 * A health area in full.
 *
 * Three levels of information, in order of how much they matter:
 *   1  the score
 *   2  the handful of numbers that explain it — for Slaap and Training,
 *      drawn (docs/SLEEP.md, docs/TRAINING.md)
 *   3  the long tail, grouped, with device-dependent groups marked
 *
 * Which metrics exist is entirely config; adding one later means adding a
 * line to config/health.php, not touching this template.
 */
declare(strict_types=1);

$area  = $data['area'];
$score = $area['score'];
$ratio = score_ratio($score['value'], $score['max']);
?>
<article class="detail detail--area" data-detail="<?= e($area['id']) ?>" data-accent="<?= e($area['accent']) ?>"
         aria-label="<?= e($area['label']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="<?= e(t('Terug naar Gezondheid')) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e(t('Gezondheid')) ?></span>
                </button>
                <?php /* The page's name, in the bar once the hero has scrolled away
                         (interactions.js); the heading below stays the one read out. */ ?>
                <span class="detail__title" aria-hidden="true"><?= e($area['label']) ?></span>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <section class="card card--hero reveal <?= state_class($score['value']) ?>"
                         aria-labelledby="detail-title-<?= e($area['id']) ?>">

                    <div class="score-ring" data-ring data-progress="<?= e((string) round($ratio, 4)) ?>"
                         role="img"
                         aria-label="<?= e($area['label']) ?>: <?= has_value($score['value'])
                             ? e(tf('%1$s van %2$s', (string) $score['value'], (string) $score['max']))
                             : e(t('nog geen gegevens')) ?>">
                        <?php /* The area's own colour, lighter where it starts — as the Overzicht's ring is the app's green. */ ?>
                        <svg class="score-ring__svg" viewBox="0 0 160 160" aria-hidden="true"
                             style="--ring-paint: url(#ringGradient-<?= e($area['id']) ?>)">
                            <defs>
                                <linearGradient id="ringGradient-<?= e($area['id']) ?>" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%"   style="stop-color: var(--accent-light)"/>
                                    <stop offset="100%" style="stop-color: var(--accent)"/>
                                </linearGradient>
                            </defs>
                            <circle class="score-ring__track" cx="80" cy="80" r="68"/>
                            <circle class="score-ring__value" cx="80" cy="80" r="68" data-ring-value/>
                        </svg>

                        <div class="score-ring__center" aria-hidden="true">
                            <p class="score-ring__number" data-count-to="<?= has_value($score['value']) ? e((string) $score['value']) : '' ?>"><?= e(score_text($score['value'])) ?></p>
                            <p class="score-ring__scale"><?= has_value($score['value']) ? e(tf('van %s', (string) $score['max'])) : e(t('Nog geen gegevens')) ?></p>
                        </div>
                    </div>

                    <h1 class="score-ring__label" id="detail-title-<?= e($area['id']) ?>"><?= e($area['label']) ?></h1>
                    <p class="card__lede"><?= e($area['summary']) ?></p>

                    <?php if (!has_value($score['value'])): ?>
                        <p class="card__hint"><?= icon('lock', 'card__hint-icon') ?><?= e($area['empty']) ?></p>
                    <?php endif; ?>
                </section>

                <?php
                /* Voeding only: the daily cijfer its score is built from. */
                if (!empty($area['rating']) && !isset($area['view'])) {
                    component('nutrition-rating', $data + ['area' => $area]);
                }

                /* An area drawn — Slaap (docs/SLEEP.md), Training
                   (docs/TRAINING.md): its own view instead of the numbers and
                   the bar of stages it shows; while a deploy is still landing
                   its files, those. */
                $view = isset($area['view']) && is_file(dirname(__DIR__) . '/components/' . $area['id'] . '-view.php')
                    && is_file(dirname(__DIR__) . '/components/area-chart.php') ? $area['view'] : null;
                if ($view !== null) {
                    component($area['id'] . '-view', $data + ['area' => $area, 'view' => $view]);
                } else {
                    component('metric-tiles', $data + ['area' => $area, 'tiles' => $area['highlights']]);

                    if (!empty($area['timeline'])) {
                        component('sleep-timeline', $data + ['area' => $area]);
                    }
                }

                /* The area's own insight (docs/INSIGHTS.md): the same stored one
                   Overzicht shows, and nothing when there is none. */
                if (isset($data['insights']['context']) && is_file(dirname(__DIR__) . '/components/research-evidence.php')) {
                    component('insights', $data + ['insights_slot' => $area['id']]);
                }

                foreach ($area['groups'] as $index => $group) {
                    component('metric-group', $data + [
                        'area'        => $area,
                        'group'       => $group,
                        'group_index' => $index,
                    ]);
                }

                /* Its own Verloop: the Gezondheid Verloop's chart, its one line
                   (docs/CHARTS.md); while a deploy is still landing its files,
                   the week and month. */
                if (isset($data['health']['history']['periods']) && is_file(dirname(__DIR__) . '/components/health-history.php')) {
                    component('health-history', $data + ['history_area' => $area['id']]);
                } else {
                    component('health-trend', $data + ['trend_area' => $area['id']]);
                }
                ?>

            </div>
        </main>
    </div>

</article>
