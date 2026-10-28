<?php
/**
 * Language and units (includes/i18n.php, docs/I18N.md), tested from the
 * rules up to the pages, on both of the app's readers.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/i18n-test.php
 *
 * The rules, without a server:
 *   - which language a device header means (English, Dutch, anything else)
 *   - numbers and dates written and read the Dutch and the English way
 *   - every conversion, both ways, and back again without drift
 *   - a goal's unit shown and typed, and the catalogue itself
 *
 * Then over HTTP, on PHP's built-in server and the database named by DB_NAME
 * (migration 021), with one throwaway account (`i18n_…`, removed at the end)
 * that has a week of every kind of data:
 *   - the device decides until the account chooses; the choice survives
 *     signing out and in, on the website and in the app alike; "Automatisch"
 *     gives it back to the device; the website's opening screen keeps it
 *   - the website's pages and the app's state in English hold no Dutch
 *   - imperial: weight in lb, height in ft/in, distance in mi, pace per mile,
 *     elevation in ft, temperature in °F — on the pages, in the charts, in
 *     the goals — while not one stored row changes; typed imperial values are
 *     stored in metric, once; switching back shows the very same metric page
 *   - language and units are independent of each other
 *
 * Never point it at a live database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 021 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/health-import.php';
require_once $root . '/includes/health-connect-map.php';
require_once $root . '/includes/goal-progress.php';
require_once $root . '/includes/goal-create.php';
require_once $root . '/includes/user.php';

if (!db_available() || !i18n_columns_present()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 021.\n");
    exit(2);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : "\n          " . $detail);
}

function section(string $title): void
{
    printf("\n== %s ==\n", $title);
}

function near(?float $a, float $b, float $eps = 1e-6): bool
{
    return $a !== null && abs($a - $b) < $eps;
}

/* ======================================================================
   THE RULES
   ====================================================================== */

section('1. Which language the device means');

foreach ([
    'en-US,en;q=0.9'              => 'en',
    'en-GB'                       => 'en',
    'nl-NL,nl;q=0.9,en;q=0.8'     => 'nl',
    'nl-BE'                       => 'nl',
    'de-DE,de;q=0.9,en;q=0.8'     => 'nl',
    'fr-FR'                       => 'nl',
    'fr;q=0.4,en;q=0.9'           => 'en',
    ''                            => 'nl',
    '*'                           => 'nl',
] as $header => $expected) {
    check(sprintf('Accept-Language "%s" → %s', $header, $expected), i18n_from_header($header) === $expected, i18n_from_header($header));
}
check('no header at all → Dutch', i18n_from_header(null) === 'nl');

section('2. Numbers and dates, written and read');

i18n_force('nl');
check('Dutch: 1.234,5 and 72,5', i18n_number(1234.5, 1) === '1.234,5' && i18n_number_trim(72.50, 2) === '72,5');
check('Dutch: 5 oktober, maandag 5 oktober 2026 style',
    i18n_date('2026-10-05', 'day_month') === '5 oktober' && i18n_date('2026-10-05', 'weekday_day_month') === 'maandag 5 oktober'
    && i18n_date('2026-10-05', 'day_mon') === '5 okt' && i18n_date('2026-10-05', 'numeric') === '5-10-2026');
check('Dutch plurals: 1 dag, 3 dagen', tn(1, '%d dag', '%d dagen') === '1 dag' && tn(3, '%d dag', '%d dagen') === '3 dagen');
i18n_force('en');
check('English: 1,234.5 and 72.5', i18n_number(1234.5, 1) === '1,234.5' && i18n_number_trim(72.50, 2) === '72.5');
check('English: October 5, Monday, October 5, Oct 5, 10/5/2026',
    i18n_date('2026-10-05', 'day_month') === 'October 5' && i18n_date('2026-10-05', 'weekday_day_month') === 'Monday, October 5'
    && i18n_date('2026-10-05', 'day_mon') === 'Oct 5' && i18n_date('2026-10-05', 'numeric') === '10/5/2026'
    && i18n_date('2026-10-05', 'day_month_year') === 'October 5, 2026');
check('English plurals: 1 day, 3 days', tn(1, '%d dag', '%d dagen') === '1 day' && tn(3, '%d dag', '%d dagen') === '3 days');
check('a string with no English yet stays Dutch rather than vanishing', t('Dit staat niet in de catalogus') === 'Dit staat niet in de catalogus');
foreach (['72,5' => 72.5, '72.5' => 72.5, '1.234,5' => 1234.5, '1,234.5' => 1234.5, ' 80 ' => 80.0] as $typed => $value) {
    check(sprintf('"%s" is read as %s, in either language', $typed, $value), near(i18n_parse_number($typed), $value));
}
check('not a number: null', i18n_parse_number('12a') === null && i18n_parse_number('') === null);

section('3. Every conversion, both ways');

