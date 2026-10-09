<?php
/**
 * A stand-in for Polar (auth.polar.com and AccessLink v4), for
 * tools/polar-test.php only:
 *
 *     FAKE_POLAR_DIR=/tmp/x php -S 127.0.0.1:8401 tools/polar-fake.php
 *
 * Plays Polar from $FAKE_POLAR_DIR/world.json, which the test writes and this
 * updates: the client it knows, whether the person approves, the codes,
 * refresh and access tokens it has handed out, failures to answer with, and
 * each account's data. Every request is written down in requests.jsonl (the
 * path, the query, which kind of Authorization came along) so the test can
 * see what Ownify asked — never a secret.
 *
 * It keeps Polar's rules that matter: Basic auth on the token endpoint, the
 * redirect URI exactly as registered, single-use codes, a refused refresh
 * once revoked, 401 for an unknown or expired access token, and — on the
 * data API — one day per request whenever `features` are asked for.
 *
 * Only the built-in server runs this, and only with FAKE_POLAR_DIR set.
 * Anywhere else it answers 404, and tools/.htaccess refuses the directory.
 */
declare(strict_types=1);

$dir = getenv('FAKE_POLAR_DIR');

if (PHP_SAPI !== 'cli-server' || !is_string($dir) || $dir === '' || !is_dir($dir)) {
    http_response_code(404);
    exit;
}

$lock = fopen($dir . '/lock', 'c');
flock($lock, LOCK_EX);

$file  = $dir . '/world.json';
$world = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
$world = is_array($world) ? $world : [];

$path   = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$auth   = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$query  = [];
$features = [];

foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
    if ($pair === '') {
        continue;
    }
    [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
    $k = rawurldecode($k);
    $v = rawurldecode($v);
    if ($k === 'features') {
        $features[] = $v;
    } else {
        $query[$k] = $v;
    }
}

