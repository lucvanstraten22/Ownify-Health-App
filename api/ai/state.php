<?php
/**
 * Ownify AI: everything the assistant sheet needs to draw itself.
 *
 *   POST [conversation_id=…] [new=1]
 *   → 200 { ok, available, unavailable, notice, consent, usage, has_data,
 *           conversations: [{id, title, label}], conversation, messages }
 *
 *   consent       accepted | declined | unknown (never asked, or asked about
 *                 other terms than today's)
 *   available     whether a question can be answered now; `unavailable` says
 *                 why not (unavailable | quota) and `notice` in words
 *   conversation  the one asked for, else the last one used; none with new=1
 *
 * Conversations and messages only once consent is given. A conversation id
 * that is not this account's: 404.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ai/assistant.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

$conversationId = isset($_POST['conversation_id']) && $_POST['conversation_id'] !== ''
    ? (is_scalar($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0)
    : null;

if ($conversationId !== null && ai_installed() && ai_conversation_get($userId, $conversationId) === null) {
    api_json(['ok' => false, 'code' => 'not_found', 'error' => ai_error_text('not_found')], 404);
}

api_ok(ai_state($userId, $conversationId, ($_POST['new'] ?? '') === '1'));
