<?php
/**
 * The Ownify app's read (api/app/state.php) and the write endpoints it uses with
 * its account token, tested end to end over HTTP.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/app-state-test.php
 *
 * Starts the app on PHP's built-in server and makes the requests the Android
 * app and the website make, through the real endpoints and the real
 * authentication, on the database named by DB_NAME (with migration 013):
 *
 *   - state.php answers an account token with the page data, and refuses a
 *     sync token (403), a token that is nobody's (401) and a request with
 *     neither token nor CSRF (419); the website's session reads it too
 *   - what it answers is what the website renders: the same scores, goals and
 *     board, and nothing a browser session alone has (the CSRF token)
 *   - every write endpoint the app uses accepts the account token, refuses a
 *     sync token and an unknown one, and keeps the website's rules without a
 *     token (CSRF 419, then the session)
 *   - deleting the account from the app: the token goes with it, and the
 *     answer carries a message instead of a browser redirect
 *
 * Accounts are named `as_…` and removed at the end, with everything hanging
 * off them. Never point it at a live database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 013 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/db.php';
require_once $root . '/includes/devices.php';
require_once $root . '/includes/google-signin.php';
require_once $root . '/includes/user.php';

if (!db_available() || !devices_scoped()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 013.\n");
    exit(2);
}

/* ======================================================================
   PLUMBING
   ====================================================================== */

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

