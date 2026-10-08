<?php
/**
 * One of Slaap's charts on a page of its own (docs/SLEEP.md): its name and
 * the chart, large, over 7 dagen, 30 dagen, 90 dagen and 1 jaar — nothing
 * else. Opened from its small chart on the Slaap page, over it; back goes
 * to Slaap.
 */
declare(strict_types=1);

$chart = $data['sleep_chart'];
?>
<article class="detail sleep-metric" data-detail="sleep-<?= e($chart['id']) ?>" data-detail-parent="sleep" data-accent="sleep"
         aria-label="<?= e($chart['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="Terug naar <?= e($chart['back']) ?>">
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

                <?php component('sleep-chart', $data + ['sleep_mode' => 'full']); ?>
            </div>
        </main>
    </div>

</article>
