<?php
/**
 * One combined insight (docs/CORRELATIONS.md), over the Inzichten page:
 *
 *   Wat Ownify zag        the label and what it means, the sentence, and
 *                         that it is a pattern in the person's own data, not
 *                         a cause; Chart A (D2): the two day groups as two
 *                         rows with the existing meter bar, from zero
 *   In cijfers            the difference and its 90% interval, the
 *                         correlation, the chance and the corrected chance,
 *                         the days per group, the period
 *   Uit onderzoek blijkt  the approved research for this link at the
 *                         person's age, each with "Bekijk onderzoek" — or
 *                         that this comes from their own data only
 *   Hoe Ownify dit berekent  the method in four sentences, and its sources
 *
 * Built from Instellingen' pieces (page-intro, settings-block, the card with
 * its states and rows, the note, the glass chip) and the Scorekompas's rows
 * with their meter: no new kind of page.
 */
declare(strict_types=1);

$d      = $data['insight_detail'];
$labels = $data['correlations']['labels'];
$id     = $d['id'];
?>
<article class="detail settings-detail insights-detail" data-detail="<?= e($id) ?>" data-detail-parent="insights" data-accent="<?= e($d['accent']) ?>"
         aria-label="<?= e($d['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close aria-label="<?= e(t('Terug naar Inzichten')) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($labels['back']) ?></span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($d['title']) ?></h1>
                    <p class="page-intro__lede"><?= e($d['body']) ?></p>
                </header>

                <section class="settings-block reveal" aria-labelledby="<?= e($id) ?>-seen">
                    <h2 class="settings-eyebrow" id="<?= e($id) ?>-seen"><?= e($labels['seen']) ?></h2>
                    <div class="card settings-card">
                        <div class="settings-state">
                            <span class="settings-state__head">
                                <span class="glass glass--chip research-detail__strength"><?= e($d['tier']['label']) ?></span>
                            </span>
                            <span class="settings-state__note"><?= e($d['tier']['means']) ?></span>
                            <?php if ($d['not_cause'] !== null): ?>
                                <span class="settings-state__note"><?= e($d['not_cause']) ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ($d['chart'] !== null): ?>
                            <div class="settings-state insight-chart">
                                <ul class="compass-rows" role="list">
                                    <?php foreach ($d['chart']['rows'] as $row): ?>
                                        <li class="compass-row is-filled">
                                            <span class="compass-row__top">
                                                <span class="compass-row__label"><?= e($row['label']) ?></span>
                                                <span class="compass-row__weight"><?= e($row['count']) ?></span>
                                                <span class="compass-row__value"><?= e($row['value']) ?></span>
                                            </span>
                                            <span class="meter compass-row__meter" aria-hidden="true">
                                                <span class="meter__fill" data-bar data-progress="<?= e((string) $row['fill']) ?>"></span>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <span class="settings-state__note"><?= e($d['chart']['note']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="settings-block reveal" aria-labelledby="<?= e($id) ?>-numbers">
                    <h2 class="settings-eyebrow" id="<?= e($id) ?>-numbers"><?= e($labels['numbers']) ?></h2>
                    <div class="card settings-card">
                        <?php foreach ($d['numbers'] as $number): ?>
                            <div class="settings-state">
                                <span class="settings-state__head">
                                    <span class="settings-state__label"><?= e($number['label']) ?></span>
                                    <span class="settings-state__value"><?= e($number['value']) ?></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="settings-block reveal" aria-labelledby="<?= e($id) ?>-research">
                    <h2 class="settings-eyebrow" id="<?= e($id) ?>-research"><?= e($labels['research']) ?></h2>
                    <?php if ($d['research'] !== []): ?>
                        <div class="card settings-card">
                            <?php foreach ($d['research'] as $item): ?>
                                <div class="settings-state">
                                    <span class="settings-state__note"><?= e($item['statement']) ?></span>
                                    <?php component('research-evidence', $data + ['evidence' => $item]); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($d['concordance'] !== null): ?>
                            <p class="settings-note">
                                <?= icon('info', 'settings-note__icon') ?>
                                <span><?= e($d['concordance']) ?></span>
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="settings-note">
                            <?= icon('info', 'settings-note__icon') ?>
                            <span><?= e((string) $d['own_data']) ?></span>
                        </p>
                    <?php endif; ?>
                </section>

                <section class="settings-block reveal" aria-labelledby="<?= e($id) ?>-method">
                    <h2 class="settings-eyebrow" id="<?= e($id) ?>-method"><?= e($labels['method']) ?></h2>
                    <div class="card settings-card">
                        <div class="settings-state">
                            <?php foreach ($d['method']['paragraphs'] as $paragraph): ?>
                                <span class="settings-state__note"><?= e($paragraph) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if ($d['method']['sources'] !== []): ?>
                        <h3 class="settings-eyebrow"><?= e($d['method']['sources_title']) ?></h3>
                        <div class="card settings-card">
                            <?php foreach ($d['method']['sources'] as $source): ?>
                                <a class="settings-row press" href="<?= e($source['href']) ?>" target="_blank" rel="noopener noreferrer">
                                    <span class="settings-row__mark" aria-hidden="true"><?= icon('chart') ?></span>
                                    <span class="settings-row__text">
                                        <span class="settings-row__label" lang="<?= e($source['language']) ?>"><?= e($source['title']) ?></span>
                                        <span class="settings-row__value"><?= e($source['citation']) ?></span>
                                    </span>
                                    <?= icon('chevron-right', 'settings-row__chevron') ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </div>
        </main>
    </div>
</article>
