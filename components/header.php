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

        <button type="button" class="pill pill--account press" data-account-open
                aria-label="<?= e($account['aria']) ?>">
            <?php $avatar = $data['auth']['user']['avatar_path'] ?? null; ?>
            <span class="pill__avatar" data-account-avatar>
                <?php if ($avatar !== null && $avatar !== ''): ?>
                    <img src="<?= e($avatar) ?>" alt="">
                <?php else: ?>
                    <?= icon('user', 'pill__icon') ?>
                <?php endif; ?>
            </span>
        </button>

    </div>
</header>
