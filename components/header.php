<?php
/**
 * Compact glass header: devices (left) · app name (centre) · account (right).
 *
 * The two side buttons are the same circle, mirrored across the app name, so
 * neither side pulls the title off centre.
 *
 * One header for the five main pages, rendered once by index.php above the
 * rail rather than inside each page: the pages slide underneath it and it
 * stays exactly where it is. Detail pages carry their own header and cover
 * this one.
 */
declare(strict_types=1);

$app     = $data['app'];
$devices = $data['header']['devices'];
$account = $data['header']['account'];
?>
<header class="app-header app-header--shared" data-header data-header-shared>
    <div class="app-header__inner shell">

        <button type="button" class="pill pill--devices press" data-devices-open
                aria-haspopup="dialog" aria-controls="devices-popup" aria-expanded="false"
                aria-label="<?= e($devices['aria']) ?>">
            <?= icon('device', 'pill__icon') ?>
        </button>

        <p class="app-header__brand">
            <span class="app-header__name"><?= e($app['name']) ?></span>
        </p>

        <?php
        /* Friend requests waiting for an answer put the red dot on the
           account button, so they are seen from every page — not only once
           the account panel is opened (lib/notices.php, notices.js). */
        $requests = count($data['community']['pending'] ?? []);
        $requestsLabel = tn($requests, '%d nieuw vriendverzoek', '%d nieuwe vriendverzoeken');
        ?>
        <button type="button" class="pill pill--account press" data-account-open
                aria-label="<?= e($account['aria'] . ($requests > 0 ? ', ' . $requestsLabel : '')) ?>">
            <?php $avatar = $data['auth']['user']['avatar_path'] ?? null; ?>
            <span class="pill__avatar" data-account-avatar>
                <?php if ($avatar !== null && $avatar !== ''): ?>
                    <img src="<?= e($avatar) ?>" alt="">
                <?php else: ?>
                    <?= icon('user', 'pill__icon') ?>
                <?php endif; ?>
            </span>
            <?= notice_dot('account') ?>
        </button>

        <?php /* Telefoon or Desktop: only on a screen wide enough for the
                 second (desktop.css, desktop.js). It changes the layout, never
                 the page or anything on it. */ ?>
        <div class="range-switch view-switch" role="group" aria-label="<?= e(t('Weergave')) ?>" data-view-switch>
            <button type="button" class="range-switch__option" data-view-choice="phone" aria-pressed="false">
                <?= icon('phone', 'view-switch__icon') ?><span><?= e(t('Telefoon')) ?></span>
            </button>
            <button type="button" class="range-switch__option" data-view-choice="desktop" aria-pressed="false">
                <?= icon('desktop', 'view-switch__icon') ?><span><?= e(t('Desktop')) ?></span>
            </button>
        </div>

    </div>
</header>
