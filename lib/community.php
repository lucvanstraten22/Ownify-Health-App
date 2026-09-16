<?php
/**
 * Community helpers — board assembly, formatting and the demo roster.
 *
 * The leaderboard never computes anything: it renders a list of entries, each
 * of which carries rank, name, points and whether it is you. Where those
 * entries come from — a scoring engine later, the demo generator now — is of
 * no concern to the UI.
 */

declare(strict_types=1);

if (!function_exists('community_prepare')) {
    /**
     * Builds every scope/period board.
     *
     * With demo off — the shipped state — every board comes back empty and the
     * page renders its empty states. Nothing is invented.
     */
    function community_prepare(array $community): array
    {
        $demo = !empty($community['demo']);
        $community['boards'] = [];

        foreach ($community['scopes'] as $scope => $scopeConfig) {
            foreach ($community['periods'] as $period => $periodConfig) {
                $community['boards'][$scope][$period] = $demo
                    ? community_demo_board($community, $scope, $period)
                    : ['entries' => [], 'you' => ['rank' => null, 'points' => null]];
            }
        }

        return $community;
    }
}

if (!function_exists('community_demo_board')) {
    /**
     * A deterministic placeholder board: same input, same output on every
     * render. Names come from a fixed pool and belong to nobody.
     */
    function community_demo_board(array $community, string $scope, string $period): array
    {
        $settings = $community['demo_settings'];
        $names    = $settings['names'];
        $limit    = $community['scopes'][$scope]['limit'];
        $yourRank = $settings['you'][$scope][$period];
        $top      = $settings['top'][$scope][$period];

        /* A small seeded generator keeps the spread varied but stable. */
        $seed = crc32($scope . '|' . $period);
        $next = static function () use (&$seed): float {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
            return $seed / 0x7FFFFFFF;
        };

        $entries = [];
        $points  = $top;

        for ($rank = 1; $rank <= $limit; $rank++) {
            $isYou = $rank === $yourRank;

            $entries[] = [
                'rank'   => $rank,
                'name'   => $isYou ? $community['you']['name'] : $names[($rank - 1) % count($names)],
                'points' => (int) round($points / 10) * 10,
                'self'   => $isYou,
            ];

            /* Gaps widen a little further down the board. */
            $points -= ($top * 0.004) + $next() * ($top * 0.012);
            $points = max($points, $top * 0.08);
        }

        $you = $yourRank <= $limit
            ? ['rank' => $yourRank, 'points' => $entries[$yourRank - 1]['points']]
            : ['rank' => $yourRank, 'points' => (int) round(($top * 0.14) / 10) * 10];

        return ['entries' => $entries, 'you' => $you];
    }
}

if (!function_exists('community_points')) {
    /** Points as the Netherlands writes them, or an em dash while empty. */
    function community_points(mixed $points): string
    {
        if (!has_value($points) || !is_numeric($points)) {
            return '—';
        }

        return number_format((float) $points, 0, ',', '.');
    }
}

if (!function_exists('community_rank')) {
    /** Rank as text, or an em dash while there is no ranking yet. */
    function community_rank(mixed $rank): string
    {
        return has_value($rank) ? (string) $rank : '—';
    }
}

if (!function_exists('community_initial')) {
    /** The monogram used in place of a profile picture. */
    function community_initial(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($name, 0, 1));
    }
}

if (!function_exists('community_avatar_accent')) {
    /** A stable accent per person, drawn from the app's own three. */
    function community_avatar_accent(?string $name): string
    {
        $accents = ['health', 'nutrition', 'activity'];

        return $accents[crc32((string) $name) % 3];
    }
}