i18n_force('en', 'imperial');
$conversions = [
    ['kg', 80.0, 'lb', 176.369809748],
    ['km', 10.0, 'mi', 6.213711922],
    ['cm', 180.0, 'in', 70.866141732],
    ['m', 100.0, 'ft', 328.083989501],
    ['°C', 36.5, '°F', 97.7],
    ['km/h', 20.0, 'mph', 12.427423845],
    ['min/km', 5.0, 'min/mi', 8.04672],
];
foreach ($conversions as [$stored, $value, $shown, $expected]) {
    $out = units_out($value, $stored);
    $back = units_in($out, $stored);
    check(sprintf('%s %s is %s %s, and back exactly', $value, $stored, round($expected, 3), $shown),
        units_label($stored) === $shown && near($out, $expected, 1e-6) && near($back, $value, 1e-9),
        json_encode([$out, $back]));
    [$canon, $unit] = units_canonical($expected, $shown);
    if ($shown !== 'min/mi') {
        check(sprintf('typed %s %s is stored as %s %s', round($expected, 3), $shown, $value, $stored), near($canon, $value, 1e-6) && $unit === ($stored === 'km/h' ? 'km/h' : $stored));
    }
}
check('180 cm is 5 ft 11 in', units_feet_inches(180) === '5 ft 11 in' && units_format(180.0, 'cm') === '5 ft 11 in');
foreach (["5'11" => 180.34, '5 ft 11 in' => 180.34, '5 11' => 180.34, '71' => 180.34, '6′0″' => 182.88, '6' => 182.88, '6 in' => 15.24] as $typed => $cm) {
    check(sprintf('"%s" is %s cm', $typed, $cm), near(units_parse_feet_inches((string) $typed), $cm, 1e-9), (string) units_parse_feet_inches((string) $typed));
}
check('nonsense heights are refused', units_parse_feet_inches('12 ft') === null && units_parse_feet_inches('5 13') === null && units_parse_feet_inches('abc') === null);
check('a pace of 5:00 /km is 8:03 /mi', units_pace(300) === '8:03 /mi');
check('values are shown rounded, never stored rounded: 72.574 kg → "160 lb"', units_format(72.574, 'kg') === '160 lb');
check('a hundred round trips through lb do not drift', (function (): bool {
    $v = 81.3;
    for ($i = 0; $i < 100; $i++) {
        $v = units_in(units_out($v, 'kg'), 'kg');
    }
    return near($v, 81.3, 1e-9);
})());
i18n_force('en', 'metric');
check('metric: nothing converts, the stored unit is shown', units_label('kg') === 'kg' && near(units_out(80.0, 'kg'), 80.0) && units_format(180.0, 'cm') === '180 cm');
i18n_force('nl', 'imperial');
check('Dutch and imperial together: "176,4 lb"', units_format(80.0, 'kg') === '176,4 lb');

section('4. A goal\'s unit, shown and typed');

i18n_force('en', 'imperial');
$lb = goal_unit('lb');
check('an imperial reader\'s weight goal starts in lb', goal_default_unit(goal_measurement('weight')) === 'lb' && goal_value_text(80.0, $lb) === '176.4 lb');
check('176.4 lb typed is 80 kg stored', near(goal_to_stored(176.369809748, $lb), 80.0, 1e-6));
check('a distance goal starts in mi: 42.195 km reads 26.2 mi', goal_default_unit(goal_measurement('distance')) === 'mi'
    && goal_value_text(42.195, goal_unit('mi')) === '26.2 mi');
check('sleep stays hours, steps stay steps (in English)', goal_value_text(480.0, goal_unit('h')) === '8 h'
    && goal_value_text(10425.0, goal_unit('steps')) === '10,425 steps');
check('a goal kept in kg stays kg for an imperial reader: the unit is the goal\'s', goal_value_text(80.0, goal_unit('kg')) === '80 kg');
check('an own habit can be kept in mi or lb too', in_array('lb', goal_measurement('custom')['unit_codes'], true)
    && in_array('mi', goal_measurement('custom')['unit_codes'], true));
i18n_force('nl', 'metric');
check('Dutch metric is as it always was: "80 kg", "10.425 stappen", "8 uur"', goal_value_text(80.0, goal_unit('kg')) === '80 kg'
    && goal_default_unit(goal_measurement('weight')) === 'kg'
    && goal_value_text(10425.0, goal_unit('steps')) === '10.425 stappen'
    && goal_value_text(480.0, goal_unit('h')) === '8 uur');

section('5. The catalogue');

exec('php ' . escapeshellarg($root . '/tools/i18n-build.php') . ' --check 2>&1', $lines, $status);
check('tools/i18n-build.php --check: every string asked for has English, placeholders match, generated files current',
    $status === 0, implode("\n          ", array_slice($lines, 0, 30)));

/* ======================================================================
   OVER HTTP
   ====================================================================== */

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name   = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/** @return array{status: int, body: ?array, raw: string, headers: string} */
function http(string $path, array $o = []): array
{
    global $base;

    $handle  = curl_init($base . $path);
    $headers = [];
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);

    if (($o['get'] ?? false) !== true) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, isset($o['json']) ? json_encode($o['json']) : http_build_query($o['form'] ?? []));
        if (isset($o['json'])) {
            $headers[] = 'Content-Type: application/json';
        }
    }
    if (isset($o['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    if (isset($o['lang'])) {
        $headers[] = 'Accept-Language: ' . $o['lang'];
    }
    if (isset($o['jar'])) {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $o['jar']);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $o['jar']);
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

    $raw    = (string) curl_exec($handle);
    $size   = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);

    $body = json_decode(substr($raw, $size), true);

    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => substr($raw, $size), 'headers' => substr($raw, 0, $size)];
}

function summary(array $r): string
{
    return 'HTTP ' . $r['status'] . ' ' . substr($r['raw'], 0, 400);
}

function csrf_of(string $jar, string $lang = 'nl'): string
{
    $page = http('/', ['get' => true, 'jar' => $jar, 'lang' => $lang]);
    preg_match('/data-csrf="([^"]+)"/', $page['raw'], $m);

    return $m[1] ?? '';
}

/**
 * The words that are Dutch and not English: every word of the catalogue's
 * Dutch that its English never uses, the Dutch month and day names, and the
 * small words Dutch is made of — less names that are the same in both.
 */
