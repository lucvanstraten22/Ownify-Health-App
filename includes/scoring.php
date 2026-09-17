<?php
/**
 * Scores — PRIVATE, scoped to the owner like the rest of the health data.
 *
 * ---------------------------------------------------------------------------
 * ONE PLACE, ON PURPOSE
 * ---------------------------------------------------------------------------
 * Every score in the application is produced here. The dashboard ring, the
 * three legend figures, the Gezondheid area cards and the trend chart all call
 * into this file, so changing how a score is arrived at — weighting the three
 * pillars instead of averaging them, say — is a change to one function and not
 * a hunt through the views.
 *
 * ---------------------------------------------------------------------------
 * MISSING IS NOT ZERO
 * ---------------------------------------------------------------------------
 * This is the rule the whole file is built around. A user who has recorded
 * nothing has NO score; a user who genuinely scored zero has a score of 0.
 * They are different facts and they must never collapse into each other, so
 * every function here returns `null` for "no data" and an int for "a score",
 * and the combining step skips nulls rather than counting them.
 *
 *     sleep 81, nutrition 93, training missing  ->  (81 + 93) / 2 = 87
 *     sleep 81, nutrition missing, training missing  ->  81
 *     all three missing  ->  null, and the card keeps its empty state
 *
 * Averaging a two-pillar total over three would read a missing pillar as a
 * zero and understate the day by a third, which is the specific bug this file
 * exists to make impossible.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';

/** The three pillars the overall score is built from, in display order. */
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
     * The overall score for one day: the average of whichever of the three
     * pillars that day actually has.
     */
    function score_overall(int $userId, string $date): ?int
    {
        return score_combine(score_domains($userId, $date));
    }

    /**
     * All three pillar scores for a day, nulls included so a caller can tell
     * which are missing.
     *
     * @return array<string,int|null>
     */
    function score_domains(int $userId, string $date): array
    {
        $scores = [];

        foreach (SCORE_DOMAINS as $domain) {
            $scores[$domain] = score_domain($userId, $date, $domain);
        }

        return $scores;
    }

    /**
     * One pillar's score for one day.
     *
     * A stored score wins: daily_scores is where an import or a future scoring
     * engine writes its results, and a recorded score is a fact rather than an
     * inference. Only when nothing is stored does this derive one from the
     * day's own readings, and only when there are readings to derive it from.
     */
    function score_domain(int $userId, string $date, string $domain): ?int
    {
        if (!in_array($domain, SCORE_DOMAINS, true)) {
            return null;
        }

        $stored = db_value(
            'SELECT score FROM daily_scores WHERE user_id = ? AND score_date = ? AND domain = ?',
            [$userId, $date, $domain]
        );

        if ($stored !== null) {
            return (int) $stored;
        }

        return match ($domain) {
            'sleep'     => score_derive_sleep($userId, $date),
            'nutrition' => score_derive_nutrition($userId, $date),
            'training'  => score_derive_training($userId, $date),
        };
    }

    /**
     * Writes a pillar score for a day.
     *
     * `null` is storable and means "we looked and there is not enough to
     * score", which is different again from having no row at all.
     */
    function score_record(int $userId, string $date, string $domain, ?int $score, string $version = 'v1'): bool
    {
        if (!in_array($domain, SCORE_DOMAINS, true)) {
            return false;
        }

        if ($score !== null) {
            $score = max(0, min(100, $score));
        }

        db_run(
            'INSERT INTO daily_scores (user_id, score_date, domain, score, algorithm_version)
                  VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score),
                                     algorithm_version = VALUES(algorithm_version),
                                     computed_at = NOW()',
            [$userId, $date, $domain, $score, $version]
        );

        return true;
    }

    /* ======================================================================
       DERIVATION — v1
       ----------------------------------------------------------------------
       Deliberately simple and deliberately in one place. These turn readings
       the user actually recorded into a 0-100 figure; they invent nothing,
       and each returns null the moment the reading it needs is absent.

       The targets below are the only judgement in this file. They are
       constants so they can be argued with, and so that replacing them with
       per-user targets later changes these three functions and nothing else.
       ====================================================================== */

    /** Sleep is scored against eight hours, tempered by how much of it stuck. */
    function score_derive_sleep(int $userId, string $date): ?int
    {
        $target = 480.0;    // minutes

        $minutes = db_value(
            'SELECT SUM(duration_minutes) FROM sleep_sessions WHERE user_id = ? AND night_of = ?',
            [$userId, $date]
        );

        if ($minutes === null) {
            $minutes = health_daily_metric($userId, 'sleep_duration', $date);
        }

        if ($minutes === null) {
            return null;    // no night recorded
        }

        // Full marks at the target; over-sleeping is not rewarded past it.
        $duration = min(1.0, (float) $minutes / $target);

        $efficiency = db_value(
            'SELECT AVG(efficiency_pct) FROM sleep_sessions WHERE user_id = ? AND night_of = ?',
            [$userId, $date]
        );

        if ($efficiency === null) {
            return (int) round($duration * 100);
        }

        // Duration carries most of it; efficiency adjusts rather than decides.
        return (int) round(($duration * 0.7 + min(1.0, (float) $efficiency / 100) * 0.3) * 100);
    }

    /**
     * Nutrition is the user's own 1-10 rating for the day.
     *
     * This is the low-friction input the product is built around: a person
     * knows whether they ate well, and asking them is both cheaper and more
     * honest than inferring it from a partial food log. Several entries in a
     * day average out.
     */
    function score_derive_nutrition(int $userId, string $date): ?int
    {
        $rating = db_value(
            'SELECT AVG(hm.value)
               FROM health_metrics hm
               JOIN health_metric_types t ON t.id = hm.metric_type_id
              WHERE hm.user_id = ? AND hm.recorded_on = ? AND t.code = ?',
            [$userId, $date, 'nutrition_rating']
        );

        if ($rating === null) {
            return null;
        }

        // 1-10 onto 0-100: a 1 is a bad day, not an absent one, so it scores
        // above zero. 10 -> 100, 1 -> 10.
        return (int) round(max(0.0, min(10.0, (float) $rating)) * 10);
    }

    /** Training is scored on active minutes, with steps as the fallback. */
    function score_derive_training(int $userId, string $date): ?int
    {
        $activeTarget = 60.0;       // minutes
        $stepTarget   = 10000.0;

        $minutes = db_value(
            'SELECT SUM(duration_seconds) / 60 FROM workouts
              WHERE user_id = ? AND DATE(started_at) = ?',
            [$userId, $date]
        );

        if ($minutes === null) {
            $minutes = health_daily_metric($userId, 'active_minutes', $date);
        }

        if ($minutes !== null) {
            return (int) round(min(1.0, (float) $minutes / $activeTarget) * 100);
        }

        $steps = health_daily_metric($userId, 'steps', $date);

        if ($steps !== null) {
            return (int) round(min(1.0, (float) $steps / $stepTarget) * 100);
        }

        return null;    // nothing moved, or nothing was recorded
    }

    /* ---------------------------------------------------------- history */

    /**
     * A pillar's score across a range, for the trend chart.
     * Days with nothing recorded are absent rather than zero.
     *
     * @return array<string,int> day => score
     */
    function score_series(int $userId, string $domain, string $from, string $to): array
    {
        $series = [];

        $cursor = new DateTimeImmutable($from);
        $end    = new DateTimeImmutable($to);

        while ($cursor <= $end) {
            $day   = $cursor->format('Y-m-d');
            $score = $domain === 'overall'
                ? score_overall($userId, $day)
                : score_domain($userId, $day, $domain);

            if ($score !== null) {
                $series[$day] = $score;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }
}
