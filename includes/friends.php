<?php
/**
 * Friendships, friend requests and blocks.
 *
 * ---------------------------------------------------------------------------
 * ONE ROW PER PAIR
 * ---------------------------------------------------------------------------
 * Friendship is symmetric and there is no follow: one row in `friendships`
 * per pair, stored in a fixed id order so a mirrored duplicate cannot exist.
 * The row is the request and, once accepted, the friendship:
 *
 *   pending    a request — requested_by sent it, created_at is when
 *   accepted   friends, both ways, since responded_at
 *   declined   the request was turned down: closed, nobody is a friend
 *   no row     nothing between them — a friend removed, a request withdrawn
 *
 * A second request for a pair that already has one is the same row, so the
 * same request cannot be sent twice however often, or however quickly, it is
 * asked for.
 *
 * ---------------------------------------------------------------------------
 * WHO MAY DO WHAT
 * ---------------------------------------------------------------------------
 * Every function takes the acting account first, and that id comes from the
 * session, never from a request. The other account is only ever the other
 * half of the pair, and each write checks the pair's own state in the same
 * statement: only the person a request was sent to can answer it, only one
 * of the two friends can end a friendship, and nobody can send a request to
 * somebody who has switched "Vriendverzoeken toestaan" off.
 *
 * Nothing here reads another account's health data. The fields that leave
 * this file about somebody else are their username and profile picture.
 *
 * ---------------------------------------------------------------------------
 * BLOCKS AND LIMITS
 * ---------------------------------------------------------------------------
 * Blocking is separate because it is one-directional — A can block B without
 * B blocking A — and a block wins: it ends whatever is between the two, and
 * neither can find or ask the other.
 *
 * An account holds at most FRIEND_LIMIT friends — the size of the friends
 * leaderboard — checked for both sides, when a request is sent and again
 * when it is accepted.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/user.php';

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

    /** The pair's row, whoever of the two asks. */
    function friend_row(int $a, int $b): ?array
    {
        [$low, $high] = friend_pair($a, $b);

        return db_one(
            'SELECT id, requested_by, status, created_at, responded_at
               FROM friendships WHERE user_low_id = ? AND user_high_id = ?',
            [$low, $high]
        );
    }

    function friend_status(int $userId, int $otherId): ?string
    {
        $row = friend_row($userId, $otherId);

        return $row === null ? null : (string) $row['status'];
    }

    /**
     * Where two accounts stand, seen from the first:
     * self · friends · outgoing (you asked) · incoming (they asked) ·
     * blocked · none.
     */
    function friend_relation(int $userId, int $otherId): string
    {
        if ($userId === $otherId) {
            return 'self';
        }

        if (block_exists($userId, $otherId)) {
            return 'blocked';
        }

        $row = friend_row($userId, $otherId);

        if ($row === null) {
            return 'none';
        }

        return match ((string) $row['status']) {
            'accepted' => 'friends',
            'pending'  => (int) $row['requested_by'] === $userId ? 'outgoing' : 'incoming',
            default    => 'none',
        };
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

    /* ==================================================================
       VRIENDVERZOEKEN TOESTAAN
       ================================================================== */

    /** Whether the database has the setting yet (migration 011). */
    function friend_setting_stored(): bool
    {
        static $stored = null;

        return $stored ??= db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
                AND column_name = 'allow_friend_requests'"
        ) > 0;
    }

    /** Whether this account can be sent a new friend request. On unless switched off. */
    function friend_requests_allowed(int $userId): bool
    {
        if (!friend_setting_stored()) {
            return true;
        }

        $value = db_value('SELECT allow_friend_requests FROM user_profiles WHERE user_id = ?', [$userId]);

        return $value === null || (int) $value === 1;
    }

    /** The account's own switch. Friends and requests already there stay. */
    function friend_set_requests_allowed(int $userId, bool $allowed): array
    {
        if (!friend_setting_stored()) {
            error_log('[ownify] friends: user_profiles has no allow_friend_requests yet, so the switch cannot be saved — '
                . 'import database/migrations/011-friend-requests-setting.sql');

            return ['ok' => false, 'error' => 'Deze instelling kan nog niet worden opgeslagen.'];
        }

        db_run(
            'INSERT INTO user_profiles (user_id, allow_friend_requests) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE allow_friend_requests = VALUES(allow_friend_requests)',
            [$userId, $allowed ? 1 : 0]
        );

        return ['ok' => true, 'error' => null, 'allowed' => $allowed];
    }

    /* ==================================================================
       FINDING SOMEBODY
       ================================================================== */

    /**
     * The account with exactly this username — case does not matter, the
     * column's collation sees to that — and where the two of you stand. A
     * username is the only way to find anybody: there is no browsing, no
     * suggestions, and nothing but the handle and the picture comes back.
     *
     * An account on either side of a block is not found.
     */
    function friend_find(int $userId, string $username): array
    {
        $username = ltrim(trim($username), '@');

        if ($username === '') {
            return ['ok' => false, 'code' => 'empty', 'error' => 'Vul een gebruikersnaam in.'];
        }

        $missing = ['ok' => false, 'code' => 'not_found', 'error' => 'Er is geen account met deze gebruikersnaam.'];

        /* Nothing that could not be a username is looked up at all. */
        if (mb_strlen($username) > 30 || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            return $missing;
        }

        $row = db_one(
            'SELECT u.id, u.username, p.avatar_path
               FROM users u
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE u.username = ? AND u.status = ?',
            [$username, 'active']
        );

        if ($row === null) {
            return $missing;
        }

        $person = friend_person($userId, (int) $row['id'], (string) $row['username'], $row['avatar_path']);

        if ($person['relation'] === 'blocked') {
            return $missing;
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'person' => $person];
    }

    /**
     * Somebody as the friends panel shows them: who, their picture, and
     * where you stand — with the line that says so, and whether a request
     * can be sent.
     */
    function friend_person(int $userId, int $otherId, string $username, ?string $avatar): array
    {
        $relation = friend_relation($userId, $otherId);
        $open     = $relation === 'none' && friend_requests_allowed($otherId);

        $status = match ($relation) {
            'self'     => 'Dit ben jij',
            'friends'  => 'Jullie zijn vrienden',
            'outgoing' => 'Verzoek verstuurd',
            'incoming' => 'Wil vrienden met je worden',
            default    => $open ? 'Nog geen vrienden' : 'Accepteert geen vriendverzoeken',
        };

        return [
            'id'          => $otherId,
            'username'    => $username,
            'avatar'      => avatar_small($avatar),
            'relation'    => $relation,
            'status'      => $status,
            'can_request' => $open,
        ];
    }

    /* ==================================================================
       REQUESTS
       ================================================================== */

    /**
     * Sends a friend request, as $fromUserId and nobody else.
     *
     * @return array{ok: bool, code: ?string, error: ?string, relation?: string}
     */
    function friend_request(int $fromUserId, int $toUserId): array
    {
        $fail = static fn (string $code, string $error): array
            => ['ok' => false, 'code' => $code, 'error' => $error, 'relation' => friend_relation($fromUserId, $toUserId)];

        if ($fromUserId === $toUserId) {
            return $fail('self', 'Je kunt jezelf niet toevoegen.');
        }

        $other = user_public_profile($toUserId);

        if ($other === null) {
            return $fail('not_found', 'Er is geen account met deze gebruikersnaam.');
        }

        if (block_exists($fromUserId, $toUserId)) {
            // Deliberately vague: a block should not be announced.
            return $fail('blocked', 'Dit verzoek kan niet worden verstuurd.');
        }

        $name = (string) $other['username'];

        switch (friend_relation($fromUserId, $toUserId)) {
            case 'friends':
                return $fail('friends', 'Jullie zijn al vrienden.');
            case 'outgoing':
                return $fail('outgoing', 'Je hebt ' . $name . ' al een vriendverzoek gestuurd.');
            case 'incoming':
                return $fail('incoming', $name . ' heeft jou al een vriendverzoek gestuurd. Je kunt het hier accepteren of weigeren.');
        }

        if (!friend_requests_allowed($toUserId)) {
            return $fail('closed', $name . ' accepteert geen vriendverzoeken.');
        }

        /* Fifty friends, both ways. Checked here rather than at the endpoint
           so the limit holds however the request arrives, and for the
           recipient too — otherwise a full account could be pushed past the
           limit by other people asking. */
        if (count(friend_ids($fromUserId)) >= FRIEND_LIMIT) {
            return $fail('limit', 'Je hebt het maximum van ' . FRIEND_LIMIT . ' vrienden bereikt.');
        }

        if (count(friend_ids($toUserId)) >= FRIEND_LIMIT) {
            return $fail('limit', 'Dit account heeft het maximum aantal vrienden bereikt.');
        }

        /* A new request, or a declined one asked again. One statement, so two
           requests at the same moment — a double tap, or both people asking
           each other at once — leave one request: the first. The assignments
           read the row as it was, because status is changed last. */
        [$low, $high] = friend_pair($fromUserId, $toUserId);

        db_run(
            "INSERT INTO friendships (user_low_id, user_high_id, requested_by, status)
                  VALUES (?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE
                    requested_by = IF(status IN ('declined', 'cancelled'), VALUES(requested_by), requested_by),
                    created_at   = IF(status IN ('declined', 'cancelled'), NOW(), created_at),
                    responded_at = IF(status IN ('declined', 'cancelled'), NULL, responded_at),
                    status       = IF(status IN ('declined', 'cancelled'), 'pending', status)",
            [$low, $high, $fromUserId]
        );

        $relation = friend_relation($fromUserId, $toUserId);

        return match ($relation) {
            'outgoing' => ['ok' => true, 'code' => null, 'error' => null, 'relation' => 'outgoing'],
            'incoming' => $fail('incoming', $name . ' heeft jou al een vriendverzoek gestuurd. Je kunt het hier accepteren of weigeren.'),
            'friends'  => $fail('friends', 'Jullie zijn al vrienden.'),
            default    => $fail('failed', 'Het verzoek kon niet worden verstuurd.'),
        };
    }

    /**
     * Accepts or declines a request — only one sent TO $userId: the update
     * itself requires the other person to be the one who asked.
     */
    function friend_respond(int $userId, int $otherId, string $action): array
    {
        if (!in_array($action, ['accepted', 'declined'], true)) {
            return ['ok' => false, 'code' => 'invalid', 'error' => 'Onbekende actie.'];
        }

        $row = friend_row($userId, $otherId);

        if ($row === null || $row['status'] !== 'pending' || $userId === $otherId) {
            return ['ok' => false, 'code' => 'gone', 'error' => 'Dit verzoek staat niet meer open.'];
        }

        if ((int) $row['requested_by'] === $userId) {
            return ['ok' => false, 'code' => 'own', 'error' => $action === 'accepted'
                ? 'Je kunt je eigen verzoek niet accepteren.'
                : 'Je kunt je eigen verzoek niet weigeren.'];
        }

        if ($action === 'accepted') {
            if (block_exists($userId, $otherId)) {
                return ['ok' => false, 'code' => 'blocked', 'error' => 'Dit verzoek kan niet worden geaccepteerd.'];
            }

            if (count(friend_ids($userId)) >= FRIEND_LIMIT) {
                return ['ok' => false, 'code' => 'limit', 'error' => 'Je hebt het maximum van ' . FRIEND_LIMIT . ' vrienden bereikt.'];
            }

            if (count(friend_ids($otherId)) >= FRIEND_LIMIT) {
                return ['ok' => false, 'code' => 'limit', 'error' => 'Dit account heeft het maximum aantal vrienden bereikt.'];
            }
        }

        $done = db_run(
            'UPDATE friendships SET status = ?, responded_at = NOW()
              WHERE id = ? AND status = ? AND requested_by = ?',
            [$action, (int) $row['id'], 'pending', $otherId]
        );

        if ($done === null || $done->rowCount() === 0) {
            return ['ok' => false, 'code' => 'gone', 'error' => 'Dit verzoek staat niet meer open.'];
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'relation' => $action === 'accepted' ? 'friends' : 'none'];
    }

    /** Ends a friendship — one of the two friends, and only a friendship. */
    function friend_remove(int $userId, int $otherId): array
    {
        [$low, $high] = friend_pair($userId, $otherId);

        $done = $userId === $otherId ? null : db_run(
            'DELETE FROM friendships WHERE user_low_id = ? AND user_high_id = ? AND status = ?',
            [$low, $high, 'accepted']
        );

        if ($done === null || $done->rowCount() === 0) {
            return ['ok' => false, 'code' => 'not_friends', 'error' => 'Jullie zijn geen vrienden.'];
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'relation' => 'none'];
    }

    /** Withdraws a request you sent that has not been answered yet. */
    function friend_cancel(int $userId, int $otherId): array
    {
        [$low, $high] = friend_pair($userId, $otherId);

        $done = $userId === $otherId ? null : db_run(
            'DELETE FROM friendships
              WHERE user_low_id = ? AND user_high_id = ? AND status = ? AND requested_by = ?',
            [$low, $high, 'pending', $userId]
        );

        if ($done === null || $done->rowCount() === 0) {
            return ['ok' => false, 'code' => 'gone', 'error' => 'Er staat geen verzoek van jou open.'];
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'relation' => 'none'];
    }

    /* ==================================================================
       LISTS — public fields only: a friend list never carries health data
       ================================================================== */

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

    /** Your friends: id, username, avatar_path (the small copy) — alphabetical. */
    function friend_list(int $userId): array
    {
        $ids = friend_ids($userId);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return avatar_small_rows(db_all(
            'SELECT u.id, u.username, p.avatar_path
               FROM users u
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE u.id IN (' . $placeholders . ') AND u.status = ?
           ORDER BY u.username',
            [...$ids, 'active']
        ));
    }

    /** Requests waiting for YOUR answer, newest first. */
    function friend_pending_for(int $userId): array
    {
        return avatar_small_rows(db_all(
            'SELECT f.id, f.requested_by, f.requested_by AS user_id, f.created_at, u.username, p.avatar_path
               FROM friendships f
               JOIN users u ON u.id = f.requested_by AND u.status = ?
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE f.status = ? AND f.requested_by <> ?
                AND (f.user_low_id = ? OR f.user_high_id = ?)
           ORDER BY f.created_at DESC',
            ['active', 'pending', $userId, $userId, $userId]
        ));
    }

    /** Requests you sent that have not been answered yet, newest first. */
    function friend_sent_by(int $userId): array
    {
        return avatar_small_rows(db_all(
            'SELECT f.id, u.id AS user_id, f.created_at, u.username, p.avatar_path
               FROM friendships f
               JOIN users u ON u.id = CASE WHEN f.user_low_id = ? THEN f.user_high_id ELSE f.user_low_id END
                           AND u.status = ?
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE f.status = ? AND f.requested_by = ?
           ORDER BY f.created_at DESC',
            [$userId, 'active', 'pending', $userId]
        ));
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

        /* A block ends whatever is between the two — a friendship, or a
           request either way — rather than leaving it dangling. */
        [$low, $high] = friend_pair($blockerId, $blockedId);
        db_run('DELETE FROM friendships WHERE user_low_id = ? AND user_high_id = ?', [$low, $high]);

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
