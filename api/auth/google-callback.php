<?php
/**
 * Where Google sends the browser back to — the redirect URI registered on the
 * OAuth client, so its path must not change without changing it there too.
 *
 * Not a JSON endpoint: a person arrives here in their browser. Every outcome
 * ends in a redirect to the app, with a one-time message saying what happened
 * for the page to show — in the account panel after signing in or after
 * deleting an account, in Settings > Account after linking. The details of a
 * failure go to the error log, never to the page.
 *
 * GET, because that is how Google returns. What makes it safe is not the
 * method but the state: a callback whose state is not the one this session
 * started with is refused before anything is exchanged or believed.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/google-signin.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');     // the code must not leak onward

/* Relative to this file, so it lands on the app wherever the app is installed. */
$app = '../../';

if (!db_available() || !google_signin_configured()) {
    auth_flash('Inloggen met Google is op dit moment niet beschikbaar.', 'error', 'account');
    header('Location: ' . $app, true, 303);
    exit;
}

$result = google_signin_finish($_GET);

/* After deleting an account that had Google: say how that went, and — when
   Google could not be told automatically — where to finish it by hand. */
if ($result['mode'] === 'revoke') {
    auth_flash(
        (string) $result['message'],
        $result['outcome'] === 'revoked' ? 'ok' : 'info',
        'account',
        $result['outcome'] === 'revoked' ? null : [
            'href'  => GOOGLE_ACCOUNT_CONNECTIONS_URL,
            'label' => 'Apps met toegang tot je Google-account',
        ]
    );

    header('Location: ' . $app, true, 303);
    exit;
}

switch ($result['outcome']) {
    case 'signed_in':
        break;                                       // the app opens signed in

    case 'choose_username':
        break;                                       // the panel opens on the username step

    case 'linked':
        auth_flash((string) $result['message'], 'ok', 'settings-account');
        break;

    default:                                         // error, cancelled
        auth_flash(
            (string) $result['message'],
            $result['outcome'] === 'cancelled' ? 'info' : 'error',
            $result['mode'] === 'link' ? 'settings-account' : 'account'
        );
}

header('Location: ' . $app, true, 303);
exit;