function dutch_words(): array
{
    $catalog = i18n_catalog();
    $dutch = $english = [];
    $words = static fn (string $s): array => preg_split('/[^\p{L}]+/u', mb_strtolower(preg_replace('/%(\d+\$)?[sd]/', ' ', $s)), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($catalog as $nl => $en) {
        foreach ($words((string) $nl) as $w) {
            $dutch[$w] = true;
        }
        foreach ($words((string) $en) as $w) {
            $english[$w] = true;
        }
    }
    foreach (['januari', 'februari', 'maart', 'mei', 'juni', 'juli', 'augustus', 'oktober', 'maandag', 'dinsdag', 'woensdag',
              'donderdag', 'vrijdag', 'zaterdag', 'zondag', 'mrt', 'okt', 'het', 'een', 'niet', 'nog', 'geen', 'voor', 'naar',
              'jouw', 'wordt', 'zijn', 'hebt', 'heeft', 'gisteren', 'vandaag', 'uur', 'dagen', 'nachten', 'stappen', 'gemiddeld',
              /* Dutch any page could say that the catalogue happens not to: a string missing from it is found too. */
              'jij', 'wij', 'jullie', 'mijn', 'onze', 'worden', 'werd', 'kan', 'kun', 'kunt', 'moet', 'moeten', 'mag', 'wil', 'wilt',
              'met', 'van', 'bij', 'uit', 'tot', 'dan', 'dit', 'deze', 'wat', 'hoe', 'waar', 'wie', 'ook', 'maar', 'meer', 'minder',
              'alleen', 'zodra', 'zonder', 'tussen', 'na', 'nu', 'hier', 'daar', 'altijd', 'nooit', 'eerst', 'daarna', 'opnieuw',
              'probeer', 'bezig', 'laden', 'fout', 'gelukt', 'mislukt', 'nacht', 'maand', 'jaar', 'jaren', 'maanden', 'weken',
              'slaap', 'voeding', 'gezondheid', 'doel', 'doelen', 'opslaan', 'verwijderen', 'annuleren', 'instellingen',
              'gegevens', 'koppelen', 'ontkoppelen', 'terug', 'volgende', 'vorige', 'sluiten', 'toevoegen', 'bewerken', 'wijzigen',
              'inloggen', 'uitloggen', 'registreren', 'wachtwoord', 'gebruikersnaam', 'vrienden', 'ranglijst', 'overzicht',
              'apparaten', 'meldingen', 'taal', 'thema', 'voorkeuren', 'eenheden', 'behaald', 'gepauzeerd', 'klaar', 'verder',
              'overslaan', 'starten', 'stoppen', 'hartslag', 'gewicht', 'lengte', 'afstand', 'herstel', 'beweging', 'verloop',
              'punten', 'keer', 'tik', 'schuif', 'grafiek', 'kiezen', 'kies', 'bekijk', 'nieuw', 'nieuwe', 'oud', 'eigen',
              'zelf', 'elke', 'iedere', 'welke', 'omdat', 'zoals', 'misschien', 'graag', 'bedankt', 'welkom', 'hallo'] as $w) {
        $dutch[$w] = true;
    }
    $same = ['ownify', 'polar', 'google', 'health', 'connect', 'gemini', 'fitbit', 'pixel', 'watch', 'android', 'flow', 'hrv',
             'rem', 'spo', 'vo', 'max', 'bpm', 'kcal', 'ms', 'min', 'km', 'kg', 'cm', 'mg', 'ai', 'api', 'app', 'ok', 'jpg',
             'png', 'webp', 'mb', 'nederlands', 'english', 'focus', 'start', 'training', 'sport', 'community', 'account',
             'privacy', 'status', 'streak', 'yoga', 'pilates', 'tennis', 'hiit', 'beta', 'wcag', 'aa', 'https', 'www',
             'com', 'ownify_test', 'oauth', 'id', 'x', 'l', 'g', 'h', 'pt', 'u', 'ft', 'mi', 'lb', 'mph', 'in', 'is', 'of',
             'me', 'de', 'op', 'en', 'te', 'al', 'die', 'per', 'die', 'stop', 'total', 'contrast', 'water', 'reps', 'volume', 'week',
             'balans', 'zone', 'apps', 'e', 'mail', 'android', 'kotlin', 'androidx', 'services', 'play', 'gb', 'nl', 'en'];
    foreach (array_keys($dutch) as $w) {
        if (isset($english[$w]) || in_array($w, $same, true) || mb_strlen($w) < 3) {
            unset($dutch[$w]);
        }
    }

    return $dutch;
}

/**
 * The text a person meets on an HTML page: text nodes, every attribute that
 * is not an address, a class or geometry — decoded when it is JSON (copy
 * for the scripts) — and the JSON blocks the scripts read.
 */
function page_texts(string $html): array
{
    $texts = [];
    if (preg_match_all('#<script type="application/json"[^>]*>(.*?)</script>#si', $html, $m)) {
        foreach ($m[1] as $json) {
            $texts = array_merge($texts, state_texts(json_decode(html_entity_decode($json), true)));
        }
    }
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html);
    /* Text marked as Dutch is Dutch on purpose: a Dutch publication's own
       title ("Uit onderzoek blijkt", docs/RESEARCH.md). */
    $html = preg_replace('#<((?!html\b)[a-z][a-z0-9]*)\b[^>]*\slang="nl"[^>]*>.*?</\1>#si', ' ', $html);
    if (preg_match_all('/\s([a-z][a-z0-9-]*)="([^"]*)"/', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as [, $name, $value]) {
            if (in_array($name, ['class', 'style', 'href', 'src', 'id', 'for', 'type', 'name', 'd', 'viewbox', 'viewBox', 'content',
                                 'action', 'method', 'role', 'lang', 'rel', 'sizes', 'autocomplete', 'inputmode', 'enterkeyhint',
                                 'width', 'height', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'offset', 'stop-color', 'points',
                                 'data-points', 'data-x', 'data-y', 'data-csrf', 'data-detail', 'data-detail-open', 'data-icon',
                                 'pattern', 'accept', 'stroke-width', 'fill', 'stroke', 'transform', 'aria-controls',
                                 'aria-labelledby', 'aria-describedby', 'data-field-input'], true)) {
                continue;
            }
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            $json  = json_decode($value, true);
            if (is_array($json)) {
                $texts = array_merge($texts, state_texts($json));
            } elseif (preg_match('/\p{L}{2}/u', $value)) {
                $texts[] = $value;
            }
        }
    }
    foreach (preg_split('/<[^>]*>/', $html) as $node) {
        $node = trim(html_entity_decode($node, ENT_QUOTES | ENT_HTML5));
        if ($node !== '') {
            $texts[] = $node;
        }
    }

    return $texts;
}

