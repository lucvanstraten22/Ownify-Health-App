<?php
/**
 * Instellingen → Privacy: the signed-in account's own switches, and nobody
 * else's — there is no id in the request to point them anywhere else. One
 * switch per request:
 *
 *   leaderboard_avatar   "Profielfoto op de ranglijst", 1 or 0. Off, every
 *                        board shows the account's initial instead of its
 *                        picture, to everybody, the account itself included.
 *                        The picture itself is not touched.
 *
 *   ai_consent           "OwnifyAI", 1 or 0: whether the assistant may send
 *                        the account's data to Google Gemini. The same answer
 *                        as the one given in the assistant (api/ai/consent.php).
 *
 *   ai_insights_consent  No longer exists (30.1): insights are Ownify's own
 *                        sentences and nothing about them goes to Gemini. An
 *                        older app that still sends it is told so (410).
 *
 * The website (session + CSRF) and the app (account token) alike.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/leaderboard.php';
require_once dirname(__DIR__, 2) . '/includes/ai/config.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (array_key_exists('ai_consent', $_POST)) {
    $value = (string) $_POST['ai_consent'];

    if ($value !== '0' && $value !== '1') {
        api_fail('Kies aan of uit.', 422);
    }

    $result = ai_consent_set($userId, $value === '1');

    if (!$result['ok']) {
        api_fail($result['error'], 503);
    }

    $allowed = ai_consented($userId);

    api_ok([
        'ai_consent' => $allowed,
        'message'    => $allowed
            ? t('OwnifyAI staat aan.')
            : t('OwnifyAI staat uit. Er gaat niets naar Gemini.'),
    ]);
}

if (array_key_exists('ai_insights_consent', $_POST)) {
    api_fail(t('Je inzichten schrijft Ownify zelf. Er gaat niets over naar Gemini, dus hier is niets meer aan of uit te zetten.'), 410);
}

$value = (string) ($_POST['leaderboard_avatar'] ?? '');

if ($value !== '0' && $value !== '1') {
    api_fail('Kies aan of uit.', 422);
}

$result = leaderboard_set_avatar_shown($userId, $value === '1');

if (!$result['ok']) {
    api_fail($result['error'], 503);
}

$shown = leaderboard_avatar_shown($userId);

api_ok([
    'leaderboard_avatar' => $shown,
    'message'            => $shown
        ? t('Je profielfoto staat op de ranglijst.')
        : t('Op de ranglijst staat nu je initiaal in plaats van je foto.'),
]);
