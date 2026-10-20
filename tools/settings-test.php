<?php
/**
 * Instellingen as of 19.0, tested on the rules and over HTTP.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/settings-test.php
 *
 * On the database named by DB_NAME (with migration 024):
 *
 *   - Voorkeuren kept on the account (api/profile/preferences.php): the week's
 *     first day and Toegankelijkheid's three switches, refused when unknown,
 *     and in the app's state afterwards
 *   - the week's first day where it shows: the goal's day calendar (its
 *     first day and its weekday initials) and Ownify AI's weekly totals
 *   - a phone's name (api/integrations/device-rename.php): only the phone
 *     named, only the owner's, at most 40 characters, empty gives its own
 *     name back, and signing in on it again keeps the owner's name
 *   - the Settings tree: no Leeftijd, no Smartwatch or Slaapring, no
 *     "Nu synchroniseren", the Toegankelijkheid switches live and "Nu actief"
 *     a status that follows them
 *
 * Accounts are named `st_…` and removed at the end, with everything hanging
 * off them. Never point it at a live database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 024 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/db.php';
require_once $root . '/includes/user.php';
require_once $root . '/includes/devices.php';
require_once $root . '/includes/goal-progress.php';
require_once $root . '/includes/ai/context.php';
require_once $root . '/lib/settings.php';
require_once $root . '/includes/i18n.php';

if (!db_available() || !preferences_columns_present() || !device_names_present()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 024.\n");
    exit(2);
}

i18n_force('nl');

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        printf("  PASS  %s\n", $label);
        return;
    }
    $fail++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : "\n          " . $detail);
}

function section(string $title): void
{
    printf("\n== %s ==\n", $title);
}

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name   = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/** @return array{status: int, body: ?array, raw: string} */
function http(string $path, array $o = []): array
{
    global $base;
    $handle  = curl_init($base . $path);
    $headers = [];
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);
    if (($o['get'] ?? false) !== true) {
        curl_setopt($handle, CURLOPT_POST, true);
        if (isset($o['json'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($o['json']));
        } else {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($o['form'] ?? []));
        }
    }
    if (isset($o['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    $raw    = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);
    $body = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $raw];
}

function summary(array $r): string
{
    return 'HTTP ' . $r['status'] . ' ' . substr($r['raw'], 0, 300);
}

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'settings-test-password';
$made     = [];

/** An account made in the app on a phone called $phone: username, token, id. */
function account(string $who, string $phone): array
{
    global $run, $made, $password;
    $username = 'st_' . $run . '_' . $who;
    $made[]   = $username;
    $r = http('/api/auth/app-register.php', ['json' => [
        'email' => $username . '@ownify-test.invalid', 'username' => $username, 'password' => $password,
        'label' => $phone, 'platform' => 'android', 'app_version' => 'test',
    ]]);
    if (($r['body']['ok'] ?? false) !== true) {
        fwrite(STDERR, "Could not create $username: " . summary($r) . "\n");
        exit(1);
    }
    $token = (string) $r['body']['token'];
    http('/api/setup/finish.php', ['bearer' => $token, 'form' => []]);
    return ['username' => $username, 'token' => $token, 'id' => (int) db_value('SELECT id FROM users WHERE username = ?', [$username])];
}

/** Signing in on another phone (or the same one again, with its token). */
function sign_in(array $who, string $phone, ?string $presented = null): string
{
    global $password;
    $r = http('/api/auth/app-login.php', ['json' => [
        'identifier' => $who['username'], 'password' => $password,
        'label' => $phone, 'platform' => 'android', 'app_version' => 'test',
    ]] + ($presented !== null ? ['bearer' => $presented] : []));
    return (string) ($r['body']['token'] ?? '');
}

function phone_of(string $token): array
{
    return db_one('SELECT id, label, custom_label FROM user_devices WHERE token_hash = ?', [device_hash($token)]) ?? [];
}

function profile_of(array $who): array
{
    return db_one('SELECT week_start, text_large, contrast_more, motion_off FROM user_profiles WHERE user_id = ?', [$who['id']]) ?? [];
}

$port   = free_port();
$base   = 'http://127.0.0.1:' . $port;
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, getenv());

for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(50_000);
}