/** Every string value in the app's state that a person can read (not ids, not keys). */
function state_texts(mixed $node, string $key = ''): array
{
    static $skip = ['id', 'key', 'icon', 'area', 'accent', 'tone', 'kind', 'type', 'format', 'state', 'scale', 'availability',
                    'mode', 'href', 'endpoint', 'provider', 'transport', 'status', 'band', 'detail', 'date', 'source_kind',
                    'source_key', 'category', 'category_key', 'priority', 'direction', 'avatar', 'username', 'name', 'email',
                    'locale', 'chosen', 'units', 'focus', 'selected', 'field', 'opens', 'dots', 'per_day', 'domain',
                    'line', 'area_path', 'path', 'd', 'x', 'y', 'theme_color', 'theme_color_light', 'tagline_app', 'unit_key',
                    'target_unit', 'post_unit', 'value_key', 'goal', 'user', 'identities', 'question_id', 'grid', 'lower',
                    'layout', 'edit', 'step', 'min', 'max',
                    /* A measurement's search words hold both languages on purpose: "slapen" finds Slaapduur in either. */
                    'keywords', 'unit_codes', 'unit_code', 'imperial_unit', 'canonical', 'default_direction', 'user_auto',
                    'units_system', 'week_start', 'measurement', 'variant', 'period_unit', 'outcome', 'tracking'];
    if (is_array($node)) {
        $out = [];
        foreach ($node as $k => $v) {
            if (is_string($k) && in_array($k, $skip, true)) {
                continue;
            }
            /* A Dutch publication's title, as published (docs/RESEARCH.md). */
            if ($k === 'title' && ($node['language'] ?? null) === 'nl') {
                continue;
            }
            $out = array_merge($out, state_texts($v, is_string($k) ? $k : $key));
        }
        return $out;
    }

    return is_string($node) && preg_match('/\p{L}{2}/u', $node) ? [$node] : [];
}

function dutch_in(array $texts, array $dutch): array
{
    $found = [];
    foreach ($texts as $text) {
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (isset($dutch[$w])) {
                $found[$text] = $w;
                break;
            }
        }
    }

    return $found;
}

$port   = free_port();
$base   = 'http://127.0.0.1:' . $port;
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, getenv());
for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(50_000);
}

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'i18n-test-password';
$made     = [];
$jars     = [];

register_shutdown_function(static function () use (&$made, &$jars, &$server): void {
    foreach ($made as $id) {
        db_run('DELETE FROM point_events WHERE user_id = ?', [$id]);
        db_run('DELETE FROM users WHERE id = ?', [$id]);
    }
    foreach ($jars as $jar) {
        @unlink($jar);
    }
    if (is_resource($server)) {
        proc_terminate($server);
    }
});

/** An account made in the app; its token, its id and its name. */
function account(string $who, string $lang = 'nl-NL', bool $setup = false): array
{
    global $run, $made, $password;

    $username = 'i18n_' . $run . '_' . $who;
    $r = http('/api/auth/app-register.php', ['lang' => $lang, 'json' => [
        'email' => $username . '@ownify-test.invalid', 'username' => $username, 'password' => $password,
        'label' => 'i18n test phone', 'platform' => 'android', 'app_version' => 'test',
    ]]);
    if (($r['body']['ok'] ?? false) !== true) {
        fwrite(STDERR, "Could not create $username: " . summary($r) . "\n");
        exit(1);
    }
    $token = (string) $r['body']['token'];
    if (!$setup) {
        http('/api/setup/finish.php', ['bearer' => $token, 'form' => []]);
    }
    $id = (int) db_value('SELECT id FROM users WHERE username = ?', [$username]);
    $made[] = $id;

    return ['username' => $username, 'token' => $token, 'id' => $id];
}

function state(array $who, string $lang = 'nl-NL'): array
{
    $r = http('/api/app/state.php', ['bearer' => $who['token'], 'lang' => $lang]);

    return $r['body']['data'] ?? ['_error' => summary($r)];
}

function prefer(array $who, array $fields, string $lang = 'nl-NL'): array
{
    return http('/api/profile/preferences.php', ['bearer' => $who['token'], 'lang' => $lang, 'form' => $fields]);
}

/** A week of everything, in the account's own rows (Health Connect's records, as the app sends them). */
function seed(int $userId): void
{
    $today = new DateTimeImmutable('today');
    $at    = static fn (DateTimeImmutable $d, string $time): string => (new DateTimeImmutable($d->format('Y-m-d') . ' ' . $time))->format(DATE_ATOM);
    db_run('UPDATE users SET created_at = NOW() - INTERVAL 30 DAY WHERE id = ?', [$userId]);
    db_run('UPDATE user_profiles SET date_of_birth = ?, gender = ? WHERE user_id = ?', ['1990-04-12', 'female', $userId]);

    $records = [];
    for ($ago = 7; $ago >= 1; $ago--) {
        $day   = $today->modify("-{$ago} day");
        $night = $day->modify('-1 day');
        $records[] = ['recordType' => 'SleepSession', 'metadata' => ['id' => "s$ago", 'dataOrigin' => 'com.test.ring'],
            'startTime' => $at($night, '23:10:00'), 'endTime' => $at($day, '07:05:00'),
            'stages' => [
                ['stage' => 4, 'startTime' => $at($night, '23:10:00'), 'endTime' => $at($day, '01:00:00')],
                ['stage' => 5, 'startTime' => $at($day, '01:00:00'), 'endTime' => $at($day, '02:30:00')],
                ['stage' => 6, 'startTime' => $at($day, '02:30:00'), 'endTime' => $at($day, '04:00:00')],
                ['stage' => 1, 'startTime' => $at($day, '04:00:00'), 'endTime' => $at($day, '04:15:00')],
                ['stage' => 4, 'startTime' => $at($day, '04:15:00'), 'endTime' => $at($day, '07:05:00')],
            ]];
        $records[] = ['recordType' => 'Steps', 'metadata' => ['id' => "st$ago", 'dataOrigin' => 'com.test.phone'],
            'startTime' => $at($day, '08:00:00'), 'endTime' => $at($day, '20:00:00'), 'count' => 8000 + 300 * $ago];
        $records[] = ['recordType' => 'Distance', 'metadata' => ['id' => "d$ago", 'dataOrigin' => 'com.test.phone'],
            'startTime' => $at($day, '08:00:00'), 'endTime' => $at($day, '20:00:00'), 'distance' => ['meters' => 6000 + 100 * $ago]];
        $records[] = ['recordType' => 'SkinTemperature', 'metadata' => ['id' => "t$ago", 'dataOrigin' => 'com.test.ring'],
            'startTime' => $at($day, '04:00:00'), 'temperature' => ['celsius' => 33.8 + 0.1 * $ago]];
        if ($ago % 2 === 1) {
            $records[] = ['recordType' => 'ExerciseSession', 'metadata' => ['id' => "w$ago", 'dataOrigin' => 'com.test.watch'],
                'startTime' => $at($day, '18:00:00'), 'endTime' => $at($day, '18:45:00'), 'exerciseTypeName' => 'running'];
        }
    }
    health_import_records($userId, 'google_health_connect', health_connect_map($records)['records']);

    /* A run's own distance and climb, as a watch (Polar) reports them: 7.5 km in 45 minutes, 60 m up. */
    db_run('UPDATE workouts SET distance_m = 7500, elevation_gain_m = 60 WHERE user_id = ?', [$userId]);

    for ($ago = 7; $ago >= 1; $ago--) {
        $day = $today->modify("-{$ago} day")->format('Y-m-d');
        db_run('INSERT INTO health_metrics (user_id, metric_type_id, source_id, external_id, value, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?)', [$userId, health_metric_type_id('nutrition_rating'), health_source_id('manual'), 'daily-rating:' . $day, 7, $day . ' 20:00:00']);
    }

    user_record_measurement($userId, 'height', 180.0, 'cm');
    user_record_measurement($userId, 'weight', 80.0, 'kg');
}

