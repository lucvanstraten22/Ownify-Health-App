<?php
/**
 * One of Over de app's documents (39.0, config/legal.php, docs/LEGAL.md):
 * the Privacyverklaring, the Voorwaarden or the Licenties, on a page of its
 * own over Over de app.
 *
 * Built from the pieces Instellingen and "Uit onderzoek blijkt" already
 * use: the back pill, the page intro, a block per section (its eyebrow, the
 * card with its text, Samenvatting onderzoek's points), the text link, and
 * Beperkingen's in-place toggle for a licence's full text. No new kind of
 * page.
 */
declare(strict_types=1);

$legal = $data['legal'];
$doc   = $data['legal_document'];
$id    = 'legal-' . $doc['id'];
?>
<article class="detail settings-detail legal-detail" data-detail="<?= e($id) ?>" data-detail-parent="settings-about" data-accent="health"
         aria-label="<?= e($doc['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="<?= e(tf('Terug naar %s', $legal['back'])) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($legal['back']) ?></span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($doc['title']) ?></h1>
                    <p class="page-intro__lede"><?= e($doc['lede']) ?></p>
                </header>

                <?php foreach ($doc['sections'] as $n => $section): ?>
                    <?php $sectionId = $id . '-' . $n; ?>
                    <section class="settings-block reveal" aria-labelledby="<?= e($sectionId) ?>">
                        <h2 class="settings-eyebrow" id="<?= e($sectionId) ?>"><?= e($section['title']) ?></h2>
                        <div class="card settings-card">
                            <div class="settings-state"<?= $section['licence'] !== null ? ' data-research-limits' : '' ?>>
                                <?php foreach ($section['text'] as $paragraph): ?>
                                    <span class="settings-state__note"><?= e($paragraph) ?></span>
                                <?php endforeach; ?>
                                <?php if ($section['points'] !== []): ?>
                                    <ul class="research-detail__points">
                                        <?php foreach ($section['points'] as $point): ?>
                                            <li><?= e($point) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if ($section['link'] !== null): ?>
                                    <a class="text-link press legal-detail__link" href="<?= e($section['link']['href']) ?>" target="_blank" rel="noopener noreferrer"><?= e($section['link']['label']) ?><?= icon('chevron-right', 'text-link__icon') ?></a>
                                <?php endif; ?>
                                <?php if ($section['licence'] !== null): ?>
                                    <button type="button" class="text-link press legal-detail__link" data-research-limits-toggle
                                            aria-expanded="false" aria-controls="<?= e($sectionId) ?>-text"><?= e($legal['licence_text']) ?><?= icon('chevron-right', 'text-link__icon') ?></button>
                                    <div class="research-detail__more" id="<?= e($sectionId) ?>-text">
                                        <div>
                                            <p class="settings-state__note legal-detail__licence" lang="en"><?= e($section['licence']) ?></p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>

            </div>
        </main>
    </div>
</article>
