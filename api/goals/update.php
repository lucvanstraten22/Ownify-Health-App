<?php
/**
 * Pause, resume, complete or re-prioritise one of the signed-in user's goals.
 * Answers with the goal that is primary afterwards.
 *
 * The goal id arrives from the browser, which is why every statement behind
 * this carries `AND user_id = ?`: an id belonging to someone else matches no
 * row rather than being checked and then trusted.
 *
 * Signed in either way (api_require_account_user()):
 *   - the website: session + CSRF token, a form post, exactly as before;
 *   - the Ownify app: `Authorization: Bearer <account token>`, JSON or form.
 * A sync token from a pairing code is refused (403): it may upload records,
 * not change goals. The first endpoint to take both; the others follow once
 * the app needs them.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goal-create.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$input  = api_json_body();
$goalId = is_scalar($input['goal_id'] ?? null) ? (int) $input['goal_id'] : 0;
$action = is_string($input['action'] ?? null) ? $input['action'] : '';

if ($goalId <= 0) {
    api_fail('Onbekend doel.', 400);
}

/* Confirms ownership before reporting anything back, so a wrong id cannot be
   used to learn whether a goal exists. */
if (goal_get($userId, $goalId) === null) {
    api_fail('Onbekend doel.', 404);
}

/* Secundair: the goal that takes the primary slot is the one a delete would
   hand it to (goal_set_secondary()); `successor` is the goal the page or the
   app moved up, kept when it is as eligible — as with a delete. */
if ($action === 'secondary') {
    $shown = is_scalar($input['successor'] ?? null) ? trim((string) $input['successor']) : '';
    if (!goal_set_secondary($userId, $goalId, $shown === '' ? null : $shown)) {
        api_fail('Er is geen ander doel dat je hoofddoel kan worden.', 409);
    }
} else {
    $ok = goal_apply_action($userId, $goalId, $action);

    if ($ok === null) {
        api_fail('Onbekende actie.', 400);
    }

    if ($ok === false) {
        api_fail('Deze wijziging kon niet worden opgeslagen.', 500);
    }
}

/* Which goal is primary now, so a caller can check what was stored. */
$primary = db_value(
    "SELECT id FROM goals WHERE user_id = ? AND priority = 'primary' AND status IN ('active','paused')",
    [$userId]
);

api_ok(['primary' => $primary === null ? null : (string) $primary]);
