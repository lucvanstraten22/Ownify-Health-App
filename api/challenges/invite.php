<?php
/**
 * Vrienden uitnodigen, from a challenge you are in.
 *
 *   challenge_id
 *   invite[]      friends' user ids (or one comma-separated string)
 *
 * Only your own friends, only while it runs. An invitation is something to
 * look at; nobody is joined until they tap Meedoen themselves.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/challenges.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (!challenges_available()) {
    api_fail('Uitdagingen zijn nog niet beschikbaar.', 503);
}

$input  = api_json_body();
$id     = filter_var($input['challenge_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$invite = $input['invite'] ?? [];
if (is_string($invite)) {
    $invite = $invite === '' ? [] : explode(',', $invite);
}

if ($id === false || $id === null) {
    api_fail('Onbekende uitdaging.', 404);
}

$result = challenge_invite($userId, $id, is_array($invite) ? $invite : []);

if (!$result['ok']) {
    api_fail($result['error'], 409);
}

api_ok([
    'count'   => $result['count'],
    'message' => $result['message'],
]);
