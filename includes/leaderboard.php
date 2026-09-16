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
 * No scoring rule lives here. points_award() takes a number someone else
 * decided; the formula belongs in a scoring engine that inserts point_rules
 * rows and calls this — see docs/DATABASE.md.
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

    /* ------------------------------------------------------------ points */

    /**
     * Records an award. `$points` is decided by the caller — this function
     * holds no rule about what earns what.
     */
    function points_award(
        int $userId,
        int $points,
        ?string $ruleCode = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $awardedAt = null
    ): ?int {
        $ruleId = null;

        if ($ruleCode !== null) {
            $found = db_value('SELECT id FROM point_rules WHERE code = ?', [$ruleCode]);
            $ruleId = $found === null ? null : (int) $found;
        }

        db_run(
            'INSERT INTO point_events (user_id, rule_id, points, awarded_at, reference_type, reference_id)
                  VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $ruleId, $points, $awardedAt ?? date('Y-m-d H:i:s'), $referenceType, $referenceId]
        );

        return db_insert_id();
    }

    /* ----------------------------------------------------------- rollups */

    /** Rebuilds one period's totals from the ledger, then re-ranks it. */
    function leaderboard_rebuild(string $periodType, ?string $periodKey = null): int
    {
        $periodKey ??= leaderboard_period_key($periodType);

        $filter = match ($periodType) {
            'month' => 'DATE_FORMAT(awarded_on, "%Y-%m") = ?',
            'year'  => 'DATE_FORMAT(awarded_on, "%Y") = ?',
            default => '1 = 1',
        };

        $params = $periodType === 'alltime' ? [] : [$periodKey];

        $totals = db_all(
            'SELECT user_id, SUM(points) AS points
               FROM point_events
              WHERE ' . $filter . '
           GROUP BY user_id
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

        return $position;
    }

    /* ------------------------------------------------------------ boards */

    /** Top 50 of the Netherlands for a period. Public fields only. */
    function leaderboard_national(string $periodType, ?string $periodKey = null, int $limit = LEADERBOARD_LIMIT): array
    {
        $periodKey ??= leaderboard_period_key($periodType);
        $limit = max(1, min($limit, LEADERBOARD_LIMIT));

        return db_all(
            'SELECT pp.position, pp.points, u.id AS user_id, u.username, p.avatar_path
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
        $periodKey ??= leaderboard_period_key($periodType);
        $limit = max(1, min($limit, LEADERBOARD_LIMIT));

        $ids = friend_ids($userId);
        $ids[] = $userId;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = db_all(
            'SELECT pp.points, u.id AS user_id, u.username, p.avatar_path
               FROM user_period_points pp
               JOIN users u ON u.id = pp.user_id AND u.status = ?
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE pp.period_type = ? AND pp.period_key = ?
                AND pp.user_id IN (' . $placeholders . ')
           ORDER BY pp.points DESC, u.username
              LIMIT ' . (int) $limit,
            ['active', $periodType, $periodKey, ...$ids]
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
