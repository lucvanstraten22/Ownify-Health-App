<?php
/**
 * Blocks or unblocks an account.
 *
 * A block is one-directional and overrides any friendship, which is why it is
 * its own table rather than a friendship status.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/friends.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId  = api_require_user();
$otherId = (int) ($_POST['user_id'] ?? 0);
$action  = (string) ($_POST['action'] ?? 'block');

if ($otherId <= 0 || $otherId === $userId) {
    api_fail('Onbekend account.', 404);
}

$result = $action === 'unblock'
    ? user_block_remove($userId, $otherId)
    : user_block_add($userId, $otherId);

if (!$result['ok']) {
    api_fail($result['error'], 422);
}

api_ok();
