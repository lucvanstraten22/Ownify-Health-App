<?php
/**
 * Whether this browser's session is signed in — and nothing more.
 *
 * Which screen a page shows, the app or the opening screen, was decided from
 * the session it was rendered for. A page brought back from the back/forward
 * cache, or looked at again after a while, asks here whether that still
 * holds; if not, it is rendered again (assets/js/account.js).
 *
 * The same test index.php applies — a session naming an account that still
 * exists — without app_auth(), which would take the one-time message meant
 * for the next page. No id, no name: a yes or a no about the caller's own
 * session, which is nothing the caller does not already know.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$userId = current_user_id();

$signedIn = $userId !== null
    && db_available()
    && db_value('SELECT id FROM users WHERE id = ? AND status <> ?', [$userId, 'deleted']) !== null;

api_ok(['signed_in' => $signedIn]);
