<?php
/**
 * Scores — PRIVATE, scoped to the owner like the rest of the health data.
 *
 * ---------------------------------------------------------------------------
 * ONE PLACE, ON PURPOSE
 * ---------------------------------------------------------------------------
 * The three category scores are calculated in includes/health-score.php, over
 * the last 168 hours, from the numbers in config/scoring.php. This file
 * keeps the one rule for combining them, and the few functions the endpoints
 * call to ask "what are my scores now?".
 *
 * ---------------------------------------------------------------------------
 * MISSING IS NOT ZERO
 * ---------------------------------------------------------------------------
 * A user who has recorded nothing has NO score; a user who genuinely scored
 * zero has a score of 0. They are different facts and they must never
 * collapse into each other, so every function here returns `null` for "no
 * data" and an int for "a score", and the combining step skips nulls rather
 * than counting them.
 *
 *     sleep 82, nutrition 74, training 71       ->  (82 + 74 + 71) / 3 = 76
 *     sleep 82, nutrition 74, training missing  ->  (82 + 74) / 2 = 78
 *     sleep 82, the others missing              ->  82
 *     all three missing                         ->  null, and the card keeps its empty state
 *
 * Averaging a two-category total over three would read a missing category as
 * a zero and understate the score by a third, which is the specific bug this
 * file exists to make impossible.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-score.php';

/** The three categories the overall score is built from, in display order. */
if (!defined('SCORE_DOMAINS')) {
    define('SCORE_DOMAINS', ['sleep', 'nutrition', 'training']);
}

if (!function_exists('score_colour_band')) {

    /**
     * How high a score is, as the colour it is shown in: 'high', 'mid' or
     * 'low' — or null when there is no score (null is never a zero).
     *
     * The one place the colour thresholds are read (config/scoring.php,
     * 'colour_bands'; inclusive lower bounds, so 80 is high and 59 is low).
     * It is the same for every category: a category's own colour never
     * depends on its score, and a score's colour never on its category.
     */
    function score_colour_band(int|float|null $score): ?string
    {
        if ($score === null) {
            return null;
        }

        $bands = (array) (health_scoring_config()['colour_bands'] ?? []);
        krsort($bands, SORT_NUMERIC);

        foreach ($bands as $from => $band) {
            if ($score >= $from) {
                return (string) $band;
            }
        }

        return $bands === [] ? null : (string) end($bands);   // below the lowest bound
    }
}

if (!function_exists('score_combine')) {

    /**
     * THE central calculation.
     *
     * Averages the scores that exist and ignores the ones that do not. Pass
     * `weights` keyed the same way to move off an equal average without
     * touching a caller.
     *
     * @param array<string,int|null> $scores  domain => score, nulls allowed
     * @param array<string,float>    $weights domain => weight, defaults to 1
     */
    function score_combine(array $scores, array $weights = []): ?int
    {
        $weighted = 0.0;
        $divisor  = 0.0;

        foreach ($scores as $domain => $score) {
            // Only null means missing. 0 is a score and must be counted.
            if ($score === null) {
                continue;
            }

            $weight = (float) ($weights[$domain] ?? 1.0);
            if ($weight <= 0.0) {
                continue;
            }

            $weighted += (float) $score * $weight;
            $divisor  += $weight;
        }

        if ($divisor <= 0.0) {
            return null;        // nothing recorded: no score, not a zero
        }

        return (int) round($weighted / $divisor);
    }

    /**
     * The three category scores, nulls included so a caller can tell which
     * are missing — now, or as recorded on an earlier day (never calculated
     * again from today's records: health_score_history()).
     *
     * @return array<string,int|null>
     */
    function score_domains(int $userId, ?string $date = null): array
    {
        $today = date('Y-m-d');

        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date < $today) {
            $days    = health_score_day_gap($date, $today) + 1;
            $history = $days <= 366 ? health_score_history($userId, $days) : [];
            $scores  = $history[$date] ?? health_score_blank_day($date, 'none');
        } else {
            $scores = health_score_now($userId);
        }

        return [
            'sleep'     => $scores['sleep']['score'],
            'nutrition' => $scores['nutrition']['score'],
            'training'  => $scores['training']['score'],
        ];
    }

    /** The overall score: the average of the categories that have a score. */
    function score_overall(int $userId, ?string $date = null): ?int
    {
        return score_combine(score_domains($userId, $date));
    }

}
