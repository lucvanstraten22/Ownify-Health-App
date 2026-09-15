<?php
/**
 * Home / Overview — the only page in this version.
 *
 * Composition only: all copy and data come from config/dashboard.php,
 * all markup from /components, all styling from /assets/css.
 */

declare(strict_types=1);

require __DIR__ . '/lib/render.php';
require __DIR__ . '/components/icons.php';

/** @var array $data */
$data = require __DIR__ . '/config/dashboard.php';

$app   = $data['app'];
$focus = $data['focus'];
?>
<!DOCTYPE html>
<html lang="<?= e($app['locale']) ?>" data-focus="<?= e($focus) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($app['theme_color']) ?>">
    <meta name="color-scheme" content="dark">
    <meta name="description" content="<?= e($app['name']) ?> — dagelijks overzicht van slaap, voeding en sport.">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">

    <title><?= e($app['name']) ?> — <?= e($app['tagline']) ?></title>

    <script>document.documentElement.classList.add('js');</script>

    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="app">

    <a class="skip-link" href="#main">Naar de inhoud</a>

    <div class="app__backdrop" aria-hidden="true"></div>

    <?php component('header', $data); ?>

    <main class="app__main" id="main" tabindex="-1">
        <div class="shell stack">
            <?php
            component('health-score', $data);
            component('secondary-scores', $data);
            component('goal-progress', $data);
            component('insights', $data);
            component('patterns', $data);
            component('recommendation', $data);
            component('leaderboard', $data);
            ?>
            <p class="disclaimer reveal"><?= e($data['disclaimer']) ?></p>
        </div>
    </main>

    <?php
    component('scroll-top', $data);
    component('bottom-navigation', $data);
    ?>

    <script src="assets/js/dashboard.js" defer></script>
    <script src="assets/js/interactions.js" defer></script>
</body>
</html>
