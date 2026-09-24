<?php
/**
 * Instellingen — the overview.
 *
 * Categories, not settings. Every row opens a detail screen through the same
 * layer Gezondheid and Doelen use, so the whole page is a short scan and
 * nothing here needs to be read twice.
 *
 * Signing out and deleting an account sit below the groups and deliberately
 * outside them: neither is a preference.
 */
declare(strict_types=1);

$settings = $data['settings'];
$auth     = $data['auth'];
$actions  = $settings['actions'];
$profile  = $settings['profile'];
?>
<section class="screen page" data-page="settings" aria-label="Instellingen"
         <?= empty($data['page_active']) ? 'aria-hidden="true" inert' : '' ?>>

    <div class="screen__scroll" data-scroller>
        <main class="app__main">
            <div class="shell stack" data-settings>

                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($settings['title']) ?></h1>
                    <p class="page-intro__lede"><?= e($settings['lede']) ?></p>
                </header>

                <!-- Who this is, before anything about the app. -->
                <button type="button" class="card settings-identity press reveal"
                        data-detail-open="settings-account"
                        aria-label="Account openen">
                    <span class="settings-identity__avatar" aria-hidden="true">
                        <?php if (!empty($profile['avatar'])): ?>
                            <img src="<?= e($profile['avatar']) ?>" alt="">
                        <?php else: ?>
                            <?= icon('user') ?>
                        <?php endif; ?>
                    </span>

                    <span class="settings-identity__text">
                        <span class="settings-identity__name">
                            <?= e($auth['signed_in'] ? (string) $profile['username'] : 'Niet ingelogd') ?>
                        </span>
                        <span class="settings-identity__meta">
                            <?= e($auth['signed_in'] ? 'Profiel en gegevens beheren' : 'Log in via de accountknop rechtsboven') ?>
                        </span>
                    </span>

                    <?= icon('chevron-right', 'settings-row__chevron') ?>
                </button>

                <?php foreach ($settings['groups'] as $index => $group): ?>
                    <?php
                    /* The account group is the identity card above it. */
                    if ($group['label'] === 'Account') {
                        continue;
                    }
                    component('settings-group', $data + ['group' => $group, 'group_index' => (string) $index]);
                    ?>
                <?php endforeach; ?>

                <!-- ------------------------------------------ account actions -->
                <section class="settings-actions reveal" aria-label="Accountacties">

                    <button type="button" class="btn settings-logout press" data-settings-logout
                            <?= $auth['signed_in'] ? '' : 'disabled' ?>>
                        <?= icon('logout', 'settings-logout__icon') ?>
                        <?= e($actions['logout']['label']) ?>
                    </button>

                    <?php if (!$auth['signed_in']): ?>
                        <p class="settings-actions__note"><?= e($actions['logout']['signed_out']) ?></p>
                    <?php endif; ?>

                    <?php /* Only an account can be deleted: signed out there is nothing
                             to delete, so the button is as inert as sign-out. */ ?>
                    <button type="button" class="settings-delete press" data-settings-delete
                            <?= $auth['signed_in'] ? '' : 'disabled' ?>>
                        <?= e($actions['delete']['label']) ?>
                    </button>

                </section>

                <?php if ($data['disclaimer'] !== ''): ?>
                <p class="disclaimer"><?= e($data['disclaimer']) ?></p>
                <?php endif; ?>

            </div>
        </main>
    </div>

</section>
