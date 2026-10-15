<?php
/**
 * Gives ONE connected phone the name its owner chooses (Instellingen ›
 * Apparaten & Gezondheid).
 *
 *   device   the phone's id (user_devices.id)
 *   name     the new name, 1–40 characters on one line; empty gives the phone
 *            its own name back
 *
 * Only the name changes: the phone's token, its source, its sync and the
 * health data it sent stay as they are. The name is kept in custom_label,
 * which the app never writes (migration 024) — signing in on the phone again
 * rewrites `label`, what the phone calls itself, and leaves this alone.
 *
 * As with revoking, the device id arrives from the request and the user from
 * the session or token; device_rename() filters on both, so an id can only
 * ever name one of your own phones. The website (session + CSRF) and the app
 * (account token) alike.
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

if (!device_names_present()) {
    api_fail('De database is nog niet bijgewerkt voor apparaatnamen (migratie 024).', 503);
}

$name = device_name_clean((string) ($_POST['name'] ?? ''));

if ($name === false) {
    api_fail(tf('Een naam heeft hooguit %d tekens.', DEVICE_NAME_MAX), 422);
}

/* "Not yours", "revoked" and "never existed" answer alike, as revoking does. */
$device = device_rename($userId, $deviceId, $name);

if ($device === null) {
    api_fail('Dit apparaat is niet gekoppeld.', 404);
}

api_ok([
    'device'  => $deviceId,
    'name'    => $device['name'],
    'custom'  => $device['custom'],
    'message' => t('Naam bewaard.'),
]);
