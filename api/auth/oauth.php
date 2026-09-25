<?php
/**
 * Starts signing in with a provider. Answers with the address to send the
 * browser to; the browser goes there itself.
 *
 *   provider=google  mode=login   from the account panel, signed out
 *   provider=google  mode=link    from Settings > Account, signed in
 *
 * POST with the CSRF token, like every other endpoint, so no other site can
 * start a sign-in — and in particular a link — in somebody's session.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/google-signin.php';

api_require_post();
api_require_csrf();

$provider = (string) ($_POST['provider'] ?? '');

if ($provider !== 'google') {
    api_fail('Onbekende aanbieder.', 400);
}

api_require_database();

if (!google_signin_configured()) {
    api_fail('Inloggen met Google is nog niet gekoppeld.', 501);
}

$mode   = (string) ($_POST['mode'] ?? 'login');
$userId = current_user_id();

if ($mode === 'link') {
    if ($userId === null) {
        api_fail('Log eerst in om Google aan je account te koppelen.', 401);
    }
} elseif ($mode === 'login') {
    if ($userId !== null) {
        api_fail('Je bent al ingelogd.', 409);
    }
} else {
    api_fail('Onbekende actie.', 400);
}

$url = google_signin_begin($mode, $userId);

if ($url === null) {
    api_fail('Inloggen met Google kon niet worden gestart.', 500);
}

api_ok(['redirect' => $url]);
