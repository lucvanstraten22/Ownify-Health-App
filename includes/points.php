<?php
/**
 * Leaderboard points — what somebody did, paid out once.
 *
 * ---------------------------------------------------------------------------
 * WHAT EARNS POINTS
 * ---------------------------------------------------------------------------
 * Actions and achievements, the moment they are recorded: a night's sleep, a
 * day's nutrition rating, a workout (and whether it was intense, and whether
 * it was a personal record), a day's steps, and three workouts in one week.
 * Every value and threshold is in config/points.php.
 *
 * NOT the Health Score. Nothing in this file reads a score, and nothing the
 * score engine does pays out. Both read the records through
 * includes/health-signals.php, so they agree about what a workout or a night
 * is, and that is all they share.
 *
 * ---------------------------------------------------------------------------
 * ONE EVENT, ONE AWARD
 * ---------------------------------------------------------------------------
 * Every award is keyed by the event it pays for and the rule it follows —
 *
 *     sleep_duration:2026-09-25        a night (by the date it ended)
 *     nutrition_rating:2026-09-25      a day's rating
 *     workout:123, workout_record:123  a workout
 *     steps:2026-09-25                 a day's steps
 *     weekly_workouts:2026-09-21       a week (by its first day)
 *
 * — and point_events holds that key UNIQUE per person. Syncing the same
 * workout three times, refreshing, submitting twice: it is the same key, so
 * it is the same row. When the data behind an award changes (more steps
 * arrive, a night is corrected) the award is evaluated again and the row
 * says what it is now worth; an event that no longer earns anything loses
 * its row. The same session recorded twice by two apps is one workout (see
 * health_workouts_counted()), and a night is its main sleep, so duplicates
 * in the data are not duplicates on the board either.
 *
 * ---------------------------------------------------------------------------
 * WHEN A POINT COUNTS
 * ---------------------------------------------------------------------------
 * `awarded_at` is when the activity happened — the end of the night, the
 * start of the workout, the last step count of the day — so the points land
 * in the month the effort was made. The rollups the boards read are rebuilt
 * as soon as an award changes. Activity from before the account existed
 * earns nothing (config: award_from).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-signals.php';
require_once __DIR__ . '/leaderboard.php';

if (!function_exists('points_process')) {

    function points_config(): array
    {
        static $config = null;

        return $config ??= (array) require dirname(__DIR__) . '/config/points.php';
    }

    /**
     * Whether the ledger can tell awards apart yet (migration 010). Without
     * that, awarding could pay the same event twice, so nothing is awarded.
     */
    function points_available(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        if (!db_available()) {
            return $available = false;
        }

        $available = db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'point_events' AND column_name = 'award_key'"
        ) > 0;

        if (!$available) {
            error_log('[ownify] points: point_events has no award_key yet, so nothing is awarded — '
                . 'import database/migrations/010-health-score-and-points.sql');
        }

        return $available;
    }

    /* ==================================================================
       THE ONE WAY IN
       ================================================================== */

    /**
     * Awards what the given records earn, and takes back what they no longer
     * earn. Safe to call as often as anybody likes for the same records.
     *
     * @param array{nights?: string[], workouts?: int[], nutrition_days?: string[], step_days?: string[]} $touched
     *        nights and days as Y-m-d, workouts by id — what was just saved
     * @return array<int,array> the awards that changed, oldest first
     */
    function points_process(int $userId, array $touched, ?DateTimeImmutable $now = null): array
    {
        if (!points_available()) {
            return [];
        }

        $ctx = points_context($userId, $now ?? new DateTimeImmutable('now'));
        $changes = [];

        foreach (array_unique(array_map('strval', $touched['nights'] ?? [])) as $date) {
            array_push($changes, ...points_for_night($ctx, $date));
        }

        if (!empty($touched['workouts'])) {
            array_push($changes, ...points_for_workouts($ctx, array_unique(array_map('intval', $touched['workouts']))));
        }

        foreach (array_unique(array_map('strval', $touched['nutrition_days'] ?? [])) as $date) {
            array_push($changes, ...points_for_nutrition_day($ctx, $date));
        }

        foreach (array_unique(array_map('strval', $touched['step_days'] ?? [])) as $date) {
            array_push($changes, ...points_for_steps_day($ctx, $date));
        }

        usort($changes, static fn ($a, $b) => strcmp($a['awarded_at'], $b['awarded_at']));

        points_refresh_boards($changes);

        return $changes;
    }

    /** What every evaluation needs to know about the person and the moment. */
    function points_context(int $userId, DateTimeImmutable $now): array
    {
        $from = null;

        if (points_config()['award_from'] === 'account_created') {
            $created = db_value('SELECT created_at FROM users WHERE id = ?', [$userId]);
            $from    = is_string($created) ? substr($created, 0, 10) : null;
        }

        return [
            'user'   => $userId,
            'now'    => $now->getTimestamp(),
            'from'   => $from,
            'future' => $now->getTimestamp() + 60 * (int) points_config()['future_tolerance_minutes'],
        ];
    }

    /**
     * Whether activity at this moment may earn points: 'yes', 'early'
     * (before the account existed — it earns nothing, so any award goes) or
     * 'future' (a clock that is wrong — left alone until it is not).
     */
    function points_eligibility(array $ctx, int $at): string
    {
        if ($at > $ctx['future']) {
            return 'future';
        }

        if ($ctx['from'] !== null && date('Y-m-d', $at) < $ctx['from']) {
            return 'early';
        }

        return 'yes';
    }

    /* ==================================================================
       SLEEP — one award per night, by the date the night ended
       ================================================================== */

    function points_for_night(array $ctx, string $date): array
    {
        $cfg      = points_config()['sleep'];
        $lookback = (int) $cfg['regularity']['lookback_nights'];

        try {
            $day = new DateTimeImmutable($date);
        } catch (Exception $e) {
            return [];
        }

        $nights = health_nights(
            $ctx['user'],
            $day->modify('-' . ($lookback + 2) . ' days')->format('Y-m-d 00:00:00'),
            $day->modify('+2 days')->format('Y-m-d 00:00:00')
        );

        $night = null;
        $prior = [];

        foreach ($nights as $candidate) {
            if ($candidate['date'] === $date) {
                $night = $candidate;
            } elseif ($candidate['date'] < $date) {
                $prior[] = $candidate;
            }
        }

        $keys = [
            'sleep_duration'   => 'sleep_duration:' . $date,
            'sleep_regularity' => 'sleep_regularity:' . $date,
            'sleep_quality'    => 'sleep_quality:' . $date,
        ];

        /* No night under this date any more: nothing of it earns anything. */
        if ($night === null) {
            return points_remove($ctx, $keys);
        }

        $eligibility = points_eligibility($ctx, $night['end']);

        if ($eligibility === 'future') {
            return [];
        }

        if ($eligibility === 'early') {
            return points_remove($ctx, $keys);
        }

        $at  = date('Y-m-d H:i:s', $night['end']);
        $ref = ['sleep_session', $night['session_id']];
        $changes = [];

        /* Duration: the one tier the night reached. */
        $minutes  = (int) round($night['minutes']);
        $duration = points_tier_range($cfg['duration_tiers'], $minutes);
        $changes[] = points_set($ctx, $keys['sleep_duration'], 'sleep_duration', $duration, $at, $ref,
            sprintf('%d:%02d geslapen', intdiv($minutes, 60), $minutes % 60));

        /* Regularity: in bed and up again around the usual times. */
        $prior = array_slice($prior, -$lookback);
        $reg   = $cfg['regularity'];
        $regular = 0;

        if (count($prior) >= (int) $reg['min_prior_nights']) {
            $bed  = health_clock_mean(array_map(static fn ($n) => health_clock_minutes($n['start']), $prior));
            $wake = health_clock_mean(array_map(static fn ($n) => health_clock_minutes($n['end']), $prior));

            if (health_clock_distance(health_clock_minutes($night['start']), $bed) <= $reg['window_minutes']
                && health_clock_distance(health_clock_minutes($night['end']), $wake) <= $reg['window_minutes']) {
                $regular = (int) $reg['points'];
            }
        }

        $changes[] = points_set($ctx, $keys['sleep_regularity'], 'sleep_regularity', $regular, $at, $ref,
            'Naar bed en op rond je vaste tijden');

        /* Quality: the night's own measurements, if it has any. */
        $quality = health_night_quality($night);
        $good    = ($quality !== null && $quality >= $cfg['quality']['min_score']) ? (int) $cfg['quality']['points'] : 0;

        $changes[] = points_set($ctx, $keys['sleep_quality'], 'sleep_quality', $good, $at, $ref,
            $quality === null ? null : 'Slaapkwaliteit ' . (int) round($quality) . '/100');

        return array_values(array_filter($changes));
    }

    /* ==================================================================
       NUTRITION — one award per day, for that day's rating
       ================================================================== */

    function points_for_nutrition_day(array $ctx, string $date): array
    {
        $key  = 'nutrition_rating:' . $date;
        $type = health_metric_type_id('nutrition_rating');

        $row = $type === null ? null : db_one(
            'SELECT AVG(value) AS rating, MAX(recorded_at) AS at, COUNT(*) AS n
               FROM health_metrics
              WHERE user_id = ? AND metric_type_id = ? AND recorded_on = ?',
            [$ctx['user'], $type, $date]
        );

        if ($row === null || (int) $row['n'] === 0) {
            return points_remove($ctx, ['nutrition_rating' => $key]);
        }

        $at = (int) strtotime((string) $row['at']);
        $eligibility = points_eligibility($ctx, $at);

        if ($eligibility === 'future') {
            return [];
        }

        if ($eligibility === 'early') {
            return points_remove($ctx, ['nutrition_rating' => $key]);
        }

        $rating = (int) round((float) $row['rating']);
        $points = points_nutrition_value($rating);

        $change = points_set($ctx, $key, 'nutrition_rating', $points, date('Y-m-d H:i:s', $at),
            ['day', null], 'Cijfer ' . $rating);

        return $change === null ? [] : [$change];
    }

    /* ==================================================================
       STEPS — one award per day, the highest tier reached
       ================================================================== */

    function points_for_steps_day(array $ctx, string $date): array
    {
        $key = 'steps:' . $date;

        /* The day's steps as every page shows them: a walk that the phone and
           a watch both counted is paid once (health_metric_totals()). */
        $total = health_metric_totals($ctx['user'], 'steps', $date, $date)[$date] ?? null;

        if ($total === null) {
            return points_remove($ctx, ['steps' => $key]);
        }

        /* When the day's steps were in: its last reading, or the end of the
           day when all it has is a walk that ran on past midnight. */
        $last = db_value(
            'SELECT MAX(recorded_at) FROM health_metrics
              WHERE user_id = ? AND metric_type_id = ? AND recorded_on = ?',
            [$ctx['user'], health_metric_type_id('steps'), $date]
        );

        $at = (int) strtotime($last !== null ? (string) $last : $date . ' 23:59:59');
        $eligibility = points_eligibility($ctx, $at);

        if ($eligibility === 'future') {
            return [];
        }

        if ($eligibility === 'early') {
            return points_remove($ctx, ['steps' => $key]);
        }

        $steps  = (int) round($total);
        $points = points_steps_value($steps);

        $change = points_set($ctx, $key, 'steps', $points, date('Y-m-d H:i:s', $at),
            ['day', null], number_format($steps, 0, ',', '.') . ' stappen');

        return $change === null ? [] : [$change];
    }

    /* ==================================================================
       WORKOUTS — per workout, and the week it belongs to
       ================================================================== */

    /**
     * Evaluates the given workouts, every recording that overlaps them (a
     * duplicate arriving can change which recording counts), and the weeks
     * they fall in.
     *
     * @param int[] $ids
     */
    function points_for_workouts(array $ctx, array $ids): array
    {
        $all = health_workout_records($ctx['user']);
        [$kept, $dropped] = health_workouts_counted($all);

        $byId   = [];
        $counts = [];

        foreach ($all as $workout) {
            $byId[$workout['id']] = $workout;
        }

        foreach ($kept as $workout) {
            $counts[$workout['id']] = true;
        }

        $hrMax   = health_hr_max($ctx['user'], $kept);
        $targets = [];
        $changes = [];
        $weeks   = [];

        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                /* Gone from the records: nothing of it is worth anything. */
                array_push($changes, ...points_remove($ctx, points_workout_keys($id)));
                continue;
            }

            $targets[$id] = true;

            foreach ($all as $other) {
                if (min($byId[$id]['end'], $other['end']) > max($byId[$id]['start'], $other['start'])) {
                    $targets[$other['id']] = true;
                }
            }
        }

        foreach (array_keys($targets) as $id) {
            $workout = $byId[$id];
            $weeks[points_week_start($ctx['user'], $workout['start'])] = true;

            if (!isset($counts[$id])) {
                /* Too short, too long, incomplete, or a second recording of a
                   session that already counts. */
                array_push($changes, ...points_remove($ctx, points_workout_keys($id)));
                continue;
            }

            array_push($changes, ...points_for_workout($ctx, $workout, $kept, $hrMax));
        }

        foreach (array_keys($weeks) as $weekStart) {
            $change = points_for_week($ctx, (string) $weekStart, $kept);

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /** @return array<string,string> rule => key */
    function points_workout_keys(int $id): array
    {
        return [
            'workout'           => 'workout:' . $id,
            'workout_intensity' => 'workout_intensity:' . $id,
            'workout_record'    => 'workout_record:' . $id,
        ];
    }

    /** One counted workout: its duration tier, its intensity, a record. */
    function points_for_workout(array $ctx, array $workout, array $kept, ?float $hrMax): array
    {
        $keys = points_workout_keys($workout['id']);
        $eligibility = points_eligibility($ctx, $workout['start']);

        if ($eligibility === 'future') {
            return [];
        }

        if ($eligibility === 'early') {
            return points_remove($ctx, $keys);
        }

        $cfg     = points_config()['training'];
        $at      = date('Y-m-d H:i:s', $workout['start']);
        $ref     = ['workout', $workout['id']];
        $minutes = (int) round($workout['minutes']);
        $what    = health_activity_label($workout['type']);
        $changes = [];

        /* Duration: short, normal or long — one of them. */
        $base  = 0;
        $label = null;
        foreach ($cfg['duration_tiers'] as [$from, $to, $value, $name]) {
            if ($minutes >= $from && ($to === null || $minutes < $to)) {
                $base  = (int) $value;
                $label = (string) $name;
            }
        }

        $changes[] = points_set($ctx, $keys['workout'], 'workout', $base, $at, $ref,
            $minutes . ' min ' . $what, $label);

        /* Intensity: hard, or very hard. */
        $effort   = health_workout_effort($workout, $hrMax);
        $int      = $cfg['intensity'];
        $bonus    = 0;
        $severity = null;

        if ($effort['class'] === 'hard') {
            $veryHard = ($effort['rpe'] !== null && $effort['rpe'] >= $int['very_hard_rpe'])
                || ($effort['zone_share'] !== null && $effort['zone_share'] >= $int['very_hard_zone_share'])
                || ($effort['hr_pct'] !== null && $effort['hr_pct'] >= $int['very_hard_hr_pct']);

            $bonus    = (int) ($veryHard ? $int['very_hard'] : $int['hard']);
            $severity = $veryHard ? 'Zeer intensief' : 'Intensief';
        }

        $changes[] = points_set($ctx, $keys['workout_intensity'], 'workout_intensity', $bonus, $at, $ref, $severity);

        /* A personal record, against this person's own earlier workouts of
           the same kind. */
        $record = points_workout_record($workout, $kept);

        $changes[] = points_set($ctx, $keys['workout_record'], 'workout_record',
            $record === null ? 0 : (int) $cfg['record']['points'], $at, $ref, $record);

        return array_values(array_filter($changes));
    }

    /**
     * Whether a workout beat the person's own best for that kind of activity
     * clearly: the longest distance, or the fastest pace over at least a
     * comparable distance. Needs a few earlier workouts to compare with — the
     * first run is not a record. Returns what the record was, or null.
     */
    function points_workout_record(array $workout, array $kept): ?string
    {
        $r = points_config()['training']['record'];

        if ($workout['km'] === null || $workout['km'] < $r['min_km']) {
            return null;
        }

        $history = array_values(array_filter($kept, static fn ($w) => $w['type'] === $workout['type']
            && $w['start'] < $workout['start']
            && $w['km'] !== null && $w['km'] >= $r['min_km']));

        if (count($history) < (int) $r['min_history']) {
            return null;
        }

        $km = number_format($workout['km'], 1, ',', '.');

        if ($workout['km'] > max(array_column($history, 'km')) * (1 + $r['distance_margin'])) {
            return 'Langste afstand: ' . $km . ' km';
        }

        $comparable = array_filter($history, static fn ($w) => $w['kmh'] !== null
            && $w['km'] >= $r['comparable_share'] * $workout['km']);

        if ($workout['kmh'] !== null && $comparable !== []
            && $workout['kmh'] > max(array_column($comparable, 'kmh')) * (1 + $r['speed_margin'])) {
            return 'Snelste tempo over ' . $km . ' km';
        }

        return null;
    }

    /**
     * The weekly consistency bonus: awarded the moment the week's count of
     * qualifying workouts reaches the target — once per week — and dated at
     * the workout that reached it.
     */
    function points_for_week(array $ctx, string $weekStart, array $kept): ?array
    {
        $cfg   = points_config()['training']['weekly'];
        $start = strtotime($weekStart . ' 00:00:00');
        $end   = strtotime((new DateTimeImmutable($weekStart))->modify('+7 days')->format('Y-m-d 00:00:00'));
        $key   = 'weekly_workouts:' . $weekStart;

        $count   = 0;
        $days    = [];
        $reached = null;

        foreach ($kept as $workout) {
            if ($workout['start'] < $start || $workout['start'] >= $end
                || points_eligibility($ctx, $workout['start']) !== 'yes') {
                continue;
            }

            if (!empty($cfg['distinct_days'])) {
                $day = date('Y-m-d', $workout['start']);
                if (isset($days[$day])) {
                    continue;
                }
                $days[$day] = true;
            }

            $count++;

            if ($count === (int) $cfg['workouts']) {
                $reached = $workout;
                break;
            }
        }

        if ($reached === null) {
            $gone = points_remove($ctx, ['weekly_workouts' => $key]);
            return $gone[0] ?? null;
        }

        return points_set($ctx, $key, 'weekly_workouts', (int) $cfg['points'],
            date('Y-m-d H:i:s', $reached['start']), ['week', null],
            'Week van ' . points_short_date($weekStart));
    }

    /**
     * The first day of the week a moment falls in. The person's own choice
     * in Instellingen once it is stored; until then the Ownify default.
     */
    function points_week_start(int $userId, int $timestamp): string
    {
        $first = points_config()['week_starts_on'] === 'sunday' ? 7 : 1;      // ISO: 1 = Monday, 7 = Sunday
        $day   = (new DateTimeImmutable())->setTimestamp($timestamp)->setTime(0, 0);
        $back  = ((int) $day->format('N') - $first + 7) % 7;

        return $day->modify('-' . $back . ' days')->format('Y-m-d');
    }

    /* ==================================================================
       THE LEDGER
       ================================================================== */

    /**
     * Makes one award what it should be: created, changed, or — at 0 points —
     * removed. Returns what changed, or null when it was already right.
     *
     * @param array{0: ?string, 1: ?int} $ref reference_type, reference_id
     */
    function points_set(array $ctx, string $key, string $rule, int $points, string $awardedAt, array $ref,
                        ?string $note = null, ?string $label = null): ?array
    {
        $row = db_one(
            'SELECT id, points, awarded_at, note FROM point_events WHERE user_id = ? AND award_key = ?',
            [$ctx['user'], $key]
        );

        $label ??= points_rule_label($rule);
        $note = $note === null ? null : mb_substr($note, 0, 160);

        if ($points <= 0) {
            if ($row === null) {
                return null;
            }

            db_run('DELETE FROM point_events WHERE id = ?', [(int) $row['id']]);

            return points_change($key, $rule, $label, (string) $row['note'], 0, (int) $row['points'],
                (string) $row['awarded_at'], (string) $row['awarded_at']);
        }

        if ($row !== null
            && (int) $row['points'] === $points
            && (string) $row['awarded_at'] === $awardedAt
            && $row['note'] === $note) {
            return null;
        }

        db_run(
            'INSERT INTO point_events
                    (user_id, rule_id, award_key, points, awarded_at, reference_type, reference_id, note)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rule_id = VALUES(rule_id), points = VALUES(points),
                                     awarded_at = VALUES(awarded_at),
                                     reference_type = VALUES(reference_type),
                                     reference_id = VALUES(reference_id), note = VALUES(note)',
            [$ctx['user'], points_rule_id($rule), $key, $points, $awardedAt, $ref[0], $ref[1], $note]
        );

        return points_change($key, $rule, $label, $note, $points, $row === null ? 0 : (int) $row['points'],
            $awardedAt, $row === null ? null : (string) $row['awarded_at']);
    }

    /** @param array<string,string> $keys rule => key */
    function points_remove(array $ctx, array $keys): array
    {
        $changes = [];

        foreach ($keys as $rule => $key) {
            $change = points_set($ctx, $key, $rule, 0, '', [null, null]);
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    function points_change(string $key, string $rule, string $label, ?string $note, int $points, int $previous,
                           string $awardedAt, ?string $previousAt): array
    {
        return [
            'key'         => $key,
            'rule'        => $rule,
            'label'       => $label,
            'note'        => $note,
            'points'      => $points,
            'previous'    => $previous,
            'delta'       => $points - $previous,
            'awarded_at'  => $awardedAt,
            'previous_at' => $previousAt,
        ];
    }

    /** What a rule is called, from config/points.php. */
    function points_rule_label(string $rule): string
    {
        $cfg   = points_config();
        $label = (string) ($cfg['rules'][$rule]['label'] ?? $rule);

        /* The weekly bonus says how many workouts, whatever that is set to. */
        if ($rule === 'weekly_workouts') {
            $label = (int) $cfg['training']['weekly']['workouts'] . ' trainingen deze week';
        }

        return $label;
    }

    /**
     * The point_rules row for a rule, created from config/points.php if it is
     * missing, so every award in the ledger names its rule.
     */
    function points_rule_id(string $rule): ?int
    {
        static $ids = [];

        if (array_key_exists($rule, $ids)) {
            return $ids[$rule];
        }

        $id = db_value('SELECT id FROM point_rules WHERE code = ?', [$rule]);

        if ($id === null) {
            $meta = points_config()['rules'][$rule] ?? ['label' => $rule, 'domain' => 'general', 'cadence' => 'per_event'];

            db_run(
                'INSERT IGNORE INTO point_rules (code, label, domain, points, cadence, is_active) VALUES (?, ?, ?, 0, ?, 1)',
                [$rule, mb_substr(points_rule_label($rule), 0, 120), $meta['domain'], $meta['cadence']]
            );

            $id = db_value('SELECT id FROM point_rules WHERE code = ?', [$rule]);
        }

        return $ids[$rule] = $id === null ? null : (int) $id;
    }

    /**
     * Rebuilds the board periods an award moved in or out of — this month
     * and year, or last month's for a late sync — and the all-time board.
     */
    function points_refresh_boards(array $changes): void
    {
        $periods = [];

        foreach ($changes as $change) {
            if ($change['delta'] === 0 && $change['previous_at'] === $change['awarded_at']) {
                continue;
            }

            foreach ([$change['awarded_at'], $change['previous_at']] as $at) {
                if ($at !== null && $at !== '') {
                    $periods['month:' . substr($at, 0, 7)] = ['month', substr($at, 0, 7)];
                    $periods['year:' . substr($at, 0, 4)]  = ['year', substr($at, 0, 4)];
                }
            }
        }

        if ($periods === []) {
            return;
        }

        $periods['alltime'] = ['alltime', 'all'];

        foreach ($periods as [$type, $key]) {
            leaderboard_rebuild($type, $key);
        }
    }

    /* ==================================================================
       FOR PEOPLE
       ================================================================== */

    /**
     * "+35 punten — Training" for every award that went up, oldest first:
     * what to show right after something was saved.
     *
     * @return array<int,array{points: int, text: string, label: string, note: ?string}>
     */
    function points_feedback(array $changes): array
    {
        $lines = [];

        foreach ($changes as $change) {
            if ($change['delta'] <= 0) {
                continue;
            }

            $lines[] = [
                'points' => $change['delta'],
                'label'  => $change['label'],
                'note'   => $change['note'],
                'text'   => '+' . $change['delta'] . ' punten — ' . $change['label'],
            ];
        }

        return $lines;
    }

    /**
     * The person's own awards, newest first, each with the rule it followed —
     * the answer to "why do I have these points?".
     */
    function points_history(int $userId, int $limit = 50): array
    {
        if (!points_available()) {
            return [];
        }

        return db_all(
            'SELECT e.points, e.awarded_at, e.note, e.award_key, r.code AS rule, r.label
               FROM point_events e
          LEFT JOIN point_rules r ON r.id = e.rule_id
              WHERE e.user_id = ?
           ORDER BY e.awarded_at DESC, e.id DESC
              LIMIT ' . max(1, min($limit, 500)),
            [$userId]
        );
    }

    /** "21 september" — for the week a bonus belongs to. */
    function points_short_date(string $date): string
    {
        $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli',
                   'augustus', 'september', 'oktober', 'november', 'december'];
        $t = strtotime($date . ' 12:00:00');

        return (int) date('j', $t) . ' ' . $months[(int) date('n', $t) - 1];
    }

    /** A day's rating, 1-10, in points: the one tier it falls in. */
    function points_nutrition_value(int $rating): int
    {
        foreach (points_config()['nutrition']['rating_tiers'] as [$from, $to, $value]) {
            if ($rating >= $from && $rating <= $to) {
                return (int) $value;
            }
        }

        return 0;
    }

    /** A day's steps in points: the highest tier reached, never a sum. */
    function points_steps_value(int $steps): int
    {
        $points = 0;

        foreach (points_config()['steps']['tiers'] as [$atLeast, $value]) {
            if ($steps >= $atLeast) {
                $points = (int) $value;         // tiers ascend; the highest reached wins
            }
        }

        return $points;
    }

    /** One tier from [from, up to (not including), points] rows, or 0. */
    function points_tier_range(array $tiers, int $value): int
    {
        foreach ($tiers as [$from, $to, $points]) {
            if ($value >= $from && ($to === null || $value < $to)) {
                return (int) $points;
            }
        }

        return 0;
    }
}
