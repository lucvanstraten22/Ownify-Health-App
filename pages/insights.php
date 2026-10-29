<?php
/**
 * Inzichten (docs/CORRELATIONS.md): every combined insight, opened from
 * Overzicht's Nuttige inzichten. Grouped by label, strongest first (R6):
 * Duidelijk patroon, Patroon, Mogelijk patroon (only here, with how many
 * could be chance), Geen patroon, Nog onduidelijk. Each row opens the
 * insight's own page (pages/insight-detail.php).
 *
 * Built from Instellingen' own pieces (page-intro, settings-group, the row,
 * the note), as the research detail is: no new kind of page.
 */
declare(strict_types=1);

$page = $data['correlations']['page'];
$mark = static fn (string $accent): string => match ($accent) {
    'sleep'     => 'moon',
    'nutrition' => 'utensils',
    default     => 'bolt',
};
?>
<article class="detail settings-detail insights-detail" data-detail="insights" data-accent="health"
         aria-label="<?= e($page['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close aria-label="<?= e(t('Terug naar Overzicht')) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($page['back']) ?></span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($page['title']) ?></h1>
                    <p class="page-intro__lede"><?= e($page['lede']) ?></p>
                </header>

                <?php if ($page['groups'] === []): ?>
                    <p class="settings-note reveal">
                        <?= icon('info', 'settings-note__icon') ?>
                        <span><?= e($page['empty']) ?></span>
                    </p>
                <?php endif; ?>

                <?php foreach ($page['groups'] as $group): ?>
                    <section class="settings-group reveal" aria-labelledby="insights-group-<?= e($group['tier']) ?>">
                        <h2 class="settings-eyebrow" id="insights-group-<?= e($group['tier']) ?>"><?= e($group['label']) ?></h2>

                        <div class="card settings-card">
                            <?php foreach ($group['rows'] as $row): ?>
                                <button type="button" class="settings-row press" data-detail-open="<?= e($row['detail']) ?>"
                                        aria-label="<?= e($row['title'] . ' — ' . $row['body']) ?>">
                                    <span class="settings-row__mark" aria-hidden="true"><?= icon($mark($row['accent'])) ?></span>
                                    <span class="settings-row__text">
                                        <span class="settings-row__label"><?= e($row['title']) ?></span>
                                        <span class="settings-row__value"><?= e($row['body']) ?></span>
                                    </span>
                                    <?= icon('chevron-right', 'settings-row__chevron') ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <p class="settings-note">
                            <?= icon('info', 'settings-note__icon') ?>
                            <span><?= e($group['means']) ?></span>
                        </p>
                    </section>
                <?php endforeach; ?>

            </div>
        </main>
    </div>
</article>
