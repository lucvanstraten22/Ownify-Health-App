<?php
/**
 * The two facts onboarding sets and nothing afterwards changes: date of birth
 * and gender.
 *
 * The refusal to overwrite lives in includes/user.php, not in this endpoint
 * and certainly not in a hidden form field — a field that is only hidden is
 * one request away from being sent anyway.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();

$result = user_set_onboarding_facts(
    $userId,
    isset($_POST['date_of_birth']) ? (string) $_POST['date_of_birth'] : null,
    isset($_POST['gender']) ? (string) $_POST['gender'] : null
);

if (!$result['ok']) {
    api_fail($result['error'], 422);
}

api_ok();
