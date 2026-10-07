<?php
/**
 * Dark Mode or White Mode: which one this browser chose — or the device's.
 *
 * A device preference, like a phone's own appearance setting, so it is kept
 * in a cookie and not on the account: the opening screen — where nobody is
 * signed in yet — comes up in the theme chosen on this device, and signing
 * out or in changes nothing about it.
 *
 *   ownify_theme=dark     Dark Mode, always
 *   ownify_theme=light    White Mode, always
 *   ownify_theme=system   the device's appearance (Systeem)
 *   (no cookie, or any    the same: the default. Only a choice made in
 *    other word)          Instellingen is ever stored, never what the
 *                         device happened to show.
 *
 * Dark and White arrive from the server, never painted in the other theme
 * first. On Systeem the server cannot know the device's appearance, so it
 * draws Dark, as before; the first script in <head> (components/document-
 * head.php) puts the device's theme in place before anything is painted, and
 * follows it when it changes while the page is open.
 *
 * settings.js writes the cookie the moment the choice is made in Instellingen
 * → Thema & uiterlijk and switches the page in place. Each page then sends it
 * back from the server with a year to run, so the choice lasts: Safari keeps a
 * cookie that a script set for seven days at most, one the server set for as
 * long as it says. It holds a word and nothing else.
 *
 * The colours themselves are tokens in assets/css/theme.css
 * (:root[data-theme="light"]); nothing here knows a colour but the two the
 * browser paints its own bars with.
 */

declare(strict_types=1);

/** The cookie's name; settings.js writes the same one. */
if (!defined('APP_THEME_COOKIE')) {
    define('APP_THEME_COOKIE', 'ownify_theme');
}

if (!function_exists('app_theme')) {

    /** 'system', 'dark' or 'light' — what this browser chose; Systeem when it chose nothing. */
    function app_theme_preference(): string
    {
        $chosen = $_COOKIE[APP_THEME_COOKIE] ?? '';

        return in_array($chosen, ['dark', 'light', 'system'], true) ? $chosen : 'system';
    }

    /**
     * 'light' or 'dark' — the theme the page is drawn in: the one chosen, or
     * on Systeem Dark, until the first script puts the device's in place.
     */
    function app_theme(): string
    {
        return app_theme_preference() === 'light' ? 'light' : 'dark';
    }

    /**
     * The choice again, from the server, for another year — only when one was
     * made: a browser that never chose keeps no cookie and follows its device.
     */
    function app_theme_renew(): void
    {
        if (!isset($_COOKIE[APP_THEME_COOKIE]) || headers_sent()) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);

        setcookie(APP_THEME_COOKIE, app_theme_preference(), [
            'expires'  => time() + 365 * 24 * 3600,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => false,     // settings.js changes it
            'samesite' => 'Lax',
        ]);
    }

    /**
     * What the browser paints itself with: the colour of its own bars, the
     * scheme of its form controls and scrollbars, and — as a home-screen app
     * on an iPhone — the status bar: see-through with white text over the
     * dark ground, dark text over the light one.
     *
     * @param array{theme_color: string, theme_color_light?: string} $app
     * @return array{theme: string, theme_color: string, color_scheme: string, status_bar: string}
     */
    function app_theme_head(array $app): array
    {
        $light = app_theme() === 'light';

        return [
            'theme'        => $light ? 'light' : 'dark',
            'theme_color'  => $light ? (string) ($app['theme_color_light'] ?? '#FBFAFA') : (string) $app['theme_color'],
            'color_scheme' => $light ? 'light' : 'dark',
            'status_bar'   => $light ? 'default' : 'black-translucent',
        ];
    }
}
