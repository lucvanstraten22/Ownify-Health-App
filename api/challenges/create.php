<?php
/**
 * Nieuwe uitdaging: makes a challenge with its maker in it, invites the
 * chosen friends and answers with its five-digit code.
 *
 *   kind         together | versus
 *   metric       workouts | steps | active_minutes | distance | sleep_nights
 *   target       Samen only: the shared target, in what the reader sees
 *   target_unit  km | mi (afstand only), which unit `target` was typed in
 *   length       1w | 2w | month
 *   from         today | monday
 *   name         optional; empty gets a suggested one
 *   invite[]     friends' user ids — invited, never joined for them
 *
 * docs/CHALLENGES.md.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/challenges.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (!challenges_available()) {
    api_fail('Uitdagingen zijn nog niet beschikbaar.', 503);
}

$input = api_json_body();
$invite = $input['invite'] ?? [];
if (is_string($invite)) {
    $invite = $invite === '' ? [] : explode(',', $invite);
}

$result = challenge_create($userId, [
    'kind'        => $input['kind'] ?? null,
    'metric'      => $input['metric'] ?? null,
    'target'      => $input['target'] ?? null,
    'target_unit' => $input['target_unit'] ?? 'km',
    'length'      => $input['length'] ?? null,
    'from'        => $input['from'] ?? null,
    'name'        => (string) ($input['name'] ?? ''),
    'invite'      => is_array($invite) ? $invite : [],
]);

if (!$result['ok']) {
    api_fail($result['error'], 422);
}

api_ok([
    'id'      => $result['id'],
    'code'    => $result['code'],
    'name'    => $result['name'],
    'invited' => $result['invited'],
    'share_text' => tf('Doe mee met “%s” in Ownify: vul code %s in bij Community › Uitdagingen.', $result['name'], $result['code']),
    'message' => 'Je uitdaging staat klaar',
]);
