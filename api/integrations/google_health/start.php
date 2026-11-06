<?php
/**
 * Starts connecting Google Health, for the signed-in person
 * (includes/google-health.php, docs/GOOGLE-HEALTH.md). As Polar's.
 *
 *   GET   the website: Instellingen sends the browser here (settings.js), and
 *         it goes on to Google's consent screen. The state is bound to this
 *         browser's session; the person comes back to Instellingen.
 *   POST  the app, with its account token (or the website with its session
 *         and CSRF token): { ok, url } — the address of Google's consent screen, which
 *         the app opens in the browser. Coming back, that browser asks the
 *         person to confirm which Ownify account Google Health goes to.
 *
 * Whose connection it is comes from the session or the token — never from
 * the request. Nothing secret is in the answer: the address carries the
 * client id and a one-time state, which is what Google's page needs.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/integrations.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $app    = '../../../';
    $userId = current_user_id();

    if ($userId === null || !db_available()
        || db_value('SELECT id FROM users WHERE id = ? AND status = ?', [$userId, 'active']) === null) {
        auth_flash('Log in om Google Health te koppelen.', 'info', 'account');
        header('Location: ' . $app, true, 303);
        exit;
    }

    $begin = google_health_begin($userId, 'web');

    if (!$begin['ok']) {
        auth_flash((string) $begin['error'], 'error', 'settings-devices');
        header('Location: ' . $app, true, 303);
        exit;
    }

    header('Location: ' . $begin['url'], true, 303);
    exit;
}

api_require_post();
api_require_database();

$userId = api_require_account_user();
$begin  = google_health_begin($userId, api_bearer_token() !== null ? 'app' : 'web');

if (!$begin['ok']) {
    api_fail((string) $begin['error'], (int) $begin['status']);
}

api_ok(['url' => $begin['url']]);
