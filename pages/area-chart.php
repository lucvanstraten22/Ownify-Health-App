<?php
/**
 * One of an area's charts on a page of its own (lib/area-charts.php): its
 * name and the chart, large, over 7 dagen, 30 dagen, 90 dagen and 1 jaar,
 * and under it "Over …": what the chart shows, in short points (40.1). Opened from its small chart on the area's page (Slaap,
 * Training), over it; back goes to that page.
 */
declare(strict_types=1);

$chart = $data['area_chart'];
$area  = (string) $data['chart_area'];
?>
<article class="detail area-page" data-detail="<?= e($area . '-' . $chart['id']) ?>" data-detail-parent="<?= e($area) ?>" data-accent="<?= e($area) ?>"
         aria-label="<?= e($chart['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="<?= e(tf('Terug naar %s', $chart['back'])) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($chart['back']) ?></span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">
                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($chart['title']) ?></h1>
                </header>

                <?php component('area-chart', $data + ['chart_mode' => 'full']); ?>

                <?php if ($chart['about'] !== null): ?>
                    <?php /* 40.1: what the chart shows, in Samenvatting onderzoek's short points. */ ?>
                    <section class="settings-block reveal" aria-labelledby="<?= e($area . '-' . $chart['id']) ?>-about">
                        <h2 class="settings-eyebrow" id="<?= e($area . '-' . $chart['id']) ?>-about"><?= e($chart['about']['title']) ?></h2>
                        <div class="card settings-card">
                            <div class="settings-state">
                                <ul class="research-detail__points">
                                    <?php foreach ($chart['about']['points'] as $point): ?>
                                        <li><?= e($point) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>

</article>
