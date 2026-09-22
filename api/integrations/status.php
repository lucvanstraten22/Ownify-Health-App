<?php
/**
 * "Is this phone still paired?" — asked by the app, answered without sending
 * anything.
 *
 * Until now the only way for the app to discover its token had been revoked
 * was to gather a batch, upload it, and get a 401 — which means doing the work
 * and the network round trip to learn it was pointless, on every sync, for as
 * long as the user never re-pairs. This lets it check at startup and stop.
 *
 * Authenticated exactly like ingest: the bearer token and nothing else. There
 * is no user id in the request, because a user id in a request is a user id an
 * attacker can change.
 *
 * It answers with what the app legitimately needs to decide what to do next,
 * and nothing about the account behind it — no e-mail, no username, no id. A
 * stolen token should reveal no more than the upload endpoint it was stolen
 * for already allows.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

/* POST like every other endpoint here. A GET would be cacheable by anything
   between the phone and us, and a cached "you are fine" is the one answer
   that must never be stale. */
api_require_post();
api_require_database();

$device = device_authenticate(api_bearer_token());

if ($device === null) {
    /* Same message and status as ingest, for the same reason: unknown,
       revoked and malformed all mean "pair again". */
    api_fail('Dit apparaat is niet gekoppeld.', 401);
}

$integration = integration_public($device['user_id'], $device['provider']);

api_ok([
    'provider'     => $device['provider'],
    'label'        => $device['label'],
    'last_sync_at' => $integration['last_sync_at'],
    'last_sync'    => $integration['last_sync'],

    /* So a future app can stop uploading a category the server no longer
       wants, without needing a release to find out. */
    'max_records'  => 2000,
]);
