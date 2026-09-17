<?php
/**
 * Finds accounts by handle.
 *
 * Returns public fields only — a username and a picture. There is no query
 * here that could reach a health record, which is the point of
 * user_search_by_username() being the only thing this calls.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/friends.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();
$query  = trim((string) ($_POST['q'] ?? ''));

if (mb_strlen($query) < 2) {
    api_ok(['results' => []]);
}

$blocked = user_blocked_ids($userId);
$results = [];

foreach (user_search_by_username($query, 20) as $person) {
    if ($person['id'] === $userId || in_array($person['id'], $blocked, true)) {
        continue;
    }

    $results[] = [
        'id'       => $person['id'],
        'username' => $person['username'],
        'avatar'   => $person['avatar'],
        'status'   => friend_status($userId, $person['id']),
    ];
}

api_ok(['results' => $results]);
