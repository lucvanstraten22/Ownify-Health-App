<?php
/**
 * Meedoen: joins a challenge, always on the person's own tap.
 *
 *   challenge_id   an invitation you have, or
 *   code           the five digits you were given
 *
 * Joining through a code checks it again (and counts a wrong one, as
 * lookup.php does), so this endpoint is no way around looking first.
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

$input = api_json_body();
$id    = filter_var($input['challenge_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$code  = isset($input['code']) ? trim((string) $input['code']) : null;

$result = challenge_join($userId, $id === false ? null : $id, $code);

if (!$result['ok']) {
    api_json([
        'ok'     => false,
        'status' => $result['status'] ?? 'none',
        'error'  => $result['error'],
    ], match ($result['status'] ?? null) {
        'wait'          => 429,
        'ended'         => 410,
        'full', 'limit' => 409,
        default         => 404,
    });
}

api_ok([
    'status'  => $result['status'],
    'id'      => $result['id'],
    'name'    => $result['name'],
    'message' => $result['status'] === 'member'
        ? tf('Je doet al mee aan %s', $result['name'])
        : 'Je doet mee',
]);
