<?php
/**
 * A Google Health sync now (as Polar's, api/integrations/polar/sync.php):
 * fetches the days since the last sync (with a few days'
 * overlap) and imports them. For the website (session and CSRF token) and the
 * app (account token). Answers with what happened in one sentence and the
 * connection's state as the settings screen shows it.
 *
 * Neither the website nor the app has a button for it — Google Health
 * syncs right after connecting and from cron (tools/google-health-sync.php,
 * docs/GOOGLE-HEALTH.md) — and a pull to sync asks it, on both apps
 * (pull-sync.js, PullSyncIndicator.kt).
 *
 * 44.2: a sync with a watch's month of minutes takes longer than an app
 * waits for an answer (15 seconds), which read as "Synchroniseren mislukt"
 * while the server carried on. So the answer goes after at most 8 seconds:
 * the result when it is done by then, otherwise { ok, status: "running" } —
 * the rest runs on here, and Instellingen shows "Bezig met synchroniseren…"
 * until it is done. A sync already running answers the same way: it is not
 * a failure.
 *
 * A connection Google withdrew answers 409 — never 401, which to the app
 * means its own sign-in is gone.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/integrations.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$row    = integration_get($userId, 'google_health');

if ($row === null || !in_array($row['status'], ['connected', 'error'], true)) {
    api_fail('Google Health is niet gekoppeld.', 409);
}

$running = static function (string $message) use ($userId): never {
    api_ok(['message' => $message, 'status' => 'running', 'written' => 0,
            'state' => integration_public($userId, 'google_health')]);
};

if (google_health_syncing($row)) {
    $running(t('Google Health wordt al gesynchroniseerd. Dat kan even duren.'));
}

ignore_user_abort(true);
@set_time_limit(300);

/* After 8 seconds: the answer now, the rest of the sync after it. */
$early = static function () use ($userId): void {
    $body = json_encode(['ok' => true, 'message' => t('Google Health wordt gesynchroniseerd. Dat kan even duren.'),
                         'status' => 'running', 'written' => 0, 'state' => integration_public($userId, 'google_health')],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen((string) $body));
    }
    echo $body;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
    $GLOBALS['google_health_answered'] = true;
};

$result = google_health_sync($userId, 'manual', $early, 8.0);

if (!empty($GLOBALS['google_health_answered'])) {
    exit;                                          // answered already; the sync is done now
}

$state = integration_public($userId, 'google_health');

if ($result['status'] === 'busy') {
    $running(t('Google Health wordt al gesynchroniseerd. Dat kan even duren.'));
}

if (in_array($result['status'], ['revoked', 'disconnected'], true)) {
    api_fail($result['message'], 409);
}

if ($result['status'] === 'failed') {
    api_fail($result['message'], 502);
}

api_ok(['message' => $result['message'], 'status' => $result['status'], 'written' => $result['written'], 'state' => $state]);
