<?php
/**
 * "Uit onderzoek blijkt" (docs/INSIGHTS.md, docs/RESEARCH.md): one approved
 * claim as Ownify uses it, opened over whatever page showed it
 * (data-detail-parent="*"). Short first — what Ownify says with it and how
 * strong that is — and then what the research found, for whom, its limits,
 * and the source itself with its PDF where that may be read.
 *
 * Built from Instellingen' own pieces (page-intro, settings-block, the card
 * with its states, the note, .btn), so it is no new kind of page.
 *
 * The PDF (assets/js/research.js):
 *   hosted        Ownify's own unmodified CC BY copy: read here, in a frame
 *                 inside the page, where the browser can show a PDF; downloaded
 *                 or opened by the browser where it cannot
 *   official_pdf  the publisher's PDF: read here only where the publisher
 *                 allows a page to show it (`embed`), else opened by the browser
 *   link          no PDF Ownify may show: the publication at its publisher
 */
declare(strict_types=1);

$detail = $data['research_detail'];
$copy   = $data['research']['copy'];
$source = $detail['source'];
$pdf    = $detail['pdf'];
$id     = 'research-' . $detail['id'];
$byline = implode(' · ', array_filter([$source['authors'] ?: $source['organisation'], $source['year']]));
?>
<article class="detail settings-detail research-detail" data-detail="<?= e($id) ?>" data-detail-parent="*" data-accent="health"
         aria-label="<?= e($copy['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close aria-label="<?= e(t('Terug')) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e(t('Terug')) ?></span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($copy['title']) ?></h1>
                    <p class="page-intro__lede"><?= e($detail['statement']) ?></p>
                </header>

                <?php /* How strong: the chip as a status bubble, never pressed, and what it means. */ ?>
                <section class="settings-block reveal" aria-labelledby="<?= e($id) ?>-evidence">
                    <h2 class="settings-eyebrow" id="<?= e($id) ?>-evidence"><?= e($copy['evidence']) ?></h2>
                    <div class="card settings-card">
                        <div class="settings-state">
                            <span class="settings-state__head">
                                <span class="glass glass--chip research-detail__strength"><?= e($detail['strength']['label']) ?></span>
                            </span>
                            <span class="settings-state__note"><?= e($detail['strength']['means']) ?></span>
                            <?php if (!empty($detail['strength']['why'])): ?>
                                <span class="settings-state__note"><?= e($detail['strength']['why']) ?></span>
                            <?php endif; ?>
                            <span class="settings-state__note"><?= e($detail['strength']['note']) ?></span>
                        </div>
                    </div>
                </section>

                <section class="settings-block reveal" aria-labelledby="<?= e($id) ?>-found">
                    <h2 class="settings-eyebrow" id="<?= e($id) ?>-found"><?= e($copy['found']) ?></h2>
                    <div class="card settings-card">
                        <div class="settings-state">
                            <span class="settings-state__note"><?= e($source['findings']) ?></span>
                        </div>
                    </div>
                </section>

                <section class="settings-block reveal" aria-labelledby="<?= e($id) ?>-who">
                    <h2 class="settings-eyebrow" id="<?= e($id) ?>-who"><?= e($copy['population']) ?></h2>
                    <div class="card settings-card">
                        <div class="settings-state">
                            <span class="settings-state__note"><?= e($detail['population']) ?></span>
                        </div>
                    </div>
                </section>

                <section class="settings-block reveal" aria-labelledby="<?= e($id) ?>-limits">
                    <h2 class="settings-eyebrow" id="<?= e($id) ?>-limits"><?= e($copy['limitations']) ?></h2>
                    <div class="card settings-card">
                        <?php foreach ($detail['limitations'] as $limitation): ?>
                            <div class="settings-state">
                                <span class="settings-state__note"><?= e($limitation) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="settings-block reveal" aria-labelledby="<?= e($id) ?>-source">
                    <h2 class="settings-eyebrow" id="<?= e($id) ?>-source"><?= e($copy['source']) ?></h2>
                    <div class="card settings-card">
                        <div class="settings-state">
                            <span class="settings-state__head">
                                <?php /* As published, in its own language. */ ?>
                                <span class="settings-state__label" lang="<?= e($source['language']) ?>" data-research-title><?= e($source['title']) ?></span>
                            </span>
                            <?php if ($byline !== ''): ?>
                                <span class="settings-state__note"><?= e($byline) ?></span>
                            <?php endif; ?>
                            <?php if ($source['authors'] && $source['organisation']): ?>
                                <span class="settings-state__note"><?= e($source['organisation']) ?></span>
                            <?php endif; ?>
                            <span class="settings-state__note"><?= e($source['citation']) ?></span>
                            <?php if (!empty($source['language_note'])): ?>
                                <span class="settings-state__note"><?= e($source['language_note']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="settings-state">
                            <span class="settings-state__head">
                                <span class="settings-state__label"><?= e($copy['kind_label']) ?></span>
                            </span>
                            <span class="settings-state__note"><?= e($source['kind']) ?></span>
                            <span class="settings-state__note"><?= e($source['verified']) ?></span>
                        </div>
                    </div>

                    <div class="research-detail__actions" data-research-pdf="<?= e($pdf['mode']) ?>">
                        <?php if ($pdf['mode'] !== 'link' && $pdf['href']): ?>
                            <?php /* A link the browser can always follow; research.js reads it
                                     inside the page instead, where that works. */ ?>
                            <a class="btn btn--primary press" href="<?= e($pdf['href']) ?>" target="_blank" rel="noopener noreferrer"
                               data-research-read="<?= $pdf['embed'] ? 'inline' : 'browser' ?>"
                               data-label-inline="<?= e($copy['read_pdf']) ?>" data-label-browser="<?= e($copy['open_pdf']) ?>"><?= e($pdf['embed'] ? $copy['read_pdf'] : $copy['open_pdf']) ?></a>
                            <?php if ($pdf['mode'] === 'hosted'): ?>
                                <a class="btn press" href="<?= e($pdf['href']) ?>" download><?= e($copy['download_pdf']) ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a class="btn press" href="<?= e($source['href']) ?>" target="_blank" rel="noopener noreferrer"><?= e($copy['open_source']) ?></a>
                    </div>

                    <p class="settings-note" data-research-note>
                        <?= icon('info', 'settings-note__icon') ?>
                        <span><?= e($pdf['note']) ?></span>
                    </p>
                    <p class="settings-note" data-research-offline hidden>
                        <?= icon('info', 'settings-note__icon') ?>
                        <span><?= e($copy['offline']) ?></span>
                    </p>

                    <?php if ($pdf['mode'] !== 'link'): ?>
                        <?php /* Filled by research.js on "Lees de pdf": nothing loads before. */ ?>
                        <div class="research-detail__frame" data-research-frame hidden></div>
                    <?php endif; ?>

                    <?php if (!empty($detail['retired'])): ?>
                        <p class="settings-note">
                            <?= icon('info', 'settings-note__icon') ?>
                            <span><?= e($copy['retired']) ?></span>
                        </p>
                    <?php endif; ?>
                </section>

            </div>
        </main>
    </div>

</article>
