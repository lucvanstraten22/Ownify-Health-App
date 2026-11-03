<?php
/**
 * Gegevens wissen (Instellingen › Privacy › Je gegevens): the signed-in
 * account's health data and everything worked out from it — measurements,
 * sleep, heart rate, nutrition, workouts, scores, points, insights and goals
 * (includes/data-export.php, data_wipe_tables()).
 *
 * The account stays, with its sign-in, profile, friends, challenges,
 * connections, preferences and OwnifyAI conversations (those have their own
 * button). The request has to say `confirm=wissen`, which only the
 * confirmation's yes sends; anything else deletes nothing.
 *
 *   POST confirm=wissen (csrf on the website, the account token in the app)
 *   → 200 { ok, deleted, message }
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/data-export.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (($_POST['confirm'] ?? '') !== 'wissen') {
    api_fail('Bevestig eerst dat je je gegevens wilt wissen.', 422);
}

$result = data_wipe($userId);

if (!$result['ok']) {
    api_fail((string) $result['error'], 500);
}

api_ok([
    'deleted' => $result['deleted'],
    'message' => $result['deleted'] === 0
        ? 'Er waren geen gegevens om te wissen.'
        : 'Je gegevens zijn gewist. Je account staat er nog.',
]);