/**
 * One HTTP request. $o: json (array), form (array), file (field => path),
 * bearer, jar (cookie file), get (bool).
 *
 * @return array{status: int, body: ?array, raw: string}
 */
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
        } elseif (isset($o['file'])) {
            $fields = $o['form'] ?? [];
            foreach ($o['file'] as $name => $file) {
                $fields[$name] = new CURLFile($file, 'image/png', basename($file));
            }
            curl_setopt($handle, CURLOPT_POSTFIELDS, $fields);
        } else {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($o['form'] ?? []));
        }
    }

    if (isset($o['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    if (isset($o['jar'])) {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $o['jar']);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $o['jar']);
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

/** The CSRF token the page hands the browser. */
function csrf(string $jar): string
{
    $page = http('/', ['get' => true, 'jar' => $jar]);
    preg_match('/data-csrf="([^"]+)"/', $page['raw'], $m);

    return $m[1] ?? '';
}

/* --------------------------------------------------------- the accounts */

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'app-state-test-password';
$made     = [];

/**
 * An account made in the app; returns its account token. A new account
 * starts with its setup (includes/setup.php); unless [$setup], it is
 * finished here at once, so the app's pages are what the account reads.
 */
function app_account(string $who, bool $setup = false): array
{
    global $run, $made, $password;

    $username = 'as_' . $run . '_' . $who;
    $made[]   = $username;

    $r = http('/api/auth/app-register.php', ['json' => [
        'email' => $username . '@ownify-test.invalid', 'username' => $username, 'password' => $password,
        'label' => 'State test phone', 'platform' => 'android', 'app_version' => 'test',
    ]]);

    if (($r['body']['ok'] ?? false) !== true) {
        fwrite(STDERR, "Could not create $username: " . summary($r) . "\n");
        exit(1);
    }

    $token = (string) $r['body']['token'];

    if (!$setup) {
        http('/api/setup/finish.php', ['bearer' => $token, 'form' => []]);
    }

    return ['username' => $username, 'token' => $token, 'id' => (int) db_value('SELECT id FROM users WHERE username = ?', [$username])];
}

/** A second phone, paired with a code the app asked for: its sync token. */
function paired_phone(string $accountToken): string
{
    $code = http('/api/integrations/pairing-code.php', ['bearer' => $accountToken, 'form' => ['provider' => 'google_health_connect']]);
    $pair = http('/api/integrations/pair.php', ['json' => [
        'code' => $code['body']['code'] ?? '', 'label' => 'Paired phone', 'platform' => 'android', 'app_version' => 'test',
    ]]);

    return (string) ($pair['body']['token'] ?? '');
}

function png(): string
{
    $file  = tempnam(sys_get_temp_dir(), 'avatar') . '.png';
    $image = imagecreatetruecolor(400, 300);
    imagefill($image, 0, 0, imagecolorallocate($image, 60, 158, 114));
    imagepng($image, $file);

    return $file;
}

$port    = free_port();
$base    = 'http://127.0.0.1:' . $port;
$server  = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, getenv());

for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(50_000);
}

$unknown = str_repeat('ab', 32);

try {

    /* ==================================================================
       THE READ
       ================================================================== */

    $sanne = app_account('sanne');
    $sync  = paired_phone($sanne['token']);

    section('state.php with the account token');
    $state = http('/api/app/state.php', ['bearer' => $sanne['token']]);
    $data  = $state['body']['data'] ?? [];
    check('200, ok, version 1', $state['status'] === 200 && ($state['body']['ok'] ?? null) === true
        && ($state['body']['version'] ?? null) === 1, summary($state));
    check('every part of the page is there', array_diff(
        ['app', 'header', 'overview', 'scores', 'goal', 'insights', 'patterns', 'recommendation', 'navigation', 'ai',
         'disclaimer', 'auth', 'health', 'community', 'goals', 'settings', 'today', 'compass', 'setup', 'calibration',
         'focus', 'focus_labels'],
        array_keys($data)) === []);
    check('signed in as this account', ($data['auth']['signed_in'] ?? null) === true
        && ($data['auth']['user']['username'] ?? null) === $sanne['username']);
    check('no CSRF token, no one-time message, no waiting Google sign-in, no user id',
        !array_key_exists('csrf', $data['auth'] ?? []) && !array_key_exists('flash', $data['auth'] ?? [])
        && !array_key_exists('google_pending', $data['auth'] ?? []) && !array_key_exists('id', $data['auth']['user'] ?? []));
    check('an identity is its provider and address', ($data['auth']['identities'] ?? null) === [
        ['provider' => 'email', 'email' => $sanne['username'] . '@ownify-test.invalid']]);
    check('a new account: the "no data yet" line, and an empty score',
        ($data['disclaimer'] ?? null) === ($data['disclaimers']['no_data'] ?? '-')
        && array_key_exists('value', $data['scores']['overall'] ?? []) && $data['scores']['overall']['value'] === null);
    check('what the templates work out is worked out here too: charts, wizard sources, fields',
        isset($data['health']['trend']['charts']['sleep']['week']['has_data'])
        && ($data['goals']['wizard_sources'][0]['sources'][0]['types'] ?? null) === ['milestone']
        && array_key_exists('state', $data['settings']['pages']['account']['blocks'][1]['fields'][0] ?? []));
    $compass = $data['compass'] ?? [];
    check('the Scorekompas of a new account: no score, no direction, nothing made up',
        array_key_exists('value', $compass['score'] ?? []) && $compass['score']['value'] === null
        && array_key_exists('direction', $compass) && $compass['direction'] === null
        && ($compass['trend']['state'] ?? null) === 'empty' && ($compass['trend']['chart']['has_data'] ?? true) === false
        && array_column($compass['comparison']['rows'] ?? [], 'value') === [null, null, null, null]
        && ($compass['opportunity']['state'] ?? null) === 'empty');
    check('…and what the score will be made of: three categories, Slaap\'s components weighed 45 / 30 / 25',
        array_column($compass['composition']['categories'] ?? [], 'id') === ['sleep', 'nutrition', 'training']
        && array_column($compass['composition']['categories'][0]['parts'] ?? [], 'weight') === [45, 30, 25]);
    check('dates are text', !str_contains($state['raw'], '"timezone_type"'));
    check('not cached', stripos(http('/api/app/state.php', ['bearer' => $sanne['token']])['raw'], '"ok":true') !== false);

    section('state.php refuses everything that is not the account');
    check('a sync token: 403', http('/api/app/state.php', ['bearer' => $sync])['status'] === 403);
    check('a token that is nobody\'s: 401', http('/api/app/state.php', ['bearer' => $unknown])['status'] === 401);
    check('no token, no CSRF: 419 (the website\'s rule)', http('/api/app/state.php')['status'] === 419);
    check('GET: 405', http('/api/app/state.php', ['get' => true, 'bearer' => $sanne['token']])['status'] === 405);

    section('the website reads the same state with its session, and renders the same numbers');
    $jar   = tempnam(sys_get_temp_dir(), 'jar');
    $login = http('/api/auth/login.php', ['jar' => $jar, 'form' => [
        'csrf' => csrf($jar), 'username' => $sanne['username'], 'password' => $password]]);
    check('signed in on the website', $login['status'] === 200, summary($login));

    /* Some data, so there is something to compare. */
    http('/api/health/rating.php', ['bearer' => $sanne['token'], 'form' => ['rating' => '8']]);
    $web   = http('/api/app/state.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar)]]);
    $app   = http('/api/app/state.php', ['bearer' => $sanne['token']]);
    $page  = http('/', ['get' => true, 'jar' => $jar]);
    check('the session: 200, the same account', $web['status'] === 200
        && ($web['body']['data']['auth']['user']['username'] ?? null) === $sanne['username'], summary($web));
    check('the session and the token read the same scores and health',
        ($web['body']['data']['scores'] ?? 1) === ($app['body']['data']['scores'] ?? 2)
        && ($web['body']['data']['health']['areas'] ?? 1) === ($app['body']['data']['health']['areas'] ?? 2));
    $rated = null;
    foreach ($app['body']['data']['health']['areas']['nutrition']['highlights'] ?? [] as $metric) {
        if (($metric['key'] ?? null) === 'self_rating') {
            $rated = $metric['value'];
        }
    }
    check('the page shows the day\'s cijfer the app reads (' . var_export($rated, true) . ')',
        $rated === 8 && preg_match('/data-detail="nutrition".*?card--tiles.*?data-count-to="8"/s', $page['raw']) === 1);
    check('the session and the token read the same Scorekompas',
        isset($app['body']['data']['compass']) && ($web['body']['data']['compass'] ?? 1) === ($app['body']['data']['compass'] ?? 2));
    check('the score card opens it, and the page is there to open',
        str_contains($page['raw'], 'data-detail-open="score-compass"') && str_contains($page['raw'], 'data-detail="score-compass"'));
    $ring = $app['body']['data']['scores']['overall'] ?? [];
    $held = $app['body']['data']['compass']['score'] ?? [];
    check('its score is the ring\'s (' . var_export($ring['value'] ?? null, true) . ')',
        array_key_exists('value', $ring) && array_key_exists('value', $held) && $held['value'] === $ring['value']);

    /* Instellingen in each platform's own words (`value_app`, `note_app`). */
    $appSays = [];
    $variant = false;
    foreach ($app['body']['data']['settings']['pages'] ?? [] as $settingsPage) {
        foreach ($settingsPage['blocks'] ?? [] as $block) {
            foreach ($block['items'] ?? [] as $item) {
                $appSays[(string) ($item['label'] ?? '')] = [$item['value'] ?? null, $item['note'] ?? null];
                $variant = $variant || array_key_exists('value_app', $item) || array_key_exists('note_app', $item);
            }
        }
    }
    check('Over de app in the app\'s words: Ownify, and what it uses',
        ($appSays['Naam'][0] ?? null) === 'Ownify'
        && ($appSays['Licenties'][0] ?? null) === 'AndroidX, Kotlin en Google Play-services', json_encode($appSays['Licenties'] ?? null));
    check('…its text follows the phone, not a browser, and no variant is left for the app to choose',
        ($appSays['Tekstgrootte'][1] ?? null) === 'De app schaalt mee met de lettergrootte van je telefoon' && !$variant);
    check('the website keeps its own: no external packages, the browser\'s text size',
        str_contains($page['raw'], 'Geen externe pakketten')
        && str_contains($page['raw'], 'tekstgrootte van je browser') && !str_contains($page['raw'], 'Google Play-services'));

    /* Instellingen's groups, the same on both: Meldingen, Thema & uiterlijk and
       Taal under App, then Voorkeuren with the other three, each row still
       opening its own screen. */
    $want = ['Account' => ['account'], 'Gezondheid' => ['devices'], 'Privacy' => ['privacy'],
             'App' => ['notifications', 'theme', 'language'], 'Voorkeuren' => ['units', 'week', 'accessibility'],
             'Over' => ['about']];
    $appGroups = [];
    foreach ($app['body']['data']['settings']['groups'] ?? [] as $group) {
        $appGroups[(string) $group['label']] = array_column($group['rows'] ?? [], 'id');
    }
    check('Instellingen in the app: App holds Meldingen, Thema & uiterlijk and Taal, Voorkeuren the other three',
        $appGroups === $want, json_encode($appGroups));
    $webGroups = [];
    preg_match_all('~<section class="settings-group[^"]*"[^>]*>\s*<h2 class="settings-eyebrow"[^>]*>([^<]*)</h2>(.*?)</section>~s',
        $page['raw'], $found, PREG_SET_ORDER);
    foreach ($found as [, $label, $body]) {
        preg_match_all('~data-detail-open="settings-([a-z_]+)"~', $body, $ids);
        $webGroups[html_entity_decode($label)] = $ids[1];
    }
    unset($want['Account']);   // the website's is the card above the groups
    check('…and on the website, in the same order', $webGroups === $want, json_encode($webGroups));

    /* Over de app: no Gebouwd met, and Hulp without Contact — its heading over the note. */
    $hulp = null;
    foreach ($app['body']['data']['settings']['pages']['about']['blocks'] ?? [] as $block) {
        if (($block['title'] ?? null) === 'Hulp') {
            $hulp = $block;
        }
    }
    check('Over de app in the app: no Gebouwd met or Contact, Hulp over the note',
        !isset($appSays['Gebouwd met']) && !isset($appSays['Contact'])
        && ($hulp['type'] ?? null) === 'note' && str_starts_with((string) ($hulp['text'] ?? ''), 'Ownify is geen medisch hulpmiddel'),
        json_encode($hulp));
    check('…and on the website',
        !str_contains($page['raw'], 'Gebouwd met') && !str_contains($page['raw'], 'metric-row__label">Contact<')
        && preg_match('~>Hulp</h2>\s*<p class="settings-note">.*?Ownify is geen medisch hulpmiddel~s', $page['raw']) === 1);

    /* Apple Health: no iPhone app, so nothing to pair with on either side. */
    $apple = null;
    foreach ($app['body']['data']['settings']['integrations'] ?? [] as $integration) {
        if (($integration['provider'] ?? null) === 'apple_health') {
            $apple = $integration;
        }
    }
    check('Apple Health cannot be connected, and says why', $apple !== null && ($apple['available'] ?? true) === false
        && str_contains((string) ($apple['blocked'] ?? ''), 'Ownify heeft geen iPhone-app'));
    check('…and no pairing code is minted for it', http('/api/integrations/pairing-code.php',
        ['bearer' => $sanne['token'], 'form' => ['provider' => 'apple_health']])['status'] === 409);

    section('Vrienden toevoegen: on every Vrienden board, above #1 — on no Nederland board');
    preg_match_all('/data-board data-scope="([a-z]+)" data-period="([a-z]+)"(.*?)(?=data-board data-scope=|<\/section>)/s', $page['raw'], $boards, PREG_SET_ORDER);
    $seen = [];
    foreach ($boards as [, $boardScope, $boardPeriod, $html]) {
        $add   = strpos($html, 'data-friends-add-open');
        $first = strpos($html, 'class="board-list');
        $seen[$boardScope][$boardPeriod] = $add !== false && substr_count($html, 'data-friends-add-open') === 1
            && ($first === false || $add < $first) && str_contains($html, 'Vrienden toevoegen');
    }
    check('six boards: Vrienden and Nederland, each Maand, Jaar and All-time', count($boards) === 6
        && array_keys($seen) === ['friends', 'netherlands'], json_encode(array_map('array_keys', $seen)));
    check('every Vrienden board has it once, before its first row', ($seen['friends'] ?? []) === ['month' => true, 'year' => true, 'alltime' => true],
        json_encode($seen['friends'] ?? null));
    check('no Nederland board has it, in any period', !preg_match('/data-friends-add-open|Vrienden toevoegen/',
        implode('', array_map(static fn ($b) => $b[1] === 'netherlands' ? $b[3] : '', $boards))));
    check('the app reads the same row\'s label', ($app['body']['data']['community']['add_friends'] ?? null) === 'Vrienden toevoegen');

    /* ==================================================================
       THE WRITES
       ================================================================== */

    section('every write the app makes: the account token works, the others do not');

    $bram = app_account('bram');

    /** Calls one endpoint three ways; $form is what the account-token call sends. */
    $refused = static function (string $path, array $form) use (&$sync, $unknown): void {
        check("$path: sync token 403", http($path, ['bearer' => $sync, 'form' => $form])['status'] === 403);
        check("$path: unknown token 401", http($path, ['bearer' => $unknown, 'form' => $form])['status'] === 401);
        check("$path: no token, no CSRF 419", http($path, ['form' => $form])['status'] === 419);
    };

    $goalForm = ['name' => 'Bench press 100 kg', 'category' => 'strength', 'type' => 'milestone', 'duration' => 'month',
        'priority' => 'primary', 'source_kind' => 'manual', 'target_value' => '100', 'target_unit' => 'kg', 'direction' => 'increase'];
    $created = http('/api/goals/create.php', ['bearer' => $sanne['token'], 'form' => $goalForm]);
    check('goals/create: 200 with the account token', $created['status'] === 200 && ($created['body']['goal_id'] ?? 0) > 0, summary($created));
    $refused('/api/goals/create.php', $goalForm);
    $goalId = (int) ($created['body']['goal_id'] ?? 0);

    $progress = http('/api/goals/progress.php', ['bearer' => $sanne['token'], 'form' => ['goal_id' => $goalId, 'value' => '85']]);
    check('goals/progress: 200, and the server worked out 85%', $progress['status'] === 200
        && ($progress['body']['percent'] ?? null) == 85, summary($progress));
    $refused('/api/goals/progress.php', ['goal_id' => $goalId, 'value' => '90']);

    $rating = http('/api/health/rating.php', ['bearer' => $sanne['token'], 'form' => ['rating' => '9']]);
    check('health/rating: 200 with the server\'s message', $rating['status'] === 200 && isset($rating['body']['message']), summary($rating));
    $refused('/api/health/rating.php', ['rating' => '7']);

    $update = http('/api/profile/update.php', ['bearer' => $sanne['token'], 'form' => ['first_name' => 'Sanne']]);
    check('profile/update: 200', $update['status'] === 200 && ($update['body']['saved'] ?? null) === ['first_name'], summary($update));
    $refused('/api/profile/update.php', ['first_name' => 'X']);

    $gender = http('/api/profile/onboarding.php', ['bearer' => $sanne['token'], 'form' => ['gender' => 'female']]);
    check('profile/onboarding: 200, and a second answer is refused (422)', $gender['status'] === 200
        && http('/api/profile/onboarding.php', ['bearer' => $sanne['token'], 'form' => ['gender' => 'male']])['status'] === 422, summary($gender));
    $refused('/api/profile/onboarding.php', ['date_of_birth' => '1990-01-01']);

    $avatar = http('/api/profile/avatar.php', ['bearer' => $sanne['token'], 'file' => ['avatar' => png()]]);
    check('profile/avatar: 200, a picture', $avatar['status'] === 200 && str_starts_with((string) ($avatar['body']['avatar'] ?? ''), 'uploads/avatars/'), summary($avatar));
    check('profile/avatar: sync token 403', http('/api/profile/avatar.php', ['bearer' => $sync, 'file' => ['avatar' => png()]])['status'] === 403);

    $search = http('/api/friends/search.php', ['bearer' => $sanne['token'], 'form' => ['username' => $bram['username']]]);
    check('friends/search: 200, the person', $search['status'] === 200 && ($search['body']['person']['id'] ?? 0) === $bram['id'], summary($search));
    $refused('/api/friends/search.php', ['username' => $bram['username']]);

    $request = http('/api/friends/request.php', ['bearer' => $sanne['token'], 'form' => ['user_id' => $bram['id'], 'action' => 'request']]);
    check('friends/request: 200, "Verzoek verstuurd"', $request['status'] === 200
        && ($request['body']['message'] ?? null) === 'Verzoek verstuurd', summary($request));
    $refused('/api/friends/request.php', ['user_id' => $bram['id'], 'action' => 'cancel']);

    $setting = http('/api/friends/settings.php', ['bearer' => $sanne['token'], 'form' => ['allow_requests' => '0']]);
    check('friends/settings: 200', $setting['status'] === 200 && ($setting['body']['allow_requests'] ?? null) === false, summary($setting));
    $refused('/api/friends/settings.php', ['allow_requests' => '1']);

    $code = http('/api/integrations/pairing-code.php', ['bearer' => $sanne['token'], 'form' => ['provider' => 'google_health_connect']]);
    check('integrations/pairing-code: 200, a code', $code['status'] === 200 && strlen((string) ($code['body']['code'] ?? '')) === 8, summary($code));
    $refused('/api/integrations/pairing-code.php', ['provider' => 'google_health_connect']);

    $name = 'as_' . $run . '_sanne2';
    $made[] = $name;
    $renamed = http('/api/profile/username.php', ['bearer' => $sanne['token'], 'form' => ['username' => $name]]);
    check('profile/username: 200', $renamed['status'] === 200 && ($renamed['body']['username'] ?? null) === $name, summary($renamed));
    $refused('/api/profile/username.php', ['username' => $name . 'x']);

    $deleted = http('/api/goals/delete.php', ['bearer' => $sanne['token'], 'form' => ['goal_id' => $goalId]]);
    check('goals/delete: 200', $deleted['status'] === 200, summary($deleted));
    $refused('/api/goals/delete.php', ['goal_id' => $goalId]);

    $syncRow = (int) db_value('SELECT id FROM user_devices WHERE user_id = ? AND scope = ? AND revoked_at IS NULL', [$sanne['id'], 'sync']);
    $refused('/api/integrations/device-revoke.php', ['device' => $syncRow]);
    $revoked = http('/api/integrations/device-revoke.php', ['bearer' => $sanne['token'], 'form' => ['device' => $syncRow]]);
    check('integrations/device-revoke: 200, and that phone stops', $revoked['status'] === 200
        && http('/api/app/state.php', ['bearer' => $sync])['status'] === 401, summary($revoked));

    section('the website keeps its own rules on the same endpoints');
    check('without the CSRF token: 419', http('/api/friends/settings.php', ['jar' => $jar, 'form' => ['allow_requests' => '1']])['status'] === 419);
    check('with it: 200', http('/api/friends/settings.php', ['jar' => $jar, 'form' => ['allow_requests' => '1', 'csrf' => csrf($jar)]])['status'] === 200);

    section('Profielfoto op de ranglijst: the picture on every board, unless its owner keeps it off');

    $accepted = http('/api/friends/request.php', ['bearer' => $bram['token'], 'form' => ['user_id' => $sanne['id'], 'action' => 'accept']]);
    check('bram accepts sanne: the two are friends', $accepted['status'] === 200, summary($accepted));

    $upload  = (string) ($avatar['body']['avatar'] ?? '');
    $picture = avatar_small($upload);   // what the boards and friends lists show: its small copy
    $small   = @getimagesize($root . '/' . $picture);
    check('the upload got its small copy at once: a square of ' . AVATAR_SMALL_SIDE . ' px beside the original',
        $picture !== $upload && str_starts_with($picture, preg_replace('/\.[a-z]+$/', '', $upload) . '-s.')
        && $small !== false && $small[0] === AVATAR_SMALL_SIDE && $small[1] === AVATAR_SMALL_SIDE
        && is_file($root . '/' . $upload), $picture);

    /** Sanne's picture on each board $token's pages show her on: "scope/period" => path or null. */
    $sanneOnBoards = static function (string $token) use ($name): array {
        $found = [];
        foreach (http('/api/app/state.php', ['bearer' => $token])['body']['data']['community']['boards'] ?? [] as $scope => $periods) {
            foreach ($periods as $period => $board) {
                foreach ($board['entries'] as $entry) {
                    if ($entry['name'] === $name) {
                        $found["$scope/$period"] = $entry['avatar'];
                    }
                }
            }
        }
        return $found;
    };

    /** The account's own "Profielfoto op de ranglijst" switch, as its pages have it. */
    $boardSwitch = static function (string $token): ?array {
        foreach (http('/api/app/state.php', ['bearer' => $token])['body']['data']['settings']['pages']['privacy']['blocks'] ?? [] as $block) {
            foreach ($block['items'] ?? [] as $item) {
                if (($item['key'] ?? null) === 'leaderboard_avatar') {
                    return $item;
                }
            }
        }
        return null;
    };

    $seenByBram = $sanneOnBoards($bram['token']);
    check('on: bram sees sanne\'s picture on every Vrienden board', $picture !== ''
        && count(array_filter($seenByBram, static fn (string $key): bool => str_starts_with($key, 'friends/'), ARRAY_FILTER_USE_KEY)) === 3
        && array_unique(array_values($seenByBram)) === [$picture], json_encode($seenByBram));
    check('on: sanne sees her own picture there too', array_unique(array_values($sanneOnBoards($sanne['token']))) === [$picture]);
    $ownLine = static fn (string $token): ?string =>
        http('/api/app/state.php', ['bearer' => $token])['body']['data']['community']['you']['avatar'] ?? null;
    check('on: and on her own line where a board\'s top does not reach her', $ownLine($sanne['token']) === $picture);
    $switch = $boardSwitch($sanne['token']);
    check('on by default, with the note that says so', ($switch['on'] ?? null) === true
        && ($switch['note'] ?? null) === 'Anderen zien je profielfoto naast je naam.', json_encode($switch));
    check('the website draws it on her row', str_contains(http('/', ['get' => true, 'jar' => $jar])['raw'],
        'class="board-row__photo" src="' . $picture . '"'));

    $off = http('/api/profile/privacy.php', ['bearer' => $sanne['token'], 'form' => ['leaderboard_avatar' => '0']]);
    check('profile/privacy: 200, off', $off['status'] === 200 && ($off['body']['leaderboard_avatar'] ?? null) === false, summary($off));
    $sync = paired_phone($sanne['token']);   // the phone paired before was revoked above
    $refused('/api/profile/privacy.php', ['leaderboard_avatar' => '1']);
    check('profile/privacy: neither on nor off is 422', http('/api/profile/privacy.php',
        ['bearer' => $sanne['token'], 'form' => ['leaderboard_avatar' => 'yes']])['status'] === 422);

    $seenByBram = $sanneOnBoards($bram['token']);
    check('off: every board bram sees has her row, and no picture on it', count($seenByBram) >= 3
        && array_unique(array_values($seenByBram), SORT_REGULAR) === [null], json_encode($seenByBram));
    check('off: nor on the ones she sees herself', array_unique(array_values($sanneOnBoards($sanne['token'])), SORT_REGULAR) === [null]);
    check('off: nor on her own line', $ownLine($sanne['token']) === null);
    check('off: the path is nowhere in bram\'s pages but his friends list',
        substr_count(json_encode(http('/api/app/state.php', ['bearer' => $bram['token']])['body']['data']['community']['boards']),
            pathinfo($upload, PATHINFO_FILENAME)) === 0);
    $friendsOfBram = http('/api/app/state.php', ['bearer' => $bram['token']])['body']['data']['community']['friends'] ?? [];
    check('off: the friends list still has her picture — only the boards change', in_array($picture,
        array_map(static fn (array $p): ?string => $p['avatar'] ?? $p['avatar_path'] ?? null, $friendsOfBram), true), json_encode($friendsOfBram));
    $switch = $boardSwitch($sanne['token']);
    check('off: her switch says off, with the other note', ($switch['on'] ?? null) === false
        && ($switch['note'] ?? null) === 'Op de ranglijst staat je initiaal in plaats van je foto.', json_encode($switch));
    $page = http('/', ['get' => true, 'jar' => $jar])['raw'];
    check('off: the website draws her initial, no picture', !str_contains($page, 'class="board-row__photo" src="' . $picture . '"')
        && str_contains($page, 'data-setting-toggle="leaderboard_avatar"') && str_contains($page, 'aria-checked="false"'));

    check('the website saves it with its CSRF token, not without', http('/api/profile/privacy.php',
        ['jar' => $jar, 'form' => ['leaderboard_avatar' => '1']])['status'] === 419
        && http('/api/profile/privacy.php', ['jar' => $jar, 'form' => ['leaderboard_avatar' => '1', 'csrf' => csrf($jar)]])['status'] === 200);
    check('on again: bram sees her picture again', array_unique(array_values($sanneOnBoards($bram['token']))) === [$picture]);

    unlink($root . '/' . $picture);
    check('a picture from before small copies gets one the first time it is shown',
        array_unique(array_values($sanneOnBoards($bram['token']))) === [$picture] && is_file($root . '/' . $picture));

    $replaced = http('/api/profile/avatar.php', ['bearer' => $sanne['token'], 'file' => ['avatar' => png()]]);
    $newSmall = avatar_small((string) ($replaced['body']['avatar'] ?? ''));
    check('a new picture: its own small copy on the boards, the old picture and its copy gone',
        $replaced['status'] === 200 && array_unique(array_values($sanneOnBoards($bram['token']))) === [$newSmall]
        && is_file($root . '/' . $newSmall) && !is_file($root . '/' . $picture) && !is_file($root . '/' . $upload), summary($replaced));

    section('disconnecting Health Connect from the app ends the app\'s own token too');
    $disconnect = http('/api/integrations/disconnect.php', ['bearer' => $bram['token'], 'form' => ['provider' => 'google_health_connect']]);
    check('integrations/disconnect: 200, its phones revoked', $disconnect['status'] === 200
        && ($disconnect['body']['devices_revoked'] ?? 0) >= 1, summary($disconnect));
    check('the app\'s token was one of them: 401 from now on', http('/api/app/state.php', ['bearer' => $bram['token']])['status'] === 401);

    /* ==================================================================
       THE FIRST DAYS (includes/setup.php, docs/FIRST-DAYS.md)
       ================================================================== */

    section('the first days: a new account starts with its setup, until it finishes it');
    require_once $root . '/includes/setup.php';
    require_once $root . '/includes/health-data.php';

    if (!setup_stored()) {
        check('migration 016 is imported', false, 'import database/migrations/016-setup-and-focus.sql');
    } else {
        $read = static fn (array $who): array => http('/api/app/state.php', ['bearer' => $who['token']])['body']['data'] ?? [];

        $new = app_account('setup', true);
        $s   = $read($new);
        check('a new account: its setup is pending, on the focus', ($s['setup']['pending'] ?? null) === true
            && ($s['setup']['resume'] ?? null) === 'focus');
        check('four steps, in order', array_column($s['setup']['steps'] ?? [], 'id') === ['focus', 'connect', 'profile', 'goal']);
        check('no card of the first days while the setup waits', array_key_exists('calibration', $s) && $s['calibration'] === null);
        $fields = $s['setup']['steps'][2]['fields'] ?? [];
        check('only what Ownify uses is asked, each with its reason', array_column($fields, 'key') === ['date_of_birth', 'height', 'weight']
            && array_filter(array_column($fields, 'reason'), static fn ($r) => $r === '') === []);
        check('their inputs and endpoints are Instellingen\'s own', ($fields[0]['input']['endpoint'] ?? null) === 'api/profile/onboarding.php'
            && ($fields[2]['input']['endpoint'] ?? null) === 'api/profile/update.php');
        check('no suggestion without data', array_key_exists('suggestion', $s['setup']['steps'][3] ?? []) && $s['setup']['steps'][3]['suggestion'] === null);
        check('the database says pending', db_value('SELECT setup_state FROM user_profiles WHERE user_id = ?', [$new['id']]) === 'pending');

        $bad = http('/api/profile/update.php', ['bearer' => $new['token'], 'form' => ['focus' => 'mental']]);
        check('a focus that does not exist: 422', $bad['status'] === 422, summary($bad));
        $ok = http('/api/profile/update.php', ['bearer' => $new['token'], 'form' => ['focus' => 'fitness']]);
        check('the focus, saved by profile/update: 200', $ok['status'] === 200 && in_array('focus', $ok['body']['saved'] ?? [], true), summary($ok));
        $s = $read($new);
        check('a restart opens past it, with the answer kept', ($s['setup']['resume'] ?? null) === 'connect'
            && array_column(array_filter($s['setup']['steps'][0]['options'] ?? [], static fn ($o) => $o['chosen']), 'key') === ['fitness']);
        check('the focus puts its category first in the legend', array_column($s['scores']['contributors'] ?? [], 'area') === ['training', 'sleep', 'nutrition']);
        check('and in the Scorekompas, which follows the legend', array_column($s['compass']['composition']['categories'] ?? [], 'id') === ['training', 'sleep', 'nutrition']);
        check('the chip says it', ($s['focus_labels'][$s['focus'] ?? ''] ?? null) === 'Fitheid');

        $profile = http('/api/profile/update.php', ['bearer' => $new['token'], 'form' => ['weight' => '82,4']]);
        check('a weight from the setup is a measurement like any other', $profile['status'] === 200
            && (float) db_value("SELECT value FROM user_measurements WHERE user_id = ? AND measurement_type = 'weight' ORDER BY id DESC LIMIT 1", [$new['id']]) === 82.4);

        $jar  = tempnam(sys_get_temp_dir(), 'jar');
        $web  = http('/api/auth/login.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar), 'username' => $new['username'], 'password' => $password]]);
        $page = http('/', ['get' => true, 'jar' => $jar]);
        check('the website opens on the same setup, past the focus', $web['status'] === 200 && str_contains($page['raw'], 'data-setup ')
            && str_contains($page['raw'], 'data-resume="connect"') && !str_contains($page['raw'], 'data-deck'));

        $fin = http('/api/setup/finish.php', ['bearer' => $new['token'], 'form' => []]);
        check('finish: 200, no longer pending', $fin['status'] === 200 && ($fin['body']['pending'] ?? null) === false, summary($fin));
        $s = $read($new);
        check('the app now — day 1 of the baseline', ($s['setup']['pending'] ?? null) === false
            && ($s['calibration']['phase'] ?? null) === 'building' && ($s['calibration']['day'] ?? null) === 1
            && ($s['calibration']['eyebrow'] ?? null) === 'Dag 1 van 3');
        check('the card orders by the focus too', array_column($s['calibration']['progress'] ?? [], 'id') === ['training', 'sleep', 'nutrition']);
        $page = http('/', ['get' => true, 'jar' => $jar]);
        check('the website: the app, opening on the card', str_contains($page['raw'], 'data-deck') && str_contains($page['raw'], 'card--calibration')
            && !str_contains($page['raw'], 'data-setup '));

        $doneAt = db_value('SELECT setup_done_at FROM user_profiles WHERE user_id = ?', [$new['id']]);
        http('/api/setup/finish.php', ['bearer' => $new['token'], 'form' => []]);
        check('finishing twice changes nothing — not even the day', db_value('SELECT setup_done_at FROM user_profiles WHERE user_id = ?', [$new['id']]) === $doneAt);

        http('/api/auth/logout.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar)]]);
        http('/api/auth/login.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar), 'username' => $new['username'], 'password' => $password]]);
        $page = http('/', ['get' => true, 'jar' => $jar]);
        check('signed out and in again: still the app, never the setup again', str_contains($page['raw'], 'data-deck') && !str_contains($page['raw'], 'data-setup '));

        section('the first days: the first score is the engine\'s own, then the starting point, then nothing');
        /* Three nights, the last this morning, and the setup finished three days ago: day 4. */
        foreach ([2, 1, 0] as $ago) {
            $wake  = (new DateTimeImmutable('today'))->modify("-$ago days");
            $start = $wake->modify('-1 day')->setTime(23, 20);
            health_record_sleep($new['id'], ['started_at' => $start->format('Y-m-d H:i:s'), 'ended_at' => $start->modify('+430 minutes')->format('Y-m-d H:i:s')], 'manual');
        }
        db_run('UPDATE user_profiles SET setup_done_at = ? WHERE user_id = ?', [(new DateTimeImmutable('today'))->modify('-3 days')->format('Y-m-d 09:00:00'), $new['id']]);
        $s = $read($new);
        $sleep = null;
        foreach ($s['scores']['contributors'] ?? [] as $row) {
            if ($row['area'] === 'sleep') {
                $sleep = $row['value'];
            }
        }
        check('day 4: the first score — the slaapscore', ($s['calibration']['phase'] ?? null) === 'first_score'
            && ($s['calibration']['title'] ?? null) === 'Je eerste slaapscore', json_encode($s['calibration']['phase'] ?? null));
        check('its number is the ring\'s own category score (' . var_export($sleep, true) . ')', $sleep !== null
            && ($s['calibration']['first']['value'] ?? null) === $sleep);
        check('with the Scorekompas\'s own components', array_column($s['calibration']['first']['parts'] ?? [], 'id') === ['duration', 'regularity', 'quality']);
        check('and the overall score says it rests on slaap alone',
            str_starts_with((string) ($s['calibration']['note'] ?? ''), 'Je gezondheidsscore rust voorlopig alleen op slaap.'));

        /* One night earlier: the third night now woke yesterday, so the
           first score appeared yesterday (day 3). */
        $night = static function (int $ago) use ($new): void {
            $start = (new DateTimeImmutable('today'))->modify('-' . ($ago + 1) . ' days')->setTime(23, 20);
            health_record_sleep($new['id'], ['started_at' => $start->format('Y-m-d H:i:s'), 'ended_at' => $start->modify('+430 minutes')->format('Y-m-d H:i:s')], 'manual');
        };
        $night(3);
        $s = $read($new);
        check('the day after the first score appeared: still the first score', ($s['calibration']['phase'] ?? null) === 'first_score',
            json_encode($s['calibration']['phase'] ?? null));

        /* And one more: the first score appeared two days ago (day 2). */
        $night(4);
        $s = $read($new);
        $sleep = null;
        foreach ($s['scores']['contributors'] ?? [] as $row) {
            if ($row['area'] === 'sleep') {
                $sleep = $row['value'];
            }
        }
        $start = $s['calibration']['baseline'] ?? [];
        check('two days after it: the starting point', ($s['calibration']['phase'] ?? null) === 'baseline'
            && ($s['calibration']['title'] ?? null) === 'Je startpunt', json_encode($s['calibration']['phase'] ?? null));
        check('only what has a score, with the ring\'s own number (' . var_export($sleep, true) . ')',
            array_column($start, 'id') === ['sleep'] && $sleep !== null && ($start[0]['value'] ?? null) === $sleep);
        check('and what it was worked out from', str_starts_with((string) ($start[0]['fact'] ?? ''), 'Gemiddeld 7:10 per nacht'),
            json_encode($start[0]['fact'] ?? null));
        check('the rest is named as still to come, in the focus\'s order (Fitheid)', ($s['calibration']['note'] ?? null) === 'Sport en voeding komen erbij zodra er 3 dagen van zijn.',
            json_encode($s['calibration']['note'] ?? null));

        db_run('UPDATE user_profiles SET setup_done_at = ? WHERE user_id = ?', [(new DateTimeImmutable('today'))->modify('-5 days')->format('Y-m-d 09:00:00'), $new['id']]);
        check('day 6: no card — the normal app', array_key_exists('calibration', $read($new)) && $read($new)['calibration'] === null);

        section('the first days: only new accounts');
        $old = app_account('old');
        db_run('UPDATE user_profiles SET setup_state = NULL, setup_done_at = NULL WHERE user_id = ?', [$old['id']]);
        $s = $read($old);
        check('an account from before the setup: no setup, no card', ($s['setup']['pending'] ?? null) === false && $s['calibration'] === null);
        check('its focus is Alles, as before', ($s['focus'] ?? null) === 'general' && ($s['focus_labels']['general'] ?? null) === 'Alles');
        db_run('INSERT IGNORE INTO user_profiles (user_id) VALUES (?)', [$old['id']]);
        check('a profile row made later keeps it so', $read($old)['setup']['pending'] === false);

        $pending = app_account('pending-delete', true);
        $gone = http('/api/profile/delete.php', ['bearer' => $pending['token'], 'form' => ['confirm' => 'verwijderen']]);
        check('an account deleted in the middle of its setup is gone, not stuck', $gone['status'] === 200
            && db_value('SELECT id FROM users WHERE id = ?', [$pending['id']]) === null
            && http('/api/app/state.php', ['bearer' => $pending['token']])['status'] === 401);
    }

    section('deleting the account from the app');
    $sync = paired_phone($sanne['token']);
    $refused('/api/profile/delete.php', ['confirm' => 'verwijderen']);
    check('without confirm=verwijderen: 422, nothing deleted',
        http('/api/profile/delete.php', ['bearer' => $sanne['token'], 'form' => []])['status'] === 422
        && db_value('SELECT id FROM users WHERE id = ?', [$sanne['id']]) !== null);
    $gone = http('/api/profile/delete.php', ['bearer' => $sanne['token'], 'form' => ['confirm' => 'verwijderen']]);
    check('200: no redirect, the website\'s own sentence', $gone['status'] === 200
        && array_key_exists('redirect', $gone['body'] ?? []) && $gone['body']['redirect'] === null
        && ($gone['body']['message'] ?? null) === 'Je account is verwijderd, met alles wat erbij hoorde.', summary($gone));
    check('the token went with the account: 401', http('/api/app/state.php', ['bearer' => $sanne['token']])['status'] === 401);
    check('the account is gone', db_value('SELECT id FROM users WHERE id = ?', [$sanne['id']]) === null);

    $google = app_account('google');
    db_run('INSERT INTO user_auth_identities (user_id, provider, provider_subject, email, email_verified_at) VALUES (?, ?, ?, ?, NOW())',
        [$google['id'], 'google', 'as-test-sub-' . $run, $google['username'] . '@gmail.invalid']);
    $gone = http('/api/profile/delete.php', ['bearer' => $google['token'], 'form' => ['confirm' => 'verwijderen']]);
    check('with Google linked: the "remove it yourself" notice and its link, no redirect', $gone['status'] === 200
        && array_key_exists('redirect', $gone['body'] ?? []) && $gone['body']['redirect'] === null && ($gone['body']['message'] ?? null) === GOOGLE_SIGNIN_NOT_REVOKED
        && ($gone['body']['link']['href'] ?? null) === GOOGLE_ACCOUNT_CONNECTIONS_URL, summary($gone));

} finally {
    foreach (array_unique($made) as $username) {
        db_run('DELETE FROM users WHERE username = ?', [$username]);
    }
    proc_terminate($server);
}

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
