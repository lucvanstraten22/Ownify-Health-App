<?php
/**
 * Finishes the setup a new account starts with (includes/setup.php): from
 * now on the account opens on the app, on the website and on the phone, and
 * today is day 1 of its baseline.
 *
 *   POST — the website with its session and CSRF token, the app with its
 *   account token (api_require_account_user())
 *   → 200 { ok, pending: false }
 *
 * What the setup asked is saved by the endpoints that always save it — the
 * focus and the body measurements by profile/update.php, the birth date by
 * profile/onboarding.php, a goal by goals/create.php — so this only marks
 * the end. Asking twice changes nothing: not even the day it was finished.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/setup.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (!setup_stored()) {
    api_fail('De setup kan nog niet worden afgerond.', 503);
}

setup_finish($userId);

api_ok(['pending' => setup_pending($userId)]);
