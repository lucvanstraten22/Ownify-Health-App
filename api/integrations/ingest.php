<?php
/**
 * The phone app posts the health records it read.
 *
 * Authenticated by the device token in the Authorization header, and by
 * nothing else. Whose data this is comes from the token — never from the body.
 * A user id in a request body is a user id an attacker can change, so there is
 * not one in this contract at all.
 *
 * The body is Health Connect's records as JSON; the mapping to JoLu's tables
 * is health-connect-map.php, on this side, so correcting it is a deploy rather
 * than an app release.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';
require_once dirname(__DIR__, 2) . '/includes/health-import.php';
require_once dirname(__DIR__, 2) . '/includes/health-connect-map.php';

api_require_post();
api_require_database();

$device = device_authenticate(api_bearer_token());

if ($device === null) {
    /* Covers an unknown token, a revoked one and a malformed one alike. The
       app's answer to all three is the same: pair again. */
    api_fail('Dit apparaat is niet gekoppeld.', 401);
}

$input   = api_json_body();
$records = $input['records'] ?? null;

if (!is_array($records)) {
    api_fail('Verwacht een records-array.', 422);
}

if (count($records) > 2000) {
    api_fail('Stuur maximaal 2000 records per keer.', 413);
}

/* Nothing to send is a normal outcome — a phone with no new data, or one
   whose owner granted no permissions. It is not an error and must not look
   like one, or the app will retry forever. */
if ($records === []) {
    device_note_sync($device['device_id']);
    integration_note_sync($device['user_id'], $device['provider'], 'ok');

    api_ok(['written' => 0, 'skipped' => 0, 'unmapped' => []]);
}

$mapped = health_connect_map($records);
$result = health_import_records($device['user_id'], $device['provider'], $mapped['records']);

if (!$result['ok']) {
    integration_note_sync($device['user_id'], $device['provider'], 'failed', $result['error']);
    api_fail($result['error'], 500);
}

device_note_sync($device['device_id']);

/* Partial when something was refused: the owner should see that the sync
   half-worked rather than a green tick over dropped records. */
integration_note_sync(
    $device['user_id'],
    $device['provider'],
    $result['skipped'] > 0 ? 'partial' : 'ok',
    $result['skipped'] > 0 ? $result['skipped'] . ' record(s) overgeslagen' : null
);

api_ok([
    'written'  => $result['written'],
    'skipped'  => $result['skipped'],
    'days'     => $result['days'],
    'unmapped' => $mapped['unmapped'],
    'problems' => $result['problems'],

    /* Goals this batch finished. The app has no use for it, but it makes the
       link between a sync and a goal moving visible in one response rather
       than something you have to go and check. */
    'goals_completed' => $result['goals_completed'] ?? 0,
]);
