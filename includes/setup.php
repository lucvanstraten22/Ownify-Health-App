<?php
/**
 * The first days — a new account's setup, its focus, and the baseline its
 * first days build. docs/FIRST-DAYS.md has the whole story; config/setup.php
 * has every word.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS STORED (migration 016, user_profiles)
 * ---------------------------------------------------------------------------
 *   focus          general | sleep | energy | fitness | weight
 *                  NULL = never chosen, shown as general ("Alles")
 *   setup_state    NULL     an account from before the setup existed
 *                  pending  a new account whose setup is not finished: the
 *                           app opens on it, on the website and the phone
 *                  done     finished
 *   setup_done_at  when it was finished — day 1 of the baseline
 *
 * Only registration writes `pending` (setup_begin()), so no existing account
 * ever sees the setup. Until the migration is imported none of this exists:
 * no setup, no baseline, and everybody's focus is general, as before.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT NEVER DOES
 * ---------------------------------------------------------------------------
 * Score. Every score below is the engine's own (includes/health-score.php),
 * as it stands now; the first days only say how far the engine is, from the
 * same records it reads. No reading is invented and no category is filled
 * in: too little data is said, not hidden behind a number.
 *
 * The parts marked PURE take everything they need as arguments — no
 * database, no clock — so tools/first-days-test.php can walk every day.
 */

declare(strict_types=1);

require_once __DIR__ . '/health-score.php';

