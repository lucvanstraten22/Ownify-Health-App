<?php
/**
 * Starts connecting Polar, for the signed-in person (includes/polar.php,
 * docs/POLAR.md).
 *
 *   GET   the website: Instellingen sends the browser here (settings.js), and
 *         it goes on to Polar's own page. The state is bound to this
 *         browser's session; the person comes back to Instellingen.
 *   POST  the app, with its account token (or the website with its session
 *         and CSRF token): { ok, url } — the address of Polar's page, which
 *         the app opens in the browser. Coming back, that browser asks the
 *         person to confirm which Ownify account Polar goes to.
 *
 * Whose connection it is comes from the session or the token — never from
 * the request. Nothing secret is in the answer: the address carries the
 * client id and a one-time state, which is what Polar's page needs.
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
        auth_flash('Log in om Polar te koppelen.', 'info', 'account');
        header('Location: ' . $app, true, 303);
        exit;
    }

    $begin = polar_begin($userId, 'web');

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
$begin  = polar_begin($userId, api_bearer_token() !== null ? 'app' : 'web');

if (!$begin['ok']) {
    api_fail((string) $begin['error'], (int) $begin['status']);
}

api_ok(['url' => $begin['url']]);
