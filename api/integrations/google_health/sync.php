<?php
/**
 * A Google Health sync now (as Polar's, api/integrations/polar/sync.php):
 * fetches the days since the last sync (with a few days'
 * overlap) and imports them. For the website (session and CSRF token) and the
 * app (account token). Answers with what happened in one sentence and the
 * connection's state as the settings screen shows it.
 *
 * Neither the website nor the app has a button for it — Google Health
 * syncs right after connecting and hourly from cron (tools/google-health-sync.php,
 * docs/GOOGLE-HEALTH.md) — and a pull to sync asks it, on both apps
 * (pull-sync.js, PullSyncIndicator.kt).
 *
 * A sync already running answers 409 at once. A connection Google withdrew
 * answers 409 too — never 401, which to the app means its own sign-in is gone.
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

if (google_health_syncing($row)) {
    api_fail('Google Health wordt al gesynchroniseerd. Even geduld.', 409);
}

ignore_user_abort(true);
@set_time_limit(180);

$result = google_health_sync($userId, 'manual');
$state  = integration_public($userId, 'google_health');

if (in_array($result['status'], ['busy', 'revoked', 'disconnected'], true)) {
    api_fail($result['message'], 409);
}

if ($result['status'] === 'failed') {
    api_fail($result['message'], 502);
}

api_ok(['message' => $result['message'], 'status' => $result['status'], 'written' => $result['written'], 'state' => $state]);
