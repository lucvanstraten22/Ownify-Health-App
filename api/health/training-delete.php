<?php
/**
 * Removes one of the person's trainings from Ownify (40.0,
 * docs/MANUAL-ENTRY.md), after the confirmation on its page. One that came
 * from Health Connect or Polar is only removed here and is not synced back;
 * it stays where it came from. Goals, points, the Health Score and the
 * day's insights are worked out again.
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

$result = health_manual_remove_workout($userId, (int) ($_POST['workout_id'] ?? 0));

if (!$result['ok']) {
    api_fail((string) $result['error'], 404);
}

api_ok(['imported' => $result['imported']]);
