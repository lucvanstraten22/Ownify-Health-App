<?php
/**
 * Revokes ONE paired phone.
 *
 * Different from disconnect.php, which drops the whole source and every phone
 * on it. This is for the ordinary case: a phone sold, lost, or replaced, where
 * the other phones should carry on syncing.
 *
 * The device id arrives from the browser; the user never does. device_revoke()
 * filters on both, so an id from a request can only ever name one of YOUR
 * phones — an id belonging to somebody else matches nothing and answers the
 * same way as an id that does not exist.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_database();

$userId   = api_require_account_user();
$deviceId = (int) ($_POST['device'] ?? 0);

if ($deviceId <= 0) {
    api_fail('Onbekend apparaat.', 400);
}

/* One answer for "not yours", "already revoked" and "never existed". Telling
   them apart would let somebody walk the id space and learn which ids are
   real, and none of the three is worth a different message to the owner. */
if (!device_revoke($userId, $deviceId)) {
    api_fail('Dit apparaat is niet gekoppeld.', 404);
}

api_ok(['device' => $deviceId]);
