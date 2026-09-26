<?php
/**
 * The paired phone's view of its owner's profile — what the app needs to
 * personalise what it shows: birth date and age, gender, activity level,
 * height and weight.
 *
 * Authenticated exactly like status and ingest: the bearer token and nothing
 * else. There is no user id in the request, because a user id in a request
 * is a user id an attacker can change; whose profile this is comes from the
 * token alone, so a device can only ever read the account it was paired to.
 *
 * Everything is read through user_account(), the same function the website's
 * own account screens use, so the two can never disagree:
 *
 *   - age is worked out from the birth date by user_age(), never stored;
 *   - height and weight are the newest rows in user_measurements, whichever
 *     source recorded them;
 *   - gender and activity level are passed on exactly as stored.
 *
 * Nothing is filled in: a value the person never gave is null, not a default.
 *
 * This is more than status reveals — a stolen token now also reads these
 * fields — which is why it is still only the profile, and never an e-mail
 * address, a user id or anyone else's data.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

/* POST like every other endpoint here: a GET could be cached by anything
   between the phone and us, and this answer is private. */
api_require_post();
api_require_database();

$device = device_authenticate(api_bearer_token());

if ($device === null) {
    /* Same message and status as status and ingest: unknown, revoked and
       malformed all mean "pair again". */
    api_fail('Dit apparaat is niet gekoppeld.', 401);
}

$account = user_account($device['user_id']);

if ($account === null) {
    /* The account behind the token is gone; to the app that is the same as
       a token that no longer works. */
    api_fail('Dit apparaat is niet gekoppeld.', 401);
}

/* A measurement in the unit it is always recorded in (profile/update.php and
   the Health Connect mapping both store cm and kg), or null — a value in a
   unit this endpoint does not promise is not converted by guesswork. */
$measurement = static function (?array $row, string $unit): ?float {
    return $row !== null && $row['unit'] === $unit ? (float) $row['value'] : null;
};

api_ok([
    'account' => [
        'username'       => $account['username'],
        'first_name'     => $account['first_name'],
        'last_name'      => $account['last_name'],
        'date_of_birth'  => $account['date_of_birth'],
        'age'            => $account['age'],
        'gender'         => $account['gender'],
        'activity_level' => $account['activity_level'],
        'height_cm'      => $measurement($account['height'], 'cm'),
        'weight_kg'      => $measurement($account['weight'], 'kg'),
    ],
]);
