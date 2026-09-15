<?php
/**
 * Application shell.
 *
 * Holds the two screens of the app side by side in one document so the swipe
 * between them can follow the finger. Page content lives in /pages, all copy
 * and data in config/dashboard.php.
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
    <link rel="stylesheet" href="assets/css/ai.css">
</head>
<body class="app">

    <a class="skip-link" href="#main">Naar de inhoud</a>

    <!-- Shared ground behind both screens: the visual thread between them. -->
    <div class="app__backdrop" aria-hidden="true"></div>

    <div class="deck" data-deck>
        <?php
        page('overview', $data);
        page('ai', $data);
        ?>
    </div>

    <script src="assets/js/dashboard.js" defer></script>
    <script src="assets/js/interactions.js" defer></script>
    <script src="assets/js/swipe-navigation.js" defer></script>
</body>
</html>
