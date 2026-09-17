<?php
/**
 * Community helpers — formatting for the leaderboard.
 *
 * The leaderboard never computes anything: it renders a list of entries, each
 * of which carries rank, name, points and whether it is you. Assembling those
 * entries from real accounts is lib/hydrate-community.php's job.
 */

declare(strict_types=1);

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
