<?php
/**
 * The Health Score — PRIVATE, scoped to its owner like every health record.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT MEASURES
 * ---------------------------------------------------------------------------
 * How healthy somebody's recent pattern is, per category — sleep, nutrition,
 * training — each 0-100, over a rolling window: the moment of calculation
 * minus 168 hours. Not this calendar week: the window moves with the clock,
 * so an hour from now it has an hour more at the front and an hour less at
 * the back. The overall score is the average of the categories that have
 * one. This is the only Health Score; the longer periods the Scorekompas
 * shows are its history — one stored score a day — never other scores.
 *
 * Every number that decides a score is in config/scoring.php.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES NOT DO
 * ---------------------------------------------------------------------------
 * Award points. The leaderboard (includes/points.php) pays for actions; this
 * measures a pattern, and nothing here is read by anything that pays out.
 *
 * ---------------------------------------------------------------------------
 * MISSING IS NOT ZERO
 * ---------------------------------------------------------------------------
 *   - fewer than `min_days` (3) distinct days with data in the window: no
 *     score, and the number of days so far, so the page can say how many are
 *     still needed;
 *   - days without data are not days of zero: 5 nights in 7 days are
 *     averaged over 5;
 *   - no new input for `expiry_days` days in a row (Slaap, Voeding): the
 *     category stops counting — `expired`, left out of the overall score
 *     rather than counted as zero, until new data comes in;
 *   - a component nobody's device measures is left out and the rest weigh
 *     more, rather than counting as a zero;
 *   - no category with a score: no overall score, never a 0.
 *
 * ---------------------------------------------------------------------------
 * ALWAYS CURRENT
 * ---------------------------------------------------------------------------
 * Scores are calculated from the records whenever they are read, so a new
 * night, rating or workout is in the next score without anybody pressing
 * anything. Each calculation is also written to daily_scores (with the days
 * and components it used, and until when it holds without new input) as
 * the record of that day's result — today's row only, so a day that has
 * passed keeps the score it had.
 *
 * ---------------------------------------------------------------------------
 * HISTORY IS WHAT WAS STORED, NEVER RECALCULATED
 * ---------------------------------------------------------------------------
 * health_score_history() reads those daily rows back. A day without a row of
 * its own keeps the last stored score only while that score still holds
 * (`valid_until`); after that it has none. Old days are never scored again
 * from today's records.
 */

declare(strict_types=1);

require_once __DIR__ . '/health-signals.php';
require_once __DIR__ . '/scoring.php';

if (!defined('HEALTH_SCORE_VERSION')) {
    define('HEALTH_SCORE_VERSION', 'rolling168-v1');
}

