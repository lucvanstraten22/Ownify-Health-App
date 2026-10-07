<?php
/**
 * What goes in <head>, for the app and the opening screen alike: one set of
 * meta tags, one title, one deploy stamp. Each passes the stylesheets it
 * needs as $data['styles'].
 */
declare(strict_types=1);

$app    = $data['app'];
$styles = $data['styles'] ?? [];
$theme  = app_theme_head($app);     // lib/theme.php
?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($theme['theme_color']) ?>"
          data-theme-dark="<?= e($app['theme_color']) ?>" data-theme-light="<?= e($app['theme_color_light'] ?? '#FBFAFA') ?>">
    <meta name="color-scheme" content="<?= e($theme['color_scheme']) ?>">
    <meta name="description" content="<?= e($app['name']) ?> — dagelijks overzicht van slaap, voeding en sport.">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="<?= e($theme['status_bar']) ?>">
    <meta name="mobile-web-app-capable" content="yes">

    <?php /* The Ownify logo, icon-only (docs/BRANDING.md): the browser tab, and
             a phone's home screen for the two meta tags above. favicon.ico at
             the root also answers a browser that asks for it unprompted. */ ?>
    <link rel="icon" href="<?= e(asset('favicon.ico')) ?>" sizes="16x16 32x32 48x48">
    <link rel="icon" href="<?= e(asset('assets/brand/ownify-icon-192.png')) ?>" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="<?= e(asset('assets/brand/apple-touch-icon.png')) ?>">

    <title><?= e($app['name']) ?> — <?= e($app['tagline']) ?></title>

    <?php
    /* Which commit this server is running. The deploy writes VERSION; locally
       there is no such file and nothing is emitted. It is only ever a commit
       hash — no path, no version number anything could be probed with — and it
       turns "is my change live?" from a guess into view-source. */
    $deployed = @file_get_contents(dirname(__DIR__) . '/VERSION');
    if (is_string($deployed) && preg_match('/^[0-9a-f]{7,40}$/', trim($deployed))):
        ?>
        <meta name="app-version" content="<?= e(substr(trim($deployed), 0, 7)) ?>">
    <?php endif; ?>

    <script>document.documentElement.classList.add('js');</script>

    <?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/' . $style . '.css')) ?>">
    <?php endforeach; ?>
