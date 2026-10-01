<?php
/**
 * Dark Mode and White Mode on the website, tested without a database.
 *
 *     php tools/theme-test.php
 *
 * The theme is one word in a cookie (lib/theme.php) and one set of tokens
 * (assets/css/theme.css: `:root`, then `:root[data-theme="light"]`). This
 * checks, in that order:
 *
 *   the word      no cookie, "dark" or anything else is Dark; "light" is White
 *   the head      the browser's bar colour, color-scheme and the iPhone status
 *                 bar for each
 *   the settings  Thema & uiterlijk ticks the browser's choice, the row on
 *                 Instellingen names it, and it is the one choice that saves
 *   the tokens    the light block only sets tokens the dark one has, every role
 *                 is defined, and no stylesheet writes a white or black of its
 *                 own outside one
 *   contrast      in White Mode every text tier and the warning gold reach
 *                 WCAG AA (4.5:1) on a card, in a well inside it and on the
 *                 ground — and never less than Dark Mode gives them; the error
 *                 red on a card; the score colours, the app's green and the
 *                 faint tier 3:1 as graphics
 *   the page      the front door, served by PHP's built-in server: drawn in the
 *                 cookie's theme from the first byte, the cookie sent back with
 *                 a year to run, and nothing of an unknown cookie echoed
 *
 * Exit code 0 when every check passes.
 */

declare(strict_types=1);

/* This lives under the document root on a Hestia deploy. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

require_once $root . '/lib/theme.php';
require_once $root . '/lib/settings.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}

/* ======================================================================
   THE WORD
   ====================================================================== */

echo "The word in the cookie\n";

foreach ([
    'no cookie'          => [null, 'dark'],
    '"dark"'             => ['dark', 'dark'],
    '"light"'            => ['light', 'light'],
    '"LIGHT"'            => ['LIGHT', 'dark'],
    'something else'     => ['sepia', 'dark'],
    'markup'             => ['"><script>', 'dark'],
] as $label => [$cookie, $expected]) {
    unset($_COOKIE[APP_THEME_COOKIE]);
    if ($cookie !== null) {
        $_COOKIE[APP_THEME_COOKIE] = $cookie;
    }
    check("$label is $expected", app_theme() === $expected, app_theme());
}

$_COOKIE[APP_THEME_COOKIE] = ['light'];
check('an array is dark, not an error', app_theme() === 'dark');

/* ======================================================================
   THE HEAD
   ====================================================================== */

echo "\nWhat the browser paints itself with\n";

$app = (require $root . '/config/dashboard.php')['app'];

$_COOKIE[APP_THEME_COOKIE] = 'dark';
$dark = app_theme_head($app);
check('Dark: the bar in --bg-main', $dark['theme_color'] === '#302D2F', $dark['theme_color']);
check('Dark: color-scheme dark', $dark['color_scheme'] === 'dark');
check('Dark: the iPhone status bar see-through over the dark ground', $dark['status_bar'] === 'black-translucent');

$_COOKIE[APP_THEME_COOKIE] = 'light';
$light = app_theme_head($app);
check('White: the bar in the light --bg-main', $light['theme_color'] === '#FBFAFA', $light['theme_color']);
check('White: color-scheme light', $light['color_scheme'] === 'light');
check('White: dark status-bar text', $light['status_bar'] === 'default');

/* ======================================================================
   THE SETTINGS
   ====================================================================== */

echo "\nThema & uiterlijk\n";

$settings = require $root . '/config/settings.php';

/** The theme screen's choice block. */
function theme_choice(array $settings): array
{
    foreach ($settings['pages']['theme']['blocks'] as $block) {
        if (($block['type'] ?? '') === 'choice' && ($block['name'] ?? '') === 'theme') {
            return $block;
        }
    }

    throw new RuntimeException('no theme choice in config/settings.php');
}

/** The value the main Instellingen page shows on its theme row. */
function theme_row(array $settings): ?string
{
    foreach ($settings['groups'] as $group) {
        foreach ($group['rows'] as $row) {
            if ($row['id'] === 'theme') {
                return $row['value'] ?? null;
            }
        }
    }

    return null;
}

