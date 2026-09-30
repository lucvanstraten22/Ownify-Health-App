<?php
/**
 * Ownify AI: the answer to "may Ownify send your data to Google Gemini?".
 *
 *   POST decision=accept|decline
 *   → 200 { ok, consent: accepted|declined, message }
 *
 * The same switch as Instellingen → Privacy → Ownify AI (api/profile/privacy.php
 * key ai_consent). Declining later stops everything from then on; the
 * conversations already held stay until they are wiped.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ai/assistant.php';

api_require_post();
api_require_database();

$userId   = api_require_account_user();
$decision = (string) ($_POST['decision'] ?? '');

if ($decision !== 'accept' && $decision !== 'decline') {
    api_fail('Kies toestaan of niet toestaan.', 422);
}

$result = ai_consent_set($userId, $decision === 'accept');

if (!$result['ok']) {
    api_fail((string) $result['error'], 503);
}

$state = ai_consent_state($userId);

api_ok([
    'consent' => $state,
    'message' => $state === 'accepted'
        ? 'Ownify AI staat aan.'
        : 'Ownify AI staat uit. Er gaat niets naar Gemini.',
]);