if (!function_exists('health_score_now')) {

    /**
     * All three categories and the overall score, as of $asOf (default: now).
     *
     * @return array{
     *   sleep: array{score: ?int, days: int, components: array},
     *   nutrition: array{score: ?int, days: int, components: array},
     *   training: array{score: ?int, days: int, components: array},
     *   overall: array{score: ?int},
     *   as_of: string, window_start: string
     * }
     */
    function health_score_now(int $userId, ?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable('now');

        return health_score_at(health_score_data($userId, health_score_window_start($asOf), $asOf), $asOf);
    }

    /**
     * Just the numbers: the three categories and the overall score, null where
     * there is none. What an endpoint answers with.
     *
     * @return array{sleep: ?int, nutrition: ?int, training: ?int, overall: ?int}
     */
    function health_score_summary(array $scores): array
    {
        return [
            'sleep'     => $scores['sleep']['score'],
            'nutrition' => $scores['nutrition']['score'],
            'training'  => $scores['training']['score'],
            'overall'   => $scores['overall']['score'],
        ];
    }

    /** The start of the window that ends at $asOf: exactly `window_hours` earlier, by the clock. */
    function health_score_window_start(DateTimeImmutable $asOf): DateTimeImmutable
    {
        return $asOf->setTimestamp($asOf->getTimestamp() - 3600 * (int) health_scoring_config()['window_hours']);
    }

    /** The window in whole days, as people read it: 168 hours is 7 days. */
    function health_score_window_days(): int
    {
        return max(1, intdiv((int) health_scoring_config()['window_hours'], 24));
    }

    /**
     * Recalculates now and records the result. Called after anything that can
     * change a score is saved; the page does the same when it renders.
     */
    function health_score_refresh(int $userId): array
    {
        $scores = health_score_now($userId);
        health_score_store($userId, $scores);

        return $scores;
    }

    /**
     * One score per day for the last `$days` days, oldest first — the
     * categories and the overall score, null where there was none.
     *
     * @return array<string,array{sleep: ?int, nutrition: ?int, training: ?int, overall: ?int}> date => scores, oldest first
     */
    function health_score_trend(int $userId, int $days = 28, ?DateTimeImmutable $now = null): array
    {
        return array_map('health_score_summary', health_score_history($userId, $days, $now));
    }

    /**
     * The Health Score of each of the last `$days` days, as it was recorded
     * (daily_scores) — for the Scorekompas, the Gezondheid trend and Ownify
     * AI. Never calculated again from the records: a day that has passed
     * keeps the score it had, whatever came in or aged out since.
     *
     * Each day, as health_score_at() gives a result (categories with their
     * score, days and components; overall), plus:
     *
     *   state  `today`   now: the score as it stands ($today, or calculated)
     *          `stored`  the score recorded that day
     *          `carried` no score recorded that day: each category of the
     *                    last recorded day that still held then (its
     *                    `valid_until`), combined again; `from` is that day
     *          `none`    nothing recorded, and nothing that still held —
     *                    before the history began, or after it all expired
     *
     * @return array<string,array> date => day, oldest first; the last is today
     */
    function health_score_history(int $userId, int $days = 28, ?DateTimeImmutable $now = null, ?array $today = null): array
    {
        $now      ??= new DateTimeImmutable('now');
        $todayDate = $now->format('Y-m-d');
        $first     = $now->setTime(0, 0)->modify('-' . max(0, $days - 1) . ' days');
        /* A score holds at most a window's days without input, so the days
           before the first one shown are read for what they carry into it. */
        $lookback  = $first->modify('-' . health_score_window_days() . ' days');

        $stored = health_score_stored($userId, $lookback->format('Y-m-d'), $now->modify('-1 day')->format('Y-m-d'));

        $history = [];
        $source  = null;

        for ($day = $lookback; $day->format('Y-m-d') < $todayDate; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');

            if (isset($stored[$date])) {
                $source = $stored[$date];
                $entry  = $source;
            } else {
                $entry = health_score_carried($source, $date);
            }

            if ($day >= $first) {
                $history[$date] = $entry;
            }
        }

        $live = $today ?? health_score_now($userId, $now);
        $history[$todayDate] = $live + ['date' => $todayDate, 'state' => 'today', 'from' => null];

        return $history;
    }

    /**
     * The recorded days between two dates, each as a day of
     * health_score_history() with state `stored`.
     *
     * @return array<string,array> date => day
     */
    function health_score_stored(int $userId, string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }

        $rich  = health_score_store_rich();
        $until = health_score_store_until();

        $rows = db_all(
            'SELECT score_date, domain, score' . ($rich ? ', data_days, inputs' : '') . ($until ? ', valid_until' : '') . '
               FROM daily_scores
              WHERE user_id = ? AND score_date BETWEEN ? AND ?
           ORDER BY score_date',
            [$userId, $from, $to]
        );

        $days = [];

        foreach ($rows as $row) {
            $date   = (string) $row['score_date'];
            $domain = (string) $row['domain'];
            $score  = $row['score'] === null ? null : (int) $row['score'];

            $days[$date] ??= health_score_blank_day($date, 'stored');

            if ($domain === 'overall') {
                $days[$date]['overall']['score'] = $score;
                continue;
            }

            $components = $rich && $row['inputs'] !== null ? json_decode((string) $row['inputs'], true) : null;

            $days[$date][$domain] = [
                'score'       => $score,
                'days'        => $rich && $row['data_days'] !== null ? (int) $row['data_days'] : 0,
                'components'  => is_array($components) ? $components : [],
                'facts'       => [],
                'expired'     => false,
                'valid_until' => $until && $row['valid_until'] !== null ? (string) $row['valid_until'] : null,
            ];
        }

        return $days;
    }

    /**
     * A day without a recorded score: each category of the last recorded
     * day ($source) that still held on $date, and the overall score of
     * those again — or nothing.
     */
    function health_score_carried(?array $source, string $date): array
    {
        $day = health_score_blank_day($date, 'none');

        if ($source === null) {
            return $day;
        }

        $scores = [];
        foreach (SCORE_DOMAINS as $domain) {
            $category = $source[$domain] ?? null;

            if ($category !== null && $category['score'] !== null
                && $category['valid_until'] !== null && $category['valid_until'] >= $date) {
                $day[$domain] = $category;
                $scores[$domain] = $category['score'];
            }
        }

        if ($scores !== []) {
            $day['overall']['score'] = score_combine($scores);
            $day['state'] = 'carried';
            $day['from']  = $source['date'];
        }

        return $day;
    }

    function health_score_blank_day(string $date, string $state): array
    {
        $none = ['score' => null, 'days' => 0, 'components' => [], 'facts' => [], 'expired' => false, 'valid_until' => null];

        return [
            'sleep'     => $none,
            'nutrition' => $none,
            'training'  => $none,
            'overall'   => ['score' => null],
            'date'      => $date,
            'state'     => $state,
            'from'      => null,
        ];
    }

    /* ==================================================================
       READING THE RECORDS
       ================================================================== */

    /**
     * Everything the three scores read, for records between $from and $to —
     * once, so any number of moments in that range can be scored from it.
     */
    function health_score_data(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $fromSql = $from->format('Y-m-d H:i:s');
        $toSql   = $to->format('Y-m-d H:i:s');

        /* Workouts: qualifying, duplicates removed, effort read once. */
        [$workouts] = health_workouts_counted(health_workout_records($userId, $fromSql, $toSql));
        $hrMax = health_hr_max($userId, $workouts);

        foreach ($workouts as $i => $workout) {
            $workouts[$i]['effort'] = health_workout_effort($workout, $hrMax);
            $workouts[$i]['heavy']  = health_workout_heavy($workout, $workouts[$i]['effort']);
        }

        $ratings = [];
        $ratingType = health_metric_type_id('nutrition_rating');

        if ($ratingType !== null) {
            foreach (db_all(
                'SELECT value, recorded_at, recorded_on FROM health_metrics
                  WHERE user_id = ? AND metric_type_id = ? AND recorded_at > ? AND recorded_at <= ?',
                [$userId, $ratingType, $fromSql, $toSql]
            ) as $row) {
                $at = strtotime((string) $row['recorded_at']);
                if ($at !== false) {
                    $ratings[] = ['at' => $at, 'date' => (string) $row['recorded_on'], 'value' => (float) $row['value']];
                }
            }
        }

        $vo2 = [];
        $vo2Type = health_metric_type_id('vo2max');

        if ($vo2Type !== null) {
            foreach (db_all(
                'SELECT value, recorded_at FROM health_metrics
                  WHERE user_id = ? AND metric_type_id = ? AND recorded_at > ? AND recorded_at <= ?
               ORDER BY recorded_at',
                [$userId, $vo2Type, $fromSql, $toSql]
            ) as $row) {
                $at = strtotime((string) $row['recorded_at']);
                if ($at !== false && (float) $row['value'] > 0) {
                    $vo2[] = ['at' => $at, 'value' => (float) $row['value']];
                }
            }
        }

        return [
            'nights'   => health_nights($userId, $fromSql, $toSql),
            'ratings'  => $ratings,
            'workouts' => $workouts,
            'vo2'      => $vo2,
            'goals'    => health_score_goal_results($userId, $from, $to),
        ];
    }

    /**
     * Results on the person's own strength and performance goals — the one
     * place Ownify holds lifted weights, repetitions or times. Only milestone
     * goals that go up or down, oldest entry first.
     *
     * @return array<int,array{direction: string, entries: array<int,array{at:int,value:float}>}>
     */
    function health_score_goal_results(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $categories = health_scoring_config()['training']['progression']['goal_categories'];

        if ($categories === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($categories), '?'));

        $rows = db_all(
            'SELECT g.id, g.direction, p.recorded_on, p.current_value
               FROM goals g
               JOIN goal_progress p ON p.goal_id = g.id
              WHERE g.user_id = ? AND g.goal_type = ? AND g.direction IN (?, ?)
                AND g.category IN (' . $marks . ')
                AND p.current_value IS NOT NULL AND p.current_value > 0
                AND p.recorded_on > ? AND p.recorded_on <= ?
           ORDER BY g.id, p.recorded_on',
            [$userId, 'milestone', 'increase', 'decrease', ...$categories,
             $from->format('Y-m-d'), $to->format('Y-m-d')]
        );

        $goals = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $goals[$id] ??= ['direction' => (string) $row['direction'], 'entries' => []];
            $goals[$id]['entries'][] = [
                'at'    => (int) strtotime($row['recorded_on'] . ' 12:00:00'),
                'value' => (float) $row['current_value'],
            ];
        }

        return array_values($goals);
    }

    /* ==================================================================
       SCORING ONE MOMENT
       ================================================================== */

    /** The scores as of $asOf, from records loaded by health_score_data(). */
    function health_score_at(array $data, DateTimeImmutable $asOf): array
    {
        $to    = $asOf->getTimestamp();
        $start = health_score_window_start($asOf);
        $from  = $start->getTimestamp();

        $in = static fn (int $t): bool => $t > $from && $t <= $to;

        $nights   = array_values(array_filter($data['nights'], static fn ($n) => $in($n['end'])));
        $ratings  = array_values(array_filter($data['ratings'], static fn ($r) => $in($r['at'])));
        $workouts = array_values(array_filter($data['workouts'], static fn ($w) => $in($w['start'])));
        $vo2      = array_values(array_filter($data['vo2'], static fn ($v) => $in($v['at'])));

        $goals = [];
        foreach ($data['goals'] as $goal) {
            $entries = array_values(array_filter($goal['entries'], static fn ($e) => $in($e['at'])));
            if ($entries !== []) {
                $goals[] = ['direction' => $goal['direction'], 'entries' => $entries];
            }
        }

        $today     = $asOf->format('Y-m-d');
        $sleep     = health_score_validity(health_score_sleep($nights), 'sleep', array_column($nights, 'date'), $today);
        $nutrition = health_score_validity(health_score_nutrition($ratings), 'nutrition', array_column($ratings, 'date'), $today);
        $training  = health_score_validity(
            health_score_training($workouts, $nights, $vo2, $goals, $to),
            'training',
            array_map(static fn ($w) => date('Y-m-d', $w['start']), $workouts),
            $today
        );

        $until = array_filter([$sleep['valid_until'], $nutrition['valid_until'], $training['valid_until']]);

        return [
            'sleep'        => $sleep,
            'nutrition'    => $nutrition,
            'training'     => $training,
            'overall'      => [
                'score'       => score_combine([
                    'sleep'     => $sleep['score'],
                    'nutrition' => $nutrition['score'],
                    'training'  => $training['score'],
                ]),
                /* It holds as long as any category in it does. */
                'valid_until' => $until === [] ? null : max($until),
            ],
            'as_of'        => $asOf->format('Y-m-d H:i:s'),
            'window_start' => $start->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * A category's result: the score, the days it rests on, and its parts.
     *
     * `facts` are what the components were worked out from — an average
     * night, how much bedtime varies, minutes a week — for the Scorekompas
     * to explain them in words. Nothing reads them to score, and they are
     * not stored: daily_scores keeps the components only.
     */
    function health_score_result(?float $score, int $days, array $components, array $facts = []): array
    {
        return [
            'score'      => $score === null ? null : (int) round(max(0.0, min(100.0, $score))),
            'days'       => $days,
            'components' => array_map(static fn ($v) => $v === null ? null : round($v, 1), $components),
            'facts'      => $facts,
        ];
    }

    /**
     * Whether a category's result still counts, and until when it would
     * without new input. $dates are the days its data in the window fell on
     * (a night: the morning it ended; a cijfer: its day; a workout: the day
     * it started).
     *
     *   last_input   the newest of them
     *   expired      `expiry_days` days or more since then (config/scoring.php):
     *                the category is left out — no score, no components —
     *                never counted as zero
     *   valid_until  the last day it keeps its score if nothing new comes in:
     *                until it expires, or until the window has dropped so
     *                many of its days that fewer than `min_days` are left —
     *                whichever comes first. Null without a score.
     */
    function health_score_validity(array $result, string $category, array $dates, string $today): array
    {
        $cfg    = health_scoring_config();
        $expiry = $cfg['expiry_days'][$category] ?? null;
        $min    = (int) $cfg['min_days'];

        $dates = array_values(array_unique(array_filter($dates)));
        rsort($dates);

        $last    = $dates[0] ?? null;
        $expired = $expiry !== null && $last !== null
            && health_score_day_gap($last, $today) >= (int) $expiry;

        if ($expired && $result['score'] !== null) {
            $result['score']      = null;
            $result['components'] = array_map(static fn () => null, $result['components']);
            $result['facts']      = [];
        }

        $until = null;
        if ($result['score'] !== null && count($dates) >= $min) {
            /* The window keeps a day for `window_days` days, today included. */
            $until = health_score_add_days($dates[$min - 1], health_score_window_days() - 1);
            if ($expiry !== null) {
                $until = min($until, health_score_add_days($last, (int) $expiry - 1));
            }
        }

        return $result + [
            'last_input'  => $last,
            'expired'     => $expired && $result['score'] === null && $result['days'] >= $min,
            'valid_until' => $until,
        ];
    }

    /** Whole calendar days from one Y-m-d to another. */
    function health_score_day_gap(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
    }

    function health_score_add_days(string $date, int $days): string
    {
        return date('Y-m-d', (int) strtotime($date . ' 12:00:00') + $days * 86400);
    }

    /* ------------------------------------------------------------ sleep */

    /**
     * Sleep: duration 45%, regularity 30%, quality 25%.
     *
     *   duration    each night on the duration curve, averaged
     *   regularity  how much bedtime, wake time and duration vary from night
     *               to night (standard deviation) on their curves
     *   quality     each night's measured quality (efficiency, time awake,
     *               deep and REM sleep) where the device measured it
     */
    function health_score_sleep(array $nights): array
    {
        $cfg  = health_scoring_config();
        $s    = $cfg['sleep'];
        $days = count($nights);
        $none = ['duration' => null, 'regularity' => null, 'quality' => null];

        if ($days < (int) $cfg['min_days']) {
            return health_score_result(null, $days, $none);
        }

        $duration = health_mean(array_map(
            static fn ($n) => health_curve($s['duration_curve'], $n['minutes'] / 60),
            $nights
        ));

        $bedSd  = health_clock_sd(array_map(static fn ($n) => health_clock_minutes($n['start']), $nights));
        $wakeSd = health_clock_sd(array_map(static fn ($n) => health_clock_minutes($n['end']), $nights));
        $durSd  = health_sd(array_map(static fn ($n) => $n['minutes'], $nights));

        $r = $s['regularity'];
        $regularityParts = [
            'bedtime'   => $bedSd === null ? null : health_curve($r['timing_curve'], $bedSd),
            'wake_time' => $wakeSd === null ? null : health_curve($r['timing_curve'], $wakeSd),
            'duration'  => $durSd === null ? null : health_curve($r['duration_curve'], $durSd),
        ];
        $regularity = health_weighted($regularityParts, $r['weights']);

        $qualities = array_values(array_filter(
            array_map('health_night_quality', $nights),
            static fn ($q) => $q !== null
        ));
        $quality = count($qualities) >= (int) $s['quality']['min_nights'] ? health_mean($qualities) : null;

        $components = ['duration' => $duration, 'regularity' => $regularity, 'quality' => $quality];

        /* What the components were worked out from: each night's length, the
           three spreads and their scores, and each quality measurement on
           average over the nights that had it. */
        $measured = array_map('health_night_quality_parts', $nights);
        $qualityFacts = [];
        foreach (array_keys($s['quality']['weights']) as $part) {
            $readings = array_values(array_filter(array_column($measured, $part)));
            $qualityFacts[$part] = $readings === [] ? null : [
                'value'  => health_mean(array_column($readings, 'value')),
                'score'  => health_mean(array_column($readings, 'score')),
                'nights' => count($readings),
            ];
        }

        $facts = [
            'minutes'        => array_map(static fn ($n) => (float) $n['minutes'], $nights),
            'bedtime_sd'     => $bedSd,
            'wake_sd'        => $wakeSd,
            'duration_sd'    => $durSd,
            'regularity'     => $regularityParts,
            'quality_nights' => count($qualities),
            'quality'        => $qualityFacts,
        ];

        return health_score_result(health_weighted($components, $s['weights']), $days, $components, $facts);
    }

    /* -------------------------------------------------------- nutrition */

    /**
     * Nutrition: the daily self-assessment. Each day is the average of that
     * day's ratings, scaled onto 0-100 (7 -> 70), and the score is the average
     * over the days that were rated.
     */
    function health_score_nutrition(array $ratings): array
    {
        $cfg = health_scoring_config();

        $byDay = [];
        foreach ($ratings as $rating) {
            $byDay[$rating['date']][] = $rating['value'];
        }

        $days = count($byDay);

        if ($days < (int) $cfg['min_days']) {
            return health_score_result(null, $days, ['rating' => null]);
        }

        $scale = (float) $cfg['nutrition']['rating_scale'];
        $daily = array_map(static fn ($values) => health_mean($values) * $scale, $byDay);
        $mean  = health_mean(array_values($daily));

        /* The average cijfer itself, on its own 1-10 scale. */
        return health_score_result($mean, $days, ['rating' => $mean], ['rating' => $scale > 0 ? $mean / $scale : null]);
    }

    /* --------------------------------------------------------- training */

    /**
     * Training: volume 20%, intensity 20%, progression 25%, balance 35%.
     *
     * Weeks are counted from the first workout in the window, so somebody who
     * started tracking three weeks ago is measured over three weeks, not
     * thirteen.
     */
    function health_score_training(array $workouts, array $nights, array $vo2, array $goals, int $to): array
    {
        $cfg   = health_scoring_config();
        $t     = $cfg['training'];
        $dates = [];

        foreach ($workouts as $workout) {
            $dates[date('Y-m-d', $workout['start'])] = true;
        }

        $days = count($dates);
        $none = ['volume' => null, 'intensity' => null, 'progression' => null, 'balance' => null];

        if ($days < (int) $cfg['min_days']) {
            return health_score_result(null, $days, $none);
        }

        $first = min(array_column($workouts, 'start'));
        $weeks = max(7.0, ($to - $first) / 86400) / 7;

        /* Volume: minutes a week, with diminishing returns. */
        $minutes = array_sum(array_column($workouts, 'minutes'));
        $volume  = health_curve($t['volume_curve'], $minutes / $weeks);

        /* Intensity: the share of hard sessions among those measured. */
        $known = array_values(array_filter($workouts, static fn ($w) => $w['effort']['class'] !== null));
        $intensity = null;
        $hard = null;

        if (count($known) >= (int) $t['intensity']['min_workouts']) {
            $hard = count(array_filter($known, static fn ($w) => $w['effort']['class'] === 'hard'));
            $intensity = health_curve($t['intensity']['hard_share_curve'], $hard / count($known));
        }

        $progressionFacts = [];
        $balanceFacts     = [];
        $progression = health_score_progression($workouts, $vo2, $goals, $progressionFacts);
        $balance     = health_score_balance($workouts, $nights, array_keys($dates), $first, $to, $weeks, $balanceFacts);

        $components = [
            'volume'      => $volume,
            'intensity'   => $intensity,
            'progression' => $progression,
            'balance'     => $balance,
        ];

        /* What the components were worked out from. */
        $facts = [
            'workouts'         => count($workouts),
            'minutes_per_week' => $minutes / $weeks,
            'known'            => count($known),
            'hard'             => $hard,
            'progression'      => $progressionFacts,
            'balance'          => $balanceFacts,
        ];

        return health_score_result(health_weighted($components, $t['weights']), $days, $components, $facts);
    }

    /**
     * Is this person improving on their own earlier results? Each signal is
     * a relative change, recent half against earlier half of the window:
     * pace per kind of distance activity, VO2max, and results on their own
     * strength or performance goals. Null when there is nothing to compare.
     *
     * $facts, when given, receives how many signals of each kind were
     * compared and their average change, as the score used it.
     */
    function health_score_progression(array $workouts, array $vo2, array $goals, ?array &$facts = null): ?float
    {
        $p       = health_scoring_config()['training']['progression'];
        $min     = (int) $p['min_samples'];
        $changes = [];
        $signals = ['pace' => 0, 'vo2' => 0, 'goals' => 0];

        /* Pace, per kind of activity with a distance. */
        $byType = [];
        foreach ($workouts as $workout) {
            if ($workout['km'] !== null && $workout['km'] >= $p['min_km'] && $workout['kmh'] !== null) {
                $byType[$workout['type']][] = $workout['kmh'];
            }
        }

        foreach ($byType as $speeds) {
            if (count($speeds) >= $min) {
                $half    = intdiv(count($speeds), 2);
                $earlier = health_median(array_slice($speeds, 0, $half));
                $recent  = health_median(array_slice($speeds, $half));

                if ($earlier > 0) {
                    $changes[] = $recent / $earlier - 1;
                    $signals['pace']++;
                }
            }
        }

        /* VO2max, from the first third of the readings to the last. */
        if (count($vo2) >= 2 && end($vo2)['at'] - $vo2[0]['at'] >= (int) $p['vo2_min_days'] * 86400) {
            $third   = max(1, intdiv(count($vo2), 3));
            $values  = array_column($vo2, 'value');
            $earlier = health_median(array_slice($values, 0, $third));
            $recent  = health_median(array_slice($values, -$third));

            if ($earlier > 0) {
                $changes[] = $recent / $earlier - 1;
                $signals['vo2']++;
            }
        }

        /* Their own goals: the best result recently against the best before. */
        foreach ($goals as $goal) {
            $entries = $goal['entries'];

            if (count($entries) < 2 || end($entries)['at'] - $entries[0]['at'] < (int) $p['goal_min_days'] * 86400) {
                continue;
            }

            $half    = max(1, intdiv(count($entries), 2));
            $earlier = array_column(array_slice($entries, 0, $half), 'value');
            $recent  = array_column(array_slice($entries, $half), 'value');

            if ($goal['direction'] === 'decrease') {
                $changes[] = min($earlier) / min($recent) - 1;      // a lower time is better
            } else {
                $changes[] = max($recent) / max($earlier) - 1;
            }
            $signals['goals']++;
        }

        if ($changes === []) {
            $facts = ['signals' => $signals, 'change' => null];
            return null;
        }

        $cap     = (float) $p['max_change'];
        $changes = array_map(static fn ($c) => max(-$cap, min($cap, $c)), $changes);
        $facts   = ['signals' => $signals, 'change' => health_mean($changes)];

        return health_curve($p['change_curve'], health_mean($changes));
    }

    /**
     * Training and recovery in balance: how often, how many days in a row,
     * whether the load jumps, whether hard days come back to back, and how
     * the nights after training went. Each part only where there is data for
     * it; frequency and rest always are.
     *
     * $facts, when given, receives each part as the score used it: what
     * was measured, and its score — null for a part without the data.
     *
     * @param string[] $dates training days, any order
     */
    function health_score_balance(array $workouts, array $nights, array $dates, int $first, int $to, float $weeks, ?array &$facts = null): ?float
    {
        $b = health_scoring_config()['training']['balance'];
        $s = health_scoring_config()['sleep'];

        $dayNumber = static fn (string $date): int => intdiv((int) strtotime($date . ' 12:00:00'), 86400);
        $numbers   = array_map($dayNumber, $dates);
        sort($numbers);

        /* Frequency: training days a week. */
        $frequency = health_curve($b['frequency_curve'], count($numbers) / $weeks);

        /* Rest: the longest run of training days without a rest day. */
        $longest = 1;
        $run     = 1;
        for ($i = 1; $i < count($numbers); $i++) {
            $run = $numbers[$i] === $numbers[$i - 1] + 1 ? $run + 1 : 1;
            $longest = max($longest, $run);
        }
        $rest = health_curve($b['streak_curve'], $longest);

        /* Load spikes: 7-day blocks back from now, each against the four
           before it — only where all four lie after the first workout, so
           the weeks before somebody started tracking are not read as rest. */
        $week   = 7 * 86400;
        $blocks = intdiv((int) health_scoring_config()['window_hours'], 168);
        $loads  = array_fill(0, $blocks, 0.0);

        foreach ($workouts as $workout) {
            $k = intdiv($to - $workout['start'], $week);
            if ($k >= 0 && $k < $blocks) {
                $loads[$k] += $workout['minutes'];
            }
        }

        $firstDay  = (int) strtotime(date('Y-m-d', $first) . ' 00:00:00');
        $evaluated = 0;
        $spikes    = 0;

        for ($k = 0; $k + 4 < $blocks; $k++) {
            if ($to - ($k + 5) * $week < $firstDay) {
                break;
            }

            $before = ($loads[$k + 1] + $loads[$k + 2] + $loads[$k + 3] + $loads[$k + 4]) / 4;

            if ($before > 0) {
                $evaluated++;
                if ($loads[$k] > $b['spike_ratio'] * $before) {
                    $spikes++;
                }
            }
        }

        $spikeScore = $evaluated >= (int) $b['spike_min_weeks'] ? health_curve($b['spike_curve'], $spikes / $evaluated) : null;

        /* Heavy days back to back. */
        $heavy = [];
        foreach ($workouts as $workout) {
            if ($workout['heavy']) {
                $heavy[$dayNumber(date('Y-m-d', $workout['start']))] = true;
            }
        }

        $hardDays = null;
        if (count($heavy) >= (int) $b['hard_days_min']) {
            $following = 0;
            foreach (array_keys($heavy) as $day) {
                if (isset($heavy[$day - 1])) {
                    $following++;
                }
            }
            $hardDays = health_curve($b['hard_days_curve'], $following / count($heavy));
        }

        /* The nights after training days: a night is filed under the morning
           it ends, so the night after a workout on day D is night D + 1. */
        $trained = array_flip($numbers);
        $after   = [];
        $afterMinutes = [];

        foreach ($nights as $night) {
            if (isset($trained[$dayNumber($night['date']) - 1])) {
                $after[] = health_curve($s['duration_curve'], $night['minutes'] / 60);
                $afterMinutes[] = (float) $night['minutes'];
            }
        }

        $sleep = count($after) >= (int) $b['sleep_min_nights'] ? health_mean($after) : null;

        $facts = [
            'frequency' => ['value' => count($numbers) / $weeks, 'score' => $frequency],
            'rest'      => ['value' => $longest, 'score' => $rest],
            'spikes'    => $spikeScore === null ? null : ['value' => $spikes, 'of' => $evaluated, 'score' => $spikeScore],
            'hard_days' => $hardDays === null ? null : ['value' => $following, 'of' => count($heavy), 'score' => $hardDays],
            'sleep'     => $sleep === null ? null : ['value' => health_mean($afterMinutes), 'of' => count($after), 'score' => $sleep],
        ];

        return health_weighted([
            'frequency' => $frequency,
            'rest'      => $rest,
            'spikes'    => $spikeScore,
            'hard_days' => $hardDays,
            'sleep'     => $sleep,
        ], $b['weights']);
    }

    /* ==================================================================
       THE RECORD OF EACH DAY'S SCORE
       ================================================================== */

    /**
     * Writes the scores to daily_scores under the date they were calculated
     * for, with the days and components behind them and until when they hold
     * — only what changed, so rendering a page does not write four rows
     * every time. One row per person, day and category (the primary key):
     * calculating again the same day updates that day's row.
     *
     * Only today: a result for a day that has passed is never written, so
     * the record of that day stays what it was (health_score_history()).
     */
    function health_score_store(int $userId, array $scores): void
    {
        $date = substr((string) $scores['as_of'], 0, 10);

        if ($date < date('Y-m-d')) {
            return;
        }

        $rich  = health_score_store_rich();
        $until = health_score_store_until();

        $rows = [
            'sleep'     => $scores['sleep'],
            'nutrition' => $scores['nutrition'],
            'training'  => $scores['training'],
            'overall'   => ['score' => $scores['overall']['score'], 'days' => null, 'components' => [],
                            'valid_until' => $scores['overall']['valid_until'] ?? null],
        ];

        $stored = [];
        foreach (db_all(
            'SELECT domain, score' . ($rich ? ', data_days, inputs' : '') . ($until ? ', valid_until' : '') . ', algorithm_version
               FROM daily_scores WHERE user_id = ? AND score_date = ?',
            [$userId, $date]
        ) as $row) {
            $stored[$row['domain']] = $row;
        }

        try {
            foreach ($rows as $domain => $result) {
                $inputs = $result['components'] === [] ? null : json_encode($result['components']);
                $holds  = $result['valid_until'] ?? null;
                $old    = $stored[$domain] ?? null;

                if ($old !== null
                    && $old['algorithm_version'] === HEALTH_SCORE_VERSION
                    && ($old['score'] === null ? null : (int) $old['score']) === $result['score']
                    && (!$rich || ((($old['data_days'] === null ? null : (int) $old['data_days']) === $result['days'])
                                   && $old['inputs'] === $inputs))
                    && (!$until || ($old['valid_until'] === null ? null : (string) $old['valid_until']) === $holds)) {
                    continue;
                }

                if ($rich && $until) {
                    db_run(
                        'INSERT INTO daily_scores (user_id, score_date, domain, score, data_days, inputs, valid_until, algorithm_version)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE score = VALUES(score), data_days = VALUES(data_days),
                                                 inputs = VALUES(inputs), valid_until = VALUES(valid_until),
                                                 algorithm_version = VALUES(algorithm_version),
                                                 computed_at = NOW()',
                        [$userId, $date, $domain, $result['score'], $result['days'], $inputs, $holds, HEALTH_SCORE_VERSION]
                    );
                } elseif ($rich) {
                    db_run(
                        'INSERT INTO daily_scores (user_id, score_date, domain, score, data_days, inputs, algorithm_version)
                              VALUES (?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE score = VALUES(score), data_days = VALUES(data_days),
                                                 inputs = VALUES(inputs),
                                                 algorithm_version = VALUES(algorithm_version),
                                                 computed_at = NOW()',
                        [$userId, $date, $domain, $result['score'], $result['days'], $inputs, HEALTH_SCORE_VERSION]
                    );
                } else {
                    db_run(
                        'INSERT INTO daily_scores (user_id, score_date, domain, score, algorithm_version)
                              VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE score = VALUES(score),
                                                 algorithm_version = VALUES(algorithm_version),
                                                 computed_at = NOW()',
                        [$userId, $date, $domain, $result['score'], HEALTH_SCORE_VERSION]
                    );
                }
            }
        } catch (PDOException $e) {
            /* A score that could not be written down is still the right
               score; the page shows it either way. */
            error_log('[ownify] health score: could not record the day\'s scores: ' . $e->getMessage());
        }
    }

    /** Whether daily_scores has the columns migration 010 adds. */
    function health_score_store_rich(): bool
    {
        static $rich = null;

        return $rich ??= db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'daily_scores' AND column_name = 'inputs'"
        ) > 0;
    }

    /**
     * Whether daily_scores has `valid_until` (migration 017). Without it the
     * scores are stored and read as before, and a day without a row of its
     * own is simply empty: nothing is carried that cannot be checked.
     */
    function health_score_store_until(): bool
    {
        static $until = null;

        return $until ??= db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'daily_scores' AND column_name = 'valid_until'"
        ) > 0;
    }
}
