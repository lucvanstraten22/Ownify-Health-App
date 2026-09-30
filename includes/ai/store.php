<?php
/**
 * Ownify AI: what is kept — conversations, messages, usage.
 *
 * Every function that touches a person's rows takes the signed-in user id as
 * its first argument, and every statement carries `AND user_id = ?`. An id
 * from a request (a conversation, a message) that belongs to somebody else
 * matches no row: it is not checked and then trusted, it simply finds
 * nothing.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('ai_conversation_get')) {

    /* ====================================================== conversations */

    /** One of the person's own conversations, or null. */
    function ai_conversation_get(int $userId, int $conversationId): ?array
    {
        if ($conversationId <= 0) {
            return null;
        }

        return db_one(
            'SELECT id, title, created_at, updated_at FROM ai_conversations WHERE id = ? AND user_id = ?',
            [$conversationId, $userId]
        );
    }

    /** The conversation the person last used, or null. */
    function ai_conversation_latest(int $userId): ?array
    {
        return db_one(
            'SELECT id, title, created_at, updated_at FROM ai_conversations
              WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1',
            [$userId]
        );
    }

    /** The person's conversations, the most recently used first. */
    function ai_conversations(int $userId, int $limit = 30): array
    {
        return db_all(
            'SELECT id, title, created_at, updated_at FROM ai_conversations
              WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT ' . max(1, min($limit, 100)),
            [$userId]
        );
    }

    /** A new conversation, named after its first question. */
    function ai_conversation_create(int $userId, string $firstMessage): ?int
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $firstMessage));
        if (mb_strlen($title) > 60) {
            $title = rtrim(mb_substr($title, 0, 57)) . '…';
        }

        db_run('INSERT INTO ai_conversations (user_id, title) VALUES (?, ?)', [$userId, $title]);
        $id = db_insert_id();

        ai_conversations_prune($userId);

        return $id > 0 ? $id : null;
    }

    function ai_conversation_touch(int $userId, int $conversationId): void
    {
        db_run('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ? AND user_id = ?', [$conversationId, $userId]);
    }

    /** Deletes one of the person's own conversations, with its messages. */
    function ai_conversation_delete(int $userId, int $conversationId): bool
    {
        $statement = db_run('DELETE FROM ai_conversations WHERE id = ? AND user_id = ?', [$conversationId, $userId]);

        return $statement !== null && $statement->rowCount() === 1;
    }

    /** Deletes every conversation of this person — and nobody else's. */
    function ai_conversations_delete_all(int $userId): int
    {
        $statement = db_run('DELETE FROM ai_conversations WHERE user_id = ?', [$userId]);

        return $statement === null ? 0 : $statement->rowCount();
    }

    /** Keeps the newest `max_conversations`; older ones go, with their messages. */
    function ai_conversations_prune(int $userId): void
    {
        $keep = max(1, (int) (ai_config()['max_conversations'] ?? 50));

        $cut = db_value(
            'SELECT updated_at FROM ai_conversations WHERE user_id = ?
              ORDER BY updated_at DESC, id DESC LIMIT 1 OFFSET ' . $keep,
            [$userId]
        );

        if ($cut !== null) {
            db_run('DELETE FROM ai_conversations WHERE user_id = ? AND updated_at <= ?', [$userId, $cut]);
        }
    }

    /* =========================================================== messages */

    /**
     * A conversation's messages, oldest first — the last $limit of them.
     * The conversation must be the person's own (the caller has checked;
     * the user id here makes sure).
     */
    function ai_messages(int $userId, int $conversationId, int $limit = 200): array
    {
        $rows = db_all(
            'SELECT id, role, content, action_json, action_state, created_at
               FROM ai_messages
              WHERE conversation_id = ? AND user_id = ?
           ORDER BY id DESC LIMIT ' . max(1, min($limit, 500)),
            [$conversationId, $userId]
        );

        return array_reverse($rows);
    }

    function ai_message_count(int $userId, int $conversationId): int
    {
        return (int) db_value(
            'SELECT COUNT(*) FROM ai_messages WHERE conversation_id = ? AND user_id = ?',
            [$conversationId, $userId]
        );
    }

    function ai_message_add(
        int $userId,
        int $conversationId,
        string $role,
        string $content,
        ?array $action = null,
        ?array $meta = null
    ): ?int {
        if (!in_array($role, ['system', 'user', 'assistant'], true)) {
            return null;
        }

        db_run(
            'INSERT INTO ai_messages (conversation_id, user_id, role, content, action_json, action_state, meta_json)
                  VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $conversationId,
                $userId,
                $role,
                $content,
                $action === null ? null : json_encode($action, JSON_UNESCAPED_UNICODE),
                $action === null ? null : 'pending',
                $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]
        );

        $id = db_insert_id();

        return $id > 0 ? $id : null;
    }

    function ai_message_get(int $userId, int $messageId): ?array
    {
        if ($messageId <= 0) {
            return null;
        }

        return db_one(
            'SELECT id, conversation_id, role, content, action_json, action_state, created_at
               FROM ai_messages WHERE id = ? AND user_id = ?',
            [$messageId, $userId]
        );
    }

    /**
     * Takes a proposed change for carrying out: pending → running, in one
     * statement, so the same change is never done twice however often the
     * button is pressed. Null when it is not the person's, not pending any
     * more, or already taken.
     */
    function ai_action_claim(int $userId, int $messageId): ?array
    {
        $statement = db_run(
            "UPDATE ai_messages SET action_state = 'running'
              WHERE id = ? AND user_id = ? AND role = 'assistant' AND action_state = 'pending'",
            [$messageId, $userId]
        );

        if ($statement === null || $statement->rowCount() !== 1) {
            return null;
        }

        return ai_message_get($userId, $messageId);
    }

    function ai_action_finish(int $userId, int $messageId, string $state): void
    {
        if (!in_array($state, ['done', 'declined', 'failed', 'expired', 'pending'], true)) {
            return;
        }

        db_run('UPDATE ai_messages SET action_state = ? WHERE id = ? AND user_id = ?', [$state, $messageId, $userId]);
    }

    /** The change waiting for a yes in this conversation, if there is one. */
    function ai_action_pending(int $userId, int $conversationId): ?array
    {
        return db_one(
            "SELECT id, conversation_id, role, content, action_json, action_state, created_at
               FROM ai_messages
              WHERE conversation_id = ? AND user_id = ? AND action_state = 'pending'
           ORDER BY id DESC LIMIT 1",
            [$conversationId, $userId]
        );
    }

    /** Asked about something else instead: what was waiting is not done. */
    function ai_actions_expire(int $userId, int $conversationId): void
    {
        db_run(
            "UPDATE ai_messages SET action_state = 'expired'
              WHERE conversation_id = ? AND user_id = ? AND action_state = 'pending'",
            [$conversationId, $userId]
        );
    }

    /* ============================================================== usage */

    function ai_today(): string
    {
        return date('Y-m-d');
    }

    /** Today's counts for this person. */
    function ai_usage_today(int $userId): array
    {
        $row = db_one(
            'SELECT messages, gemini_calls, input_tokens, output_tokens, errors, limit_hits
               FROM ai_usage WHERE user_id = ? AND usage_date = ?',
            [$userId, ai_today()]
        );

        return [
            'messages'      => (int) ($row['messages'] ?? 0),
            'gemini_calls'  => (int) ($row['gemini_calls'] ?? 0),
            'input_tokens'  => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'errors'        => (int) ($row['errors'] ?? 0),
            'limit_hits'    => (int) ($row['limit_hits'] ?? 0),
        ];
    }

    /** The daily limit as the apps show it: used, limit, remaining. */
    function ai_usage_summary(int $userId): array
    {
        $limit = max(0, (int) ai_config()['daily_limit']);
        $used  = ai_installed() ? ai_usage_today($userId)['messages'] : 0;

        return ['used' => $used, 'limit' => $limit, 'remaining' => max(0, $limit - $used)];
    }

    /**
     * Takes one of today's messages, if one is left — checked and counted in
     * one statement, so two questions sent at once cannot both take the last
     * one. Given back (ai_usage_release) when Gemini could not answer: a
     * question that got no answer does not count.
     */
    function ai_usage_reserve(int $userId): bool
    {
        $limit = max(0, (int) ai_config()['daily_limit']);

        db_run('INSERT IGNORE INTO ai_usage (user_id, usage_date) VALUES (?, ?)', [$userId, ai_today()]);

        $statement = db_run(
            'UPDATE ai_usage SET messages = messages + 1
              WHERE user_id = ? AND usage_date = ? AND messages < ?',
            [$userId, ai_today(), $limit]
        );

        return $statement !== null && $statement->rowCount() === 1;
    }

    function ai_usage_release(int $userId): void
    {
        db_run(
            'UPDATE ai_usage SET messages = messages - 1 WHERE user_id = ? AND usage_date = ? AND messages > 0',
            [$userId, ai_today()]
        );
    }

    /** Adds to today's counters: gemini_calls, input_tokens, output_tokens, errors, limit_hits. */
    function ai_usage_add(int $userId, array $counts): void
    {
        $columns = ['gemini_calls', 'input_tokens', 'output_tokens', 'errors', 'limit_hits'];
        $sets    = [];
        $values  = [];

        foreach ($columns as $column) {
            $n = (int) ($counts[$column] ?? 0);
            if ($n > 0) {
                $sets[]   = $column . ' = ' . $column . ' + ?';
                $values[] = $n;
            }
        }

        if ($sets === []) {
            return;
        }

        db_run('INSERT IGNORE INTO ai_usage (user_id, usage_date) VALUES (?, ?)', [$userId, ai_today()]);
        db_run(
            'UPDATE ai_usage SET ' . implode(', ', $sets) . ' WHERE user_id = ? AND usage_date = ?',
            [...$values, $userId, ai_today()]
        );
    }

    /** Requests made to Gemini today by everybody together: the shared free quota. */
    function ai_usage_global_calls(): int
    {
        return (int) db_value('SELECT COALESCE(SUM(gemini_calls), 0) FROM ai_usage WHERE usage_date = ?', [ai_today()]);
    }

    /* ====================================================== Gemini itself */

    /** Until when Gemini's free quota is known to be used up, or null. */
    function ai_quota_blocked_until(): ?DateTimeImmutable
    {
        $until = db_value("SELECT until FROM ai_service_state WHERE name = 'quota_block'");

        if ($until === null) {
            return null;
        }

        $at = new DateTimeImmutable((string) $until);

        return $at > new DateTimeImmutable('now') ? $at : null;
    }

    /** Stops asking Gemini until $until, because it said the free quota is used up. */
    function ai_quota_block(DateTimeImmutable $until, string $why): void
    {
        db_run(
            "INSERT INTO ai_service_state (name, value, until) VALUES ('quota_block', ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), until = VALUES(until)",
            [mb_substr($why, 0, 255), $until->format('Y-m-d H:i:s')]
        );
    }
}
