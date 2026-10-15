<?php
/**
 * Afwijzen and Uitdaging verlaten.
 *
 *   challenge_id
 *   action        decline (an invitation you have) | leave (a challenge you are in)
 *
 * Leaving takes what you contributed with you; the code lets you back in
 * while the challenge runs. Nobody can remove anybody else.
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
$action = (string) ($input['action'] ?? '');

if ($id === false || $id === null) {
    api_fail('Onbekende uitdaging.', 404);
}

$result = match ($action) {
    'decline' => challenge_decline($userId, $id),
    'leave'   => challenge_leave($userId, $id),
    default   => ['ok' => false, 'error' => 'Onbekende actie.'],
};

if (!$result['ok']) {
    api_fail($result['error'], 409);
}

api_ok([
    'message' => $action === 'decline' ? 'Uitnodiging afgewezen' : tf('Je doet niet meer mee aan %s', $result['name']),
]);
