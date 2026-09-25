<?php
/**
 * Scores — PRIVATE, scoped to the owner like the rest of the health data.
 *
 * ---------------------------------------------------------------------------
 * ONE PLACE, ON PURPOSE
 * ---------------------------------------------------------------------------
 * The three category scores are calculated in includes/health-score.php, over
 * a rolling 90-day window, from the numbers in config/scoring.php. This file
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
     * are missing — now, or as of the end of an earlier day.
     *
     * @return array<string,int|null>
     */
    function score_domains(int $userId, ?string $date = null): array
    {
        $scores = health_score_now($userId, score_as_of($date));

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

    /** Today, or not given: now. An earlier day: the end of that day. */
    function score_as_of(?string $date): DateTimeImmutable
    {
        $now = new DateTimeImmutable('now');

        if ($date === null || $date === '' || $date >= $now->format('Y-m-d')) {
            return $now;
        }

        try {
            return (new DateTimeImmutable($date))->setTime(23, 59, 59);
        } catch (Exception $e) {
            return $now;
        }
    }
}