try {
    $sanne = account('sanne', 'Pixel 9');
    $lotte = account('lotte', 'Galaxy S25');

    /* ==================================================================
       VOORKEUREN ON THE ACCOUNT
       ================================================================== */
    section('Voorkeuren: kept on the account');

    $p = profile_of($sanne);
    check('a new account: Monday, and the three switches off', $p['week_start'] === null && (int) $p['text_large'] === 0
        && (int) $p['contrast_more'] === 0 && (int) $p['motion_off'] === 0, json_encode($p));

    $r = http('/api/profile/preferences.php', ['bearer' => $sanne['token'], 'form' => ['week' => 'sunday']]);
    check('the week from Sunday: saved', $r['status'] === 200 && ($r['body']['week'] ?? null) === 'sunday', summary($r));
    check('and kept', profile_of($sanne)['week_start'] === 'sunday');

    $r = http('/api/profile/preferences.php', ['bearer' => $sanne['token'], 'form' => ['week' => 'tuesday']]);
    check('an unknown first day: 422, nothing changed', $r['status'] === 422 && profile_of($sanne)['week_start'] === 'sunday', summary($r));

    $r = http('/api/profile/preferences.php', ['bearer' => $sanne['token'], 'form' => ['motion_off' => '1', 'text_large' => '1']]);
    check('Animaties uit and Grotere tekst: on, answered as the switches', $r['status'] === 200
        && ($r['body']['motion_off'] ?? null) === true && ($r['body']['text_large'] ?? null) === true
        && ($r['body']['contrast_more'] ?? null) === false, summary($r));
    $p = profile_of($sanne);
    check('and kept', (int) $p['motion_off'] === 1 && (int) $p['text_large'] === 1 && (int) $p['contrast_more'] === 0);

    $r = http('/api/profile/preferences.php', ['bearer' => $sanne['token'], 'form' => ['contrast_more' => 'yes']]);
    check('a switch that is not 0 or 1: 422', $r['status'] === 422 && (int) profile_of($sanne)['contrast_more'] === 0, summary($r));

    check('another account is untouched', profile_of($lotte)['week_start'] === null && (int) profile_of($lotte)['motion_off'] === 0);

    $r = http('/api/profile/preferences.php', ['form' => ['week' => 'sunday']]);
    check('the website without its CSRF token: 419', $r['status'] === 419, summary($r));

    $state = http('/api/app/state.php', ['bearer' => $sanne['token']]);
    $i18n  = $state['body']['data']['i18n'] ?? [];
    check('the app\'s state carries them', ($i18n['week_start'] ?? null) === 'sunday' && ($i18n['motion_off'] ?? null) === true
        && ($i18n['text_large'] ?? null) === true && ($i18n['contrast_more'] ?? null) === false, json_encode($i18n));

    /* ==================================================================
       WHERE THE WEEK STARTS
       ================================================================== */
    section('the week\'s first day, where it shows');

    $thursday = new DateTimeImmutable('2026-10-15');
    $goal     = ['start_date' => '2026-10-13', 'end_date' => '2026-10-31'];

    $state = &i18n_state();
    $state['week_start'] = 'monday';
    check('Monday: a Thursday\'s week starts on Monday the 12th', week_first_day($thursday)->format('Y-m-d') === '2026-10-12');
    $days = goal_calendar($goal, ['days' => []], $thursday);
    check('Monday: the calendar starts on Monday 12 October', $days[0]['date'] === '2026-10-12' && $days[0]['state'] === 'before', json_encode($days[0]));
    check('Monday: M D W D V Z Z', week_initials() === ['M', 'D', 'W', 'D', 'V', 'Z', 'Z']);

    $state['week_start'] = 'sunday';
    check('Sunday: a Thursday\'s week starts on Sunday the 11th', week_first_day($thursday)->format('Y-m-d') === '2026-10-11');
    check('Sunday: a Sunday is its own week\'s first day', week_first_day(new DateTimeImmutable('2026-10-18'))->format('Y-m-d') === '2026-10-18');
    $days = goal_calendar($goal, ['days' => []], $thursday);
    check('Sunday: the calendar starts on Sunday 11 October', $days[0]['date'] === '2026-10-11', json_encode($days[0]));
    check('Sunday: Z M D W D V Z', week_initials() === ['Z', 'M', 'D', 'W', 'D', 'V', 'Z']);
    check('every row still seven days: the goal\'s days fill whole weeks', (count($days) - (int) (new DateTimeImmutable('2026-10-11'))->diff(new DateTimeImmutable('2026-10-31'))->days - 1) === 0);

    /* A long goal shows the weeks up to the end of this week — Saturday
       when the week starts on Sunday. */
    $long = goal_calendar(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], ['days' => []], $thursday);
    check('Sunday: a long goal\'s calendar ends on Saturday the 17th', end($long)['date'] === '2026-10-17', json_encode(end($long)));
    $state['week_start'] = 'monday';
    $long = goal_calendar(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], ['days' => []], $thursday);
    check('Monday: on Sunday the 18th', end($long)['date'] === '2026-10-18', json_encode(end($long)));

    /* Ownify AI's weekly totals, by each week's first day. */
    $source = (int) db_value("SELECT id FROM data_sources WHERE code = 'polar'");
    foreach (['-4 day', '-3 day'] as $back) {
        $day = (new DateTimeImmutable('today'))->modify($back)->format('Y-m-d');
        db_run('INSERT INTO workouts (user_id, source_id, activity_type, started_at, ended_at, duration_seconds) VALUES (?, ?, ?, ?, ?, ?)',
            [$sanne['id'], $source, 'running', $day . ' 18:00:00', $day . ' 18:30:00', 1800]);
    }
    $state['week_start'] = 'sunday';
    $keys = array_keys(ai_block_training($sanne['id'], 14)['per_week'] ?? []);
    $expect = array_values(array_unique(array_map(
        static fn (string $back): string => week_first_day((new DateTimeImmutable('today'))->modify($back))->format('Y-m-d'),
        ['-4 day', '-3 day']
    )));
    check('AI: each week keyed by its first day, from Sunday', $keys === $expect, json_encode([$keys, $expect]));
    check('and the note says where the weeks start', str_contains((string) (ai_block_training($sanne['id'], 14)['note'] ?? ''), 'start on Sunday'));
    $state['week_start'] = 'monday';

    /* ==================================================================
       A PHONE'S NAME
       ================================================================== */
    section('renaming a phone');

    $second   = sign_in($sanne, 'Galaxy Tab');
    $phoneA   = phone_of($sanne['token']);
    $phoneB   = phone_of($second);
    $phoneL   = phone_of($lotte['token']);
    check('two phones on one account, each its own row', !empty($phoneA['id']) && !empty($phoneB['id']) && $phoneA['id'] !== $phoneB['id']);

    $r = http('/api/integrations/device-rename.php', ['bearer' => $sanne['token'], 'form' => ['device' => $phoneA['id'], 'name' => "  Sanne's   Pixel  "]]);
    check('renamed: one line, spaces tidied', $r['status'] === 200 && ($r['body']['name'] ?? null) === "Sanne's Pixel" && ($r['body']['custom'] ?? null) === true, summary($r));
    check('the name kept beside its own', phone_of($sanne['token'])['custom_label'] === "Sanne's Pixel" && phone_of($sanne['token'])['label'] === 'Pixel 9');
    check('the other phone keeps its name', phone_of($second)['custom_label'] === null && phone_of($second)['label'] === 'Galaxy Tab');

    $r = http('/api/integrations/device-rename.php', ['bearer' => $lotte['token'], 'form' => ['device' => $phoneA['id'], 'name' => 'Mine now']]);
    check('somebody else\'s phone: 404, nothing changed', $r['status'] === 404 && phone_of($sanne['token'])['custom_label'] === "Sanne's Pixel", summary($r));

    $r = http('/api/integrations/device-rename.php', ['bearer' => $sanne['token'], 'form' => ['device' => $phoneA['id'], 'name' => str_repeat('a', 41)]]);
    check('41 characters: 422', $r['status'] === 422 && phone_of($sanne['token'])['custom_label'] === "Sanne's Pixel", summary($r));

    $r = http('/api/integrations/device-rename.php', ['bearer' => $sanne['token'], 'form' => ['device' => 0, 'name' => 'x']]);
    check('no phone: 400', $r['status'] === 400, summary($r));

    /* Signing in on the same phone again rewrites what it calls itself, never the owner's name. */
    $again = sign_in($sanne, 'Pixel 9 Pro', $sanne['token']);
    $after = phone_of($again);
    check('signing in on it again: the same row, its own name updated, the owner\'s name kept',
        ($after['id'] ?? null) === $phoneA['id'] && $after['label'] === 'Pixel 9 Pro' && $after['custom_label'] === "Sanne's Pixel", json_encode($after));
    $sanne['token'] = $again;

    $settings = settings_prepare(config_copy('settings'), ['signed_in' => true, 'user' => ['id' => $sanne['id'], 'username' => $sanne['username']]]);
    $hc = array_values(array_filter($settings['integrations'], static fn ($i) => ($i['provider'] ?? '') === 'google_health_connect'))[0] ?? [];
    $names = array_column($hc['devices'] ?? [], 'label');
    check('Settings shows each by its name', in_array("Sanne's Pixel", $names, true) && in_array('Galaxy Tab', $names, true), json_encode($names));
    $own = array_values(array_filter($hc['devices'] ?? [], static fn ($d) => $d['label'] === "Sanne's Pixel"))[0] ?? [];
    check('with its own name, for the editor', ($own['own'] ?? null) === 'Pixel 9 Pro' && ($own['custom'] ?? null) === true, json_encode($own));

    $r = http('/api/integrations/device-rename.php', ['bearer' => $sanne['token'], 'form' => ['device' => $phoneA['id'], 'name' => '']]);
    check('empty: its own name back', $r['status'] === 200 && ($r['body']['name'] ?? null) === 'Pixel 9 Pro' && phone_of($sanne['token'])['custom_label'] === null, summary($r));

    $r = http('/api/integrations/device-rename.php', ['bearer' => $sanne['token'], 'form' => ['device' => $phoneL['id'], 'name' => 'x']]);
    check('Lotte\'s phone, asked by Sanne: 404', $r['status'] === 404 && phone_of($lotte['token'])['custom_label'] === null, summary($r));

    http('/api/integrations/device-revoke.php', ['bearer' => $sanne['token'], 'form' => ['device' => $phoneB['id']]]);
    $r = http('/api/integrations/device-rename.php', ['bearer' => $sanne['token'], 'form' => ['device' => $phoneB['id'], 'name' => 'Old tablet']]);
    check('a revoked phone: 404', $r['status'] === 404, summary($r));

    /* ==================================================================
       THE SETTINGS TREE
       ================================================================== */
    section('the Settings tree');

    $state['motion_off'] = true;
    $state['text_large'] = false;
    $settings = settings_prepare(config_copy('settings'), ['signed_in' => true, 'user' => ['id' => $sanne['id'], 'username' => $sanne['username']]]);

    $fields = [];
    foreach ($settings['pages']['account']['blocks'] as $block) {
        foreach ($block['fields'] ?? [] as $field) {
            $fields[] = $field['key'];
        }
    }
    check('no Leeftijd; the date of birth stays', !in_array('age', $fields, true) && in_array('date_of_birth', $fields, true), json_encode($fields));
    check('no Smartwatch or Slaapring', array_intersect(['watch', 'ring'], array_column($settings['integrations'], 'key')) === []);
    check('no "Nu synchroniseren"', !isset($settings['integration_labels']['sync_now']));

    $blocks  = $settings['pages']['preferences']['blocks'];
    $toggles = array_merge(...array_map(static fn ($b) => ($b['type'] === 'toggles') ? $b['items'] : [], $blocks));
    $byKey   = array_column($toggles, null, 'key');
    check('Toegankelijkheid: three live switches, to preferences.php', count(array_filter($toggles, static fn ($t) => ($t['endpoint'] ?? '') === 'api/profile/preferences.php')) === 3);
    check('each as the account has it', ($byKey['motion_off']['on'] ?? null) === true && ($byKey['text_large']['on'] ?? null) === false);

    $status = array_values(array_filter($blocks, static fn ($b) => $b['type'] === 'states' && !empty($b['status'])))[0] ?? [];
    $items  = array_column($status['items'] ?? [], null, 'key');
    check('"Nu actief" is a status block', ($status['title'] ?? null) === 'Nu actief');
    check('Minder beweging: Altijd, on', ($items['motion_off']['value'] ?? null) === 'Altijd' && ($items['motion_off']['tone'] ?? null) === 'on');
    check('Tekstgrootte: the device decides, neutral', ($items['text_large']['value'] ?? null) === 'Volgt je systeem' && ($items['text_large']['tone'] ?? null) === 'neutral');

    $week = array_values(array_filter($blocks, static fn ($b) => ($b['name'] ?? '') === 'week'))[0] ?? [];
    check('the week\'s choice is kept, no longer "not saved"', !empty($week['saves']));
    $state['motion_off'] = false;

} finally {
    foreach (array_unique($made) as $username) {
        db_run('DELETE FROM users WHERE username = ?', [$username]);
    }
    proc_terminate($server);
}

/* Pull to sync (20.0): only a source the server can ask on request says it can be synced. */
echo "\nPull to sync: which sources can be asked\n";
require_once $root . '/includes/integrations.php';
$providers = integration_providers();
check('Polar can be asked for new data (its sync endpoint)', !empty($providers['polar']['sync'])
    && is_file($root . '/api/integrations/polar/sync.php'));
check('Google Health cannot (no sync implementation)', empty($providers['google_health']['sync']));
check('Health Connect cannot: only its phone can send', empty($providers['google_health_connect']['sync']));
check('integration_public() says so', integration_public(0, 'polar')['can_sync'] === true
    && integration_public(0, 'google_health_connect')['can_sync'] === false);

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
