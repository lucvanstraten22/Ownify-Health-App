<?php
/**
 * The one step after Google for somebody new: choosing a username.
 *
 * The Google identity is not in this request and cannot be: it waits in the
 * server session, put there only by google-callback.php after the ID token was
 * verified, and it expires after ten minutes. All the browser sends is the
 * username it would like. Only when that is accepted is the account created,
 * already linked to Google, and signed in.
 *
 *   action=create  username=…   create the account (the default)
 *   action=cancel               forget the waiting identity; nothing was saved
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/google-signin.php';

api_require_post();
api_require_csrf();
api_require_database();

if (($_POST['action'] ?? 'create') === 'cancel') {
    google_signin_cancel();
    auth_flash('Aanmelden met Google is geannuleerd. Er is niets opgeslagen.', 'info', 'account');
    api_ok();
}

if (current_user_id() !== null) {
    google_signin_cancel();
    api_fail('Je bent al ingelogd.', 409);
}

$result = google_signin_create_account((string) ($_POST['username'] ?? ''));

if (!$result['ok']) {
    /* Gone for good: the page reloads onto the ordinary sign-in form, and
       this says why. */
    if (!empty($result['expired'])) {
        auth_flash((string) $result['error'], 'error', 'account');
    }

    api_json([
        'ok'      => false,
        'error'   => $result['error'],
        'expired' => !empty($result['expired']),
    ], !empty($result['expired']) ? 410 : 422);
}

session_login((int) $result['user_id']);
auth_touch_last_seen((int) $result['user_id']);

api_ok(api_account_payload((int) $result['user_id']));
