<?php
/**
 * Ownify AI: what the assistant is told about the person asking.
 *
 * Built fresh for every question, from the person's own rows, through the
 * same readers the pages use — so the assistant sees the scores, nights,
 * workouts and goals the app shows, never an older copy and never an
 * example. Nothing here is stored.
 *
 * Only what fits the question goes along (ai_topics()): a question about
 * sleep brings the nights, the sleep score and — because training can be
 * part of the picture — a short line about training, not two weeks of meals.
 * A few small things always go: who the person is (as far as they told
 * Ownify), today's scores and their goals in one line each. The assistant
 * can ask for more through its tools (includes/ai/tools.php), which read
 * the same blocks for a longer stretch.
 *
 * Missing stays missing. A value nobody recorded is left out, and the
 * profile lists what it does not know, so the assistant has nothing to fill
 * in with a guess. Other people are never in here: the one community fact is
 * the person's own place and points.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/user.php';
require_once dirname(__DIR__) . '/health-data.php';
require_once dirname(__DIR__) . '/health-signals.php';
require_once dirname(__DIR__) . '/health-score.php';
require_once dirname(__DIR__) . '/health-totals.php';
require_once dirname(__DIR__) . '/nutrition-targets.php';
require_once dirname(__DIR__) . '/goal-progress.php';
require_once dirname(__DIR__) . '/friends.php';
require_once dirname(__DIR__, 2) . '/lib/hydrate-goals.php';
require_once dirname(__DIR__, 2) . '/lib/hydrate-community.php';

if (!function_exists('ai_topics')) {

    /**
     * Words that point at a part of the data, in Dutch and English. A word
     * ending in * is a stem ("slaap*" is also "slaapscore"); any other is
     * matched as a whole word, so "moe" (tired) is not found in "moet".
     */
    function ai_topic_words(): array
    {
        return [
            'sleep' => ['slaap*', 'slapen', 'sliep*', 'geslapen', 'nacht*', 'bedtijd*', 'naar bed', 'wakker*',
                'wektijd*', 'opstaan', 'rem-slaap', 'diepe slaap', 'dutje*', 'moe', 'vermoeid*', 'uitgerust',
                'insomnia', 'sleep*', 'slept', 'night*', 'bedtime*', 'tired', 'nap', 'naps', 'hrv',
                'rusthartslag', 'resting heart rate'],
            'nutrition' => ['voeding*', 'eten', 'eet', 'gegeten', 'maaltijd*', 'ontbijt*', 'lunch*', 'diner*',
                'avondeten', 'calorie*', 'calorieën', 'kcal', 'eiwit*', 'proteïne*', 'proteine*', 'koolhydra*',
                'vet', 'vetten', 'suiker*', 'vezel*', 'water', 'drink*', 'dieet*', 'snack*', 'macro*', 'vitamine*',
                'supplement*', 'creatine', 'nutrition', 'food', 'eat', 'eating', 'meal*', 'protein*', 'carb*',
                'diet*', 'sugar*', 'hydrat*', 'fiber*', 'fibre*'],
            'training' => ['train*', 'sport*', 'workout*', 'oefening*', 'hardlo*', 'rennen', 'renn*', 'run',
                'runs', 'running', 'fiets*', 'zwem*', 'kracht*', 'gym', 'fitness', 'cardio', 'hartslag*', 'vo2*',
                'conditie', 'km', '5k', '10k', 'marathon*', 'interval*', 'herstel*', 'recovery', 'spier*',
                'muscle*', 'exercise*', 'lift*', 'squat*', 'bench*', 'deadlift*', 'pace', 'tempo', 'belasting',
                'training load'],
            'activity' => ['stappen', 'stap', 'steps', 'beweg*', 'actief', 'actieve', 'activiteit*', 'activity',
                'wandel*', 'walk*', 'lopen', 'trap', 'traplopen', 'floors', 'zitten', 'sedentary'],
            'body' => ['gewicht*', 'weeg*', 'woog', 'kilo*', 'kg', 'afval*', 'af te vallen', 'aankom*',
                'aan te komen', 'bmi', 'vetpercentage', 'taille', 'lengte', 'weight*', 'body fat', 'lean mass',
                'lichaam*'],
            'goals' => ['doel*', 'streak*', 'mijlpaal*', 'voortgang', 'progress', 'goal*', 'target*', 'behalen',
                'halen', 'volhouden'],
            'score' => ['score*', 'gezondheid*', 'health', 'hoe gaat het', 'hoe sta ik', 'hoe was', 'overzicht',
                'samenvatting', 'trend*', 'patroon*', 'patronen', 'verloop', 'deze week', 'vorige week',
                'this week', 'last week', 'summary', 'overview', 'pattern*', 'verander*', 'change*'],
            'community' => ['ranglijst*', 'ranking', 'positie', 'punten', 'vrienden', 'leaderboard*', 'points',
                'friends'],
        ];
    }

    /**
     * Which parts of the data a question is about. A follow-up with no words
     * of its own ("en vorige maand?"), or one that points back ("hoe kan
     * dat?"), is also about what the question before it was about. Nothing
     * recognisable: 'overview'.
     *
     * @return string[]
     */
    function ai_topics(string $message, ?string $previous = null): array
    {
        $found = ai_topics_in($message);

        /* "En hoe zit het daarmee vergeleken met vorige week?" is still about
           what came before: a short question that points back brings the
           previous question's topics along with its own. */
        $pointsBack = mb_strlen($message) <= 100
            && preg_match('/(?<![\p{L}])(dat|daarmee|daarvan|daarbij|daar|die|dit|deze|het|hiervan|hiermee|ervan|that|this|it|those|these)(?![\p{L}])/iu', $message) === 1;

        if ($previous !== null && ($found === [] || $pointsBack)) {
            $found = array_values(array_unique([...$found, ...ai_topics_in($previous)]));
        }

        return $found === [] ? ['overview'] : $found;
    }

    /** @return string[] */
    function ai_topics_in(string $text): array
    {
        $text  = mb_strtolower($text);
        $found = [];

        foreach (ai_topic_words() as $topic => $words) {
            foreach ($words as $word) {
                $stem    = str_ends_with($word, '*');
                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote(rtrim($word, '*'), '/') . ($stem ? '' : '(?![\p{L}\p{N}])') . '/u';

                if (preg_match($pattern, $text) === 1) {
                    $found[] = $topic;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The context for one question: always the profile, today's scores and
     * the goals in brief; the rest by topic.
     *
     * @param string[] $topics from ai_topics()
     * @return array<string, mixed>  block name => data
     */
    function ai_context(int $userId, array $topics): array
    {
        $has = static fn (string ...$any): bool => array_intersect($any, $topics) !== [];

        $context = [
            'now'     => ai_now_label(),
            'profile' => ai_block_profile($userId),
            'scores'  => ai_block_scores($userId, $has('score', 'overview') ? 28 : 0),
        ];

        /* A question about one part, or about everything, gets the last two
           weeks side by side: the cheapest way to see what changed. */
        $context['last_7_days_vs_previous_7'] = ai_block_week($userId);

        if ($has('sleep')) {
            $context['sleep'] = ai_block_sleep($userId, 14);
        }
        if ($has('training')) {
            $context['training'] = ai_block_training($userId, 28);
        }
        if ($has('activity') || ($has('training', 'body') && !$has('activity'))) {
            $context['activity'] = ai_block_activity($userId, 14);
        }
        if ($has('nutrition') || ($has('body') && !$has('nutrition'))) {
            $context['nutrition'] = ai_block_nutrition($userId, $has('nutrition') ? 14 : 7);
        }
        if ($has('body')) {
            $context['body'] = ai_block_body($userId, 30);
        }

        $context['goals'] = ai_block_goals($userId, $has('goals'));

        if ($has('community')) {
            $context['community'] = ai_block_community($userId);
        }

        return $context;
    }

    /* ============================================================ helpers */

    function ai_now_label(): string
    {
        $days = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];

        return $days[(int) date('w')] . ' ' . date('Y-m-d H:i') . ' (' . date_default_timezone_get() . ')';
    }

    function ai_day_label(string $date): string
    {
        $days = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
        $ts   = strtotime($date);

        return $ts === false ? $date : $date . ' ' . $days[(int) date('w', $ts)];
    }

    /** $days days ending today: [from, to] as Y-m-d. */
    function ai_range(int $days, int $endOffset = 0): array
    {
        $to   = new DateTimeImmutable('today -' . $endOffset . ' days');
        $from = $to->modify('-' . max(0, $days - 1) . ' days');

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    function ai_round(?float $value, int $decimals = 0): int|float|null
    {
        if ($value === null) {
            return null;
        }

        return $decimals === 0 ? (int) round($value) : round($value, $decimals);
    }

    function ai_clock(?float $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        $m = ((int) round($minutes)) % 1440;

        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    function ai_avg(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn ($v) => $v !== null));

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** A daily series (date => value) rounded, and only the days that have one. */
    function ai_series(int $userId, string $code, string $from, string $to, int $decimals = 0): array
    {
        $out = [];

        foreach (health_metric_series($userId, $code, $from, $to) as $day => $value) {
            $out[ai_day_label((string) $day)] = ai_round((float) $value, $decimals);
        }

        return $out;
    }

    /* ============================================================= blocks */

    /**
     * Who the person is, as far as they told Ownify. What they did not tell
     * is listed under `unknown`, so it is not guessed.
     */
    function ai_block_profile(int $userId): array
    {
        $account = user_account($userId);

        if ($account === null) {
            return ['unknown' => ['everything']];
        }

        $profile = [];
        $unknown = [];

        $put = static function (string $key, $value) use (&$profile, &$unknown): void {
            if ($value === null || $value === '') {
                $unknown[] = $key;
            } else {
                $profile[$key] = $value;
            }
        };

        $put('first_name', $account['first_name'] ?? null);
        $put('age', $account['age'] ?? null);
        $put('gender', in_array($account['gender'] ?? null, ['female', 'male', 'non_binary', 'other'], true) ? $account['gender'] : null);
        $put('height_cm', isset($account['height']['value']) && $account['height']['unit'] === 'cm' ? ai_round((float) $account['height']['value']) : null);

        $weight = $account['weight'] ?? null;
        $put('weight_kg', $weight !== null && $weight['unit'] === 'kg' ? ai_round((float) $weight['value'], 1) : null);
        if ($weight !== null) {
            $profile['weight_measured_on'] = substr((string) $weight['measured_at'], 0, 10);
        }

        $put('activity_level', $account['activity_level'] ?? null);
        $profile['member_since'] = substr((string) $account['created_at'], 0, 10);
        $profile['language']     = (string) ($account['locale'] ?: 'nl');
        $profile['unknown']      = $unknown;

        return $profile;
    }

    /**
     * The Health Score as the app shows it: now, per category with the days
     * it rests on and its parts, and — when asked about — how it moved.
     */
    function ai_block_scores(int $userId, int $trendDays): array
    {
        $now = health_score_now($userId);

        $block = [
            'about'   => 'Scores over the last 168 hours (7 days), 0-100; a category needs at least '
                . (int) health_scoring_config()['min_days'] . ' days of data in that window, and sleep and nutrition'
                . ' stop counting after ' . (int) (health_scoring_config()['expiry_days']['sleep'] ?? 3)
                . ' days without new data (left out, never zero). Components are 0-100'
                . ' sub-scores. The trend is the score as it was recorded each day.',
            'overall' => $now['overall']['score'],
        ];

        foreach (['sleep', 'nutrition', 'training'] as $area) {
            $block[$area] = [
                'score'      => $now[$area]['score'],
                'days_of_data' => $now[$area]['days'],
                'components' => array_filter($now[$area]['components'], static fn ($v) => $v !== null),
            ];
        }

        if ($trendDays > 0) {
            $trend  = health_score_trend($userId, $trendDays);
            $dates  = array_keys($trend);
            $picked = [];

            /* One point a week, and the last few days in full: enough to see
               the line move without sending every day. */
            foreach ($dates as $i => $date) {
                $fromEnd = count($dates) - 1 - $i;
                if ($fromEnd < 7 || $fromEnd % 7 === 0) {
                    $picked[ai_day_label($date)] = array_filter($trend[$date], static fn ($v) => $v !== null);
                }
            }

            $block['trend'] = $picked;
        }

        return $block;
    }

    /**
     * The last seven days next to the seven before, across everything: the
     * shortest honest answer to "what changed?".
     */
    function ai_block_week(int $userId): array
    {
        $weeks = ['last_7_days' => ai_range(7), 'previous_7_days' => ai_range(7, 7)];
        $out   = [];

        foreach ($weeks as $label => [$from, $to]) {
            $nights   = health_nights_on($userId, $from, $to);
            $minutes  = array_map(static fn (array $n): float => (float) $n['minutes'], $nights);
            [$counted] = health_workouts_counted(health_workout_records($userId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'));
            $steps    = health_metric_totals($userId, 'steps', $from, $to);
            $ratings  = health_metric_series($userId, 'nutrition_rating', $from, $to);
            $energy   = health_metric_series($userId, 'energy', $from, $to);

            $out[$label] = array_filter([
                'dates'               => $from . ' – ' . $to,
                'nights_recorded'     => count($nights),
                'avg_sleep_minutes'   => ai_round(ai_avg($minutes)),
                'workouts'            => count($counted),
                'workout_minutes'     => ai_round(array_sum(array_map(static fn (array $w): float => (float) ($w['minutes'] ?? 0), $counted))),
                'days_with_steps'     => count($steps),
                'avg_steps'           => ai_round(ai_avg(array_values($steps))),
                'nutrition_days_rated' => count($ratings),
                'avg_nutrition_rating_of_10' => ai_round(ai_avg(array_values($ratings)), 1),
                'avg_energy_kcal_logged' => ai_round(ai_avg(array_values($energy))),
            ], static fn ($v) => $v !== null);
        }

        return $out;
    }

    /** Nights, their averages and the sleep-time measurements, for $days days. */
    function ai_block_sleep(int $userId, int $days): array
    {
        $days = max(1, min($days, 90));
        [$from, $to] = ai_range($days);

        $nights = [];
        $recent = [];
        $before = [];
        $cutoff = (new DateTimeImmutable('today -6 days'))->format('Y-m-d');

        foreach (health_nights_on($userId, $from, $to) as $date => $night) {
            $nights[] = array_filter([
                'night_of'        => ai_day_label((string) $date),
                'asleep_minutes'  => ai_round($night['minutes']),
                'in_bed_minutes'  => ai_round($night['in_bed']),
                'bedtime'         => date('H:i', $night['start']),
                'wake_time'       => date('H:i', $night['end']),
                'efficiency_pct'  => ai_round($night['efficiency']),
                'awakenings'      => ai_round($night['awakenings']),
                'awake_minutes'   => ai_round($night['awake']),
                'deep_minutes'    => ai_round($night['deep']),
                'rem_minutes'     => ai_round($night['rem']),
                'light_minutes'   => ai_round($night['light']),
            ], static fn ($v) => $v !== null);

            if ((string) $date >= $cutoff) {
                $recent[] = $night;
            } else {
                $before[] = $night;
            }
        }

        $summary = static function (array $set): ?array {
            if ($set === []) {
                return null;
            }

            $bed  = array_map(static fn (array $n): float => health_clock_minutes($n['start']), $set);
            $wake = array_map(static fn (array $n): float => health_clock_minutes($n['end']), $set);

            return array_filter([
                'nights'               => count($set),
                'avg_asleep_minutes'   => ai_round(ai_avg(array_map(static fn (array $n): float => (float) $n['minutes'], $set))),
                'avg_bedtime'          => ai_clock(health_clock_mean($bed)),
                'avg_wake_time'        => ai_clock(health_clock_mean($wake)),
                'bedtime_spread_minutes' => ai_round(health_clock_sd($bed)),
                'avg_efficiency_pct'   => ai_round(ai_avg(array_map(static fn (array $n) => $n['efficiency'], $set))),
            ], static fn ($v) => $v !== null);
        };

        $block = [
            'days_covered' => $from . ' – ' . $to,
            'nights'       => $nights,
        ];

        if ($days >= 8) {
            $block['last_7_nights']      = $summary($recent);
            $block['nights_before_that'] = $summary($before);
        }

        foreach (['sleeping_hr' => 0, 'resting_hr' => 0, 'hrv' => 0, 'respiratory_rate' => 1, 'sleep_regularity' => 0, 'spo2' => 0] as $code => $decimals) {
            $series = ai_series($userId, $code, $from, $to, $decimals);
            if ($series !== []) {
                $block['measurements'][$code] = $series;
            }
        }

        if ($nights === []) {
            $block['note'] = 'No nights recorded in this period.';
        }

        return $block;
    }

    /** Workouts, week by week, and the fitness measurements, for $days days. */
    function ai_block_training(int $userId, int $days): array
    {
        $days = max(1, min($days, 90));
        [$from, $to] = ai_range($days);

        $records = health_workout_records($userId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00');
        [$counted] = health_workouts_counted($records);
        $countedIds = array_flip(array_map(static fn (array $w): int => $w['id'], $counted));

        $list = [];
        foreach (array_slice($records, -40) as $w) {
            $list[] = array_filter([
                'date'        => ai_day_label(date('Y-m-d', $w['start'])),
                'start'       => date('H:i', $w['start']),
                'type'        => health_activity_label($w['type']),
                'minutes'     => ai_round($w['minutes']),
                'km'          => ai_round($w['km'], 2),
                'avg_kmh'     => ai_round($w['kmh'], 1),
                'avg_hr'      => ai_round($w['avg_hr']),
                'max_hr'      => ai_round($w['max_hr']),
                'effort_of_10' => ai_round($w['rpe'], 1),
                'counts_for_score' => isset($countedIds[$w['id']]),
            ], static fn ($v) => $v !== null);
        }

        $weeks = [];
        foreach ($counted as $w) {
            $week = date('o-\WW', $w['start']);
            $weeks[$week]['workouts'] = ($weeks[$week]['workouts'] ?? 0) + 1;
            $weeks[$week]['minutes']  = ($weeks[$week]['minutes'] ?? 0) + (int) round((float) ($w['minutes'] ?? 0));
            if ($w['km'] !== null) {
                $weeks[$week]['km'] = round(($weeks[$week]['km'] ?? 0) + $w['km'], 1);
            }
        }

        $block = [
            'days_covered' => $from . ' – ' . $to,
            'workouts'     => $list,
            'per_week'     => $weeks,
            'note'         => 'counts_for_score is false for very short sessions and for a second recording of the same session.',
        ];

        foreach (['vo2max' => 1, 'readiness' => 0, 'training_load' => 0] as $code => $decimals) {
            $series = ai_series($userId, $code, $from, $to, $decimals);
            if ($series !== []) {
                $block['measurements'][$code] = $series;
            }
        }

        if ($records === []) {
            $block['note'] = 'No workouts recorded in this period.';
        }

        return $block;
    }

    /** Steps and everyday movement, day by day, for $days days. */
    function ai_block_activity(int $userId, int $days): array
    {
        $days = max(1, min($days, 90));
        [$from, $to] = ai_range($days);

        $block = ['days_covered' => $from . ' – ' . $to];

        foreach (['steps' => 0, 'distance' => 1, 'active_minutes' => 0, 'active_energy' => 0, 'floors' => 0] as $code => $decimals) {
            $series = ai_series($userId, $code, $from, $to, $decimals);
            if ($series !== []) {
                $block['per_day'][$code] = $series;
                $block['average_per_day_with_data'][$code] = ai_round(ai_avg(array_values($series)), $decimals);
            }
        }

        if (!isset($block['per_day'])) {
            $block['note'] = 'No steps or movement recorded in this period.';
        }

        return $block;
    }

    /** What was eaten and rated, day by day, and the person's targets. */
    function ai_block_nutrition(int $userId, int $days): array
    {
        $days = max(1, min($days, 60));
        [$from, $to] = ai_range($days);

        $block = ['days_covered' => $from . ' – ' . $to];

        foreach ([
            'nutrition_rating' => 1, 'energy' => 0, 'protein' => 0, 'carbs' => 0, 'fat' => 0,
            'fibre' => 0, 'sugar' => 0, 'water' => 1,
        ] as $code => $decimals) {
            $series = ai_series($userId, $code, $from, $to, $decimals);
            if ($series !== []) {
                $block['per_day'][$code] = $series;
            }
        }

        $meals = db_all(
            'SELECT DATE(consumed_at) AS day, COUNT(*) AS n FROM nutrition_entries
              WHERE user_id = ? AND consumed_at >= ? AND consumed_at < ?
           GROUP BY DATE(consumed_at) ORDER BY day',
            [$userId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        );
        foreach ($meals as $row) {
            $block['per_day']['meals_logged'][ai_day_label((string) $row['day'])] = (int) $row['n'];
        }

        $block['about_rating'] = 'nutrition_rating is the person\'s own daily rating of their eating, 1-10; the nutrition score is built from it.';

        $targets = nutrition_targets_for_user($userId);
        if ($targets !== null) {
            $block['targets'] = array_filter($targets['targets'], static fn ($v) => $v !== null);
            if ($targets['missing'] !== []) {
                $block['targets_cannot_be_worked_out_without'] = $targets['missing'];
            }
            $block['targets_based_on'] = 'Mifflin-St Jeor with the profile\'s activity level; the primary weight goal sets deficit or surplus.';
        }

        if (!isset($block['per_day'])) {
            $block['note'] = 'Nothing eaten or rated has been recorded in this period.';
        }

        return $block;
    }

    /** Body measurements, newest first. */
    function ai_block_body(int $userId, int $limit): array
    {
        $block = [];

        foreach (['weight' => $limit, 'body_fat_pct' => 10, 'waist_cm' => 10, 'lean_mass' => 10, 'height' => 1] as $type => $n) {
            $rows = user_measurement_history($userId, $type, $n);
            if ($rows === []) {
                continue;
            }

            $block[$type] = array_map(static fn (array $r): array => [
                'date'  => substr((string) $r['measured_at'], 0, 10),
                'value' => ai_round((float) $r['value'], 1),
                'unit'  => $r['unit'],
            ], $rows);
        }

        return $block === [] ? ['note' => 'No body measurements recorded.'] : $block;
    }

    /**
     * The goals as the Doelen page shows them, worked out now. In brief
     * unless the question is about goals; completed ones then too.
     */
    function ai_block_goals(int $userId, bool $detail): array
    {
        $config = require dirname(__DIR__, 2) . '/config/goals.php';
        $goals  = hydrate_goals($config, $userId)['goals'] ?? [];
        $out    = [];

        foreach ($goals as $goal) {
            $completed = $goal['status'] === 'completed';

            if ($completed && (!$detail || count(array_filter($out, static fn ($g) => ($g['status'] ?? '') === 'completed')) >= 5)) {
                continue;
            }

            $line = [
                'goal_id'  => (int) $goal['id'],
                'name'     => $goal['name'],
                'type'     => $goal['type'],
                'status'   => $goal['status'],
                'priority' => $goal['priority'],
                'percent'  => $goal['percent'],
                'current'  => $goal['current_label'],
                'target'   => $goal['target_label'],
            ];

            if ($detail) {
                $line += array_filter([
                    'category'     => $config['categories'][$goal['category']]['label'] ?? null,
                    'tracked_from' => $goal['tracking'] === 'auto' ? ($goal['source_label'] ?? null) : 'kept by hand',
                    'direction'    => $goal['type'] === 'milestone' ? ($goal['direction'] === 'decrease' ? 'lower is better' : 'higher is better') : null,
                    'a_day_counts_when' => $goal['daily_label'] ?? null,
                    'days_met'     => $goal['counts_days'] ? $goal['days_met'] : null,
                    'days_total'   => $goal['counts_days'] ? $goal['days_total'] : null,
                    'started_days_ago' => $goal['started_days_ago'],
                    'ends_in_days' => $goal['ends_in_days'],
                    'duration'     => $goal['duration'],
                    'completed_days_ago' => $goal['completed_days_ago'],
                    'facts'        => $goal['extra_facts'] ?: null,
                ], static fn ($v) => $v !== null);
            }

            $out[] = array_filter($line, static fn ($v) => $v !== null);
        }

        return $out === [] ? ['note' => 'No goals yet.'] : $out;
    }

    /** The person's own place and points — nobody else's. */
    function ai_block_community(int $userId): array
    {
        $block     = ['friends' => count(friend_ids($userId))];
        $community = require dirname(__DIR__, 2) . '/config/community.php';

        /* The boards' own scopes (friends, netherlands), so the place is the
           one the Community page shows — outside the top 50 included. */
        foreach ($community['scopes'] as $scope => $scopeConfig) {
            foreach (['month', 'alltime'] as $period) {
                $board = hydrate_community_board($userId, $scope, $period, (int) $scopeConfig['limit']);
                $block['your_place'][$scope . '_' . $period] = $board['you'];
            }
        }

        $block['about'] = 'Points come from logging sleep, workouts, steps and rating nutrition; the rank is among friends or everybody.';

        return $block;
    }

    /** Whether the person has recorded anything at all yet. */
    function ai_has_data(int $userId): bool
    {
        foreach (['sleep_sessions', 'workouts', 'health_metrics', 'user_measurements', 'goals'] as $table) {
            if (db_value('SELECT 1 FROM ' . $table . ' WHERE user_id = ? LIMIT 1', [$userId]) !== null) {
                return true;
            }
        }

        return false;
    }
}
