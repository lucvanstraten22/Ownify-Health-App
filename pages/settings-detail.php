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
 *   signin        the ways into this account, and linking Google
 *   integrations  the health sources, expandable in place
 *   choice        pick one of several options
 *   states        read-only facts: label, value, one line of explanation
 *   toggles       switches: one with a `key` saves (api/profile/privacy.php);
 *                 the rest are off and disabled while the feature does not exist
 *   actions       a button that does one thing after "are you sure?" in place
 *   rows          plain label/value information
 *   note          one framed line of explanation
 *   section       a heading over the blocks after it, with its icon and one
 *                 line (Voorkeuren: Eenheden, Eerste dag van de week and
 *                 Toegankelijkheid on one screen)
 */
declare(strict_types=1);

$settings = $data['settings'];
$page     = $data['settings_page'];
$id       = $data['settings_page_id'];
$profile  = $settings['profile'];

/* Any screen offering a choice says once, at the foot, that it is not saved —
   except for a choice that is (`saves`: the theme). */
$hasChoice = false;
foreach ($page['blocks'] as $block) {
    if ($block['type'] === 'choice' && empty($block['saves'])) { $hasChoice = true; }
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

                    <?php elseif ($block['type'] === 'signin'):
                        /* Rows in the same shape as the profile fields. The
                           Google row is the only live one: a button while
                           Google can be linked and is not, a fact once it is. */
                        $auth       = $data['auth'];
                        $identities = [];
                        foreach ($auth['identities'] ?? [] as $identity) {
                            $identities[$identity['provider']] = $identity;
                        }
                        $email      = $identities['email'] ?? null;
                        $google     = $identities['google'] ?? null;
                        $canLink    = !empty($auth['providers']['google']);
                        $flash      = $auth['flash'] ?? null;
                        $flash      = ($flash !== null && $flash['target'] === 'settings-account') ? $flash : null;
                        ?>

                        <section class="settings-block reveal" aria-labelledby="<?= e($blockId) ?>" data-signin>
                            <h2 class="settings-eyebrow" id="<?= e($blockId) ?>"><?= e($block['title']) ?></h2>

                            <?php if ($flash !== null): ?>
                                <p class="settings-note settings-note--flash" data-signin-flash
                                   role="<?= $flash['tone'] === 'error' ? 'alert' : 'status' ?>">
                                    <?= icon($flash['tone'] === 'ok' ? 'check' : 'info', 'settings-note__icon') ?>
                                    <span><?= e($flash['message']) ?></span>
                                </p>
                            <?php endif; ?>

                            <p class="settings-note settings-note--flash" data-signin-error role="alert" hidden></p>

                            <div class="card settings-card">
                                <?php if (empty($auth['signed_in'])): ?>

                                    <div class="settings-field is-empty">
                                        <span class="settings-field__text">
                                            <span class="settings-field__label">Niet ingelogd</span>
                                        </span>
                                        <span class="settings-field__value">—</span>
                                    </div>

                                <?php else: ?>

                                    <div class="settings-field <?= $email !== null ? 'is-filled' : 'is-empty' ?>">
                                        <span class="settings-field__text">
                                            <span class="settings-field__label">E-mail en wachtwoord</span>
                                            <span class="settings-field__note">
                                                <?= e($email !== null ? 'Inloggen met je gebruikersnaam of e-mailadres' : 'Je logt in met Google') ?>
                                            </span>
                                        </span>
                                        <span class="settings-field__value"><?= e($email['email'] ?? 'Niet ingesteld') ?></span>
                                    </div>

                                    <?php if ($google !== null): ?>

                                        <div class="settings-field is-filled">
                                            <span class="settings-field__text">
                                                <span class="settings-field__label">Google</span>
                                                <span class="settings-field__note">Gekoppeld — je kunt ook met Google inloggen</span>
                                            </span>
                                            <span class="settings-field__value"><?= e($google['email'] ?? 'Gekoppeld') ?></span>
                                            <?= icon('check', 'settings-field__mark') ?>
                                        </div>

                                    <?php elseif ($canLink): ?>

                                        <button type="button" class="settings-field press is-editable is-empty" data-google-link>
                                            <span class="settings-field__text">
                                                <span class="settings-field__label">Google</span>
                                                <span class="settings-field__note">Koppel Google om daarmee in te loggen</span>
                                            </span>
                                            <span class="settings-field__value">Koppel Google</span>
                                            <?= icon('chevron-right', 'settings-field__mark') ?>
                                        </button>

                                    <?php else: ?>

                                        <div class="settings-field is-empty">
                                            <span class="settings-field__text">
                                                <span class="settings-field__label">Google</span>
                                            </span>
                                            <span class="settings-field__value">Nog niet beschikbaar</span>
                                        </div>

                                    <?php endif; ?>

                                <?php endif; ?>
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
                                    <?php if (!empty($item['key'])): ?>
                                    <?php /* A switch that saves: the account's own value, and the note
                                              that goes with it (settings_prepare()); settings.js saves it. */ ?>
                                    <button type="button" class="settings-toggle settings-toggle--live" role="switch"
                                            data-setting-toggle="<?= e($item['key']) ?>"
                                            aria-checked="<?= !empty($item['on']) ? 'true' : 'false' ?>"
                                            data-note-on="<?= e($item['note_on'] ?? '') ?>"
                                            data-note-off="<?= e($item['note_off'] ?? '') ?>">
                                        <span class="settings-toggle__text">
                                            <span class="settings-toggle__label"><?= e($item['label']) ?></span>
                                            <span class="settings-toggle__note" data-setting-toggle-note><?= e($item['note'] ?? '') ?></span>
                                        </span>
                                        <span class="switch<?= !empty($item['on']) ? ' is-on' : '' ?>" aria-hidden="true">
                                            <span class="switch__knob"></span>
                                        </span>
                                    </button>
                                    <?php else: ?>
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
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </section>

                    <?php elseif ($block['type'] === 'actions'): ?>

                        <?php /* One thing to do, asked about first, in place (settings.js). */ ?>
                        <section class="settings-block reveal" aria-labelledby="<?= e($blockId) ?>">
                            <h2 class="settings-eyebrow" id="<?= e($blockId) ?>"><?= e($block['title']) ?></h2>

                            <div class="card settings-card">
                                <?php foreach ($block['items'] as $item): ?>
                                    <div class="settings-action" data-setting-action="<?= e($item['key']) ?>"
                                         data-endpoint="<?= e($item['endpoint']) ?>"
                                         data-fields="<?= e(json_encode($item['fields'] ?? new stdClass())) ?>">
                                        <div class="settings-action__row">
                                            <span class="settings-toggle__text">
                                                <span class="settings-toggle__label"><?= e($item['label']) ?></span>
                                                <span class="settings-toggle__note" data-setting-action-note role="status"><?= e($item['note'] ?? '') ?></span>
                                            </span>
                                            <button type="button" class="btn press settings-action__go<?= !empty($item['danger']) ? ' settings-action__go--danger' : '' ?>"
                                                    data-setting-action-go><?= e($item['confirm']) ?></button>
                                        </div>
                                        <div class="settings-action__confirm" data-setting-action-confirm hidden>
                                            <p class="settings-action__question"><?= e($item['question']) ?></p>
                                            <div class="settings-action__buttons">
                                                <button type="button" class="btn press" data-setting-action-no><?= e($item['cancel']) ?></button>
                                                <button type="button" class="btn press settings-action__yes" data-setting-action-yes><?= e($item['confirm']) ?></button>
                                            </div>
                                        </div>
                                    </div>
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

                    <?php elseif ($block['type'] === 'note' && !empty($block['title'])): ?>

                        <section class="settings-block reveal" aria-labelledby="<?= e($blockId) ?>">
                            <h2 class="settings-eyebrow" id="<?= e($blockId) ?>"><?= e($block['title']) ?></h2>

                            <p class="settings-note">
                                <?= icon($block['icon'], 'settings-note__icon') ?>
                                <span><?= e($block['text']) ?></span>
                            </p>
                        </section>

                    <?php elseif ($block['type'] === 'section'): ?>

                        <header class="settings-section reveal">
                            <span class="icon-tile" aria-hidden="true"><?= icon($block['icon']) ?></span>
                            <span class="settings-section__text">
                                <h2 class="settings-section__title"><?= e($block['title']) ?></h2>
                                <?php if (!empty($block['lede'])): ?>
                                    <span class="settings-section__lede"><?= e($block['lede']) ?></span>
                                <?php endif; ?>
                            </span>
                        </header>

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
