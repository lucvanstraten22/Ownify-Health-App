<?php
/**
 * Points and leaderboards.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS STORED AND WHAT IS DERIVED
 * ---------------------------------------------------------------------------
 *   point_events        the ledger — the only source of truth
 *   user_period_points  points per period, plus the NATIONAL position
 *   friends ranking     never stored: it depends on who is asking, so it is
 *                       derived by joining the rollup to that user's friends
 *
 * The rollup is a cache: leaderboard_rebuild() reconstructs it from the ledger
 * at any time, so losing it costs nothing.
 *
 * No rule about what earns points lives here: that is includes/points.php,
 * with its values in config/points.php. This file keeps the ledger's rollups
 * and reads the boards.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/friends.php';

/** A board never shows more than this, whatever the scope. */
const LEADERBOARD_LIMIT = 50;

if (!function_exists('leaderboard_period_key')) {

    /** 'month' => '2026-04', 'year' => '2026', 'alltime' => 'all'. */
    function leaderboard_period_key(string $periodType, ?DateTimeInterface $when = null): string
    {
        $when ??= new DateTimeImmutable('now');

        return match ($periodType) {
            'month'   => $when->format('Y-m'),
            'year'    => $when->format('Y'),
            default   => 'all',
        };
    }

    /* ----------------------------------------------------------- rollups */

    /**
     * Rebuilds one period's totals from the ledger, then re-ranks it.
     *
     * Called whenever an award in the period changes (includes/points.php),
     * so a point earned now is on the board now. The period is a range of
     * days, which the index on awarded_on serves. Somebody whose points in the
     * period went back to nothing is taken off its board.
     */
    function leaderboard_rebuild(string $periodType, ?string $periodKey = null): int
    {
        $periodKey ??= leaderboard_period_key($periodType);

        [$filter, $params] = match ($periodType) {
            'month' => ['awarded_on BETWEEN ? AND LAST_DAY(?)', [$periodKey . '-01', $periodKey . '-01']],
            'year'  => ['awarded_on BETWEEN ? AND ?', [$periodKey . '-01-01', $periodKey . '-12-31']],
            default => ['1 = 1', []],
        };

        $totals = db_all(
            'SELECT user_id, SUM(points) AS points
               FROM point_events
              WHERE ' . $filter . '
           GROUP BY user_id
             HAVING SUM(points) > 0
           ORDER BY points DESC, user_id',
            $params
        );

        $position = 0;

        foreach ($totals as $row) {
            $position++;

            db_run(
                'INSERT INTO user_period_points (user_id, period_type, period_key, points, position)
                      VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE points = VALUES(points),
                                         position = VALUES(position),
                                         computed_at = NOW()',
                [(int) $row['user_id'], $periodType, $periodKey, (int) $row['points'], $position]
            );

            leaderboard_note_best_position((int) $row['user_id'], 'national', $periodType, $position, $periodKey);
        }

        /* Off the board: whoever no longer has points in the period. */
        db_run(
            'DELETE FROM user_period_points
              WHERE period_type = ? AND period_key = ?
                AND user_id NOT IN (SELECT user_id
                                      FROM point_events
                                     WHERE ' . $filter . '
                                  GROUP BY user_id
                                    HAVING SUM(points) > 0)',
            [$periodType, $periodKey, ...$params]
        );

        return $position;
    }

    /* ------------------------------------------------------------ boards */

    /**
     * Whether user_profiles has "Profielfoto op de ranglijst" (migration 014).
     * Until it does, every picture shows on the boards and the switch cannot
     * be saved.
     */
    function leaderboard_avatar_setting_stored(): bool
    {
        static $stored = null;

        return $stored ??= db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
                AND column_name = 'leaderboard_avatar'"
        ) > 0;
    }

    /**
     * The picture column of a board query (`p` is the person's user_profiles
     * row): their picture, but only when they show it on the boards. Decided
     * in the query itself, so the path of a picture somebody keeps off the
     * boards never leaves the database — for anyone, themselves included.
     */
    function leaderboard_avatar_select(): string
    {
        return leaderboard_avatar_setting_stored()
            ? 'CASE WHEN p.leaderboard_avatar = 1 THEN p.avatar_path END AS avatar_path'
            : 'p.avatar_path';
    }

    /** This account's own picture as the boards show it: its path, or null when it has none or keeps it off. */
    function leaderboard_avatar_of(int $userId): ?string
    {
        $path = db_value('SELECT ' . leaderboard_avatar_select() . ' FROM user_profiles p WHERE p.user_id = ?', [$userId]);

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** Whether this account's picture shows on the boards. On unless switched off. */
    function leaderboard_avatar_shown(int $userId): bool
    {
        if (!leaderboard_avatar_setting_stored()) {
            return true;
        }

        $value = db_value('SELECT leaderboard_avatar FROM user_profiles WHERE user_id = ?', [$userId]);

        return $value === null || (int) $value === 1;
    }

    /** The account's own switch: its picture on the boards, or its initial. */
    function leaderboard_set_avatar_shown(int $userId, bool $shown): array
    {
        if (!leaderboard_avatar_setting_stored()) {
            error_log('[ownify] leaderboard: user_profiles has no leaderboard_avatar yet, so the switch cannot be saved — '
                . 'import database/migrations/014-leaderboard-avatar-setting.sql');

            return ['ok' => false, 'error' => 'Deze instelling kan nog niet worden opgeslagen.'];
        }

        db_run(
            'INSERT INTO user_profiles (user_id, leaderboard_avatar) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE leaderboard_avatar = VALUES(leaderboard_avatar)',
            [$userId, $shown ? 1 : 0]
        );

        return ['ok' => true, 'error' => null, 'shown' => $shown];
    }

    /** Top 50 of the Netherlands for a period. Public fields only. */
    function leaderboard_national(string $periodType, ?string $periodKey = null, int $limit = LEADERBOARD_LIMIT): array
    {
        $periodKey ??= leaderboard_period_key($periodType);
        $limit = max(1, min($limit, LEADERBOARD_LIMIT));

        return db_all(
            'SELECT pp.position, pp.points, u.id AS user_id, u.username, ' . leaderboard_avatar_select() . '
               FROM user_period_points pp
               JOIN users u ON u.id = pp.user_id AND u.status = ?
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE pp.period_type = ? AND pp.period_key = ? AND pp.position IS NOT NULL
           ORDER BY pp.position
              LIMIT ' . (int) $limit,
            ['active', $periodType, $periodKey]
        );
    }

    /**
     * Top 50 of the asking user's friends, plus the user. Ranked within the
     * group, so position 1 here means "first among your friends".
     */
    function leaderboard_friends(int $userId, string $periodType, ?string $periodKey = null, int $limit = LEADERBOARD_LIMIT): array
    {
        $limit = max(1, min($limit, LEADERBOARD_LIMIT));

        return array_slice(leaderboard_friends_group($userId, $periodType, $periodKey), 0, $limit);
    }

    /**
     * The whole group — the user and every friend — ranked on the period's
     * points, the same order the board has always used.
     *
     * Once somebody has friends, everyone in the group is on it: a friend
     * who has not earned points in the period yet is there with 0, so a
     * request accepted a moment ago puts them on the board at once, and the
     * user is always on their own board. Without friends it is the user
     * alone, once they have points — as it always was. The group is at most
     * FRIEND_LIMIT + 1 people, so it is read whole and cut to the board's
     * size by leaderboard_friends().
     */
    function leaderboard_friends_group(int $userId, string $periodType, ?string $periodKey = null): array
    {
        $periodKey ??= leaderboard_period_key($periodType);

        $friends = friend_ids($userId);
        $ids = [...$friends, $userId];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = $friends === []
            ? db_all(
                'SELECT pp.points, u.id AS user_id, u.username, ' . leaderboard_avatar_select() . '
                   FROM user_period_points pp
                   JOIN users u ON u.id = pp.user_id AND u.status = ?
              LEFT JOIN user_profiles p ON p.user_id = u.id
                  WHERE pp.period_type = ? AND pp.period_key = ?
                    AND pp.user_id IN (' . $placeholders . ')
               ORDER BY pp.points DESC, u.username',
                ['active', $periodType, $periodKey, ...$ids]
            )
            : db_all(
                'SELECT COALESCE(pp.points, 0) AS points, u.id AS user_id, u.username, ' . leaderboard_avatar_select() . '
                   FROM users u
              LEFT JOIN user_period_points pp
                     ON pp.user_id = u.id AND pp.period_type = ? AND pp.period_key = ?
              LEFT JOIN user_profiles p ON p.user_id = u.id
                  WHERE u.status = ? AND u.id IN (' . $placeholders . ')
               ORDER BY points DESC, u.username',
                [$periodType, $periodKey, 'active', ...$ids]
            );

        $position = 0;
        foreach ($rows as $index => $row) {
            $rows[$index]['position'] = ++$position;
        }

        return $rows;
    }

    /** The user's own national position, whether or not it is in the top 50. */
    function leaderboard_position(int $userId, string $periodType, ?string $periodKey = null): ?array
    {
        $periodKey ??= leaderboard_period_key($periodType);

        $row = db_one(
            'SELECT position, points FROM user_period_points
              WHERE user_id = ? AND period_type = ? AND period_key = ?',
            [$userId, $periodType, $periodKey]
        );

        if ($row === null) {
            return null;
        }

        return [
            'position' => $row['position'] === null ? null : (int) $row['position'],
            'points'   => (int) $row['points'],
        ];
    }

    /* ---------------------------------------------------- best ever rank */

    /** Best is the LOWEST number ever reached, and it never gets worse. */
    function leaderboard_note_best_position(
        int $userId,
        string $scope,
        string $periodType,
        int $position,
        string $periodKey
    ): void {
        db_run(
            'INSERT INTO leaderboard_best_positions
                    (user_id, scope, period_type, best_position, achieved_period_key)
                  VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                    achieved_period_key = IF(VALUES(best_position) < best_position, VALUES(achieved_period_key), achieved_period_key),
                    achieved_at         = IF(VALUES(best_position) < best_position, NOW(), achieved_at),
                    best_position       = LEAST(best_position, VALUES(best_position))',
            [$userId, $scope, $periodType, $position, $periodKey]
        );
    }

    function leaderboard_best_position(int $userId, string $scope = 'national', string $periodType = 'alltime'): ?array
    {
        $row = db_one(
            'SELECT best_position, achieved_period_key, achieved_at
               FROM leaderboard_best_positions
              WHERE user_id = ? AND scope = ? AND period_type = ?',
            [$userId, $scope, $periodType]
        );

        if ($row === null) {
            return null;
        }

        $row['best_position'] = (int) $row['best_position'];

        return $row;
    }
}
