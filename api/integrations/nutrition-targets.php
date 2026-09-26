<?php
/**
 * The paired phone's daily energy and protein targets for its owner.
 *
 * Authenticated exactly like status, ingest and profile: the bearer token and
 * nothing else. There is no user id in the request, because a user id in a
 * request is a user id an attacker can change; whose targets these are comes
 * from the token alone.
 *
 * Nothing is calculated here. includes/nutrition-targets.php works it out from
 * the account's own profile and primary goal — the one place the formula and
 * its numbers live, so the phone, the website and the Health Score can never
 * disagree about them.
 *
 * A target that cannot be worked out is null, and `missing` says which input
 * was absent; `assumed` says which input was not given and what was used in
 * its place. Nothing is invented.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';
require_once dirname(__DIR__, 2) . '/includes/nutrition-targets.php';

/* POST like every other endpoint here: a GET could be cached by anything
   between the phone and us, and this answer is private. */
api_require_post();
api_require_database();

$device = device_authenticate(api_bearer_token());

if ($device === null) {
    /* Same message and status as the other device endpoints: unknown,
       revoked and malformed all mean "pair again". */
    api_fail('Dit apparaat is niet gekoppeld.', 401);
}

$result = nutrition_targets_for_user($device['user_id']);

if ($result === null) {
    /* The account behind the token is gone; to the app that is the same as
       a token that no longer works. */
    api_fail('Dit apparaat is niet gekoppeld.', 401);
}

/* `assumed` names inputs, so it is always a JSON object — {} when nothing
   was assumed, rather than the [] an empty PHP array would become. */
$result['assumed'] = (object) $result['assumed'];

api_ok($result);
