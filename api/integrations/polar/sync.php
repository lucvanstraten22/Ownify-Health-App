<?php
/**
 * A Polar sync now: fetches the days since the last sync (with a few days'
 * overlap) and imports them. For the website (session and CSRF token) and the
 * app (account token). Answers with what happened in one sentence and the
 * connection's state as the settings screen shows it.
 *
 * Since 19.0 neither the website nor the app has a button for it — Polar
 * syncs right after connecting and hourly from cron (tools/polar-sync.php,
 * docs/POLAR.md) — but installed apps from before 19.0 still have one, so
 * the endpoint stays.
 *
 * A sync already running answers 409 at once. A connection Polar withdrew
 * answers 409 too — never 401, which to the app means its own sign-in is gone.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/integrations.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$row    = integration_get($userId, 'polar');

if ($row === null || !in_array($row['status'], ['connected', 'error'], true)) {
    api_fail('Polar is niet gekoppeld.', 409);
}

if (polar_syncing($row)) {
    api_fail('Polar wordt al gesynchroniseerd. Even geduld.', 409);
}

ignore_user_abort(true);
@set_time_limit(180);

$result = polar_sync($userId, 'manual');
$state  = integration_public($userId, 'polar');

if (in_array($result['status'], ['busy', 'revoked', 'disconnected'], true)) {
    api_fail($result['message'], 409);
}

if ($result['status'] === 'failed') {
    api_fail($result['message'], 502);
}

api_ok(['message' => $result['message'], 'status' => $result['status'], 'written' => $result['written'], 'state' => $state]);
