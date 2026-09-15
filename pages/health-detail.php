<?php
/**
 * A health area in full.
 *
 * Three levels of information, in order of how much they matter:
 *   1  the score
 *   2  the handful of numbers that explain it
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
<article class="detail" data-detail="<?= e($area['id']) ?>" data-accent="<?= e($area['accent']) ?>"
         aria-label="<?= e($area['label']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="Terug naar Gezondheid">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label">Gezondheid</span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <section class="card card--hero reveal <?= state_class($score['value']) ?>"
                         aria-labelledby="detail-title-<?= e($area['id']) ?>">

                    <div class="score-ring" data-ring data-progress="<?= e((string) round($ratio, 4)) ?>"
                         role="img"
                         aria-label="<?= e($area['label']) ?>: <?= has_value($score['value'])
                             ? e((string) $score['value']) . ' van ' . e((string) $score['max'])
                             : 'nog geen gegevens' ?>">
                        <svg class="score-ring__svg" viewBox="0 0 160 160" aria-hidden="true">
                            <circle class="score-ring__track" cx="80" cy="80" r="68"/>
                            <circle class="score-ring__value" cx="80" cy="80" r="68" data-ring-value/>
                        </svg>

                        <div class="score-ring__center" aria-hidden="true">
                            <p class="score-ring__number" data-count-to="<?= has_value($score['value']) ? e((string) $score['value']) : '' ?>"><?= e(score_text($score['value'])) ?></p>
                            <p class="score-ring__scale"><?= has_value($score['value']) ? 'van ' . e((string) $score['max']) : 'Nog geen gegevens' ?></p>
                        </div>
                    </div>

                    <h1 class="score-ring__label" id="detail-title-<?= e($area['id']) ?>"><?= e($area['label']) ?></h1>
                    <p class="card__lede"><?= e($area['summary']) ?></p>

                    <?php if (!has_value($score['value'])): ?>
                        <p class="card__hint"><?= icon('lock', 'card__hint-icon') ?><?= e($area['empty']) ?></p>
                    <?php endif; ?>
                </section>

                <?php
                component('metric-tiles', $data + ['area' => $area, 'tiles' => $area['highlights']]);

                if (!empty($area['timeline'])) {
                    component('sleep-timeline', $data + ['area' => $area]);
                }

                foreach ($area['groups'] as $index => $group) {
                    component('metric-group', $data + [
                        'area'        => $area,
                        'group'       => $group,
                        'group_index' => $index,
                    ]);
                }

                component('health-trend', $data + ['trend_area' => $area['id']]);
                ?>

            </div>
        </main>
    </div>

</article>
