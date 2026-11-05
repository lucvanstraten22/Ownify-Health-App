<?php
/**
 * A training entered by hand from Training's + (40.0, docs/MANUAL-ENTRY.md):
 * date, activity type (Health Connect's name), start (HH:MM), duration in
 * minutes and, if known, the calories. Refused (409) when a training is
 * already recorded in that time: nothing is merged or overwritten.
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

$result = health_manual_workout($userId, [
    'date'    => $_POST['date'] ?? '',
    'type'    => $_POST['type'] ?? '',
    'start'   => $_POST['start'] ?? '',
    'minutes' => $_POST['minutes'] ?? '',
    'kcal'    => $_POST['kcal'] ?? '',
]);

if (!$result['ok']) {
    api_fail((string) $result['error'], ($result['code'] ?? '') === 'overlap' ? 409 : 422);
}

api_ok([
    'workout_id'    => $result['workout_id'],
    'heart_minutes' => $result['heart_minutes'],
    'message'       => (string) health_manual_config()['training']['saved'],
]);
