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
          data-theme-dark="<?= e($app['theme_color']) ?>" data-theme-light="<?= e($app['theme_color_light'] ?? '#F6F1F6') ?>">
    <meta name="color-scheme" content="<?= e($theme['color_scheme']) ?>">
    <meta name="description" content="<?= e($app['name']) ?> — <?= e(t('dagelijks overzicht van slaap, voeding en training.')) ?>">
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

        /* In the iPhone on a wide screen (phone-simulator.php):
           this page is the phone's website, with the iPhone's safe areas and
           no visible scrollbars (phone-sim.css). */
        try {
            if (window.frameElement && window.frameElement.hasAttribute('data-phone-screen')) {
                root.setAttribute('data-frame', 'phone');
            }
        } catch (error) { /* not ours to look into */ }

        /* The theme the account sees; around the iPhone the page is always
           light (paint), the phone itself keeps this one. */
        var own = root.getAttribute('data-theme') || 'dark';

        function chosen() {
            var word = /(?:^|;\s*)ownify_theme=(dark|light)(?:;|$)/.exec(document.cookie);
            return word ? word[1] : null;
        }

        function apply(theme) {
            own = theme;
            paint();
        }

        function paint() {
            var theme = root.getAttribute('data-layout') === 'phone-frame' ? 'light' : own;
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

        window.ownifyTheme = { apply: apply, follow: follow, paint: paint, own: function () { return own; } };

        /* Voorkeuren › Toegankelijkheid, as the account chose it
           (includes/i18n.php, migration 024): on <html> before anything is
           painted, so the stylesheets' twins of the device's own settings
           apply — data-text, data-contrast, data-motion. The scripts that ask
           the device about motion or contrast hear the account too: matchMedia
           answers yes for those two while the account says so, and follows
           the device otherwise. settings.js switches through here. */
        var media = window.matchMedia ? window.matchMedia.bind(window) : null;
        var asked = {
            motion:   /prefers-reduced-motion:\s*reduce/,
            contrast: /prefers-contrast:\s*more/
        };

        function access(choices) {
            var set = function (name, value, on) {
                if (on) { root.setAttribute(name, value); } else { root.removeAttribute(name); }
            };
            set('data-text', 'large', !!choices.text_large);
            set('data-contrast', 'more', !!choices.contrast_more);
            set('data-motion', 'reduce', !!choices.motion_off);
        }

        access(<?= json_encode(function_exists('accessibility_choices') ? accessibility_choices() : new stdClass()) ?>);

        if (media) {
            window.matchMedia = function (query) {
                var device = media(query);
                var attribute = asked.motion.test(query) ? 'data-motion' : asked.contrast.test(query) ? 'data-contrast' : null;
                if (!attribute) { return device; }
                return {
                    media: device.media,
                    get matches() { return root.hasAttribute(attribute) || device.matches; },
                    addEventListener: function (type, fn) { device.addEventListener && device.addEventListener(type, fn); },
                    removeEventListener: function (type, fn) { device.removeEventListener && device.removeEventListener(type, fn); },
                    addListener: function (fn) { device.addListener && device.addListener(fn); },
                    removeListener: function (fn) { device.removeListener && device.removeListener(fn); }
                };
            };
        }

        window.ownifyAccess = { apply: access };

        /* The layout, before anything is painted: from 768px wide the
           phone's website in an iPhone (phone-simulator.php); narrower — and
           in the iPhone itself — the phone's own. */
        var wide = window.matchMedia ? window.matchMedia('(min-width: 768px)') : null;
        function layout() {
            var framed = root.getAttribute('data-frame') === 'phone';
            var was = root.getAttribute('data-layout');
            root.setAttribute('data-layout', wide && wide.matches && !framed ? 'phone-frame' : 'phone');
            /* Back from the iPhone: the theme may have been changed in it. */
            if (was === 'phone-frame' && root.getAttribute('data-layout') !== 'phone-frame') {
                var word = /(?:^|;\s*)ownify_theme=(dark|light)(?:;|$)/.exec(document.cookie);
                if (word) { own = word[1]; } else if (device) { own = device.matches ? 'light' : 'dark'; }
            }
            if (window.ownifyTheme) { window.ownifyTheme.paint(); }
        }
        layout();
        if (wide && wide.addEventListener) { wide.addEventListener('change', layout); }
        else if (wide && wide.addListener) { wide.addListener(layout); }
        window.ownifyLayout = { apply: layout };

        /* Off to Google or Polar (signing in, linking, confirming, connecting
           a source). Their pages refuse to be shown in a frame — Google
           answers 403 — so from the iPhone on a wide screen the whole window
           goes, as it does on a phone. The way back lands on this page
           around the iPhone: ownify_trip asks it to leave the message the
           callback left for the iPhone itself (auth_flash_take()). */
        var TRIP = 'ownify_trip';
        function tripCookie(age) {
            document.cookie = TRIP + '=' + (age > 0 ? '1' : '') + '; Max-Age=' + age + '; Path=/; SameSite=Lax'
                + (location.protocol === 'https:' ? '; Secure' : '');
        }
        window.ownifyLeave = function (url) {
            var target = window;
            if (root.getAttribute('data-frame') === 'phone') {
                try {
                    if (window.top.location.origin === window.location.origin) { target = window.top; }
                } catch (error) { target = window; }
            }
            if (target !== window) { tripCookie(900); }
            target.location.assign(new URL(url, window.location.href).href);
        };
        /* Back on a narrow window instead: no iPhone to leave it for. */
        if (root.getAttribute('data-layout') === 'phone' && root.getAttribute('data-frame') !== 'phone'
            && new RegExp('(?:^|;\\s*)' + TRIP + '=1').test(document.cookie)) {
            tripCookie(0);
            window.location.reload();
        }
    })();
    </script>

    <?php /* The language and units for the scripts (assets/js/i18n.js,
             docs/I18N.md): the English catalogue only on an English page,
             and before every other script, which are deferred too. */ ?>
    <?php if (function_exists('i18n_locale') && i18n_locale() === 'en'): ?>
    <script src="<?= e(asset('assets/js/lang-en.js')) ?>" defer></script>
    <?php endif; ?>
    <script src="<?= e(asset('assets/js/i18n.js')) ?>" defer></script>

    <?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/' . $style . '.css')) ?>">
    <?php endforeach; ?>
