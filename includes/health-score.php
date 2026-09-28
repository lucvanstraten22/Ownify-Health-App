<?php
/**
 * The Health Score — PRIVATE, scoped to its owner like every health record.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT MEASURES
 * ---------------------------------------------------------------------------
 * How healthy somebody's recent pattern is, per category — sleep, nutrition,
 * training — each 0-100, over a rolling window: the moment of calculation
 * minus 90 days. Not this week, not this month: the window moves with the
 * clock, so tomorrow's score has one day more at the front and one day less
 * at the back. The overall score is the average of the categories that have
 * one.
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
 *   - days without data are not days of zero: 24 nights in 90 days are
 *     averaged over 24;
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
 * and components it used) as the record of that day's result.
 */

declare(strict_types=1);

require_once __DIR__ . '/health-signals.php';
require_once __DIR__ . '/scoring.php';

if (!defined('HEALTH_SCORE_VERSION')) {
    define('HEALTH_SCORE_VERSION', 'rolling90-v1');
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

    /** The start of the window that ends at $asOf: exactly `window_days` earlier. */
    function health_score_window_start(DateTimeImmutable $asOf): DateTimeImmutable
    {
        return $asOf->modify('-' . (int) health_scoring_config()['window_days'] . ' days');
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
     * One score per day for the last `$days` days, each as of the end of its
     * day (today: as of now) — the trend chart. The records are read once.
     *
     * @return array<string,array{sleep: ?int, nutrition: ?int, training: ?int, overall: ?int}> date => scores, oldest first
     */
    function health_score_trend(int $userId, int $days = 28, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $firstDay = $now->setTime(0, 0)->modify('-' . ($days - 1) . ' days');

        $data = health_score_data(
            $userId,
            health_score_window_start($firstDay->setTime(23, 59, 59)),
            $now
        );

        $trend = [];

        for ($i = 0; $i < $days; $i++) {
            $day  = $firstDay->modify('+' . $i . ' days');
            $asOf = $day->setTime(23, 59, 59);

            if ($asOf > $now) {
                $asOf = $now;
            }

            $at = health_score_at($data, $asOf);

            $trend[$day->format('Y-m-d')] = [
                'sleep'     => $at['sleep']['score'],
                'nutrition' => $at['nutrition']['score'],
                'training'  => $at['training']['score'],
                'overall'   => $at['overall']['score'],
            ];
        }

        return $trend;
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
     * place JoLu holds lifted weights, repetitions or times. Only milestone
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

        $sleep     = health_score_sleep($nights);
        $nutrition = health_score_nutrition($ratings);
        $training  = health_score_training($workouts, $nights, $vo2, $goals, $to);

        return [
            'sleep'        => $sleep,
            'nutrition'    => $nutrition,
            'training'     => $training,
            'overall'      => ['score' => score_combine([
                'sleep'     => $sleep['score'],
                'nutrition' => $nutrition['score'],
                'training'  => $training['score'],
            ])],
            'as_of'        => $asOf->format('Y-m-d H:i:s'),
            'window_start' => $start->format('Y-m-d H:i:s'),
        ];
    }

    /** A category's result: the score, the days it rests on, and its parts. */
    function health_score_result(?float $score, int $days, array $components): array
    {
        return [
            'score'      => $score === null ? null : (int) round(max(0.0, min(100.0, $score))),
            'days'       => $days,
            'components' => array_map(static fn ($v) => $v === null ? null : round($v, 1), $components),
        ];
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
        $regularity = health_weighted([
            'bedtime'   => $bedSd === null ? null : health_curve($r['timing_curve'], $bedSd),
            'wake_time' => $wakeSd === null ? null : health_curve($r['timing_curve'], $wakeSd),
            'duration'  => $durSd === null ? null : health_curve($r['duration_curve'], $durSd),
        ], $r['weights']);

        $qualities = array_values(array_filter(
            array_map('health_night_quality', $nights),
            static fn ($q) => $q !== null
        ));
        $quality = count($qualities) >= (int) $s['quality']['min_nights'] ? health_mean($qualities) : null;

        $components = ['duration' => $duration, 'regularity' => $regularity, 'quality' => $quality];

        return health_score_result(health_weighted($components, $s['weights']), $days, $components);
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

        return health_score_result($mean, $days, ['rating' => $mean]);
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

        if (count($known) >= (int) $t['intensity']['min_workouts']) {
            $hard = count(array_filter($known, static fn ($w) => $w['effort']['class'] === 'hard'));
            $intensity = health_curve($t['intensity']['hard_share_curve'], $hard / count($known));
        }

        $progression = health_score_progression($workouts, $vo2, $goals);
        $balance     = health_score_balance($workouts, $nights, array_keys($dates), $first, $to, $weeks);

        $components = [
            'volume'      => $volume,
            'intensity'   => $intensity,
            'progression' => $progression,
            'balance'     => $balance,
        ];

        return health_score_result(health_weighted($components, $t['weights']), $days, $components);
    }

    /**
     * Is this person improving on their own earlier results? Each signal is
     * a relative change, recent half against earlier half of the window:
     * pace per kind of distance activity, VO2max, and results on their own
     * strength or performance goals. Null when there is nothing to compare.
     */
    function health_score_progression(array $workouts, array $vo2, array $goals): ?float
    {
        $p       = health_scoring_config()['training']['progression'];
        $min     = (int) $p['min_samples'];
        $changes = [];

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
        }

        if ($changes === []) {
            return null;
        }

        $cap     = (float) $p['max_change'];
        $changes = array_map(static fn ($c) => max(-$cap, min($cap, $c)), $changes);

        return health_curve($p['change_curve'], health_mean($changes));
    }

    /**
     * Training and recovery in balance: how often, how many days in a row,
     * whether the load jumps, whether hard days come back to back, and how
     * the nights after training went. Each part only where there is data for
     * it; frequency and rest always are.
     *
     * @param string[] $dates training days, any order
     */
    function health_score_balance(array $workouts, array $nights, array $dates, int $first, int $to, float $weeks): ?float
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
        $blocks = intdiv((int) health_scoring_config()['window_days'], 7);
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

        foreach ($nights as $night) {
            if (isset($trained[$dayNumber($night['date']) - 1])) {
                $after[] = health_curve($s['duration_curve'], $night['minutes'] / 60);
            }
        }

        $sleep = count($after) >= (int) $b['sleep_min_nights'] ? health_mean($after) : null;

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
     * for, with the days and components behind them — only what changed, so
     * rendering a page does not write four rows every time.
     */
    function health_score_store(int $userId, array $scores): void
    {
        $date = substr((string) $scores['as_of'], 0, 10);
        $rich = health_score_store_rich();

        $rows = [
            'sleep'     => $scores['sleep'],
            'nutrition' => $scores['nutrition'],
            'training'  => $scores['training'],
            'overall'   => ['score' => $scores['overall']['score'], 'days' => null, 'components' => []],
        ];

        $stored = [];
        foreach (db_all(
            'SELECT domain, score' . ($rich ? ', data_days, inputs' : '') . ', algorithm_version
               FROM daily_scores WHERE user_id = ? AND score_date = ?',
            [$userId, $date]
        ) as $row) {
            $stored[$row['domain']] = $row;
        }

        try {
            foreach ($rows as $domain => $result) {
                $inputs = $result['components'] === [] ? null : json_encode($result['components']);
                $old    = $stored[$domain] ?? null;

                if ($old !== null
                    && $old['algorithm_version'] === HEALTH_SCORE_VERSION
                    && ($old['score'] === null ? null : (int) $old['score']) === $result['score']
                    && (!$rich || ((($old['data_days'] === null ? null : (int) $old['data_days']) === $result['days'])
                                   && $old['inputs'] === $inputs))) {
                    continue;
                }

                if ($rich) {
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
            error_log('[jolu] health score: could not record the day\'s scores: ' . $e->getMessage());
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
}
