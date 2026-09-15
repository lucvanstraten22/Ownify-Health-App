<?php
/** Compact glass header: devices (left) · app name (centre) · account (right). */
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
            <span class="pill__label"><?= e($devices['label']) ?></span>
            <span class="pill__badge<?= $devices['connected'] > 0 ? ' is-active' : '' ?>" aria-hidden="true"></span>
        </button>

        <p class="app-header__brand">
            <span class="app-header__name"><?= e($app['name']) ?></span>
        </p>

        <button type="button" class="pill pill--account press"
                aria-label="<?= e($account['aria']) ?>">
            <?= icon('user', 'pill__icon') ?>
        </button>

    </div>
</header>
