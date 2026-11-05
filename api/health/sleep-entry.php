<?php
/**
 * A night entered or corrected by hand from Slaap's + (40.0,
 * docs/MANUAL-ENTRY.md): the morning (date), bedtime and wake-up time
 * (HH:MM). A night already there for that morning keeps its row and its
 * measurements and takes these times; a sync keeps them after.
 * The website (session + CSRF) and the app (account token) alike.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/health-manual.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (!health_manual_ready()) {
    api_fail(t('Dit kan op deze server nog niet.'), 503);
}

$result = health_manual_sleep($userId, $_POST['date'] ?? '', $_POST['bedtime'] ?? '', $_POST['wakeup'] ?? '');

if (!$result['ok']) {
    api_fail((string) $result['error'], 422);
}

$copy = health_manual_config()['sleep'];

api_ok([
    'session_id'    => $result['session_id'],
    'updated'       => $result['updated'],
    'night'         => $result['night'],
    'heart_minutes' => $result['heart_minutes'],
    'message'       => (string) ($result['updated'] ? $copy['updated'] : $copy['saved']),
]);
