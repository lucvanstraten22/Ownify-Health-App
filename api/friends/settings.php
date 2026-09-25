<?php
/**
 * "Vriendverzoeken toestaan" — the signed-in account's own switch, and
 * nobody else's: there is no id in the request to point it anywhere else.
 *
 * Off, nobody can send this account a new friend request. Its friends, and
 * requests already waiting for it, are not touched.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/friends.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();
$value  = (string) ($_POST['allow_requests'] ?? '');

if ($value !== '0' && $value !== '1') {
    api_fail('Kies aan of uit.', 422);
}

$result = friend_set_requests_allowed($userId, $value === '1');

if (!$result['ok']) {
    api_fail($result['error'], 503);
}

api_ok([
    'allow_requests' => friend_requests_allowed($userId),
    'message'        => $value === '1'
        ? 'Anderen kunnen je een vriendverzoek sturen.'
        : 'Niemand kan je nu een nieuw vriendverzoek sturen.',
]);