file_put_contents($dir . '/requests.jsonl', json_encode([
    'method'   => $_SERVER['REQUEST_METHOD'] ?? '',
    'path'     => $path,
    'query'    => $query,
    'features' => $features,
    'auth'     => str_starts_with($auth, 'Basic ') ? 'basic' : (str_starts_with($auth, 'Bearer ') ? 'bearer:' . substr($auth, 7) : ''),
    'form'     => array_diff_key($_POST, ['code' => 1, 'refresh_token' => 1]) + (isset($_POST['code']) ? ['code' => 'given'] : []),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$answer = static function (int $status, mixed $body = null, array $headers = []) use (&$world, $file, $lock): never {
    file_put_contents($file, json_encode($world, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    flock($lock, LOCK_UN);
    http_response_code($status);
    foreach ($headers as $header) {
        header($header);
    }
    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    exit;
};

$mint = static fn (string $prefix): string => $prefix . bin2hex(random_bytes(8));

/* A failure the test queued for this path, once. */
foreach ($world['fail'] ?? [] as $prefix => $queue) {
    if (str_starts_with($path, $prefix) && $queue !== []) {
        $next = array_shift($world['fail'][$prefix]);
        $answer((int) $next['status'], $next['body'] ?? ['errorMessage' => 'fake failure']);
    }
}

/* ============================================================ the OAuth side */

if ($path === '/oauth/authorize') {
    if (($query['client_id'] ?? '') !== ($world['client']['id'] ?? null) || ($query['response_type'] ?? '') !== 'code') {
        $answer(400, ['error' => 'invalid_request']);
    }
    $redirect = $query['redirect_uri'] ?? '';
    if ($redirect !== ($world['redirect'] ?? null)) {
        $answer(400, ['error' => 'invalid_redirect_uri']);
    }
    $world['last_scope'] = $query['scope'] ?? '';
    $sep = str_contains($redirect, '?') ? '&' : '?';
    if (!empty($world['deny'])) {
        $answer(302, null, ['Location: ' . $redirect . $sep . http_build_query(['error' => 'access_denied', 'state' => $query['state'] ?? ''])]);
    }
    $code = $mint('code-');
    $world['codes'][$code] = ['account' => $world['approve'] ?? 'alice', 'redirect_uri' => $redirect, 'used' => false];
    $world['last_code'] = $code;
    $answer(302, null, ['Location: ' . $redirect . $sep . http_build_query(['code' => $code, 'state' => $query['state'] ?? ''])]);
}

if ($path === '/oauth/token') {
    $expected = 'Basic ' . base64_encode(($world['client']['id'] ?? '') . ':' . ($world['client']['secret'] ?? ''));
    if (!hash_equals($expected, $auth) || !str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded')) {
        $answer(401, ['error' => 'invalid_client']);
    }

    $issue = static function (string $account) use (&$world, $mint): array {
        $access  = $mint('at-');
        $refresh = $mint('rt-');
        $seconds = (int) ($world['expires_in'] ?? 43199);
        $world['access'][$access]   = ['account' => $account, 'expires' => time() + $seconds];
        $world['refresh'][$refresh] = ['account' => $account, 'revoked' => false];
        return ['access_token' => $access, 'token_type' => 'bearer', 'refresh_token' => $refresh,
                'expires_in' => $seconds, 'scope' => $world['last_scope'] ?? '', 'jti' => $mint('jti-')];
    };

    $grant = $_POST['grant_type'] ?? '';

    if ($grant === 'authorization_code') {
        $code = $_POST['code'] ?? '';
        $row  = $world['codes'][$code] ?? null;
        if ($row === null || $row['used'] || ($_POST['redirect_uri'] ?? '') !== $row['redirect_uri']) {
            $answer(400, ['error' => 'invalid_grant']);
        }
        $world['codes'][$code]['used'] = true;
        $answer(200, $issue($row['account']));
    }

    if ($grant === 'refresh_token') {
        $token = $_POST['refresh_token'] ?? '';
        $row   = $world['refresh'][$token] ?? null;
        if ($row === null || $row['revoked'] || !empty($world['revoke_all'])) {
            $answer(400, ['error' => 'invalid_grant']);
        }
        $world['refreshes'] = ($world['refreshes'] ?? 0) + 1;
        $world['refresh'][$token]['revoked'] = true;            // rotated: the old one stops working
        $answer(200, $issue($row['account']));
    }

    $answer(400, ['error' => 'unsupported_grant_type']);
}

/* ========================================================== the data API */

if (!str_starts_with($path, '/v4/data/')) {
    $answer(404, ['errorMessage' => 'not found']);
}

$bearer  = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
$access  = $world['access'][$bearer] ?? null;

if ($access === null || $access['expires'] <= time() || !empty($world['revoke_all'])) {
    $answer(401, ['errorMessage' => 'unauthorized']);
}

$data = $world['accounts'][$access['account']] ?? [];
$from = $query['from'] ?? null;
$to   = $query['to'] ?? null;
$in   = static fn (?string $date): bool => $date !== null && $from !== null && $to !== null && $date >= $from && $date < $to;

/* Polar's rule: with features, one day at a time. */
if ($features !== [] && $from !== null && $to !== null
    && (new DateTimeImmutable($from))->modify('+1 day')->format('Y-m-d') !== $to
    && $path !== '/v4/data/continuous-samples') {
    $answer(400, ['errorMessage' => 'only one day at a time with features']);
}

switch ($path) {
    case '/v4/data/user-devices':
        $answer(200, $data['devices'] ?? ['devicesData' => [], 'userDevicesData' => ['activeDevices' => []]]);

    case '/v4/data/sports/list':
        $answer(200, ['sports' => [
            ['id' => ['id' => 1], 'name' => 'RUNNING'],
            ['id' => ['id' => 2], 'name' => 'CYCLING'],
            ['id' => ['id' => 3], 'name' => 'STRENGTH_TRAINING'],
        ]]);

    case '/v4/data/training-sessions/list':
        $out = [];
        foreach ($data['trainings'] ?? [] as $session) {
            if ($in(substr((string) $session['startTime'], 0, 10))) {
                if (!in_array('samples', $features, true)) {
                    foreach ($session['exercises'] ?? [] as $i => $exercise) {
                        unset($session['exercises'][$i]['samples']);
                    }
                }
                $out[] = $session;
            }
        }
        $answer(200, ['trainingSessions' => $out]);

    case '/v4/data/sleeps':
        $out = [];
        foreach ($data['sleeps'] ?? [] as $night) {
            if ($in($night['sleepDate'])) {
                $out[] = $features === [] ? ['sleepDate' => $night['sleepDate']] : $night;
            }
        }
        $answer(200, ['nightSleeps' => $out]);

    case '/v4/data/activity/list':
        $out = [];
        foreach ($data['activity'] ?? [] as $day) {
            if ($in($day['date'])) {
                $out[] = $features === [] ? ['date' => $day['date']] : $day;
            }
        }
        $answer(200, ['activities' => ['activityDays' => $out]]);

    case '/v4/data/continuous-samples':
        $out = [];
        foreach ($data['heart'] ?? [] as $day) {
            if ($in($day['date'])) {
                $out[] = $day;
            }
        }
        $answer(200, ['continuousSamples' => ['heartRateSamplesPerDay' => $out]]);

    case '/v4/data/nightly-recharge-results':
        $out = [];
        foreach ($data['recharge'] ?? [] as $result) {
            if ($in($result['sleepResultDate'])) {
                $out[] = $result;
            }
        }
        $answer(200, ['nightlyRechargeResults' => ['nightlyRechargeResults' => $out]]);
}

$answer(404, ['errorMessage' => 'not found']);
