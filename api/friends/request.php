<?php
/**
 * Sends, accepts, declines or cancels a friend request.
 *
 * Every branch takes the acting user from the session; the id in the request
 * only ever names the other person.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/friends.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId  = api_require_user();
$otherId = (int) ($_POST['user_id'] ?? 0);
$action  = (string) ($_POST['action'] ?? 'request');

if ($otherId <= 0 || user_public_profile($otherId) === null) {
    api_fail('Onbekend account.', 404);
}

$result = match ($action) {
    'request' => friend_request($userId, $otherId),
    'accept'  => friend_respond($userId, $otherId, 'accepted'),
    'decline' => friend_respond($userId, $otherId, 'declined'),
    'cancel'  => friend_cancel($userId, $otherId),
    default   => ['ok' => false, 'error' => 'Onbekende actie.'],
};

if (!$result['ok']) {
    api_fail($result['error'], 422);
}

api_ok(['status' => friend_status($userId, $otherId)]);