if (!function_exists('setup_stored')) {

    /** The focuses, as stored. `general` is "Alles", and what NULL means. */
    function setup_focus_keys(): array
    {
        return ['general', 'sleep', 'energy', 'fitness', 'weight'];
    }

    /* ==================================================================
       STORAGE
       ================================================================== */

    /** Whether migration 016 is in: the focus and the setup can be kept. */
    function setup_stored(): bool
    {
        static $stored = null;

        if ($stored !== null) {
            return $stored;
        }

        if (!db_available()) {
            return false;
        }

        return $stored = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
                AND column_name IN ('focus', 'setup_state', 'setup_done_at')"
        ) === 3;
    }

    /**
     * A new account: its setup is waiting. Called by registration, inside
     * its transaction, right after the profile row is made.
     */
    function setup_begin(int $userId): void
    {
        if (setup_stored()) {
            db_run("UPDATE user_profiles SET setup_state = 'pending' WHERE user_id = ?", [$userId]);
        }
    }

    /**
     * The account's focus and where its setup stands.
     *
     * @return array{focus: string, chosen: bool, state: ?string, done_at: ?string}
     */
    function setup_state(int $userId): array
    {
        $none = ['focus' => 'general', 'chosen' => false, 'state' => null, 'done_at' => null];

        if (!setup_stored()) {
            return $none;
        }

        $row = db_one('SELECT focus, setup_state, setup_done_at FROM user_profiles WHERE user_id = ?', [$userId]);

        if ($row === null) {
            return $none;
        }

        $focus = (string) ($row['focus'] ?? '');

        return [
            'focus'   => in_array($focus, setup_focus_keys(), true) ? $focus : 'general',
            'chosen'  => $row['focus'] !== null,
            'state'   => $row['setup_state'] === null ? null : (string) $row['setup_state'],
            'done_at' => $row['setup_done_at'] === null ? null : (string) $row['setup_done_at'],
        ];
    }

    function setup_pending(int $userId): bool
    {
        return setup_state($userId)['state'] === 'pending';
    }

    /** @return array{ok: bool, error: ?string} */
    function setup_set_focus(int $userId, string $focus): array
    {
        if (!setup_stored()) {
            return ['ok' => false, 'error' => 'Je focus kan nog niet worden bewaard.'];
        }

        if (!in_array($focus, setup_focus_keys(), true)) {
            return ['ok' => false, 'error' => 'Onbekende focus.'];
        }

        db_run('INSERT IGNORE INTO user_profiles (user_id) VALUES (?)', [$userId]);
        db_run('UPDATE user_profiles SET focus = ? WHERE user_id = ?', [$focus, $userId]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Finishes the setup: from now on the app, and today is day 1 of the
     * baseline. Only a pending setup finishes, so asking twice changes
     * nothing — not even the day it was finished.
     */
    function setup_finish(int $userId): bool
    {
        if (!setup_stored()) {
            return false;
        }

        db_run(
            "UPDATE user_profiles SET setup_state = 'done', setup_done_at = NOW()
              WHERE user_id = ? AND setup_state = 'pending'",
            [$userId]
        );

        return true;
    }

    /* ==================================================================
       FOCUS — PURE
       ================================================================== */

    /**
     * The three score categories in the order a focus puts them: the
     * Overzicht legend, the Scorekompas and the first days follow it. Only
     * the order changes — every category is always there.
     */
    function setup_focus_order(string $focus): array
    {
        return match ($focus) {
            'fitness' => ['training', 'sleep', 'nutrition'],
            'weight'  => ['nutrition', 'training', 'sleep'],
            default   => ['sleep', 'nutrition', 'training'],
        };
    }

    /**
     * Rows that carry an `area` (the ring's legend), in the focus's order;
     * anything the order does not name keeps its place at the end.
     */
    function setup_order_rows(array $rows, string $focus, string $key = 'area'): array
    {
        $rank = array_flip(setup_focus_order($focus));

        $indexed = [];
        foreach (array_values($rows) as $i => $row) {
            $indexed[] = [$rank[$row[$key] ?? ''] ?? (100 + $i), $i, $row];
        }

        usort($indexed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($indexed, 2);
    }

    /* ==================================================================
       THE FIRST DAYS — PURE
       ================================================================== */

    /**
     * The Overzicht card of the first days, or null when there is none.
     *
     * Day 1 is the day the setup was finished. The card is shown on days 1
     * to `window` (5): three days to build the baseline, two to arrive at
     * the starting point. Which card it is follows from the engine alone:
     *
     *   building     no category has a score yet: how far each one is, in
     *                its own unit, and one small fact from the newest data
     *   first_score  the first score — on the day it first existed and the
     *                day after: the category's score with its real
     *                components (the Scorekompas's own rows)
     *   baseline     after that, to the end of the window: each category
     *                that has a score, as the starting point. Days 4 and 5
     *                without any data say there is no starting point yet.
     *
     * @param array $in {
     *   day: int, focus: string, min_days: int, today: string (Y-m-d),
     *   first_day: ?int  the first day (1-based) a category had a score,
     *   results: array   health_score_at() now: sleep/nutrition/training
     *                    {score, days, components, facts}, overall {score}
     *   records: array   health_score_data() over the engine's window:
     *                    nights, ratings, workouts
     *   weight: ?array   the newest weight {value, unit, measured_at}
     *   connected: bool  a health source is linked
     *   areas: array     id => {label, accent, icon}, the legend's names
     *   composition: array id => the Scorekompas's category row
     * }
     */
    function calibration_build(array $in, array $copy): ?array
    {
        $c      = $copy;
        $day    = (int) $in['day'];
        $window = (int) $c['window'];

        if ($day < 1 || $day > $window) {
            return null;
        }

        $order  = setup_focus_order((string) $in['focus']);
        $scored = array_values(array_filter(
            $order,
            static fn (string $id): bool => ($in['results'][$id]['score'] ?? null) !== null
        ));
        /* Enough days once, but nothing new for three (config/scoring.php,
           expiry_days): not counted now, and not "too little data" either. */
        $expired = array_values(array_filter(
            $order,
            static fn (string $id): bool => !empty($in['results'][$id]['expired'])
        ));

        $base = [
            'day'         => $day,
            'eyebrow'     => $day <= (int) $c['days']
                ? sprintf($c['eyebrow_day'], $day, (int) $c['days'])
                : $c['eyebrow'],
            'lede'        => null,
            'progress'    => [],
            'observation' => null,
            'first'       => null,
            'baseline'    => [],
            'note'        => null,
            'open'        => null,
        ];

        /* ------------------------------------------------ nothing scored */
        if ($scored === []) {
            if ($expired !== []) {
                return [
                    'phase'       => 'building',
                    'title'       => $c['expired']['title'],
                    'lede'        => $c['expired']['lede'],
                    'progress'    => calibration_progress($in, $c, $order),
                    'observation' => calibration_observation($in, $c),
                ] + $base;
            }

            $anyData = false;
            foreach ($order as $id) {
                $anyData = $anyData || (int) ($in['results'][$id]['days'] ?? 0) > 0;
            }

            if ($day > (int) $c['days'] && !$anyData) {
                return [
                    'phase'    => 'baseline',
                    'title'    => $c['baseline']['empty_title'],
                    'lede'     => $c['baseline']['empty_lede'],
                    'progress' => calibration_progress($in, $c, $order),
                ] + $base;
            }

            $lede = $c['building']['lede'];

            return [
                'phase'       => 'building',
                'title'       => $c['building']['title'],
                'lede'        => $lede[$day] ?? $lede['later'],
                'progress'    => calibration_progress($in, $c, $order),
                'observation' => calibration_observation($in, $c),
            ] + $base;
        }

        /* --------------------------------------------- the first score */
        $firstDay = (int) ($in['first_day'] ?? $day);

        if ($day <= $firstDay + 1) {
            $id       = $scored[0];
            $category = $in['composition'][$id] ?? null;

            if ($category !== null) {
                return [
                    'phase' => 'first_score',
                    'title' => sprintf($c['first']['title'], $c['first']['names'][$id] ?? $id),
                    'first' => $category,
                    'note'  => calibration_overall_note($in, $c, $order, $scored),
                    'open'  => $c['first']['open'],
                ] + $base;
            }
        }

        /* ------------------------------------------ the starting point */
        $rows = [];
        foreach ($scored as $id) {
            $rows[] = [
                'id'     => $id,
                'label'  => (string) ($in['areas'][$id]['label'] ?? $id),
                'accent' => (string) ($in['areas'][$id]['accent'] ?? $id),
                'icon'   => (string) ($in['areas'][$id]['icon'] ?? ''),
                'value'  => (int) $in['results'][$id]['score'],
                'band'   => score_colour_band((int) $in['results'][$id]['score']),
                'fact'   => calibration_baseline_fact($id, $in['results'][$id], $c),
            ];
        }

        $missing = array_values(array_diff($order, $scored, $expired));
        $note    = trim(
            ($missing === [] ? '' : sprintf(
                count($missing) === 1 ? $c['baseline']['missing_one'] : $c['baseline']['missing'],
                setup_ucfirst(calibration_names($missing, $c))
            )) . ' ' . calibration_expired_note($expired, $c)
        );

        return [
            'phase'    => 'baseline',
            'title'    => $c['baseline']['title'],
            'lede'     => $c['baseline']['lede'],
            'baseline' => $rows,
            'note'     => $note === '' ? null : $note,
            'open'     => $c['baseline']['open'],
        ] + $base;
    }

    /**
     * The first day (1-based; day 1 is the setup's own date) at whose end
     * the engine had a score for any category — today counted as of now —
     * or null while there is none. $read is health_score_data() from the
     * window of the setup's day to now; each day is scored exactly as the
     * Scorekompas's history scores it.
     */
    function calibration_first_day(array $read, DateTimeImmutable $start, int $day, DateTimeImmutable $now): ?int
    {
        for ($d = 1; $d <= $day; $d++) {
            $asOf = $start->setTime(0, 0)->modify('+' . ($d - 1) . ' days')->setTime(23, 59, 59);
            $at   = health_score_at($read, $asOf > $now ? $now : $asOf);

            if ($at['sleep']['score'] !== null || $at['nutrition']['score'] !== null || $at['training']['score'] !== null) {
                return $d;
            }
        }

        return null;
    }

    /** The records the engine counts now: those inside its window at $now. */
    function calibration_window_records(array $read, DateTimeImmutable $now): array
    {
        $from = health_score_window_start($now)->getTimestamp();
        $to   = $now->getTimestamp();
        $in   = static fn (int $t): bool => $t > $from && $t <= $to;

        return [
            'nights'   => array_values(array_filter($read['nights'] ?? [], static fn ($n) => $in((int) $n['end']))),
            'ratings'  => array_values(array_filter($read['ratings'] ?? [], static fn ($r) => $in((int) $r['at']))),
            'workouts' => array_values(array_filter($read['workouts'] ?? [], static fn ($w) => $in((int) $w['start']))),
        ];
    }

    /**
     * How far each category is, in the focus's order: "2 van 3 nachten",
     * what those days hold, or — with nothing yet — where its data comes
     * from.
     */
    function calibration_progress(array $in, array $c, array $order): array
    {
        $need  = (int) $in['min_days'];
        $p     = $c['progress'];
        $rows  = [];

        foreach ($order as $id) {
            $days = (int) ($in['results'][$id]['days'] ?? 0);
            $unit = $p['units'][$id] ?? ['dag', 'dagen'];

            $how = null;
            if ($days === 0) {
                $how = match (true) {
                    $id === 'nutrition' => $p['how']['nutrition'],
                    !empty($in['connected']) => $p['how']['connected'],
                    default => $p['how']['source'],
                };
            }

            $detail = $days > 0 ? calibration_detail($id, $in['records'] ?? [], $p['detail']) : null;
            if (!empty($in['results'][$id]['expired']) && !empty($in['results'][$id]['last_input'])) {
                $detail = sprintf(
                    $c['expired']['detail'],
                    setup_day_ref((string) $in['results'][$id]['last_input'], (string) ($in['today'] ?? ''), $c['observation'])
                );
            }

            $rows[] = [
                'id'     => $id,
                'label'  => (string) ($in['areas'][$id]['label'] ?? $id),
                'accent' => (string) ($in['areas'][$id]['accent'] ?? $id),
                'icon'   => (string) ($in['areas'][$id]['icon'] ?? ''),
                'days'   => min($days, $need),
                'needed' => $need,
                'count'  => sprintf($p['count'], min($days, $need), $need, $need === 1 ? $unit[0] : $unit[1]),
                'detail' => $detail,
                'how'    => $how,
            ];
        }

        return $rows;
    }

    /** What a category's days so far hold, from the engine's own records. */
    function calibration_detail(string $id, array $records, array $d): ?string
    {
        switch ($id) {
            case 'sleep':
                $minutes = array_map(static fn ($n) => (float) $n['minutes'], $records['nights'] ?? []);
                if ($minutes === []) {
                    return null;
                }
                return count($minutes) === 1
                    ? sprintf($d['sleep_one'], setup_hours($minutes[0]))
                    : sprintf($d['sleep'], setup_hours(array_sum($minutes) / count($minutes)));

            case 'nutrition':
                $daily = calibration_daily_ratings($records['ratings'] ?? []);
                if ($daily === []) {
                    return null;
                }
                return count($daily) === 1
                    ? sprintf($d['nutrition_one'], setup_decimal(reset($daily)))
                    : sprintf($d['nutrition'], setup_decimal(array_sum($daily) / count($daily)));

            case 'training':
                $workouts = $records['workouts'] ?? [];
                if ($workouts === []) {
                    return null;
                }
                $n = count($workouts);
                $minutes = (int) round(array_sum(array_map(static fn ($w) => (float) $w['minutes'], $workouts)));
                return sprintf($n === 1 ? $d['training'][0] : $d['training'][1], $n, $minutes);
        }

        return null;
    }

    /**
     * One small fact from the newest data, in the focus's order — the first
     * category that has any. What happened, never what to do about it.
     */
    function calibration_observation(array $in, array $c): ?string
    {
        $o       = $c['observation'];
        $records = $in['records'] ?? [];
        $today   = (string) $in['today'];
        $order   = setup_focus_order((string) $in['focus']);

        if (($in['focus'] ?? null) === 'weight') {
            array_unshift($order, 'weight');
        }

        foreach ($order as $id) {
            switch ($id) {
                case 'weight':
                    $weight = $in['weight'] ?? null;
                    if ($weight !== null && ($weight['unit'] ?? 'kg') === 'kg') {
                        return sprintf($o['weight'], setup_decimal((float) $weight['value']));
                    }
                    break;

                case 'sleep':
                    $nights = $records['nights'] ?? [];
                    if ($nights === []) {
                        break;
                    }
                    if (count($nights) === 1) {
                        $night = $nights[0];
                        return sprintf(
                            $o['sleep_one'],
                            setup_hours((float) $night['minutes']),
                            date('H:i', (int) $night['start']),
                            date('H:i', (int) $night['end'])
                        );
                    }
                    $bed = health_clock_mean(array_map(static fn ($n) => health_clock_minutes((int) $n['start']), $nights));
                    return sprintf($o['sleep'], count($nights), setup_clock((float) $bed));

                case 'nutrition':
                    $daily = calibration_daily_ratings($records['ratings'] ?? []);
                    if ($daily === []) {
                        break;
                    }
                    if (count($daily) === 1) {
                        $date = (string) array_key_first($daily);
                        return sprintf($o['nutrition_one'], setup_day_ref($date, $today, $o), setup_decimal($daily[$date]));
                    }
                    $low  = min($daily);
                    $high = max($daily);
                    return abs($high - $low) < 0.05
                        ? sprintf($o['nutrition_same'], count($daily), setup_decimal($low))
                        : sprintf($o['nutrition'], setup_decimal($low), setup_decimal($high));

                case 'training':
                    $workouts = $records['workouts'] ?? [];
                    if ($workouts === []) {
                        break;
                    }
                    $last = $workouts[0];
                    foreach ($workouts as $workout) {
                        if ((int) $workout['start'] > (int) $last['start']) {
                            $last = $workout;
                        }
                    }
                    return sprintf(
                        $o['training'],
                        (int) round((float) $last['minutes']),
                        setup_day_ref(date('Y-m-d', (int) $last['start']), $today, $o)
                    );
            }
        }

        return null;
    }

    /**
     * Under the first score: what the overall score rests on now. One
     * category is the whole score for now, and the sentence says so.
     */
    function calibration_overall_note(array $in, array $c, array $order, array $scored): ?string
    {
        $expired = array_values(array_filter($order, static fn (string $id): bool => !empty($in['results'][$id]['expired'])));
        $missing = array_values(array_diff($order, $scored, $expired));
        $overall = $in['results']['overall']['score'] ?? null;

        $text = match (true) {
            $missing !== [] => sprintf(
                count($missing) === 1 ? $c['first']['only_one'] : $c['first']['only'],
                calibration_names($scored, $c),
                setup_ucfirst(calibration_names($missing, $c))
            ),
            $overall === null => '',
            count($scored) === 1 => sprintf($c['first']['alone'], calibration_names($scored, $c)),
            default => sprintf($c['first']['all'], (int) $overall, calibration_names($scored, $c)),
        };

        $text = trim($text . ' ' . calibration_expired_note($expired, $c));

        return $text === '' ? null : $text;
    }

    /** "Voeding telt weer mee zodra er nieuwe gegevens zijn." — or nothing. */
    function calibration_expired_note(array $expired, array $c): string
    {
        if ($expired === []) {
            return '';
        }

        return sprintf(
            count($expired) === 1 ? $c['expired']['note_one'] : $c['expired']['note'],
            setup_ucfirst(calibration_names($expired, $c))
        );
    }

    /** A starting point's one line, from the facts its score was worked out from. */
    function calibration_baseline_fact(string $id, array $result, array $c): ?string
    {
        $f     = $c['baseline']['facts'];
        $facts = $result['facts'] ?? [];

        return match ($id) {
            'sleep' => !empty($facts['minutes'])
                ? sprintf($f['sleep'], setup_hours(array_sum($facts['minutes']) / count($facts['minutes'])))
                : null,
            'nutrition' => isset($facts['rating'])
                ? sprintf($f['nutrition'], setup_decimal((float) $facts['rating']))
                : null,
            'training' => isset($facts['balance']['frequency']['value'])
                ? sprintf($f['training'], setup_decimal((float) $facts['balance']['frequency']['value']))
                : null,
            default => null,
        };
    }

    /** "slaap", "slaap en voeding", "slaap, voeding en sport". */
    function calibration_names(array $ids, array $c): string
    {
        $names = array_map(static fn (string $id): string => (string) ($c['names'][$id] ?? $id), array_values($ids));

        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names) . ' en ' . $last;
    }

    /** A day's cijfers averaged per date, oldest first: date => cijfer. */
    function calibration_daily_ratings(array $ratings): array
    {
        $byDay = [];
        foreach ($ratings as $rating) {
            $byDay[(string) $rating['date']][] = (float) $rating['value'];
        }

        ksort($byDay);

        return array_map(static fn (array $values): float => array_sum($values) / count($values), $byDay);
    }

    /* ==================================================================
       A FIRST GOAL FROM THE PERSON'S OWN DATA — PURE
       ================================================================== */

    /**
     * A first goal the setup can offer, worked out from the last two weeks of
     * the person's own data, in the focus's order — or null when nothing can
     * honestly be worked out. It is the normal goal model, made through the
     * normal rules (goal_create_from_input) once the person says yes.
     *
     *   sleep     at least 3 nights: the average, and the next half hour
     *             above it as what makes a night count — 6:41 -> 7 uur — on
     *             5 nights of a week. Nothing above 8 uur, where the sleep
     *             score's duration curve tops out.
     *   training  at least 3 workouts: their number per week, and one more,
     *             counted over a week.
     *   weight    never: how much and which way is the person's own call.
     *
     * @param array $records nights and workouts of the last 14 days, as health_score_data() reads them
     * @return array{source: string, basis: string, name: string, input: array<string,string>}|null
     */
    function setup_suggestion_plan(string $focus, array $records, array $copy): ?array
    {
        $s = $copy;

        $order = match ($focus) {
            'fitness' => ['training', 'sleep'],
            'weight'  => [],
            default   => ['sleep', 'training'],
        };

        foreach ($order as $kind) {
            if ($kind === 'sleep') {
                $nights = $records['nights'] ?? [];
                if (count($nights) < 3) {
                    continue;
                }

                $average = array_sum(array_map(static fn ($n) => (float) $n['minutes'], $nights)) / count($nights);
                $target  = (floor($average / 30) + 1) * 30;          // the next half hour above it

                if ($target > 480) {
                    continue;
                }

                $hours  = $target / 60;
                $words  = setup_decimal_trim($hours);
                $nightsInWeek = 5;

                return [
                    'source' => 'sleep',
                    'basis'  => sprintf($s['sleep']['basis'][0], count($nights), setup_hours($average)),
                    'name'   => sprintf($s['sleep']['name'], $nightsInWeek, $words),
                    'input'  => [
                        'name'         => sprintf($s['sleep']['name'], $nightsInWeek, $words),
                        'category'     => 'habit',
                        'type'         => 'accumulate',
                        'measure'      => 'days',
                        'duration'     => 'week',
                        'source_kind'  => 'metric',
                        'source_key'   => 'sleep_duration',
                        'daily_target' => rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.'),
                        'target_value' => (string) $nightsInWeek,
                        'direction'    => 'increase',
                        'priority'     => 'primary',
                    ],
                ];
            }

            if ($kind === 'training') {
                $workouts = $records['workouts'] ?? [];
                if (count($workouts) < 3) {
                    continue;
                }

                $perWeek = count($workouts) / 2;
                $target  = (int) min(7, max(2, floor($perWeek) + 1));

                return [
                    'source' => 'training',
                    'basis'  => sprintf($s['training']['basis'][0], count($workouts)),
                    'name'   => sprintf($s['training']['name'], $target),
                    'input'  => [
                        'name'         => sprintf($s['training']['name'], $target),
                        'category'     => 'activity',
                        'type'         => 'accumulate',
                        'measure'      => 'amount',
                        'duration'     => 'week',
                        'source_kind'  => 'workout',
                        'source_key'   => 'sessions',
                        'target_value' => (string) $target,
                        'direction'    => 'increase',
                        'priority'     => 'primary',
                    ],
                ];
            }
        }

        return null;
    }

    /* ==================================================================
       WORDS — PURE
       ================================================================== */

    /** Minutes as the app writes durations: 401 -> "6:41". */
    function setup_hours(float $minutes): string
    {
        $minutes = (int) round($minutes);

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** A clock time from minutes after midnight: 1420 -> "23:40". */
    function setup_clock(float $minutes): string
    {
        $minutes = ((int) round($minutes)) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** One decimal, with a comma, without ",0": 7 -> "7", 7.46 -> "7,5". */
    function setup_decimal(float $value): string
    {
        $text = number_format($value, 1, ',', '');

        return str_ends_with($text, ',0') ? substr($text, 0, -2) : $text;
    }

    /** Hours as a person says them: 7 -> "7", 7.5 -> "7,5". */
    function setup_decimal_trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }

    /** "vandaag", "gisteren", or "op 2 oktober". */
    function setup_day_ref(string $date, string $today, array $o): string
    {
        static $months = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli',
                          'augustus', 'september', 'oktober', 'november', 'december'];

        if ($date === $today) {
            return $o['today'];
        }

        $day = new DateTimeImmutable($date);

        if ($day->modify('+1 day')->format('Y-m-d') === $today) {
            return $o['yesterday'];
        }

        return sprintf($o['on'], (int) $day->format('j') . ' ' . $months[(int) $day->format('n')]);
    }

    function setup_ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