$choice = theme_choice($settings);
check('Dark is the default', $choice['selected'] === 'dark' && theme_row($settings) === 'Donker');
check('Licht is offered, and nothing is disabled', array_column($choice['options'], 'key') === ['dark', 'light']
    && array_filter($choice['options'], static fn (array $o): bool => !empty($o['disabled'])) === []);
check('the theme is a choice that saves', ($choice['saves'] ?? false) === true);

$others = [];
foreach ($settings['pages'] as $id => $page) {
    foreach ($page['blocks'] as $block) {
        if (($block['type'] ?? '') === 'choice' && ($block['name'] ?? '') !== 'theme') {
            $others[] = $id;
            check("$id: still says its choice is not kept", empty($block['saves']));
        }
    }
}
check('there are other choices to tell apart', $others !== []);

$asLight = settings_use_theme($settings, 'light');
check('White: Licht is ticked', theme_choice($asLight)['selected'] === 'light');
check('White: the row says Licht', theme_row($asLight) === 'Licht', (string) theme_row($asLight));

$asDark = settings_use_theme($asLight, 'dark');
check('back to Dark: Donker ticked and named', theme_choice($asDark)['selected'] === 'dark' && theme_row($asDark) === 'Donker');
check('nothing else on the settings changes', settings_use_theme($settings, 'dark') === $settings);

/* ======================================================================
   THE TOKENS
   ====================================================================== */

echo "\nThe tokens\n";

$css = (string) file_get_contents($root . '/assets/css/theme.css');

/** A block's declarations: name => value. */
function tokens(string $css, string $selector): array
{
    $start = strpos($css, $selector . ' {');
    if ($start === false) {
        throw new RuntimeException("$selector is not in theme.css");
    }
    $body = substr($css, $start, strpos($css, "\n}", $start) - $start);
    preg_match_all('/--([a-z0-9-]+):\s*([^;]+);/', $body, $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as [, $name, $value]) {
        $out[$name] = trim(preg_replace('/\s+/', ' ', $value));
    }

    return $out;
}

$darkTokens  = tokens($css, ':root');
$lightTokens = tokens($css, ':root[data-theme="light"]');

$onlyLight = array_diff(array_keys($lightTokens), array_keys($darkTokens));
check('White Mode sets no token Dark Mode lacks', $onlyLight === [], implode(', ', $onlyLight));

$roles = ['ink', 'fill', 'fill-k', 'lift', 'lift-k', 'glint-k', 'shade', 'shade-k', 'scrim', 'scrim-k', 'ground', 'pane-body',
          'tint-base', 'tint-keep', 'tip-surface', 'tip-surface-solid', 'board-you-surface', 'switch-knob', 'error'];
foreach ($roles as $role) {
    check("--$role: in both themes", isset($darkTokens[$role], $lightTokens[$role]));
}

/* In Dark Mode every role is exactly the white or black it took the place of. */
check('Dark roles are the original white, black and greys',
    $darkTokens['ink'] === '255, 255, 255' && $darkTokens['fill'] === '255, 255, 255' && $darkTokens['lift'] === '255, 255, 255'
    && $darkTokens['shade'] === '0, 0, 0' && $darkTokens['scrim'] === '0, 0, 0'
    && $darkTokens['ground'] === '48, 45, 47' && $darkTokens['pane-body'] === '30, 28, 29'
    && $darkTokens['tint-base'] === '#ffffff');
check('Dark multipliers are all 1', array_unique(array_map(static fn (string $k): string => $darkTokens[$k],
    ['fill-k', 'lift-k', 'glint-k', 'shade-k', 'scrim-k', 'tint-keep'])) === ['1']);

/* Outside the two token blocks, nobody writes a white, a black or the
   ground's greys as a colour of their own: they are roles. */
