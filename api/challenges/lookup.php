<?php
/**
 * Code invoeren: what a five-digit code is, to look at before joining.
 *
 *   code   five digits
 *
 * Answers `status`:
 *   found   the challenge, as somebody who is not in it sees it (`review`)
 *   member  you are already in it (`id`, `name`)
 *   ended / none / wait   with an `error` to show under the boxes
 *
 * Wrong codes are slowed down as wrong passwords are (includes/auth-throttle.php).
 * Looking joins nobody: that takes Meedoen (join.php).
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
$code   = trim((string) ($input['code'] ?? ''));
$result = challenge_lookup($userId, $code);

if (!$result['ok']) {
    api_json([
        'ok'     => false,
        'status' => $result['status'],
        'error'  => $result['error'],
    ], $result['status'] === 'wait' ? 429 : ($result['status'] === 'ended' ? 410 : 404));
}

$challenge = $result['challenge'];

if ($result['status'] === 'member') {
    api_ok([
        'status'  => 'member',
        'id'      => (int) $challenge['id'],
        'name'    => (string) $challenge['name'],
        'message' => tf('Je doet al mee aan %s', (string) $challenge['name']),
    ]);
}

api_ok([
    'status' => 'found',
    'id'     => (int) $challenge['id'],
    'review' => challenge_review($challenge, $userId, challenge_today(), $code),
]);
