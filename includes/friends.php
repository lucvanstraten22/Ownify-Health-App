<?php
/**
 * Friendships and blocks.
 *
 * Friendship is symmetric and there is no follow: one row per pair, stored in
 * a fixed id order so a mirrored duplicate cannot exist. Blocking is separate
 * because it is one-directional — A can block B without B blocking A — and a
 * block always wins over a friendship row.
 *
 * There is no limit on friends. The 50 is a LEADERBOARD limit, applied when
 * the board is read (see includes/leaderboard.php), not here.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** The most friends one account may hold. */
if (!defined('FRIEND_LIMIT')) {
    define('FRIEND_LIMIT', 50);
}

if (!function_exists('friend_pair')) {

    /** The canonical [low, high] ordering the unique key depends on. */
    function friend_pair(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    function friend_status(int $userId, int $otherId): ?string
    {
        [$low, $high] = friend_pair($userId, $otherId);

        $status = db_value(
            'SELECT status FROM friendships WHERE user_low_id = ? AND user_high_id = ?',
            [$low, $high]
        );

        return $status === null ? null : (string) $status;
    }

    function block_exists(int $a, int $b): bool
    {
        return db_value(
            'SELECT 1 FROM user_blocks
              WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)
              LIMIT 1',
            [$a, $b, $b, $a]
        ) !== null;
    }

    function friend_request(int $fromUserId, int $toUserId): array
    {
        if ($fromUserId === $toUserId) {
            return ['ok' => false, 'error' => 'Je kunt jezelf niet toevoegen.'];
        }

        if (block_exists($fromUserId, $toUserId)) {
            // Deliberately vague: a block should not be announced.
            return ['ok' => false, 'error' => 'Dit verzoek kan niet worden verstuurd.'];
        }

        /* Fifty friends, both ways. Checked here rather than at the endpoint
           so the limit holds however the request arrives, and checked for the
           recipient too — otherwise a full account could be pushed past the
           limit by other people asking. */
        if (count(friend_ids($fromUserId)) >= FRIEND_LIMIT) {
            return ['ok' => false, 'error' => 'Je hebt het maximum van ' . FRIEND_LIMIT . ' vrienden bereikt.'];
        }

        if (count(friend_ids($toUserId)) >= FRIEND_LIMIT) {
            return ['ok' => false, 'error' => 'Dit account heeft het maximum aantal vrienden bereikt.'];
        }

        [$low, $high] = friend_pair($fromUserId, $toUserId);
        $existing = db_one(
            'SELECT id, status FROM friendships WHERE user_low_id = ? AND user_high_id = ?',
            [$low, $high]
        );

        if ($existing !== null && $existing['status'] === 'accepted') {
            return ['ok' => false, 'error' => 'Jullie zijn al vrienden.'];
        }

        if ($existing !== null && $existing['status'] === 'pending') {
            return ['ok' => true, 'error' => null, 'status' => 'pending'];
        }

        db_run(
            'INSERT INTO friendships (user_low_id, user_high_id, requested_by, status)
                  VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE requested_by = VALUES(requested_by),
                                     status = VALUES(status),
                                     responded_at = NULL,
                                     created_at = NOW()',
            [$low, $high, $fromUserId, 'pending']
        );

        return ['ok' => true, 'error' => null, 'status' => 'pending'];
    }

    /** Only the person who was asked may accept or decline. */
    function friend_respond(int $userId, int $otherId, string $action): array
    {
        if (!in_array($action, ['accepted', 'declined'], true)) {
            return ['ok' => false, 'error' => 'Onbekende actie.'];
        }

        [$low, $high] = friend_pair($userId, $otherId);
        $row = db_one(
            'SELECT id, requested_by, status FROM friendships WHERE user_low_id = ? AND user_high_id = ?',
            [$low, $high]
        );

        if ($row === null || $row['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'Er staat geen verzoek open.'];
        }

        if ((int) $row['requested_by'] === $userId) {
            return ['ok' => false, 'error' => 'Je kunt je eigen verzoek niet accepteren.'];
        }

        db_run('UPDATE friendships SET status = ?, responded_at = NOW() WHERE id = ?', [$action, (int) $row['id']]);

        return ['ok' => true, 'error' => null, 'status' => $action];
    }

    function friend_cancel(int $userId, int $otherId): array
    {
        [$low, $high] = friend_pair($userId, $otherId);

        db_run(
            'UPDATE friendships SET status = ?, responded_at = NOW()
              WHERE user_low_id = ? AND user_high_id = ? AND (requested_by = ? OR status = ?)',
            ['cancelled', $low, $high, $userId, 'accepted']
        );

        return ['ok' => true, 'error' => null];
    }

    /**
     * Accepted friends, with anyone on either side of a block removed.
     *
     * Two plain queries rather than one clever one: native prepared statements
     * cannot reuse a named placeholder, and this reads better anyway.
     */
    function friend_ids(int $userId): array
    {
        $rows = db_all(
            'SELECT CASE WHEN user_low_id = ? THEN user_high_id ELSE user_low_id END AS friend_id
               FROM friendships
              WHERE status = ? AND (user_low_id = ? OR user_high_id = ?)',
            [$userId, 'accepted', $userId, $userId]
        );

        $ids = array_map(static fn (array $r): int => (int) $r['friend_id'], $rows);
        if ($ids === []) {
            return [];
        }

        $blocked = db_all(
            'SELECT blocked_id AS id FROM user_blocks WHERE blocker_id = ?
             UNION
             SELECT blocker_id AS id FROM user_blocks WHERE blocked_id = ?',
            [$userId, $userId]
        );

        $exclude = array_map(static fn (array $r): int => (int) $r['id'], $blocked);

        return array_values(array_diff($ids, $exclude));
    }

    /** Public fields only — a friend list never carries health data. */
    function friend_list(int $userId): array
    {
        require_once __DIR__ . '/user.php';

        $ids = friend_ids($userId);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return db_all(
            'SELECT u.id, u.username, p.avatar_path
               FROM users u
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE u.id IN (' . $placeholders . ') AND u.status = ?
           ORDER BY u.username',
            [...$ids, 'active']
        );
    }

    function friend_pending_for(int $userId): array
    {
        return db_all(
            'SELECT f.id, f.requested_by, u.username, p.avatar_path
               FROM friendships f
               JOIN users u ON u.id = f.requested_by
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE f.status = ? AND f.requested_by <> ?
                AND (f.user_low_id = ? OR f.user_high_id = ?)
           ORDER BY f.created_at DESC',
            ['pending', $userId, $userId, $userId]
        );
    }

    /* ------------------------------------------------------------ blocks */

    function user_block_add(int $blockerId, int $blockedId): array
    {
        if ($blockerId === $blockedId) {
            return ['ok' => false, 'error' => 'Je kunt jezelf niet blokkeren.'];
        }

        db_run(
            'INSERT INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE created_at = created_at',
            [$blockerId, $blockedId]
        );

        // A block ends the friendship rather than leaving it dangling.
        friend_cancel($blockerId, $blockedId);

        return ['ok' => true, 'error' => null];
    }

    function user_block_remove(int $blockerId, int $blockedId): array
    {
        db_run('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [$blockerId, $blockedId]);

        return ['ok' => true, 'error' => null];
    }

    function user_blocked_ids(int $userId): array
    {
        $rows = db_all('SELECT blocked_id FROM user_blocks WHERE blocker_id = ?', [$userId]);

        return array_map(static fn (array $r): int => (int) $r['blocked_id'], $rows);
    }
}
