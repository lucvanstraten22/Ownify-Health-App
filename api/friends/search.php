<?php
/**
 * Finds the account with exactly this username — the "Vriend toevoegen"
 * lookup. Only when asked: the panel sends it when the person presses
 * Zoeken, never while they type.
 *
 * Returns public fields only — the username, the picture and where the two
 * of you stand. There is no query behind this that could reach a health
 * record, and no partial match: a username you do not know is not something
 * this will show you.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/friends.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$result = friend_find($userId, (string) ($_POST['username'] ?? $_POST['q'] ?? ''));

if (!$result['ok']) {
    api_json(['ok' => false, 'code' => $result['code'], 'error' => $result['error']],
        $result['code'] === 'empty' ? 422 : 404);
}

api_ok(['person' => $result['person']]);
