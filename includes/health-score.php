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
    define('HEALTH_SCORE_VERSION', 'factors-v2');
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

            $inputs = $rich && $row['inputs'] !== null ? json_decode((string) $row['inputs'], true) : null;
            $inputs = is_array($inputs) ? $inputs : [];
            /* Since 38.0 the components sit beside the range and coverage;
               before, the inputs were the components themselves. */
            $components = array_key_exists('components', $inputs) || array_key_exists('coverage', $inputs)
                ? (array) ($inputs['components'] ?? [])
                : $inputs;

            if ($domain === 'overall') {
                $days[$date]['overall']['score'] = $score;
                continue;
            }

            $days[$date][$domain] = [
                'score'       => $score,
                'days'        => $rich && $row['data_days'] !== null ? (int) $row['data_days'] : 0,
                'components'  => $components,
                'range'       => $inputs['range'] ?? null,
                'coverage'    => $inputs['coverage'] ?? null,
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

        return [
            'nights'   => health_nights($userId, $fromSql, $toSql),
            'ratings'  => $ratings,
            'workouts' => $workouts,
            'goals'    => health_score_goal_results($userId, $from, $to),
        ] + health_score_day_records($userId, $from, $to);
    }

    /**
     * The day-by-day records the 38.0 factors read: what was eaten and
     * drunk, caffeine with its times, steps, resting heart rate, HRV (with
     * the baseline days before the window), and the profile the curves
     * need — sex, age, weight and the energy target.
     *
     * @return array{food: array, caffeine: array, steps: array, resting_hr: array, hrv: array, profile: array}
     */
    function health_score_day_records(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $fromDate = $from->format('Y-m-d');
        $toDate   = $to->format('Y-m-d');
        $baseline = (int) health_scoring_config()['sleep']['hrv']['baseline_days'];

        $food = [];
        foreach (['energy', 'protein', 'carbs', 'fat', 'fibre', 'sugar', 'saturated_fat', 'sodium', 'water'] as $code) {
            foreach (health_daily_metric_days($userId, $code, $fromDate, $toDate) as $date => $value) {
                $food[$date][$code] = (float) $value;
            }
        }

        $caffeine = [];
        $caffeineType = health_metric_type_id('caffeine');
        if ($caffeineType !== null) {
            foreach (db_all(
                'SELECT value, recorded_at, recorded_on FROM health_metrics
                  WHERE user_id = ? AND metric_type_id = ? AND recorded_on BETWEEN ? AND ? AND value > 0',
                [$userId, $caffeineType, $fromDate, $toDate]
            ) as $row) {
                $at = strtotime((string) $row['recorded_at']);
                if ($at !== false) {
                    $caffeine[] = ['at' => $at, 'date' => (string) $row['recorded_on'], 'mg' => (float) $row['value']];
                }
            }
        }

        $profile = ['sex' => null, 'age' => null, 'weight_kg' => null, 'energy_target' => null];
        $account = user_account($userId);
        if ($account !== null) {
            $weight = $account['weight'] ?? null;
            $profile['sex']       = in_array($account['gender'] ?? null, ['female', 'male'], true) ? (string) $account['gender'] : null;
            $profile['age']       = $account['age'] ?? null;
            $profile['weight_kg'] = $weight !== null && ($weight['unit'] ?? null) === 'kg' ? (float) $weight['value'] : null;
        }
        try {
            require_once __DIR__ . '/nutrition-targets.php';
            $target = nutrition_targets_for_user($userId)['targets']['calorie_target_kcal'] ?? null;
            $profile['energy_target'] = $target !== null && (float) $target > 0 ? (float) $target : null;
        } catch (Throwable $e) {
            $profile['energy_target'] = null;
        }

        return [
            'food'       => $food,
            'caffeine'   => $caffeine,
            'steps'      => health_metric_totals($userId, 'steps', $fromDate, $toDate),
            'resting_hr' => health_daily_metric_days($userId, 'resting_hr', $fromDate, $toDate),
            'hrv'        => health_daily_metric_days($userId, 'hrv',
                                $from->modify('-' . $baseline . ' days')->format('Y-m-d'), $toDate),
            'profile'    => $profile,
        ];
    }

    /**
     * Results on the person's own training goals — the one place Ownify
     * holds lifted weights, repetitions or times: a Mijlpaal in Training
     * (an Eigen gewoonte such as "bench press 100 kg"), each day's figure as
     * goal_progress keeps it, oldest first.
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
                AND g.category_code IN (' . $marks . ')
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

    /**
     * The scores as of $asOf, from records loaded by health_score_data().
     * Without $withRange the uncertainty range is not drawn: for a caller
     * that only asks whether there is a score.
     */
    function health_score_at(array $data, DateTimeImmutable $asOf, bool $withRange = true): array
    {
        $to    = $asOf->getTimestamp();
        $start = health_score_window_start($asOf);
        $from  = $start->getTimestamp();

        $in = static fn (int $t): bool => $t > $from && $t <= $to;

        $nights   = array_values(array_filter($data['nights'], static fn ($n) => $in($n['end'])));
        $ratings  = array_values(array_filter($data['ratings'], static fn ($r) => $in($r['at'])));
        $workouts = array_values(array_filter($data['workouts'], static fn ($w) => $in($w['start'])));

        $goals = [];
        foreach ($data['goals'] as $goal) {
            $entries = array_values(array_filter($goal['entries'], static fn ($e) => $in($e['at'])));
            if ($entries !== []) {
                $goals[] = ['direction' => $goal['direction'], 'entries' => $entries];
            }
        }

        $today = $asOf->format('Y-m-d');
        $days  = static fn (array $byDate, int $first = 0): array => array_filter(
            $byDate,
            static fn ($date) => ($ago = health_score_day_gap((string) $date, $today)) >= $first && $ago < health_score_window_days(),
            ARRAY_FILTER_USE_KEY
        );

        $food     = $days($data['food'] ?? []);
        $caffeine = array_values(array_filter($data['caffeine'] ?? [], static fn ($c) => isset($food[$c['date']])));
        $steps    = $days($data['steps'] ?? []);
        $profile  = ($data['profile'] ?? []) + ['sex' => null, 'age' => null, 'weight_kg' => null, 'energy_target' => null];

        $sleepResult = health_score_sleep($nights, $today, $data['hrv'] ?? []);
        $nutritionResult = health_score_nutrition($ratings, $today, $food, $caffeine, $nights, $profile);

        $sleep     = health_score_validity($sleepResult, 'sleep', array_column($nights, 'date'), $today);
        $nutrition = health_score_validity($nutritionResult, 'nutrition', $nutritionResult['dates'] ?? array_column($ratings, 'date'), $today);
        unset($nutrition['dates']);
        $training  = health_score_training($workouts, $nights, $goals, $to, $today, $steps, $days($data['resting_hr'] ?? []), $profile);
        $training  = health_score_validity($training, 'training', $training['dates'], $today);
        unset($training['dates']);

        /* The uncertainty range and coverage (D5): stored with every
           score, not shown. */
        $range = $withRange
            ? health_score_uncertainty(['sleep' => $sleep, 'nutrition' => $nutrition, 'training' => $training])
            : array_fill_keys(['sleep', 'nutrition', 'training', 'overall'], ['range' => null, 'coverage' => null]);
        foreach (['sleep', 'nutrition', 'training'] as $category) {
            ${$category}['range']    = $range[$category]['range'];
            ${$category}['coverage'] = $range[$category]['coverage'];
        }

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
                'range'       => $range['overall']['range'],
                'coverage'    => $range['overall']['coverage'],
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

    /** How much a record of $date counts on $today (config `recency`). */
    function health_score_day_weight(string $date, string $today): float
    {
        return health_recency((float) health_score_day_gap($date, $today));
    }

    /**
     * Sleep (38.0): eight factors, weighed by config/scoring.php.
     *
     *   duration       each night on the LE8 duration curve, averaged
     *   regularity     how much bedtime and wake time vary (standard
     *                  deviation), each on its curve, the two averaged
     *   social_jetlag  the midpoint of sleep on free days against work days
     *   efficiency, awake, deep, rem
     *                  each night's measurement where the device took it,
     *                  once `quality.min_nights` nights have it
     *   hrv            the window's HRV against the person's own baseline
     *
     * Every average counts recent nights more (health_recency()).
     *
     * @param array<string,float> $hrv date => ms, the window and the baseline before it
     */
    function health_score_sleep(array $nights, ?string $today = null, array $hrv = []): array
    {
        $cfg   = health_scoring_config();
        $s     = $cfg['sleep'];
        $days  = count($nights);
        $today ??= date('Y-m-d');
        $none  = array_fill_keys(array_keys($s['factors']), null);

        if ($days < (int) $cfg['min_days']) {
            return health_score_result(null, $days, $none);
        }

        $weights = array_map(static fn ($n) => health_score_day_weight($n['date'], $today), $nights);

        /* Duration. */
        $duration = health_weighted_mean(array_map(
            static fn ($n) => health_curve($s['duration_curve'], $n['minutes'] / 60),
            $nights
        ), $weights);

        /* Regularity: bedtime and wake time. */
        $bedSd  = health_clock_sd(array_map(static fn ($n) => health_clock_minutes($n['start']), $nights), $weights);
        $wakeSd = health_clock_sd(array_map(static fn ($n) => health_clock_minutes($n['end']), $nights), $weights);
        $timing = $s['regularity']['timing_curve'];
        $regularityParts = [
            'bedtime'   => $bedSd === null ? null : health_curve($timing, $bedSd),
            'wake_time' => $wakeSd === null ? null : health_curve($timing, $wakeSd),
        ];
        $regularity = health_mean(array_values(array_filter($regularityParts, static fn ($v) => $v !== null)));

        /* Social jetlag: free days are the nights into Saturday and Sunday. */
        $mid = ['free' => [[], []], 'work' => [[], []]];
        foreach ($nights as $i => $night) {
            $group = (int) date('N', (int) strtotime($night['date'] . ' 12:00:00')) >= 6 ? 'free' : 'work';
            $mid[$group][0][] = health_clock_minutes(intdiv($night['start'] + $night['end'], 2));
            $mid[$group][1][] = $weights[$i];
        }
        $jetlag = null;
        if ($mid['free'][0] !== [] && $mid['work'][0] !== []) {
            $jetlag = health_clock_distance(
                (float) health_clock_mean($mid['free'][0], $mid['free'][1]),
                (float) health_clock_mean($mid['work'][0], $mid['work'][1])
            ) / 60;
        }

        /* What the device measured about each night. */
        $measured = array_map('health_night_quality_parts', $nights);
        $quality  = [];
        $qualityFacts = [];
        foreach (['efficiency', 'awake', 'deep', 'rem'] as $part) {
            $scores = [];
            $values = [];
            $w      = [];
            foreach ($measured as $i => $parts) {
                if ($parts[$part] !== null) {
                    $scores[] = $parts[$part]['score'];
                    $values[] = $parts[$part]['value'];
                    $w[]      = $weights[$i];
                }
            }
            $enough = count($scores) >= (int) $s['quality']['min_nights'];
            $quality[$part] = $enough ? health_weighted_mean($scores, $w) : null;
            $qualityFacts[$part] = $scores === [] ? null : [
                'value'  => health_weighted_mean($values, $w),
                'score'  => health_weighted_mean($scores, $w),
                'nights' => count($scores),
            ];
        }

        /* HRV against the person's own baseline. */
        [$hrvScore, $hrvFacts] = health_score_hrv($hrv, $today);

        $components = [
            'duration'      => $duration,
            'regularity'    => $regularity,
            'social_jetlag' => $jetlag === null ? null : health_curve($s['social_jetlag']['curve'], $jetlag),
            'efficiency'    => $quality['efficiency'],
            'awake'         => $quality['awake'],
            'deep'          => $quality['deep'],
            'rem'           => $quality['rem'],
            'hrv'           => $hrvScore,
        ];

        $facts = [
            'minutes'        => array_map(static fn ($n) => (float) $n['minutes'], $nights),
            'average'        => health_weighted_mean(array_map(static fn ($n) => (float) $n['minutes'], $nights), $weights),
            'bedtime_sd'     => $bedSd,
            'wake_sd'        => $wakeSd,
            'regularity'     => $regularityParts,
            'social_jetlag'  => $jetlag,
            'quality_nights' => max(0, ...array_values(array_map(static fn ($q) => $q['nights'] ?? 0, $qualityFacts))),
            'quality'        => $qualityFacts,
            'hrv'            => $hrvFacts,
        ];

        return health_score_result(health_weighted($components, $s['weights']), $days, $components, $facts);
    }

    /**
     * HRV against the person's own baseline: the z-score of the window's
     * mean ln(rMSSD) — recent days counting more — against the mean and
     * spread of the `baseline_days` before the window. Null without
     * `min_days` days in the window or `min_baseline` before it.
     *
     * @param array<string,float> $hrv date => ms
     * @return array{0: ?float, 1: ?array}
     */
    function health_score_hrv(array $hrv, string $today): array
    {
        $h      = health_scoring_config()['sleep']['hrv'];
        $window = health_score_window_days();
        $now    = [];
        $nowW   = [];
        $base   = [];

        foreach ($hrv as $date => $ms) {
            if ($ms <= 0) {
                continue;
            }
            $ago = health_score_day_gap((string) $date, $today);
            if ($ago >= 0 && $ago < $window) {
                $now[]  = log((float) $ms);
                $nowW[] = health_recency((float) $ago);
            } elseif ($ago >= $window && $ago < $window + (int) $h['baseline_days']) {
                $base[] = log((float) $ms);
            }
        }

        if (count($now) < (int) $h['min_days'] || count($base) < (int) $h['min_baseline']) {
            return [null, null];
        }

        $mean = (float) health_mean($base);
        $sd   = max((float) $h['min_sd'], (float) health_sd($base));
        $cur  = (float) health_weighted_mean($now, $nowW);
        $z    = ($cur - $mean) / $sd;

        return [health_curve($h['curve'], $z), ['z' => $z, 'ms' => exp($cur), 'baseline_ms' => exp($mean)]];
    }

    /* -------------------------------------------------------- nutrition */

    /**
     * Nutrition (38.0): ten factors, weighed by config/scoring.php.
     *
     * What was eaten counts on days whose log reaches `food_day_share` of
     * the energy target: fibre and sodium per 1,000 kcal, saturated fat,
     * sugar and fat as a share of energy, protein per kg, energy against
     * the target — each the recency-weighted average over those days, on
     * its curve. Water on the days it was recorded; caffeine timing on the
     * food days, for somebody who records caffeine at all. The daily
     * rating as before. Without a single food day, the rating is the whole
     * score.
     *
     * @param array<string,array<string,float>> $food date => metric => day total, in the window
     */
    function health_score_nutrition(array $ratings, ?string $today = null, array $food = [], array $caffeine = [],
                                    array $nights = [], array $profile = []): array
    {
        $cfg   = health_scoring_config();
        $n     = $cfg['nutrition'];
        $today ??= date('Y-m-d');
        $none  = array_fill_keys(array_keys($n['factors']), null);

        $byDay = [];
        foreach ($ratings as $rating) {
            $byDay[$rating['date']][] = $rating['value'];
        }

        /* The days whose log is complete enough. */
        $target    = isset($profile['energy_target']) && $profile['energy_target'] > 0 ? (float) $profile['energy_target'] : null;
        $threshold = $target !== null ? $target * (float) $n['food_day_share'] : (float) $n['food_day_fallback_kcal'];
        $kcal      = [];
        foreach ($food as $date => $day) {
            $energy = $day['energy'] ?? (isset($day['carbs'], $day['protein'], $day['fat'])
                ? $day['carbs'] * 4 + $day['protein'] * 4 + $day['fat'] * 9
                : null);
            if ($energy !== null && $energy >= $threshold && $energy > 0) {
                $kcal[(string) $date] = (float) $energy;
            }
        }

        $dates = array_values(array_unique(array_merge(array_map('strval', array_keys($byDay)), array_keys($kcal))));
        $days  = count($dates);

        if ($days < (int) $cfg['min_days']) {
            return health_score_result(null, $days, $none) + ['dates' => $dates];
        }

        $scale = (float) $n['rating_scale'];
        $rated = [];
        $ratedW = [];
        foreach ($byDay as $date => $values) {
            $rated[]  = health_mean($values) * $scale;
            $ratedW[] = health_score_day_weight((string) $date, $today);
        }
        $rating = $rated === [] ? null : health_weighted_mean($rated, $ratedW);

        $components = $none;
        $components['rating'] = $rating;
        $facts = ['rating' => $rating === null || $scale <= 0 ? null : $rating / $scale, 'food_days' => count($kcal)];

        if ($kcal !== []) {
            /* A food-day average of one value per day, where the day has it. */
            $average = static function (callable $value) use ($kcal, $food, $today): ?float {
                $values = [];
                $w      = [];
                foreach ($kcal as $date => $energy) {
                    $v = $value($food[$date], $energy);
                    if ($v !== null) {
                        $values[] = $v;
                        $w[]      = health_score_day_weight($date, $today);
                    }
                }
                return $values === [] ? null : health_weighted_mean($values, $w);
            };

            $values = [
                'fibre'         => $average(static fn ($d, $e) => isset($d['fibre']) ? $d['fibre'] / ($e / 1000) : null),
                'sodium'        => $average(static fn ($d, $e) => isset($d['sodium']) ? $d['sodium'] / 1000 / ($e / 1000) : null),
                'saturated_fat' => $average(static fn ($d, $e) => isset($d['saturated_fat']) ? $d['saturated_fat'] * 9 / $e * 100 : null),
                'sugar'         => $average(static fn ($d, $e) => isset($d['sugar']) ? $d['sugar'] * 4 / $e * 100 : null),
                'fat_share'     => $average(static fn ($d, $e) => isset($d['fat']) ? $d['fat'] * 9 / $e * 100 : null),
                'protein'       => !empty($profile['weight_kg'])
                    ? $average(static fn ($d, $e) => isset($d['protein']) ? $d['protein'] / (float) $profile['weight_kg'] : null)
                    : null,
                'energy'        => $target !== null ? $average(static fn ($d, $e) => abs($e - $target) / $target) : null,
            ];

            foreach ($values as $key => $value) {
                $components[$key] = $value === null ? null : health_curve($n[$key . '_curve'], $value);
            }

            /* Water: litres a day, on the days it was recorded, against the
               person's norm. */
            $norm  = (float) ($n['water_norm_l'][$profile['sex'] ?? 'unknown'] ?? $n['water_norm_l']['unknown']);
            $water = [];
            $waterW = [];
            foreach ($food as $date => $day) {
                if (($day['water'] ?? 0) > 0) {
                    $water[]  = $day['water'] / $norm;
                    $waterW[] = health_score_day_weight((string) $date, $today);
                }
            }
            $waterShare = $water === [] ? null : health_weighted_mean($water, $waterW);
            $components['water'] = $waterShare === null ? null : health_curve($n['water_curve'], $waterShare);
            $values['water']     = $waterShare === null ? null : $waterShare * $norm;

            /* Caffeine: hours from the day's last caffeine to the usual
               bedtime. A food day without any is a day without caffeine —
               for somebody who records it at all. */
            $bedtime = $nights === [] ? null : health_clock_mean(
                array_map(static fn ($night) => health_clock_minutes($night['start']), $nights),
                array_map(static fn ($night) => health_score_day_weight($night['date'], $today), $nights)
            );
            $hours = null;
            if ($caffeine !== [] && $bedtime !== null) {
                $last = [];
                foreach ($caffeine as $c) {
                    $last[$c['date']] = max($last[$c['date']] ?? 0, $c['at']);
                }
                $scores = [];
                $gaps   = [];
                $w      = [];
                foreach (array_keys($kcal) as $date) {
                    /* The usual bedtime that evening: after midnight is the next day. */
                    $bed = (int) strtotime($date . ' 00:00:00') + (int) round(60 * $bedtime) + ($bedtime < 720 ? 86400 : 0);
                    $gap = isset($last[$date]) ? max(0.0, ($bed - $last[$date]) / 3600) : null;
                    $scores[] = $gap === null ? 100.0 : health_curve($n['caffeine_curve'], $gap);
                    if ($gap !== null) {
                        $gaps[] = $gap;
                    }
                    $w[] = health_score_day_weight($date, $today);
                }
                $components['caffeine'] = health_weighted_mean($scores, $w);
                $hours = $gaps === [] ? null : health_mean($gaps);
            }
            $values['caffeine'] = $hours;

            $facts['values'] = $values;
        }

        return health_score_result(health_weighted($components, $n['weights']), $days, $components, $facts) + ['dates' => $dates];
    }

    /* --------------------------------------------------------- training */

    /** Whether a workout is strength training (config `strength_types`). */
    function health_score_is_strength(array $workout): bool
    {
        return in_array($workout['type'] ?? '', (array) health_scoring_config()['training']['strength_types'], true);
    }

    /**
     * Training (38.0): eight factors, weighed by config/scoring.php.
     *
     *   steps        steps a day on completed days, recent days counting
     *                more, on Paluch's curve (from 60 its own)
     *   aerobic      aerobic minutes per week, vigorous counting double
     *   strength     strength minutes per week
     *   intensity    the share of aerobic minutes that were vigorous
     *   active_days  days with 20 aerobic minutes or 7,000 steps, per week
     *   resting_hr   the average resting heart rate
     *   recovery     the share of heavy days followed by a rest or easy day
     *   progression  their own results, recent against earlier
     *
     * Per week is the window's recency-weighted total, scaled so an even
     * week counts exactly its own total. Without a workout in the window
     * the workout factors are not measured — left out, not zero.
     *
     * @param array<string,float> $steps     date => steps, in the window
     * @param array<string,float> $restingHr date => bpm, in the window
     */
    function health_score_training(array $workouts, array $nights, array $goals, int $to, ?string $today = null,
                                   array $steps = [], array $restingHr = [], array $profile = []): array
    {
        $cfg    = health_scoring_config();
        $t      = $cfg['training'];
        $today ??= date('Y-m-d', $to);
        $none   = array_fill_keys(array_keys($t['factors']), null);
        $window = health_score_window_days();

        /* Steps count on completed days; today is still going. */
        $complete = array_filter($steps, static fn ($date) => (string) $date < $today, ARRAY_FILTER_USE_KEY);

        $workoutDays = [];
        foreach ($workouts as $workout) {
            $workoutDays[date('Y-m-d', $workout['start'])] = true;
        }
        $dates = array_values(array_unique(array_merge(array_keys($workoutDays), array_map('strval', array_keys($complete)))));
        sort($dates);
        $days = count($dates);

        if ($days < (int) $cfg['min_days']) {
            return health_score_result(null, $days, $none) + ['dates' => $dates];
        }

        $weightOf = static fn (string $date): float => health_score_day_weight($date, $today);
        /* An even week: every one of the window's days at its weight. */
        $evenWeek = 0.0;
        for ($ago = 0; $ago < $window; $ago++) {
            $evenWeek += health_recency((float) $ago);
        }
        $perWeek = static fn (float $weighted): float => $evenWeek > 0 ? $weighted / $evenWeek * 7 : 0.0;

        /* Steps. */
        $stepsMean = $complete === [] ? null : health_weighted_mean(
            array_values($complete),
            array_map($weightOf, array_map('strval', array_keys($complete)))
        );
        $older     = ($profile['age'] ?? null) !== null && (int) $profile['age'] >= (int) $t['older_from_age'];
        $stepScore = $stepsMean === null ? null : health_curve($older ? $t['steps_curve_older'] : $t['steps_curve'], $stepsMean);

        /* Workouts: aerobic and strength minutes, intensity, recovery. */
        $aerobic = $strength = $intensity = $recovery = $progression = null;
        $aerobicWeek = $strengthWeek = $vigorousShare = null;
        $known = 0;
        $recoveryFacts = null;
        $progressionFacts = [];
        $dayMinutes = [];

        if ($workouts !== []) {
            $aerobicSum = $strengthSum = $vigorous = $classified = 0.0;

            foreach ($workouts as $workout) {
                $date    = date('Y-m-d', $workout['start']);
                $w       = $weightOf($date);
                $minutes = (float) ($workout['minutes'] ?? 0);

                if (health_score_is_strength($workout)) {
                    $strengthSum += $w * $minutes;
                    continue;
                }

                $hard = ($workout['effort']['class'] ?? null) === 'hard';
                $aerobicSum += $w * $minutes * ($hard ? 2 : 1);
                $dayMinutes[$date] = ($dayMinutes[$date] ?? 0) + $minutes;

                if (($workout['effort']['class'] ?? null) !== null) {
                    $known++;
                    $classified += $w * $minutes;
                    $vigorous   += $hard ? $w * $minutes : 0;
                }
            }

            $aerobicWeek  = $perWeek($aerobicSum);
            $strengthWeek = $perWeek($strengthSum);
            $aerobic      = health_curve($t['aerobic_curve'], $aerobicWeek);
            $strength     = health_curve($t['strength_curve'], $strengthWeek);

            if ($known >= (int) $t['intensity']['min_workouts'] && $classified > 0) {
                $vigorousShare = $vigorous / $classified;
                $intensity     = health_curve($t['intensity_curve'], $vigorousShare);
            }

            /* Recovery: a heavy day is judged once the day after it is
               over — or sooner, when today is already heavy too. */
            $heavy = [];
            foreach ($workouts as $workout) {
                if ($workout['heavy']) {
                    $heavy[date('Y-m-d', $workout['start'])] = true;
                }
            }
            $judged = $ok = 0.0;
            $count  = $okCount = 0;
            foreach (array_keys($heavy) as $date) {
                $next = health_score_add_days((string) $date, 1);
                if ($next >= $today && !isset($heavy[$today])) {
                    continue;
                }
                if ($next > $today) {
                    continue;
                }
                $w = $weightOf((string) $date);
                $judged += $w;
                $count++;
                if (!isset($heavy[$next])) {
                    $ok += $w;
                    $okCount++;
                }
            }
            if ($count >= (int) $t['recovery_min'] && $judged > 0) {
                $recovery      = health_curve($t['recovery_curve'], $ok / $judged);
                $recoveryFacts = ['value' => $okCount, 'of' => $count];
            }

            $progression = health_score_progression($workouts, $goals, $progressionFacts);
        }

        /* Active days: 20 aerobic minutes or 7,000 steps. */
        $active = 0.0;
        $activeCount = 0;
        for ($ago = 0; $ago < $window; $ago++) {
            $date = health_score_add_days($today, -$ago);
            if (($dayMinutes[$date] ?? 0) >= (float) $t['active_day_minutes']
                || ($steps[$date] ?? 0) >= (float) $t['active_day_steps']) {
                $active += health_recency((float) $ago);
                $activeCount++;
            }
        }
        $activeWeek = $perWeek($active);

        /* Resting heart rate. */
        $rhr = $restingHr === [] ? null : health_weighted_mean(
            array_values($restingHr),
            array_map($weightOf, array_map('strval', array_keys($restingHr)))
        );

        $components = [
            'steps'       => $stepScore,
            'aerobic'     => $aerobic,
            'strength'    => $strength,
            'intensity'   => $intensity,
            'active_days' => health_curve($t['active_days_curve'], $activeWeek),
            'resting_hr'  => $rhr === null ? null : health_curve($t['resting_hr_curve'], $rhr),
            'recovery'    => $recovery,
            'progression' => $progression,
        ];

        $facts = [
            'workouts'          => count($workouts),
            'training_days'     => count($workoutDays),
            'steps'             => $stepsMean,
            'aerobic_per_week'  => $aerobicWeek,
            'strength_per_week' => $strengthWeek,
            'vigorous_share'    => $vigorousShare,
            'known'             => $known,
            'active_days'       => $activeWeek,
            'active_count'      => $activeCount,
            'resting_hr'        => $rhr,
            'recovery'          => $recoveryFacts,
            'progression'       => $progressionFacts,
        ];

        return health_score_result(health_weighted($components, $t['weights']), $days, $components, $facts) + ['dates' => $dates];
    }

    /**
     * Is this person improving on their own earlier results? Each signal is
     * a relative change, recent half against earlier half of the window:
     * pace per kind of distance activity, and results on their own strength
     * or performance goals. Null when there is nothing to compare.
     *
     * $facts, when given, receives how many signals of each kind were
     * compared and their average change, as the score used it.
     */
    function health_score_progression(array $workouts, array $goals, ?array &$facts = null): ?float
    {
        $p       = health_scoring_config()['training']['progression'];
        $min     = (int) $p['min_samples'];
        $changes = [];
        $signals = ['pace' => 0, 'goals' => 0];

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

    /* ------------------------------------------------------ uncertainty */

    /**
     * How sure each score is (38.0, D5) — stored with it, not shown.
     *
     *   range     the 5th to 95th percentile of the score when the method
     *             itself is varied: every factor's evidence and measurement
     *             grade moves one step down or up with a chance of 1/3 each,
     *             and every overlap is scaled by a factor between 0.4 and
     *             1.3, `draws` times over (config `uncertainty`). The
     *             overall range varies the three categories together.
     *   coverage  the share of the category's weight whose factors had
     *             data (0-1); overall, the three categories' average, a
     *             category without a score counting as 0.
     *
     * The draws come from a fixed seed, so the same factors always give
     * the same range and a stored row only changes when the data does.
     *
     * @param array<string,array> $results category => result
     * @return array<string,array{range: ?array{0: float, 1: float}, coverage: float}>
     */
    function health_score_uncertainty(array $results): array
    {
        static $memo = [];

        $cfg = health_scoring_config();
        $u   = $cfg['uncertainty'];
        $out = [];
        $components = [];

        foreach (['sleep', 'nutrition', 'training'] as $category) {
            $result = $results[$category] ?? null;
            $parts  = ($result !== null && $result['score'] !== null) ? array_filter(
                (array) $result['components'],
                static fn ($v) => $v !== null
            ) : [];

            $weights  = (array) $cfg[$category]['weights'];
            $coverage = 0.0;
            foreach ($parts as $key => $value) {
                $coverage += (float) ($weights[$key] ?? 0);
            }

            if ($parts !== []) {
                $components[$category] = $parts;
            }
            $out[$category] = ['range' => null, 'coverage' => round($coverage, 3)];
        }

        $out['overall'] = [
            'range'    => null,
            'coverage' => round((($out['sleep']['coverage'] + $out['nutrition']['coverage'] + $out['training']['coverage']) / 3), 3),
        ];

        if ($components === []) {
            return $out;
        }

        $key = md5(json_encode($components));
        if (isset($memo[$key])) {
            return array_replace_recursive($out, $memo[$key]);
        }

        $steps = [0.25, 0.5, 1.0];
        $state = (int) $u['seed'];
        /* A small generator of its own (a 32-bit LCG), so nothing else's
           random numbers are touched. */
        $random = static function () use (&$state): float {
            $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;
            return $state / 4294967296;
        };
        $jitter = static function (float $grade) use ($steps, $random, $u): float {
            $i = array_search($grade, $steps, true);
            if ($i === false) {
                return $grade;
            }
            $r = $random();
            if ($r < (float) $u['step_chance'] && $i > 0) {
                return $steps[$i - 1];
            }
            if ($r > 1 - (float) $u['step_chance'] && $i < 2) {
                return $steps[$i + 1];
            }
            return $grade;
        };

        [$lo, $hi] = $u['overlap'];
        $draws = [];
        for ($d = 0; $d < (int) $u['draws']; $d++) {
            $sum = 0.0;
            foreach ($components as $category => $parts) {
                $grades = [];
                foreach ($cfg[$category]['factors'] as $factor => $grade) {
                    $grades[$factor] = [
                        'evidence'    => $jitter((float) $grade['evidence']),
                        'measurement' => $jitter((float) $grade['measurement']),
                    ];
                }
                $scale = $lo + ($hi - $lo) * $random();
                $score = (float) health_weighted($parts, health_score_weights($category, $grades, $scale));
                $draws[$category][] = $score;
                $sum += $score;
            }
            $draws['overall'][] = $sum / count($components);
        }

        $found = [];
        [$pLo, $pHi] = $u['percentiles'];
        foreach ($draws as $category => $scores) {
            sort($scores);
            $n = count($scores);
            $found[$category]['range'] = [
                round($scores[(int) floor($pLo / 100 * $n)], 1),
                round($scores[min($n - 1, (int) floor($pHi / 100 * $n))], 1),
            ];
        }

        $memo[$key] = $found;

        return array_replace_recursive($out, $found);
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
                            'valid_until' => $scores['overall']['valid_until'] ?? null,
                            'range' => $scores['overall']['range'] ?? null, 'coverage' => $scores['overall']['coverage'] ?? null],
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
                /* The components, and since 38.0 the range and coverage
                   (D5) beside them. */
                $inputs = $result['components'] === [] && ($result['range'] ?? null) === null ? null : json_encode(
                    $domain === 'overall'
                        ? ['range' => $result['range'] ?? null, 'coverage' => $result['coverage'] ?? null]
                        : ['components' => $result['components'], 'range' => $result['range'] ?? null,
                           'coverage' => $result['coverage'] ?? null]
                );
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
