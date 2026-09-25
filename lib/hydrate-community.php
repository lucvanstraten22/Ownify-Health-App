<?php
/**
 * Fills the Community boards from real accounts and real points.
 *
 * The demo roster is gone. Every name on a board from here on is an account
 * that exists, and the only fields read about another person are the public
 * ones — handle and picture. Points and position are public by design; nothing
 * private is joined in, and there is no query here that could return one
 * user's health data to another.
 *
 * Points are awarded by includes/points.php, for what people actually did —
 * never for their Health Score — and the rollups these boards read are rebuilt
 * the moment an award changes. A period nobody has earned points in renders
 * the existing empty state: an empty board, never an invented one.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/leaderboard.php';
require_once dirname(__DIR__) . '/includes/friends.php';

if (!function_exists('hydrate_community')) {

    function hydrate_community(array $community, ?int $userId): array
    {
        /* Whatever happens below, the generated roster is not coming back. */
        $community['demo']   = false;
        $community['boards'] = [];

        foreach ($community['scopes'] as $scope => $scopeConfig) {
            foreach ($community['periods'] as $period => $periodConfig) {
                $community['boards'][$scope][$period] = ($userId === null || !db_available())
                    ? ['entries' => [], 'you' => ['rank' => null, 'points' => null]]
                    : hydrate_community_board($userId, $scope, $period, (int) $scopeConfig['limit']);
            }
        }

        /* What the account panel's Vrienden page shows: friends, requests
           both ways, and the account's own "Vriendverzoeken toestaan". */
        if ($userId !== null && db_available()) {
            $community['friends']        = friend_list($userId);
            $community['pending']        = friend_pending_for($userId);
            $community['sent']           = friend_sent_by($userId);
            $community['allow_requests'] = friend_requests_allowed($userId);
            $community['best']           = [
                'national' => leaderboard_best_position($userId, 'national', 'alltime'),
                'friends'  => leaderboard_best_position($userId, 'friends', 'alltime'),
            ];
        } else {
            $community['friends']        = [];
            $community['pending']        = [];
            $community['sent']           = [];
            $community['allow_requests'] = true;
            $community['best']           = ['national' => null, 'friends' => null];
        }

        return $community;
    }

    /** One scope/period board, in the shape the leaderboard component renders. */
    function hydrate_community_board(int $userId, string $scope, string $period, int $limit): array
    {
        /* Friends: the whole group, so the user's own place is known even
           when fifty friends are ahead of them. */
        $group = $scope === 'friends' ? leaderboard_friends_group($userId, $period) : null;
        $rows  = $group !== null
            ? array_slice($group, 0, max(1, min($limit, LEADERBOARD_LIMIT)))
            : leaderboard_national($period, null, $limit);

        $entries = [];
        $you     = ['rank' => null, 'points' => null];

        foreach ($rows as $row) {
            $isYou = (int) $row['user_id'] === $userId;

            $entry = [
                'rank'   => (int) $row['position'],
                'name'   => (string) $row['username'],
                'points' => (int) $row['points'],
                'self'   => $isYou,
                'avatar' => $row['avatar_path'] ?? null,
            ];

            $entries[] = $entry;

            if ($isYou) {
                $you = ['rank' => $entry['rank'], 'points' => $entry['points']];
            }
        }

        if ($you['rank'] === null && $group !== null) {
            foreach ($group as $row) {
                if ((int) $row['user_id'] === $userId) {
                    $you = ['rank' => (int) $row['position'], 'points' => (int) $row['points']];
                }
            }
        }

        /* Outside the top 50 nationally, the user still gets their own line —
           that is what the board's floating position row is for. */
        if ($you['rank'] === null && $scope === 'netherlands') {
            $position = leaderboard_position($userId, $period);

            if ($position !== null) {
                $you = ['rank' => $position['position'], 'points' => $position['points']];
            }
        }

        return ['entries' => $entries, 'you' => $you];
    }
}