$tokenBlocks = '/:root(\[data-theme="light"\])? \{.*?\n\}/s';
$literal     = '/rgba\(\s*(255\s*,\s*255\s*,\s*255|0\s*,\s*0\s*,\s*0|48\s*,\s*45\s*,\s*47|30\s*,\s*28\s*,\s*29)\s*,\s*[0-9.]+\s*\)/';
foreach (glob($root . '/assets/css/*.css') ?: [] as $file) {
    $text  = (string) file_get_contents($file);
    $outer = basename($file) === 'theme.css' ? (string) preg_replace($tokenBlocks, '', $text) : $text;
    preg_match_all($literal, $outer, $found);
    check(basename($file) . ': every white, black and ground grey is a role', $found[0] === [], implode(' ', array_slice($found[0], 0, 3)));
}

/* ======================================================================
   CONTRAST
   ====================================================================== */

echo "\nContrast in White Mode\n";

/** #rrggbb or rgba(r, g, b, a) or "r, g, b" → [r, g, b, a]. */
function colour(string $value): array
{
    if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m)) {
        return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2)), 1.0];
    }
    if (preg_match('/^rgba\((\d+),\s*(\d+),\s*(\d+),\s*([0-9.]+)\)$/', $value, $m)) {
        return [(int) $m[1], (int) $m[2], (int) $m[3], (float) $m[4]];
    }
    if (preg_match('/^(\d+),\s*(\d+),\s*(\d+)$/', $value, $m)) {
        return [(int) $m[1], (int) $m[2], (int) $m[3], 1.0];
    }

    throw new RuntimeException("not a colour: $value");
}

/** $front painted over the opaque $back. */
function over(array $front, array $back): array
{
    $a = $front[3];

    return [$front[0] * $a + $back[0] * (1 - $a), $front[1] * $a + $back[1] * (1 - $a), $front[2] * $a + $back[2] * (1 - $a), 1.0];
}

function luminance(array $c): float
{
    $lin = static function (float $v): float {
        $v /= 255;

        return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $lin($c[0]) + 0.7152 * $lin($c[1]) + 0.0722 * $lin($c[2]);
}

function contrast(array $a, array $b): float
{
    [$hi, $lo] = [max(luminance($a), luminance($b)), min(luminance($a), luminance($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}

/**
 * Where text sits in one theme: a card on the ground (the surface gradient's
 * first stop over --bg-main), a well inside that card (--fill at .05) and
 * the ground at its deepest.
 */
function grounds(array $t): array
{
    preg_match('/rgba\([^)]+\)/', $t['surface-gradient'], $first);
    $card = over(colour($first[0]), colour($t['bg-main']));
    $fill = colour($t['fill']);
    $fill[3] = 0.05 * (float) $t['fill-k'];

    return ['card' => $card, 'well' => over($fill, $card), 'ground' => colour($t['bg-deep'])];
}

/** The worst a text colour does where text sits. */
function worst(array $t, string $token): float
{
    $text = colour($t[$token]);

    return min(array_map(static fn (array $g): float => contrast(over($text, $g), $g), grounds($t)));
}

/* White Mode is the dark :root with the light block over it, as the cascade has it. */
$theme = ['dark' => $darkTokens, 'light' => array_merge($darkTokens, $lightTokens)];

foreach (['text-primary', 'text-secondary', 'text-muted', 'attention'] as $token) {
    $l = worst($theme['light'], $token);
    $d = worst($theme['dark'], $token);
    check(sprintf('--%s: %.2f:1 at worst (AA; Dark Mode gives %.2f)', $token, $l, $d), $l >= 4.5 && $l >= $d);
}

$card = grounds($theme['light'])['card'];
$redLight = contrast(colour($theme['light']['error']), $card);
$redDark  = contrast(colour($theme['dark']['error']), grounds($theme['dark'])['card']);
check(sprintf('--error on a card: %.2f:1 (Dark Mode gives %.2f)', $redLight, $redDark), $redLight >= 4.5 && $redLight >= $redDark);

foreach (['score-high', 'score-mid', 'score-low', 'health', 'text-faint'] as $token) {
    $c = contrast(over(colour($theme['light'][$token]), $card), $card);
    check(sprintf('--%s as a graphic on a card: %.2f:1', $token, $c), $c >= 3.0);
}

/* The accents that changed kept their hue: deeper, not another colour. */
function hue(array $c): float
{
    [$r, $g, $b] = [$c[0] / 255, $c[1] / 255, $c[2] / 255];
    $max = max($r, $g, $b);
    $d   = $max - min($r, $g, $b);
    if ($d == 0) {
        return 0.0;
    }
    $h = match ($max) {
        $r      => fmod(($g - $b) / $d + 6, 6),
        $g      => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };

    return $h * 60;
}

foreach (['score-mid', 'score-low', 'attention'] as $token) {
    $was = colour($darkTokens[$token]);
    $now = colour($lightTokens[$token]);
    check("--$token: the same hue, deeper", abs(hue($was) - hue($now)) <= 1.5 && luminance($now) < luminance($was),
        sprintf('%.1f° → %.1f°', hue($was), hue($now)));
}

$unchanged = ['sleep', 'sleep-light', 'nutrition', 'nutrition-light', 'training', 'training-light', 'score-high', 'health', 'neutral', 'miss'];
$moved = array_values(array_filter($unchanged, static fn (string $k): bool => isset($lightTokens[$k])));
check('the categories, score-high, the green, the grey tile and the missed day keep their value', $moved === [], implode(', ', $moved));

/* ======================================================================
   THE PAGE, SERVED
   ====================================================================== */

echo "\nThe front door, served\n";

$socket = stream_socket_server('tcp://127.0.0.1:0');
$name   = (string) stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr($name, strrpos($name, ':') + 1);

$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(50_000);
}

/** GET / with a theme cookie (or none): [html, the Set-Cookie headers]. */
function front_door(int $port, ?string $cookie): array
{
    $handle  = curl_init('http://127.0.0.1:' . $port . '/');
    $cookies = [];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $cookie === null ? [] : ['Cookie: ' . APP_THEME_COOKIE . '=' . rawurlencode($cookie)],
        CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$cookies): int {
            if (stripos($line, 'Set-Cookie: ' . APP_THEME_COOKIE . '=') === 0) {
                $cookies[] = trim($line);
            }

            return strlen($line);
        },
    ]);
    $html = (string) curl_exec($handle);
    unset($handle);

    return [$html, $cookies];
}

