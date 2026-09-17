<?php
/**
 * Records a meal, and with it the day's 1-10 rating.
 *
 * Several entries a day are expected. Each is its own row and the day's
 * nutrition score is their average, so a second honest rating refines the day
 * instead of overwriting the first.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/scoring.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();

$rating = $_POST['rating'] ?? null;

if ($rating !== null && $rating !== '') {
    $rating = (float) $rating;

    if ($rating < 1 || $rating > 10) {
        api_fail('Een beoordeling loopt van 1 tot 10.', 422);
    }
}

$entry = [
    'meal_type'   => (string) ($_POST['meal_type'] ?? 'other'),
    'label'       => ($_POST['label'] ?? '') === '' ? null : mb_substr((string) $_POST['label'], 0, 120),
    'consumed_at' => ($_POST['consumed_at'] ?? '') === '' ? date('Y-m-d H:i:s') : (string) $_POST['consumed_at'],
    'notes'       => ($_POST['notes'] ?? '') === '' ? null : mb_substr((string) $_POST['notes'], 0, 255),
    'rating'      => $rating === '' ? null : $rating,
];

foreach (['water', 'energy', 'protein', 'carbs', 'fat', 'saturated_fat', 'fibre', 'sugar', 'sodium'] as $nutrient) {
    if (isset($_POST[$nutrient]) && $_POST[$nutrient] !== '') {
        $entry[$nutrient] = (float) str_replace(',', '.', (string) $_POST[$nutrient]);
    }
}

$entryId = health_record_nutrition($userId, $entry);

if ($entryId === null) {
    api_fail('Deze invoer kon niet worden opgeslagen.', 500);
}

$date = substr((string) $entry['consumed_at'], 0, 10);

api_ok([
    'entry_id' => $entryId,
    'scores'   => score_domains($userId, $date),
    'overall'  => score_overall($userId, $date),
]);
