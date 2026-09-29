<?php
/**
 * Deletes the signed-in account, with everything that belongs to it.
 *
 * The account is the one in the session, or the Ownify app's account token
 * (api_require_account_user()) — never one named in the request — and the
 * request has to say `confirm=verwijderen`, which only the second, final step
 * of the confirmation sends. A stray or forged call without it deletes
 * nothing, and the CSRF token stops one from another site at all.
 *
 * What goes is listed in user_delete_account(): every row of the account's,
 * the Google link and paired phones included, and the profile picture.
 * Then this browser is signed out; any other still signed in to the account
 * — the app's token among them, which went with its row — is turned away on
 * its next request.
 *
 * An account that had Google gets one more step: the answer carries a
 * redirect through Google that asks it to forget Ownify for that Google account
 * (see google_signin_forget). The account is already deleted by then, so
 * nothing about that step can stop or undo the deletion.
 *
 * The app gets no redirect: the step through Google keeps its state in a
 * browser session, and the app has none to carry it back. It gets the words
 * the website shows when that step is not possible — and the link to do it by
 * hand — as `message` and `link`.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/google-signin.php';
require_once dirname(__DIR__, 2) . '/includes/leaderboard.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (($_POST['confirm'] ?? '') !== 'verwijderen') {
    api_fail('Bevestig eerst dat je je account wilt verwijderen.', 422);
}

/* The board periods this account earned points in: its points leave with it,
   and those periods are re-ranked at once, so nobody below is left one place
   short of where they now are. */
$periods = db_all(
    "SELECT DISTINCT DATE_FORMAT(awarded_on, '%Y-%m') AS month, DATE_FORMAT(awarded_on, '%Y') AS year
       FROM point_events WHERE user_id = ?",
    [$userId]
);

$result = user_delete_account($userId, app_upload_root());

if (!$result['ok']) {
    api_fail((string) $result['error'], 500);
}

if ($periods !== []) {
    foreach (array_unique(array_column($periods, 'month')) as $month) {
        leaderboard_rebuild('month', $month);
    }
    foreach (array_unique(array_column($periods, 'year')) as $year) {
        leaderboard_rebuild('year', $year);
    }
    leaderboard_rebuild('alltime', 'all');
}

/* The app, signed in with its account token: nothing to sign out here (its
   token went with the account's rows) and no browser to send through Google. */
if (api_bearer_token() !== null) {
    $google = $result['google_sub'] !== null;

    api_ok([
        'redirect' => null,
        'message'  => $google ? GOOGLE_SIGNIN_NOT_REVOKED : 'Je account is verwijderd, met alles wat erbij hoorde.',
        'link'     => $google ? [
            'href'  => GOOGLE_ACCOUNT_CONNECTIONS_URL,
            'label' => 'Apps met toegang tot je Google-account',
        ] : null,
    ]);
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