try {
    [$html, $set] = front_door($port, null);
    check('never chose: drawn dark', (bool) preg_match('/<html[^>]* data-theme="dark"/', $html));
    check('never chose: no cookie handed out', $set === [], implode(' | ', $set));

    [$html, $set] = front_door($port, 'light');
    check('chose White: drawn light from the first byte', (bool) preg_match('/<html[^>]* data-theme="light"/', $html));
    check('chose White: the light bar colour', str_contains($html, 'name="theme-color" content="#FBFAFA"'));
    check('chose White: color-scheme light', str_contains($html, 'name="color-scheme" content="light"'));
    check('chose White: dark iPhone status-bar text', str_contains($html, 'apple-mobile-web-app-status-bar-style" content="default"'));
    check('chose White: both bar colours there for settings.js to swap',
        str_contains($html, 'data-theme-dark="#302D2F"') && str_contains($html, 'data-theme-light="#FBFAFA"'));
    $renewed = $set[0] ?? '';
    check('chose White: the choice sent back for a year', str_contains($renewed, APP_THEME_COOKIE . '=light')
        && str_contains($renewed, 'Max-Age=31536000') && stripos($renewed, 'SameSite=Lax') !== false && stripos($renewed, 'path=/') !== false, $renewed);
    check('chose White: the cookie is not HttpOnly (settings.js writes it)', stripos($renewed, 'HttpOnly') === false);

    [$html, $set] = front_door($port, '"><script>alert(1)</script>');
    check('an unknown cookie: drawn dark', (bool) preg_match('/<html[^>]* data-theme="dark"/', $html));
    check('an unknown cookie: nothing of it in the page', !str_contains($html, 'alert(1)'));
    check('an unknown cookie: replaced by "dark"', str_contains($set[0] ?? '', APP_THEME_COOKIE . '=dark;'), $set[0] ?? 'none');
} finally {
    proc_terminate($server);
}

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