/** Every stored row of the account that a setting could have touched, in one comparable string. */
function rows_of(int $userId): string
{
    return json_encode([
        db_all('SELECT measurement_type, value, unit FROM user_measurements WHERE user_id = ? ORDER BY id', [$userId]),
        db_all('SELECT name, target_value, target_unit, daily_target, start_value, best_value, total_value FROM goals WHERE user_id = ? ORDER BY id', [$userId]),
        db_all('SELECT distance_m, elevation_gain_m, duration_seconds FROM workouts WHERE user_id = ? ORDER BY id', [$userId]),
        db_all('SELECT m.value FROM health_metrics m WHERE m.user_id = ? ORDER BY m.id', [$userId]),
    ]);
}

try {
    section('6. The device decides until the account chooses');

    $ana = account('ana', 'en-US');
    seed($ana['id']);

    check('a new account has chosen nothing (locale NULL), whatever its phone said',
        db_value('SELECT locale FROM user_profiles WHERE user_id = ?', [$ana['id']]) === null);
    $s = state($ana, 'en-US,en;q=0.9');
    check('an English phone: the app state is English, chosen null', ($s['i18n']['locale'] ?? null) === 'en'
        && array_key_exists('chosen', $s['i18n'] ?? []) && $s['i18n']['chosen'] === null, json_encode($s['i18n'] ?? $s));
    check('…and says so in English', ($s['navigation'][0]['label'] ?? null) === 'Health' && ($s['overview']['title'] ?? null) === 'Today');
    check('a Dutch phone: Dutch', (state($ana, 'nl-NL')['i18n']['locale'] ?? null) === 'nl' && (state($ana, 'nl-NL')['navigation'][0]['label'] ?? null) === 'Gezondheid');
    check('a German phone: Dutch, the fallback', (state($ana, 'de-DE,de;q=0.9,en;q=0.8')['i18n']['locale'] ?? null) === 'nl');

    $r = prefer($ana, ['language' => 'en'], 'nl-NL');
    check('choosing English (from a Dutch phone): saved', ($r['body']['ok'] ?? false) === true && ($r['body']['locale'] ?? null) === 'en'
        && db_value('SELECT locale FROM user_profiles WHERE user_id = ?', [$ana['id']]) === 'en', summary($r));
    check('…and answered in English at once', ($r['body']['message'] ?? null) === 'Saved.');
    check('the account\'s choice beats the phone: English on a Dutch phone', (state($ana, 'nl-NL')['i18n']['locale'] ?? null) === 'en'
        && (state($ana, 'nl-NL')['i18n']['chosen'] ?? null) === 'en');
    $r = prefer($ana, ['language' => 'auto'], 'nl-NL');
    check('Automatisch: the choice is forgotten (NULL), the phone decides again', array_key_exists('chosen', $r['body'] ?? []) && $r['body']['chosen'] === null
        && db_value('SELECT locale FROM user_profiles WHERE user_id = ?', [$ana['id']]) === null
        && (state($ana, 'nl-NL')['i18n']['locale'] ?? null) === 'nl' && (state($ana, 'en-GB')['i18n']['locale'] ?? null) === 'en');
    check('nonsense is refused: 422', prefer($ana, ['language' => 'fr'])['status'] === 422 && prefer($ana, ['units' => 'furlongs'])['status'] === 422
        && prefer($ana, [])['status'] === 422);
    check('a refusal is said in the request\'s language', (prefer($ana, ['language' => 'fr'], 'en-US')['body']['error'] ?? null) === 'Unknown language.');

    section('7. The website: the same choice, kept on the account and in the browser');

    $jar = tempnam(sys_get_temp_dir(), 'i18n');
    $jars[] = $jar;
    $page = http('/', ['get' => true, 'jar' => $jar, 'lang' => 'en-US']);
    check('signed out, an English browser: the opening screen in English', str_contains($page['raw'], '<html lang="en"')
        && str_contains($page['raw'], 'Log in') && !str_contains($page['raw'], 'Inloggen'), substr($page['raw'], 0, 200));
    $page = http('/', ['get' => true, 'jar' => $jar, 'lang' => 'nl-NL']);
    check('…a Dutch one: in Dutch', str_contains($page['raw'], '<html lang="nl"') && str_contains($page['raw'], 'Inloggen'));

    $login = http('/api/auth/login.php', ['jar' => $jar, 'lang' => 'nl-NL', 'form' => ['csrf' => csrf_of($jar), 'username' => $ana['username'], 'password' => $password]]);
    check('signed in on the website', $login['status'] === 200, summary($login));
    $r = http('/api/profile/preferences.php', ['jar' => $jar, 'lang' => 'nl-NL', 'form' => ['csrf' => csrf_of($jar), 'language' => 'en']]);
    check('English chosen on the website: saved on the account, and a cookie for this browser',
        ($r['body']['ok'] ?? false) === true && db_value('SELECT locale FROM user_profiles WHERE user_id = ?', [$ana['id']]) === 'en'
        && str_contains($r['headers'], 'ownify_lang=en'), summary($r));
    $page = http('/', ['get' => true, 'jar' => $jar, 'lang' => 'nl-NL']);
    check('the next page load is English, on a Dutch browser', str_contains($page['raw'], '<html lang="en"'));
    check('…the English catalogue goes to the scripts, and the units', str_contains($page['raw'], 'assets/js/lang-en.js')
        && str_contains($page['raw'], 'data-units="metric"'));
    check('the same choice reaches the app (one account, one language)', (state($ana, 'nl-NL')['i18n']['locale'] ?? null) === 'en');

    http('/api/auth/logout.php', ['jar' => $jar, 'lang' => 'nl-NL', 'form' => ['csrf' => csrf_of($jar, 'nl-NL')]]);
    $page = http('/', ['get' => true, 'jar' => $jar, 'lang' => 'nl-NL']);
    check('signed out again: the opening screen stays English (the browser kept the choice)', str_contains($page['raw'], '<html lang="en"')
        && str_contains($page['raw'], 'Log in'));
    $login = http('/api/auth/login.php', ['jar' => $jar, 'lang' => 'nl-NL', 'form' => ['csrf' => csrf_of($jar), 'username' => $ana['username'], 'password' => $password]]);
    $page = http('/', ['get' => true, 'jar' => $jar, 'lang' => 'nl-NL']);
    check('signed in again: still English', str_contains($page['raw'], '<html lang="en"'));

    section('8. Nothing Dutch left in English');

    $dutch = dutch_words();
    check('the Dutch-only vocabulary to look for is not empty', count($dutch) > 300, (string) count($dutch));

    $nlPage = http('/', ['get' => true, 'jar' => $jar, 'lang' => 'nl-NL']);
    prefer($ana, ['language' => 'nl']);
    $nlState = state($ana, 'nl-NL');
    prefer($ana, ['language' => 'en']);
    $nlHits = count(dutch_in(state_texts($nlState), $dutch));
    check('the search works: the same state in Dutch has plenty of Dutch in it', $nlHits > 200, (string) $nlHits);

    @file_put_contents(sys_get_temp_dir() . '/i18n-test-page-en.html', $page['raw']);
    $leftHtml = dutch_in(page_texts($page['raw']), $dutch);
    check('the website, signed in, every page and screen in the document: no Dutch', $leftHtml === [],
        implode("\n          ", array_map(static fn ($t, $w) => "[$w] " . mb_substr($t, 0, 140), array_keys($leftHtml), $leftHtml)));

    $en = state($ana, 'en-US');
    $leftState = dutch_in(state_texts($en), $dutch);
    check('the app\'s state in English: no Dutch', $leftState === [],
        implode("\n          ", array_map(static fn ($t, $w) => "[$w] " . mb_substr($t, 0, 140), array_keys($leftState), $leftState)));

    $out = http('/', ['get' => true, 'jar' => tempnam(sys_get_temp_dir(), 'i18n'), 'lang' => 'en-US']);
    $leftWelcome = dutch_in(page_texts($out['raw']), $dutch);
    check('the opening screen in English: no Dutch', $leftWelcome === [], implode("\n          ", array_keys($leftWelcome)));

    /* A brand-new account: the setup, on the website and in the app. */
    $new = account('new', 'en-US', true);
    $jarNew = tempnam(sys_get_temp_dir(), 'i18n');
    $jars[] = $jarNew;
    http('/api/auth/login.php', ['jar' => $jarNew, 'lang' => 'en-US', 'form' => ['csrf' => csrf_of($jarNew, 'en-US'), 'username' => $new['username'], 'password' => $password]]);
    $setupPage = http('/', ['get' => true, 'jar' => $jarNew, 'lang' => 'en-US']);
    check('a new account on an English browser: the setup, in English', str_contains($setupPage['raw'], 'data-setup')
        && str_contains($setupPage['raw'], 'How should Ownify work for you?'), substr($setupPage['raw'], 0, 300));
    $left = dutch_in(page_texts($setupPage['raw']), $dutch);
    check('…with no Dutch in it', $left === [], implode("\n          ", array_map(static fn ($t, $w) => "[$w] " . mb_substr($t, 0, 140), array_keys($left), $left)));
    $left = dutch_in(state_texts(state($new, 'en-US')), $dutch);
    check('the app\'s state for the setup: no Dutch', $left === [], implode("\n          ", array_map(static fn ($t, $w) => "[$w] " . mb_substr($t, 0, 140), array_keys($left), $left)));

    /* The first days: one day of data, the calibration card. */
    $early = account('early', 'en-US');
    db_run('UPDATE users SET created_at = NOW() - INTERVAL 1 DAY WHERE id = ?', [$early['id']]);
    db_run('INSERT INTO health_metrics (user_id, metric_type_id, source_id, external_id, value, recorded_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$early['id'], health_metric_type_id('nutrition_rating'), health_source_id('manual'), 'daily-rating:' . date('Y-m-d', strtotime('-1 day')), 6, date('Y-m-d 20:00:00', strtotime('-1 day'))]);
    user_record_measurement($early['id'], 'weight', 72.5, 'kg');
    $earlyState = state($early, 'en-US');
    check('the first days: a calibration card', !empty($earlyState['calibration']), json_encode(array_keys($earlyState)));
    $left = dutch_in(state_texts($earlyState), $dutch);
    check('…in English, with no Dutch', $left === [], implode("\n          ", array_map(static fn ($t, $w) => "[$w] " . mb_substr($t, 0, 140), array_keys($left), $left)));

    section('9. Units: imperial on the page, metric in the database');

    $before = rows_of($ana['id']);
    $metric = state($ana, 'en-US');
    $r = prefer($ana, ['units' => 'imperial'], 'en-US');
    check('imperial chosen: saved', ($r['body']['units'] ?? null) === 'imperial'
        && db_value('SELECT units FROM user_profiles WHERE user_id = ?', [$ana['id']]) === 'imperial', summary($r));
    $imp = state($ana, 'en-US');
    check('the state says imperial', ($imp['i18n']['units'] ?? null) === 'imperial');

    $fields = [];
    foreach ($imp['settings']['pages']['account']['blocks'] ?? [] as $block) {
        foreach ($block['fields'] ?? [] as $field) {
            $fields[$field['key']] = $field;
        }
    }
    check('weight 80 kg reads "176.4 lb"', ($fields['weight']['value'] ?? null) === '176.4 lb', json_encode($fields['weight'] ?? null));
    check('height 180 cm reads "5 ft 11 in"', ($fields['height']['value'] ?? null) === '5 ft 11 in', json_encode($fields['height'] ?? null));
    check('the weight editor starts at 176.4 in lb, and sends lb back', ($fields['weight']['input']['value'] ?? null) === '176.4'
        && ($fields['weight']['input']['unit'] ?? null) === 'lb' && ($fields['weight']['input']['post_unit'] ?? null) === 'lb');
    check('the height editor is feet and inches, 5 and 11, and sends inches', ($fields['height']['input']['type'] ?? null) === 'feet_inches'
        && ($fields['height']['input']['feet'] ?? null) === '5' && ($fields['height']['input']['inches'] ?? null) === '11'
        && ($fields['height']['input']['post_unit'] ?? null) === 'in');

    $metrics = $imp['health']['metrics'] ?? [];
    check('the registry says mi and °F where it said km and °C', ($metrics['distance']['unit'] ?? null) === 'mi'
        && ($metrics['skin_temp']['unit'] ?? null) === '°F' && ($metrics['elevation']['unit'] ?? null) === 'ft');

    $sessions = $imp['health']['areas']['training']['view']['details'] ?? [];
    $stats = [];
    foreach ($sessions as $session) {
        foreach ($session['stats'] ?? [] as $stat) {
            $stats[$stat['key']] = $stat;
        }
    }
    check('a 7.5 km run reads 4.7 mi', ($stats['distance']['value'] ?? null) === '4.7' && ($stats['distance']['unit'] ?? null) === 'mi', json_encode($stats['distance'] ?? null));
    check('its pace per mile: 9:39 /mi (6:00 /km)', ($stats['pace']['value'] ?? null) === '9:39' && ($stats['pace']['unit'] ?? null) === '/mi', json_encode($stats['pace'] ?? null));
    check('its elevation 60 m reads 197 ft', ($stats['elevation']['value'] ?? null) === '197' && ($stats['elevation']['unit'] ?? null) === 'ft', json_encode($stats['elevation'] ?? null));

    $charts = [];
    foreach (['sleep', 'training'] as $area) {
        foreach ($imp['health']['areas'][$area]['view']['charts'] ?? [] as $chart) {
            $charts[$chart['id']] = $chart;
        }
    }
    $distanceSeries = array_values(array_filter($charts['steps']['series'] ?? [], static fn ($s) => $s['key'] === 'distance'));
    check('the Steps + Distance chart draws miles', ($charts['steps']['latest'] ?? null) !== null
        && str_contains(json_encode($charts['steps']), ' mi'), json_encode($charts['steps']['latest'] ?? null));
    check('the skin temperature chart reads °F', str_contains(json_encode($charts['skin_temp'] ?? [], JSON_UNESCAPED_UNICODE), '°F'));

    check('not one stored row changed', rows_of($ana['id']) === $before);

    section('10. Typed in imperial, stored in metric — once');

    $r = http('/api/profile/update.php', ['bearer' => $ana['token'], 'lang' => 'en-US', 'form' => ['weight' => '170', 'weight_unit' => 'lb']]);
    $stored = (float) db_value("SELECT value FROM user_measurements WHERE user_id = ? AND measurement_type = 'weight' ORDER BY id DESC LIMIT 1", [$ana['id']]);
    check('170 lb is stored as 77.111 kg', ($r['body']['ok'] ?? false) === true && near($stored, 77.111, 0.001), summary($r) . ' ' . $stored);
    $r = http('/api/profile/update.php', ['bearer' => $ana['token'], 'lang' => 'en-US', 'form' => ['height' => '71', 'height_unit' => 'in']]);
    $stored = (float) db_value("SELECT value FROM user_measurements WHERE user_id = ? AND measurement_type = 'height' ORDER BY id DESC LIMIT 1", [$ana['id']]);
    check('71 in is stored as 180.34 cm', ($r['body']['ok'] ?? false) === true && near($stored, 180.34, 0.001), summary($r));
    $r = http('/api/profile/update.php', ['bearer' => $ana['token'], 'lang' => 'en-US', 'form' => ['height' => "6'0", 'height_unit' => 'in']]);
    check('"6\'0" is stored as 182.88 cm', ($r['body']['ok'] ?? false) === true
        && near((float) db_value("SELECT value FROM user_measurements WHERE user_id = ? AND measurement_type = 'height' ORDER BY id DESC LIMIT 1", [$ana['id']]), 182.88, 0.001));
    $r = http('/api/profile/update.php', ['bearer' => $ana['token'], 'lang' => 'en-US', 'form' => ['weight' => '80']]);
    check('an older app that names no unit typed kg, as always: 80 kg stays 80 kg', ($r['body']['ok'] ?? false) === true
        && near((float) db_value("SELECT value FROM user_measurements WHERE user_id = ? AND measurement_type = 'weight' ORDER BY id DESC LIMIT 1", [$ana['id']]), 80.0));
    $r = http('/api/profile/update.php', ['bearer' => $ana['token'], 'lang' => 'en-US', 'form' => ['weight' => '1000', 'weight_unit' => 'lb']]);
    check('the limits are the stored ones: 1000 lb (454 kg) refused, in English', $r['status'] === 422 && ($r['body']['error'] ?? '') === 'Enter a valid value for weight.', summary($r));

    /* No unit named: an imperial reader's default — lb, mi. */
    $goal = http('/api/goals/create.php', ['bearer' => $ana['token'], 'lang' => 'en-US', 'form' => [
        'name' => 'Reach my weight', 'measurement' => 'weight', 'type' => 'milestone', 'duration' => 'quarter',
        'target_value' => '165', 'direction' => 'decrease',
    ]]);
    $row = db_one('SELECT target_value, unit_code FROM goals WHERE user_id = ? AND name = ?', [$ana['id'], 'Reach my weight']);
    check('a weight goal of 165 lb is stored as 74.843 kg, kept in lb', ($goal['body']['ok'] ?? false) === true && $row !== null
        && near((float) $row['target_value'], 74.843, 0.001) && $row['unit_code'] === 'lb', summary($goal) . json_encode($row));

    $goal = http('/api/goals/create.php', ['bearer' => $ana['token'], 'lang' => 'en-US', 'form' => [
        'name' => 'Run a lot', 'measurement' => 'distance', 'type' => 'accumulate', 'duration' => 'month', 'target_value' => '50',
    ]]);
    $row = db_one('SELECT target_value, unit_code FROM goals WHERE user_id = ? AND name = ?', [$ana['id'], 'Run a lot']);
    check('a distance goal of 50 mi is stored as 80.467 km, kept in mi', ($goal['body']['ok'] ?? false) === true && $row !== null
        && near((float) $row['target_value'], 80.467, 0.001) && $row['unit_code'] === 'mi', summary($goal) . json_encode($row));

    $goal = http('/api/goals/create.php', ['bearer' => $ana['token'], 'lang' => 'en-US', 'form' => [
        'name' => 'Bench press', 'measurement' => 'custom', 'category' => 'training', 'type' => 'milestone', 'duration' => 'quarter',
        'unit' => 'lb', 'target_value' => '225', 'baseline_value' => '135', 'direction' => 'increase',
    ]]);
    $bench = db_one('SELECT id, target_value, baseline_value, unit_code FROM goals WHERE user_id = ? AND name = ?', [$ana['id'], 'Bench press']);
    check('a goal kept by hand, 135 → 225 lb, is stored as 61.235 → 102.058 kg', ($goal['body']['ok'] ?? false) === true && $bench !== null
        && near((float) $bench['target_value'], 102.058, 0.001) && near((float) $bench['baseline_value'], 61.235, 0.001) && $bench['unit_code'] === 'lb',
        summary($goal) . json_encode($bench));
    $r = http('/api/goals/progress.php', ['bearer' => $ana['token'], 'lang' => 'en-US', 'form' => ['goal_id' => $bench['id'], 'value' => '200']]);
    $entry = (float) db_value('SELECT current_value FROM goal_progress WHERE goal_id = ? ORDER BY recorded_on DESC LIMIT 1', [$bench['id']]);
    check('a result of 200 lb is stored as 90.718 kg, and 135 → 225 at 200 is 72%', ($r['body']['ok'] ?? false) === true && near($entry, 90.718, 0.001)
        && ($r['body']['percent'] ?? null) === 72, summary($r) . ' ' . $entry);

    $imp   = state($ana, 'en-US');
    $goals = [];
    foreach ($imp['goals']['all'] ?? [] as $g) {
        $goals[$g['name']] = $g;
    }
    check('the weight goal reads its target in lb', ($goals['Reach my weight']['target_label'] ?? null) === '165 lb', json_encode($goals['Reach my weight']['target_label'] ?? null));
    check('the distance goal reads 50 mi', ($goals['Run a lot']['target_label'] ?? null) === '50 mi', json_encode($goals['Run a lot']['target_label'] ?? null));
    check('the hand-kept goal: latest 200 lb of 225 lb, and 72% from the stored kilos', ($goals['Bench press']['current_label'] ?? null) === '200 lb'
        && ($goals['Bench press']['target_label'] ?? null) === '225 lb' && ($goals['Bench press']['percent'] ?? null) === 72,
        json_encode([$goals['Bench press']['current_label'] ?? null, $goals['Bench press']['target_label'] ?? null, $goals['Bench press']['percent'] ?? null]));
    check('its entry box asks in lb', ($goals['Bench press']['entry']['unit'] ?? null) === 'lb');
    check('its chart is drawn in lb', str_contains(json_encode($goals['Bench press']['chart'] ?? []), ' lb'));

    section('11. Back to metric: the very same page');

    $snapshot = rows_of($ana['id']);
    prefer($ana, ['units' => 'metric'], 'en-US');
    $back = state($ana, 'en-US');
    $goals = [];
    foreach ($back['goals']['all'] ?? [] as $g) {
        $goals[$g['name']] = $g;
    }
    check('a goal keeps the unit it was made in: still 200 lb of 225 lb, still 72%',
        ($goals['Bench press']['current_label'] ?? null) === '200 lb' && ($goals['Bench press']['target_label'] ?? null) === '225 lb'
        && ($goals['Bench press']['percent'] ?? null) === 72,
        json_encode([$goals['Bench press']['current_label'] ?? null, $goals['Bench press']['target_label'] ?? null]));
    check('switching units changed no row', rows_of($ana['id']) === $snapshot);
    $strip = static fn (array $s): string => json_encode([$s['health']['areas']['training']['view']['details'] ?? null, $s['health']['metrics'] ?? null]);
    check('the training sessions and the registry are exactly what metric showed before imperial', $strip($back) === $strip($metric));

    section('12. Language and units are independent');

    prefer($ana, ['units' => 'imperial', 'language' => 'nl']);
    $nlImp = state($ana, 'en-US');
    $fields = [];
    foreach ($nlImp['settings']['pages']['account']['blocks'] ?? [] as $block) {
        foreach ($block['fields'] ?? [] as $field) {
            $fields[$field['key']] = $field;
        }
    }
    check('Dutch and imperial: "Gezondheid", and the weight in lb with a decimal comma', ($nlImp['navigation'][0]['label'] ?? null) === 'Gezondheid'
        && ($fields['weight']['value'] ?? null) === '176,4 lb', json_encode($fields['weight']['value'] ?? null));
    prefer($ana, ['units' => 'metric', 'language' => 'en']);
    $enMet = state($ana, 'nl-NL');
    $fields = [];
    foreach ($enMet['settings']['pages']['account']['blocks'] ?? [] as $block) {
        foreach ($block['fields'] ?? [] as $field) {
            $fields[$field['key']] = $field;
        }
    }
    check('English and metric: "Health", "80 kg"', ($enMet['navigation'][0]['label'] ?? null) === 'Health' && ($fields['weight']['value'] ?? null) === '80 kg');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
