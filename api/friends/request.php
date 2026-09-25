<?php
/**
 * Sends, accepts, declines or withdraws a friend request, or removes a
 * friend.
 *
 *   request   send one to user_id
 *   accept    a request user_id sent you
 *   decline   the same, turned down
 *   remove    end your friendship with user_id
 *   cancel    withdraw a request you sent
 *
 * Every branch takes the acting user from the session; the id in the request
 * only ever names the other person, and each action checks the pair's own
 * row — a request can only be answered by the person it was sent to, and a
 * friendship only ended by one of the two friends.
 *
 * The answer says where the two now stand, as the panel shows it.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/friends.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId  = api_require_user();
$otherId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action  = (string) ($_POST['action'] ?? 'request');
$other   = $otherId === false ? null : user_public_profile($otherId);

if ($other === null) {
    api_fail('Onbekend account.', 404);
}

$result = match ($action) {
    'request' => friend_request($userId, $otherId),
    'accept'  => friend_respond($userId, $otherId, 'accepted'),
    'decline' => friend_respond($userId, $otherId, 'declined'),
    'remove'  => friend_remove($userId, $otherId),
    'cancel'  => friend_cancel($userId, $otherId),
    default   => ['ok' => false, 'code' => 'invalid', 'error' => 'Onbekende actie.'],
};

/* Where the two stand now, whatever happened — so the panel can show it. */
$person = friend_person($userId, $otherId, (string) $other['username'], $other['avatar']);

if (!$result['ok']) {
    $status = match ($result['code'] ?? null) {
        'friends', 'outgoing', 'incoming', 'gone', 'not_friends' => 409,
        'closed', 'blocked', 'limit', 'own'                     => 403,
        'self', 'invalid'                                       => 422,
        default                                                 => 422,
    };

    api_json([
        'ok'     => false,
        'code'   => $result['code'] ?? null,
        'error'  => $result['error'],
        'person' => $person['relation'] === 'blocked' ? null : $person,
    ], $status);
}

$messages = [
    'request' => 'Verzoek verstuurd',
    'accept'  => 'Jullie zijn nu vrienden.',
    'decline' => 'Verzoek geweigerd.',
    'remove'  => $person['username'] . ' is verwijderd uit je vrienden.',
    'cancel'  => 'Verzoek ingetrokken.',
];

api_ok([
    'status'  => friend_status($userId, $otherId),
    'person'  => $person,
    'message' => $messages[$action],
]);
