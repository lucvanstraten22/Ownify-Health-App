<?php
/**
 * One settings screen, built from its blocks.
 *
 * Every screen in Instellingen goes through this one template: the config
 * says which blocks a screen has and the template knows how to draw each
 * kind. Adding a screen is config, not another file.
 *
 *   identity      avatar and username
 *   fields        profile fields, each with its own edit behaviour
 *   integrations  the health sources, expandable in place
 *   choice        pick one of several options
 *   states        read-only facts: label, value, one line of explanation
 *   toggles       switches, off and disabled while the feature does not exist
 *   rows          plain label/value information
 *   note          one framed line of explanation
 */
declare(strict_types=1);

$settings = $data['settings'];
$page     = $data['settings_page'];
$id       = $data['settings_page_id'];
$profile  = $settings['profile'];

/* Any screen offering a choice says once, at the foot, that it is not saved. */
$hasChoice = false;
foreach ($page['blocks'] as $block) {
    if ($block['type'] === 'choice') { $hasChoice = true; }
}
?>
<article class="detail settings-detail" data-detail="settings-<?= e($id) ?>" data-accent="health"
         aria-label="<?= e($page['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="Terug naar Instellingen">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label">Instellingen</span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($page['title']) ?></h1>
                    <?php if (!empty($page['lede'])): ?>
                        <p class="page-intro__lede"><?= e($page['lede']) ?></p>
                    <?php endif; ?>
                </header>

                <?php foreach ($page['blocks'] as $n => $block):
                    $blockId = 'settings-' . $id . '-' . $n;
                    ?>

                    <?php if ($block['type'] === 'identity'): ?>

                        <section class="card settings-hero reveal">
                            <span class="settings-hero__avatar" aria-hidden="true">
                                <?php if (!empty($profile['avatar'])): ?>
                                    <img src="<?= e($profile['avatar']) ?>" alt="">
                                <?php else: ?>
                                    <?= icon('user') ?>
                                <?php endif; ?>
                            </span>
                            <p class="settings-hero__name">
                                <?= e($profile['username'] ?? 'Niet ingelogd') ?>
                            </p>
                            <p class="settings-hero__meta">
                                <?= e(trim(((string) ($profile['first_name'] ?? '')) . ' ' . ((string) ($profile['last_name'] ?? ''))) ?: 'Naam nog niet ingevuld') ?>
                            </p>
                        </section>

                    <?php elseif ($block['type'] === 'fields'): ?>

                        <section class="settings-block reveal" aria-labelledby="<?= e($blockId) ?>">
                            <h2 class="settings-eyebrow" id="<?= e($blockId) ?>"><?= e($block['title']) ?></h2>
                            <?php if (!empty($block['lede'])): ?>
                                <p class="settings-block__lede"><?= e($block['lede']) ?></p>
                            <?php endif; ?>

                            <div class="card settings-card">
                                <?php foreach ($block['fields'] as $field): ?>
                                    <?php component('settings-field', $data + ['field' => $field]); ?>
                                <?php endforeach; ?>
                            </div>
                        </section>

                    <?php elseif ($block['type'] === 'integrations'): ?>

                        <section class="settings-block reveal" aria-labelledby="<?= e($blockId) ?>">
                            <h2 class="settings-eyebrow" id="<?= e($blockId) ?>"><?= e($block['title']) ?></h2>

                            <div class="settings-integrations">
                                <?php foreach ($settings['integrations'] as $integration): ?>
                                    <?php component('settings-integration', $data + ['integration' => $integration]); ?>
                                <?php endforeach; ?>
                            </div>
                        </section>

                    <?php elseif ($block['type'] === 'choice'): ?>

                        <section class="settings-block reveal" aria-labelledby="<?= e($blockId) ?>">
                            <h2 class="settings-eyebrow" id="<?= e($blockId) ?>"><?= e($block['title']) ?></h2>

                            <div class="card settings-card" role="radiogroup" aria-labelledby="<?= e($blockId) ?>">
                                <?php foreach ($block['options'] as $option):
                                    $isOn  = $option['key'] === $block['selected'];
                                    $isOff = !empty($option['disabled']);
                                    ?>
                                    <button type="button"
                                            class="settings-option press<?= $isOn ? ' is-selected' : '' ?>"
                                            role="radio" aria-checked="<?= $isOn ? 'true' : 'false' ?>"
                                            data-choice="<?= e($block['name']) ?>"
                                            data-choice-key="<?= e($option['key']) ?>"
                                            <?= $isOff ? 'disabled aria-disabled="true"' : '' ?>>

                                        <span class="settings-option__text">
                                            <span class="settings-option__label"><?= e($option['label']) ?></span>
                                            <?php if (!empty($option['note'])): ?>
                                                <span class="settings-option__note"><?= e($option['note']) ?></span>
                                            <?php endif; ?>
                                        </span>

                                        <span class="settings-option__mark" aria-hidden="true"><?= icon('check') ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </section>

                    <?php elseif ($block['type'] === 'states'): ?>

                        <section class="settings-block reveal" aria-labelledby="<?= e($blockId) ?>">
                            <h2 class="settings-eyebrow" id="<?= e($blockId) ?>"><?= e($block['title']) ?></h2>

                            <div class="card settings-card">
                                <?php foreach ($block['items'] as $item): ?>
                                    <div class="settings-state<?= $item['value'] === null ? ' is-empty' : '' ?>">
                                        <span class="settings-state__head">
                                            <span class="settings-state__label"><?= e($item['label']) ?></span>
                                            <span class="settings-state__value"><?= e($item['value'] ?? '—') ?></span>
                                        </span>
                                        <?php if (!empty($item['note'])): ?>
                                            <span class="settings-state__note"><?= e($item['note']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                    <?php elseif ($block['type'] === 'toggles'): ?>

                        <section class="settings-block reveal" aria-labelledby="<?= e($blockId) ?>">
                            <h2 class="settings-eyebrow" id="<?= e($blockId) ?>"><?= e($block['title']) ?></h2>
                            <?php if (!empty($block['lede'])): ?>
                                <p class="settings-block__lede"><?= e($block['lede']) ?></p>
                            <?php endif; ?>

                            <div class="card settings-card">
                                <?php foreach ($block['items'] as $item): ?>
                                    <?php /* Disabled on purpose: nothing behind these exists yet, and a
                                              switch that moves but changes nothing is a lie. */ ?>
                                    <button type="button" class="settings-toggle" disabled aria-disabled="true"
                                            role="switch" aria-checked="<?= !empty($item['on']) ? 'true' : 'false' ?>">
                                        <span class="settings-toggle__text">
                                            <span class="settings-toggle__label"><?= e($item['label']) ?></span>
                                            <?php if (!empty($item['note'])): ?>
                                                <span class="settings-toggle__note"><?= e($item['note']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="switch<?= !empty($item['on']) ? ' is-on' : '' ?>" aria-hidden="true">
                                            <span class="switch__knob"></span>
                                        </span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </section>

                    <?php elseif ($block['type'] === 'rows'): ?>

                        <section class="settings-block reveal" aria-labelledby="<?= e($blockId) ?>">
                            <h2 class="settings-eyebrow" id="<?= e($blockId) ?>"><?= e($block['title']) ?></h2>

                            <div class="card">
                                <div class="metric-rows">
                                    <?php foreach ($block['items'] as $item): ?>
                                        <div class="metric-row">
                                            <span class="metric-row__label"><?= e($item['label']) ?></span>
                                            <span class="metric-row__value"><?= e($item['value']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </section>

                    <?php elseif ($block['type'] === 'note'): ?>

                        <p class="settings-note reveal">
                            <?= icon($block['icon'], 'settings-note__icon') ?>
                            <span><?= e($block['text']) ?></span>
                        </p>

                    <?php endif; ?>

                <?php endforeach; ?>

                <?php if ($hasChoice): ?>
                    <p class="disclaimer"><?= e($settings['not_saved']) ?></p>
                <?php endif; ?>

            </div>
        </main>
    </div>

</article>
