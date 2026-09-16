<?php
/**
 * Compact glass header: devices (left) · app name (centre) · account (right).
 *
 * The two side buttons are the same circle, mirrored across the app name, so
 * neither side pulls the title off centre.
 */
declare(strict_types=1);

$app     = $data['app'];
$devices = $data['header']['devices'];
$account = $data['header']['account'];
?>
<header class="app-header" data-header>
    <div class="app-header__inner shell">

        <button type="button" class="pill pill--devices press"
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
