<?php
/**
 * Saves the parts of a profile a person may change after onboarding.
 *
 * Height and weight are not columns here: they go to user_measurements, so
 * changing your weight adds a reading rather than erasing last month's.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();
$saved  = [];

/* ------------------------------------------------------------- names */

$fields = [];
foreach (['first_name', 'last_name', 'activity_level'] as $key) {
    if (array_key_exists($key, $_POST)) {
        $fields[$key] = (string) $_POST[$key];
    }
}

if ($fields !== []) {
    $result = user_update_profile($userId, $fields);

    if (!$result['ok']) {
        api_fail($result['error'], 422);
    }

    $saved = array_merge($saved, array_keys($fields));
}

/* ------------------------------------------------------ measurements */

$limits = [
    'height' => ['unit' => 'cm', 'min' => 50,  'max' => 260],
    'weight' => ['unit' => 'kg', 'min' => 20,  'max' => 400],
];

foreach ($limits as $type => $limit) {
    if (!array_key_exists($type, $_POST) || $_POST[$type] === '') {
        continue;
    }

    $value = (float) str_replace(',', '.', (string) $_POST[$type]);

    if ($value < $limit['min'] || $value > $limit['max']) {
        api_fail('Vul een geldige waarde in voor ' . ($type === 'height' ? 'lengte' : 'gewicht') . '.', 422);
    }

    user_record_measurement($userId, $type, $value, $limit['unit']);
    $saved[] = $type;
}

if ($saved === []) {
    api_fail('Niets om op te slaan.', 400);
}

api_ok(['saved' => $saved] + api_account_payload($userId));
