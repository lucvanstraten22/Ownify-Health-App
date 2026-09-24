<?php
/**
 * Deletes the signed-in account, with everything that belongs to it.
 *
 * The account is the one in the session — never one named in the request —
 * and the request has to say `confirm=verwijderen`, which only the second,
 * final step of the confirmation sends. A stray or forged call without it
 * deletes nothing, and the CSRF token stops one from another site at all.
 *
 * What goes is listed in user_delete_account(): every row of the account's,
 * the Google link and paired phones included, and the profile picture.
 * Then this browser is signed out; any other still signed in to the account
 * is turned away on its next request.
 *
 * An account that had Google gets one more step: the answer carries a
 * redirect through Google that asks it to forget JoLu for that Google account
 * (see google_signin_forget). The account is already deleted by then, so
 * nothing about that step can stop or undo the deletion.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/google-signin.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();

if (($_POST['confirm'] ?? '') !== 'verwijderen') {
    api_fail('Bevestig eerst dat je je account wilt verwijderen.', 422);
}

$result = user_delete_account($userId, app_upload_root());

if (!$result['ok']) {
    api_fail((string) $result['error'], 500);
}

/* Nobody is signed in to an account that no longer exists. A fresh session
   carries the message about it to the next page. */
session_logout();
session_boot();
session_regenerate_id(true);

$redirect = null;

if ($result['google_sub'] !== null) {
    $redirect = google_signin_begin('revoke', null, $result['google_sub']);

    if ($redirect === null) {
        /* Google sign-in is no longer configured here, so Google cannot be
           asked. The link was removed on this side; the rest is by hand. */
        auth_flash(GOOGLE_SIGNIN_NOT_REVOKED, 'info', 'account', [
            'href'  => GOOGLE_ACCOUNT_CONNECTIONS_URL,
            'label' => 'Apps met toegang tot je Google-account',
        ]);
    }
} else {
    auth_flash('Je account is verwijderd, met alles wat erbij hoorde.', 'ok', 'account');
}

api_ok(['redirect' => $redirect]);
