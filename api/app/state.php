<?php
/**
 * Everything the signed-in JoLu app shows, as JSON — the app's one read.
 *
 * The website renders its pages on the server in one request (index.php); the
 * Android app lays out the same pages natively and reads them from here. Both
 * go through app_page_data() (lib/app-data.php), so the app can never show a
 * score, a point, a goal percentage, a chart or a sentence the website would
 * not: nothing is worked out on the phone.
 *
 *   POST, Authorization: Bearer <account token>
 *   → 200 { ok, version, data }
 *
 * `data` is what the templates read: config/dashboard.php filled for the
 * account — auth, scores, goal (the Overzicht card), health, community,
 * goals, settings, disclaimer and the copy around them. See
 * app_state_payload() for what is left out.
 *
 * Reading it has the same side effect as loading the website: each goal's
 * progress is recomputed and stored on the way (hydrate_goals).
 *
 * Signed in either way (api_require_account_user()): the app's ACCOUNT token,
 * or the website's session and CSRF token. A pairing (sync) token is 403 — it
 * may upload, not read the account — and an unknown, revoked or lapsed token
 * is 401.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/app-data.php';

/** Bumped when the shape of `data` changes in a way an older app cannot read. */
const APP_STATE_VERSION = 1;

api_require_post();
api_require_database();

$userId = api_require_account_user();

$data = require dirname(__DIR__, 2) . '/config/dashboard.php';

/* The session's own state when the website asks, the token's account when
   the app does — the same shape either way. */
$data['auth'] = api_bearer_token() === null ? app_auth() : app_auth_account($userId);

if (empty($data['auth']['signed_in'])) {
    api_fail('Je bent niet ingelogd.', 401);
}

api_ok([
    'version' => APP_STATE_VERSION,
    'data'    => app_state_payload(app_page_data($data, $userId)),
]);
