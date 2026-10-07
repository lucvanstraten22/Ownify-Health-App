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

    <script>
    /* Before anything is painted. Thema & uiterlijk on Systeem — chosen, or
       nothing chosen (lib/theme.php) — is the device's appearance: put in
       place now, and followed when the device changes while the page is open.
       Donker or Licht stays as it is. settings.js switches through here. */
    (function () {
        var root = document.documentElement;
        var device = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;
        root.classList.add('js');

        function chosen() {
            var word = /(?:^|;\s*)ownify_theme=(dark|light)(?:;|$)/.exec(document.cookie);
            return word ? word[1] : null;
        }

        function apply(theme) {
            root.setAttribute('data-theme', theme);
            var bar = document.querySelector('meta[name="theme-color"]');
            if (bar) { bar.setAttribute('content', bar.getAttribute(theme === 'light' ? 'data-theme-light' : 'data-theme-dark')); }
            var scheme = document.querySelector('meta[name="color-scheme"]');
            if (scheme) { scheme.setAttribute('content', theme); }
            var status = document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]');
            if (status) { status.setAttribute('content', theme === 'light' ? 'default' : 'black-translucent'); }
        }

        function follow() {
            if (!chosen() && device) { apply(device.matches ? 'light' : 'dark'); }
        }

        follow();
        if (device && device.addEventListener) { device.addEventListener('change', follow); }
        else if (device && device.addListener) { device.addListener(follow); }

        window.ownifyTheme = { apply: apply, follow: follow };
    })();
    </script>

    <?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/' . $style . '.css')) ?>">
    <?php endforeach; ?>
