<?php
/**
 * Ownify AI: ask a question, get the answer.
 *
 *   POST message=…  [conversation_id=…]
 *   → 200 { ok, conversation: {id, title, label}, messages: [the question, the answer
 *           (or: the question, the proposal it answered, Ownify's note)], usage }
 *   → 4xx/5xx { ok: false, code, error, usage? }
 *
 *   code  consent      403  not allowed yet (Instellingen → Privacy, or the sheet)
 *         limit        429  today's messages are used up
 *         quota        503  Gemini's free quota is used up, for everybody
 *         unavailable  503  not set up, or Gemini could not be reached
 *         timeout      503  no answer in time
 *         blocked      422  Gemini would not answer this
 *         empty, too_long  422
 *         not_found    404  no such conversation — of this account
 *
 * Without conversation_id a new conversation begins with this question. The
 * user is whoever the session or the app's account token says, never a field
 * in the request. See includes/ai/assistant.php for the steps.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ai/assistant.php';

/* A question with a look-up takes a few requests to Gemini; on Windows the
   PHP time limit counts the waiting too. */
@set_time_limit(120);

api_require_post();
api_require_database();

$userId = api_require_account_user();

$conversationId = isset($_POST['conversation_id']) && $_POST['conversation_id'] !== ''
    ? (is_scalar($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0)
    : null;

$message = is_string($_POST['message'] ?? null) ? $_POST['message'] : '';

$result = ai_chat($userId, $conversationId, $message);

if (!$result['ok']) {
    $status = (int) $result['status'];
    unset($result['status']);
    api_json($result, $status);
}

api_ok($result);
