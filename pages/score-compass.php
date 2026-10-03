<?php
/**
 * Scorekompas — the Health Score explained, opened from its card on
 * Overzicht. A detail page like a health area's: the same layer, the same
 * way in and out.
 *
 * Four questions, in the order they are asked:
 *   1  what the score is made of     the categories and their components
 *   2  what is changing              30 days, in a line and in sentences
 *   3  how it compares with you      the score now and its earlier averages
 *   4  where the most room is        one component, and what goes with it
 *
 * Every number and sentence is the server's (includes/score-compass.php);
 * this only lays them out, exactly as the app does.
 */
declare(strict_types=1);

$compass = $data['compass'];
$score   = $compass['score'];
$overall = $data['scores']['overall'];
$ratio   = score_ratio($score['value'], $score['max']);

$parts       = $compass['composition'];
$trend       = $compass['trend'];
$comparison  = $compass['comparison'];
$opportunity = $compass['opportunity'];
$chart       = $trend['chart'];
?>
<article class="detail detail--compass" data-detail="score-compass" data-accent="health"
         aria-label="<?= e($compass['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="Terug naar <?= e($compass['back']) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($compass['back']) ?></span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <?php /* The score itself, as Overzicht shows it. */ ?>
                <section class="card card--hero reveal <?= state_class($score['value']) ?>" aria-labelledby="compass-title">
                    <div class="score-ring" data-ring data-progress="<?= e((string) round($ratio, 4)) ?>"
                         role="img"
                         aria-label="<?= e($overall['label']) ?>: <?= has_value($score['value'])
                             ? e((string) $score['value']) . ' van ' . e((string) $score['max'])
                             : 'nog geen gegevens' ?>">
                        <svg class="score-ring__svg" viewBox="0 0 160 160" aria-hidden="true">
                            <defs>
                                <linearGradient id="compassRingGradient" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%"   stop-color="var(--sleep)"/>
                                    <stop offset="55%"  stop-color="var(--training)"/>
                                    <stop offset="100%" stop-color="var(--nutrition)"/>
                                </linearGradient>
                            </defs>
                            <circle class="score-ring__track" cx="80" cy="80" r="68"/>
                            <circle class="score-ring__value" cx="80" cy="80" r="68" data-ring-value/>
                        </svg>

                        <div class="score-ring__center" aria-hidden="true">
                            <p class="score-ring__number" data-count-to="<?= has_value($score['value']) ? e((string) $score['value']) : '' ?>"><?= e(score_text($score['value'])) ?></p>
                            <p class="score-ring__scale"><?= has_value($score['value']) ? 'van ' . e((string) $score['max']) : e($overall['caption']) ?></p>
                        </div>
                    </div>

                    <h1 class="score-ring__label" id="compass-title"><?= e($compass['title']) ?></h1>
                    <?php if ($compass['direction'] !== null): ?>
                        <?php component('score-direction', ['direction' => $compass['direction']]); ?>
                    <?php endif; ?>
                    <p class="card__lede"><?= e($compass['lede']) ?></p>
                </section>

                <?php /* 1 — What the score is made of. */ ?>
                <section class="card reveal compass-card" aria-labelledby="compass-parts-title">
                    <div class="card__head card__head--compact">
                        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('rings') ?></span>
                        <h2 class="card__eyebrow" id="compass-parts-title"><?= e($parts['title']) ?></h2>
                    </div>

                    <p class="compass-note"><?= e($parts['note']) ?></p>

                    <div class="compass-cats">
                        <?php foreach ($parts['categories'] as $category): ?>
                            <?php component('compass-category', ['category' => $category]); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php /* 2 — What is changing. */ ?>
                <section class="card card--trend reveal compass-card <?= $chart['has_data'] ? 'is-filled' : 'is-empty' ?>" aria-labelledby="compass-trend-title">
                    <div class="card__head">
                        <div class="card__head-group">
                            <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('chart') ?></span>
                            <h2 class="card__eyebrow" id="compass-trend-title"><?= e($trend['title']) ?></h2>
                        </div>
                        <?php if ($trend['direction'] !== null): ?>
                            <span class="chip chip--quiet score-direction__chip" data-direction="<?= e($trend['direction']['key']) ?>">
                                <?= icon('arrow-up', 'score-direction__icon') ?><?= e($trend['direction']['label']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($trend['text'] !== []): ?>
                        <div class="compass-text">
                            <?php foreach ($trend['text'] as $sentence): ?>
                                <p><?= e($sentence) ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="chart" data-chart>
                        <div class="chart__range is-active" data-range="compass">
                            <svg class="chart__svg" viewBox="0 0 <?= (int) $chart['width'] ?> <?= (int) $chart['height'] ?>"
                                 preserveAspectRatio="none" role="img" aria-label="<?= e($trend['aria']) ?>">
                                <?php foreach ([0.25, 0.5, 0.75] as $line): ?>
                                    <line class="chart__grid" x1="0" x2="<?= (int) $chart['width'] ?>"
                                          y1="<?= round(12 + $line * ($chart['height'] - 24), 1) ?>"
                                          y2="<?= round(12 + $line * ($chart['height'] - 24), 1) ?>"/>
                                <?php endforeach; ?>

                                <g class="chart__series" data-accent="health">
                                    <?php foreach ($chart['area'] as $path): ?>
                                        <path class="chart__area" d="<?= e($path) ?>"/>
                                    <?php endforeach; ?>
                                    <?php foreach ($chart['line'] as $path): ?>
                                        <path class="chart__line" d="<?= e($path) ?>" data-draw/>
                                    <?php endforeach; ?>
                                    <?php foreach ($chart['dots'] as $dot): ?>
                                        <circle class="chart__dot" cx="<?= e((string) $dot[0]) ?>" cy="<?= e((string) $dot[1]) ?>" r="2.5"/>
                                    <?php endforeach; ?>
                                </g>
                            </svg>

                            <ul class="chart__axis" role="list">
                                <?php
                                $ticks = count($trend['axis']);
                                foreach ($trend['axis'] as $tick => $label):
                                    $left = $ticks > 1 ? ($tick / ($ticks - 1)) * 100 : 50;
                                    ?>
                                    <li class="chart__tick" style="left: <?= e((string) round($left, 2)) ?>%;"><?= e($label) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <?php if (!$chart['has_data']): ?>
                            <p class="chart__empty"><?= e($trend['empty']) ?></p>
                        <?php endif; ?>
                    </div>
                </section>

                <?php /* 3 — Compared with yourself, and nobody else. */ ?>
                <section class="card reveal compass-card" aria-labelledby="compass-self-title">
                    <div class="card__head card__head--compact">
                        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('pulse') ?></span>
                        <h2 class="card__eyebrow" id="compass-self-title"><?= e($comparison['title']) ?></h2>
                    </div>

                    <p class="compass-note"><?= e($comparison['note']) ?></p>

                    <div class="metric-rows compass-compare">
                        <?php foreach ($comparison['rows'] as $row): ?>
                            <div class="metric-row <?= $row['value'] === null ? 'is-empty' : '' ?>">
                                <span class="compass-compare__label">
                                    <span class="metric-row__label"><?= e($row['label']) ?></span>
                                    <span class="compass-compare__note"><?= e($row['note']) ?></span>
                                </span>
                                <span class="compass-score" data-score="<?= e((string) ($row['band'] ?? '')) ?>">
                                    <span class="compass-score__dot" aria-hidden="true"></span><?= e(score_text($row['value'])) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($comparison['delta'] !== null): ?>
                        <p class="compass-delta"><?= e($comparison['delta']['text']) ?></p>
                    <?php endif; ?>
                </section>

                <?php /* 4 — Where the most room is. */ ?>
                <section class="card reveal compass-card compass-chance <?= $opportunity['state'] === 'filled' ? 'is-filled' : 'is-empty' ?>"
                         aria-labelledby="compass-chance-title">
                    <div class="card__head card__head--compact">
                        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('sparkle') ?></span>
                        <h2 class="card__eyebrow" id="compass-chance-title"><?= e($opportunity['title']) ?></h2>
                    </div>

                    <?php if ($opportunity['state'] === 'filled'): ?>
                        <div class="compass-cat__head compass-chance__head" data-accent="<?= e($opportunity['accent']) ?>">
                            <span class="icon-tile icon-tile--solid" aria-hidden="true"><?= icon_solid($opportunity['icon']) ?></span>
                            <span class="compass-cat__text">
                                <span class="compass-cat__label"><?= e($opportunity['name']) ?></span>
                                <span class="compass-cat__meta"><?= e($opportunity['label']) ?></span>
                            </span>
                            <span class="compass-score" data-score="<?= e((string) ($opportunity['band'] ?? '')) ?>">
                                <span class="compass-score__dot" aria-hidden="true"></span><?= e(score_text($opportunity['value'])) ?>
                            </span>
                        </div>
                        <div class="compass-text">
                            <p><?= e($opportunity['fact']) ?></p>
                            <p><?= e($opportunity['relation']) ?></p>
                        </div>
                        <p class="card__hint card__hint--plain"><?= e($opportunity['gain_text']) ?></p>
                    <?php else: ?>
                        <p class="compass-empty"><?= e($opportunity['empty']) ?></p>
                    <?php endif; ?>
                </section>

                <p class="disclaimer reveal"><?= e($compass['footnote']) ?></p>

            </div>
        </main>
    </div>

</article>
