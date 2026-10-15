<?php
/**
 * Uitdagingen — challenges with friends (docs/CHALLENGES.md).
 *
 * A challenge is Samen — everybody adds up towards one target — or
 * Wedstrijd — the most wins. It counts one thing (trainingen, stappen,
 * actieve minuten, afstand, nachten van 7 uur) for 1 week, 2 weeks or
 * 1 month, from today or from next Monday.
 *
 * THE RULES (the user's, 18.0)
 *   - Anybody with the five-digit code can look at a challenge and then join
 *     it themselves; nobody has to be friends. A code works for as long as
 *     the challenge runs, its last day included.
 *   - Friends can be invited. An invitation is only ever accepted by the
 *     person invited: nobody is joined for anybody else.
 *   - What you do counts from the day you join (or from the start, when you
 *     joined before it).
 *   - Only synced data counts: Health Connect, Polar. Nothing typed in.
 *   - Participants see each other's username, picture (as the leaderboards
 *     show it) and total for this challenge. Nothing else.
 *   - A Wedstrijd tied on its last day goes on: the first day that ends with
 *     one person ahead decides it.
 *   - Leaving takes your contribution with you. You can join again with the
 *     code while it runs.
 *   - Challenges have nothing to do with leaderboard points.
 *
 * PRIVACY
 *   Every total is worked out here, on the server, from the person's own
 *   data for this challenge's metric and dates — and only for people who
 *   joined it themselves. Nothing about anybody's health is stored in the
 *   challenge tables, and nothing beyond the one total ever leaves this file
 *   for another person.
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/user.php';
require_once __DIR__ . '/friends.php';
require_once __DIR__ . '/leaderboard.php';
require_once __DIR__ . '/health-totals.php';
require_once __DIR__ . '/health-signals.php';
require_once __DIR__ . '/auth-throttle.php';
require_once dirname(__DIR__) . '/lib/community.php';

if (!function_exists('challenges_available')) {

    /** Joined people a challenge can hold. */
    define('CHALLENGE_MEMBERS_MAX', 50);

    /** Challenges one person can be in at the same time (made or joined). */
    define('CHALLENGE_RUNNING_MAX', 20);

    /** The longest name a challenge can have. */
    define('CHALLENGE_NAME_MAX', 60);

    /** A night that counts: at least 7 hours asleep. */
    define('CHALLENGE_NIGHT_MINUTES', 420);

    /** Finished challenges the Uitdagingen page keeps showing. */
    define('CHALLENGE_ENDED_SHOWN', 10);

    /* ================================================================
       CATALOGUE
       ================================================================ */

    /** Whether migration 022 is in. Until it is, Community shows no challenges. */
    function challenges_available(): bool
    {
        static $available = null;

        if (!db_available()) {
            return false;
        }

        return $available ??= (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name IN ('challenges', 'challenge_members')"
        ) === 2;
    }

    function challenge_copy(): array
    {
        return config_copy('challenges');
    }

    /**
     * What a challenge can count, in the reader's language and units: the
     * patterns for one and many, the stepper and the suggested target per
     * length in what is shown (km or mi).
     */
    function challenge_metrics(): array
    {
        $copy = challenge_copy();
        $out  = [];

        foreach ($copy['metrics'] as $key => $metric) {
            if ($metric['unit'] === 'km') {
                $unit = units_label('km');
                $metric['one']  = '%s ' . $unit;
                $metric['many'] = '%s ' . $unit;
                $metric['what'] = units_imperial() ? t('mijlen') : t('kilometers');
            }

            $metric['key']      = $key;
            $metric['unit_shown'] = $metric['unit'] === 'km' ? units_label('km') : '';
            $metric['targets']  = [];

            foreach ($copy['lengths'] as $length => $spec) {
                $metric['targets'][$length] = challenge_round_to((float) $metric['per_week'] * (int) $spec['weeks'], (float) $metric['step']);
            }

            $out[$key] = $metric;
        }

        return $out;
    }

    function challenge_metric(string $key): ?array
    {
        return challenge_metrics()[$key] ?? null;
    }

    function challenge_round_to(float $value, float $step): float
    {
        return max($step, round($value / $step) * $step);
    }

    /* ================================================================
       DATES
       ================================================================ */

    function challenge_today(?DateTimeImmutable $today = null): DateTimeImmutable
    {
        return ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    /** Days from $a to $b: 0 on the same day, negative when $b is earlier. */
    function challenge_days_between(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return (int) $a->diff($b)->format('%r%a');
    }

    /** Next Monday; a week from today when today is a Monday. */
    function challenge_next_monday(DateTimeImmutable $today): DateTimeImmutable
    {
        $weekday = (int) $today->format('N');

        return $today->modify('+' . (8 - $weekday) . ' days');
    }

    /**
     * The first and last day of a new challenge: 1 week, 2 weeks or 1 month,
     * from today or from next Monday. The last day is inclusive.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    function challenge_dates(string $length, string $from, DateTimeImmutable $today): array
    {
        $start = $from === 'monday' ? challenge_next_monday($today) : $today;
        $end   = match ($length) {
            '2w'    => $start->modify('+13 days'),
            'month' => $start->modify('+1 month')->modify('-1 day'),
            default => $start->modify('+6 days'),
        };

        return [$start, $end];
    }

    /** "1 okt – 30 nov" / "Oct 1 – Nov 30". */
    function challenge_period_text(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return i18n_date($start, 'day_mon') . ' – ' . i18n_date($end, 'day_mon');
    }

    /** "ma 19 okt" / "Mon Oct 19". */
    function challenge_day_text(DateTimeImmutable $day): string
    {
        return i18n_weekday((int) $day->format('w'), true) . ' ' . i18n_date($day, 'day_mon');
    }

    /* ================================================================
       NUMBERS AND WORDS
       ================================================================ */

    /** A value as it is shown, without its unit: "16", "41.280", "293", "8,4". */
    function challenge_value_text(string $metric, float $value): string
    {
        if ($metric === 'distance') {
            $shown = (float) units_out($value, 'km');

            return i18n_number_trim($shown, abs($shown) >= 100 ? 0 : 1);
        }

        return i18n_number(round($value));
    }

    /** A value with its unit: "16 trainingen", "1 nacht van 7 uur", "500 km". */
    function challenge_amount(string $metric, float $value): string
    {
        $spec  = challenge_metric($metric);
        $text  = challenge_value_text($metric, $value);
        $shown = $metric === 'distance' ? round((float) units_out($value, 'km'), 1) : round($value);

        return sprintf($shown == 1 ? $spec['one'] : $spec['many'], $text);
    }

    /** A place: "2e" in Dutch, "2nd" in English. */
    function challenge_place(int $n): string
    {
        if (i18n_locale() !== 'en') {
            return $n . 'e';
        }

        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : match ($n % 10) {
            1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th',
        };

        return $n . $suffix;
    }

    function challenge_lower_first(string $text): string
    {
        return $text === '' ? '' : mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** The name a challenge gets when it is not given one. */
    function challenge_suggest_name(string $kind, string $metric, float $target, string $length): string
    {
        $spec = challenge_metric($metric);

        if ($spec === null) {
            return '';
        }

        if ($kind === 'versus') {
            return $spec['versus'];
        }

        $amount = sprintf($spec['many'], challenge_value_text($metric, $target));

        return match ($length) {
            '2w'    => tf('Samen %s in 2 weken', $amount),
            'month' => tf('Samen %s in een maand', $amount),
            default => tf('Samen %s in een week', $amount),
        };
    }

    /** A challenge name as it is kept: one line, single spaces, at most 60 characters. */
    function challenge_clean_name(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_substr($name, 0, CHALLENGE_NAME_MAX);
    }

    /* ================================================================
       WHAT COUNTS, DAY BY DAY
       ================================================================ */

    /**
     * One person's figure per day for a metric, synced data only. Only days
     * with something come back.
     *
     *   workouts       sessions that started that day
     *   steps, active_minutes, distance
     *                  the day total the Training card shows, with two apps
     *                  that counted the same walk counted once
     *   sleep_nights   1 for a night (filed under the morning) of at least
     *                  7 hours asleep, the main sleep only
     *
     * Workouts and nights typed in by hand (source `manual`) never count.
     *
     * @return array<string, float>
     */
    function challenge_day_values(int $userId, string $metric, string $from, string $to): array
    {
        if ($to < $from) {
            return [];
        }

        $out = [];

        switch ($metric) {
            case 'steps':
            case 'active_minutes':
            case 'distance':
                $out = health_metric_totals($userId, $metric, $from, $to);
                break;

            case 'workouts':
                foreach (db_all(
                    "SELECT DATE(w.started_at) AS d, COUNT(*) AS v
                       FROM workouts w
                  LEFT JOIN data_sources s ON s.id = w.source_id
                      WHERE w.user_id = ? AND DATE(w.started_at) BETWEEN ? AND ?
                        AND (s.code IS NULL OR s.code <> 'manual')
                   GROUP BY DATE(w.started_at)",
                    [$userId, $from, $to]
                ) as $row) {
                    $out[(string) $row['d']] = (float) $row['v'];
                }
                break;

            case 'sleep_nights':
                $nights = health_nights_from_rows(db_all(
                    "SELECT ss.id, ss.night_of, ss.started_at, ss.ended_at, ss.duration_minutes,
                            ss.time_in_bed_minutes, ss.efficiency_pct, ss.awakenings, ss.awake_minutes,
                            ss.light_minutes, ss.deep_minutes, ss.rem_minutes
                       FROM sleep_sessions ss
                  LEFT JOIN data_sources s ON s.id = ss.source_id
                      WHERE ss.user_id = ? AND ss.night_of BETWEEN ? AND ?
                        AND (s.code IS NULL OR s.code <> 'manual')
                   ORDER BY ss.night_of, ss.started_at",
                    [$userId, $from, $to]
                ));

                foreach ($nights as $night) {
                    if ((float) $night['minutes'] >= CHALLENGE_NIGHT_MINUTES) {
                        $out[(string) $night['date']] = 1.0;
                    }
                }
                break;
        }

        ksort($out);

        return $out;
    }

    /* ================================================================
       READING
       ================================================================ */

    function challenge_find(int $challengeId): ?array
    {
        return db_one('SELECT * FROM challenges WHERE id = ?', [$challengeId]);
    }

    function challenge_find_code(string $code): ?array
    {
        return db_one('SELECT * FROM challenges WHERE code = ?', [$code]);
    }

    function challenge_member_row(int $challengeId, int $userId): ?array
    {
        return db_one('SELECT * FROM challenge_members WHERE challenge_id = ? AND user_id = ?', [$challengeId, $userId]);
    }

    function challenge_joined_count(int $challengeId): int
    {
        return (int) db_value(
            "SELECT COUNT(*) FROM challenge_members m JOIN users u ON u.id = m.user_id
              WHERE m.challenge_id = ? AND m.status = 'joined' AND u.status = 'active'",
            [$challengeId]
        );
    }

    /** Challenges this person is in that have not ended yet (overtime included). */
    function challenge_running_count(int $userId, DateTimeImmutable $today): int
    {
        return (int) db_value(
            "SELECT COUNT(*) FROM challenge_members m JOIN challenges c ON c.id = m.challenge_id
              WHERE m.user_id = ? AND m.status = 'joined' AND c.end_date >= ?",
            [$userId, $today->format('Y-m-d')]
        );
    }

    /** Whether a challenge still takes new people: until its last day, inclusive. */
    function challenge_open(array $challenge, DateTimeImmutable $today): bool
    {
        return (string) $challenge['end_date'] >= $today->format('Y-m-d');
    }

    /**
     * The people in a challenge — joined, and invited — with only the fields
     * another participant may see: username and picture as the boards show it.
     */
    function challenge_people(int $challengeId): array
    {
        return db_all(
            'SELECT m.user_id, m.status, m.joined_at, m.invited_by, u.username, '
            . leaderboard_avatar_select() . "
               FROM challenge_members m
               JOIN users u ON u.id = m.user_id
          LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE m.challenge_id = ? AND m.status IN ('joined', 'invited') AND u.status = 'active'
           ORDER BY m.joined_at IS NULL, m.joined_at, u.username",
            [$challengeId]
        );
    }

    /** A person as a challenge shows them. */
    function challenge_person(array $row, int $viewerId): array
    {
        $name = (string) $row['username'];

        return [
            'name'    => $name,
            'initial' => community_initial($name),
            'accent'  => community_avatar_accent($name),
            'avatar'  => avatar_small($row['avatar_path'] ?? null),
            'you'     => (int) $row['user_id'] === $viewerId,
        ];
    }

    /**
     * Where everybody stands, worked out from their own synced data.
     *
     *   state      upcoming | running | overtime | ended
     *   members    joined people, highest first, each with `total`
     *   winner     (Wedstrijd, ended) the one who won
     *   decided    the day it was decided — the last day, or later after a tie
     *   overtime   a tie on the last day sent it on (ongoing or decided)
     */
    function challenge_standings(array $challenge, DateTimeImmutable $today): array
    {
        $start  = new DateTimeImmutable((string) $challenge['start_date']);
        $end    = new DateTimeImmutable((string) $challenge['end_date']);
        $versus = $challenge['kind'] === 'versus';
        $metric = (string) $challenge['metric'];

        $state = match (true) {
            $today < $start => 'upcoming',
            $today <= $end  => 'running',
            default         => 'ended',
        };

        /* A Wedstrijd past its last day reads on to today, in case it has to
           go on; everything else stops at the last day. */
        $through = $state === 'ended' && $versus ? $today : min($today, $end);
        $members = [];

        foreach (challenge_people((int) $challenge['id']) as $row) {
            if ($row['status'] !== 'joined') {
                continue;
            }

            $joined = $row['joined_at'] !== null ? new DateTimeImmutable(substr((string) $row['joined_at'], 0, 10)) : $start;
            $from   = max($start, $joined);
            $days   = $state === 'upcoming' ? [] : challenge_day_values(
                (int) $row['user_id'], $metric, $from->format('Y-m-d'), $through->format('Y-m-d')
            );

            $total = 0.0;
            foreach ($days as $date => $value) {
                if ($date <= $end->format('Y-m-d')) {
                    $total += $value;
                }
            }

            $members[] = $row + ['days' => $days, 'total' => $total, 'from' => $from];
        }

        $winner   = null;
        $decided  = null;
        $overtime = false;

        if ($versus && $state === 'ended' && $members !== []) {
            $top  = max(array_map(static fn (array $m): float => round($m['total'], 3), $members));
            $tied = array_keys(array_filter($members, static fn (array $m): bool => round($m['total'], 3) === $top));

            if (count($tied) === 1) {
                $winner  = $tied[0];
                $decided = $end;
            } else {
                /* Gelijkspel: the tied go on, a day at a time, and the first
                   day that ends with one of them ahead decides it. Today is
                   not over yet, so it can lead but not decide. */
                $overtime = true;
                $running  = array_fill_keys($tied, $top);

                for ($day = $end->modify('+1 day'); $day <= $today; $day = $day->modify('+1 day')) {
                    $key = $day->format('Y-m-d');

                    foreach ($tied as $index) {
                        $running[$index] += $members[$index]['days'][$key] ?? 0.0;
                    }

                    if ($day == $today) {
                        break;
                    }

                    $best    = max(array_map(static fn (float $v): float => round($v, 3), $running));
                    $leaders = array_keys(array_filter($running, static fn (float $v): bool => round($v, 3) === $best));

                    if (count($leaders) === 1) {
                        $winner  = $leaders[0];
                        $decided = $day;
                        break;
                    }
                }

                foreach ($tied as $index) {
                    $members[$index]['total'] = $running[$index];
                }

                if ($winner === null) {
                    $state = 'overtime';
                }
            }
        }

        $winnerId = $winner !== null ? (int) $members[$winner]['user_id'] : null;

        usort($members, static function (array $a, array $b): int {
            return [round($b['total'], 3), (string) $a['username']] <=> [round($a['total'], 3), (string) $b['username']];
        });

        $winnerRow = null;
        foreach ($members as $member) {
            if ((int) $member['user_id'] === $winnerId) {
                $winnerRow = $member;
            }
        }

        return [
            'state'    => $state,
            'members'  => $members,
            'winner'   => $winnerRow,
            'decided'  => $decided,
            'overtime' => $overtime,
            'start'    => $start,
            'end'      => $end,
        ];
    }

    /* ================================================================
       WHAT A SCREEN SHOWS
       ================================================================ */

    /** The line under a challenge's name: "Nog 4 dagen", "Laatste dag", "Begint ma 19 okt"… */
    function challenge_left_text(array $standings, DateTimeImmutable $today): string
    {
        $end = $standings['end'];

        return match ($standings['state']) {
            'upcoming' => tf('Begint %s', challenge_day_text($standings['start'])),
            'overtime' => t('Verlenging'),
            'ended'    => tf('Afgelopen op %s', i18n_date($standings['decided'] ?? $end, 'day_mon')),
            default    => challenge_days_between($today, $end) === 0
                ? t('Laatste dag')
                : tn(challenge_days_between($today, $end), 'Nog %d dag', 'Nog %d dagen'),
        };
    }

    /** Avatars for the foot of a tile: the first few joined people, and how many more. */
    function challenge_faces(array $people, int $shown = 4): array
    {
        return [
            'people' => array_slice($people, 0, $shown),
            'more'   => max(0, count($people) - $shown),
            'label'  => tn(count($people), '%d deelnemer', '%d deelnemers'),
        ];
    }

    /**
     * One challenge as its tile, its row on Uitdagingen and its own page show
     * it, for somebody who joined it — every sentence written here, in the
     * reader's language, so the website and the app say the same.
     */
    function challenge_view(array $challenge, int $userId, DateTimeImmutable $today, array $friends = []): array
    {
        $copy      = challenge_copy();
        $metric    = (string) $challenge['metric'];
        $spec      = challenge_metric($metric);
        $versus    = $challenge['kind'] === 'versus';
        $standings = challenge_standings($challenge, $today);
        $state     = $standings['state'];
        $members   = $standings['members'];
        $start     = $standings['start'];
        $end       = $standings['end'];
        $length    = challenge_days_between($start, $end) + 1;
        $elapsed   = max(0, min($length, challenge_days_between($start, $today) + 1));
        $left      = challenge_left_text($standings, $today);
        $period    = challenge_period_text($start, $end);
        $open      = challenge_open($challenge, $today);

        $total = array_sum(array_map(static fn (array $m): float => $m['total'], $members));
        $mine  = null;
        $rank  = null;

        foreach ($members as $index => $member) {
            if ((int) $member['user_id'] === $userId) {
                $mine = $member;
                /* Competition ranking: equal totals share a place. */
                $rank = 1 + count(array_filter($members, static fn (array $m): bool => round($m['total'], 3) > round($member['total'], 3)));
            }
        }

        $mineTotal = $mine['total'] ?? 0.0;
        $count     = count($members);
        $leader    = $members[0]['total'] ?? 0.0;

        /* ----------------------------------------------------- people */
        $people = [];
        $others = 0;

        foreach ($members as $member) {
            $person = challenge_person($member, $userId);
            $tone   = $person['you'] ? 0 : min(4, ++$others);
            $value  = (float) $member['total'];

            $people[] = $person + [
                'value'      => round($value, 3),
                'value_text' => challenge_value_text($metric, $value),
                'tone'       => $tone,
                'rank'       => 1 + count(array_filter($members, static fn (array $m): bool => round($m['total'], 3) > round($value, 3))),
                'share'      => $versus
                    ? ($leader > 0 ? round($value / $leader, 4) : 0.0)
                    : ($total > 0 ? round($value / $total, 4) : 0.0),
                'share_text' => $versus ? '' : i18n_number($total > 0 ? round(100 * $value / $total) : 0) . '%',
            ];
        }

        /* Your name on your own row: Jij. */
        foreach ($people as &$person) {
            if ($person['you']) {
                $person['label'] = t('Jij');
            } else {
                $person['label'] = $person['name'];
            }
        }
        unset($person);

        $pendingRows = array_values(array_filter(
            challenge_people((int) $challenge['id']),
            static fn (array $row): bool => $row['status'] === 'invited'
        ));
        $pending = array_map(static fn (array $row): array => challenge_person($row, $userId), $pendingRows);
        $pendingNames = array_map(static fn (array $p): string => $p['name'], $pending);

        /* ------------------------------------------------- the figures */
        $view = [
            'id'         => (int) $challenge['id'],
            'name'       => (string) $challenge['name'],
            'kind'       => (string) $challenge['kind'],
            'metric'     => $metric,
            'icon'       => $spec['icon'],
            'accent'     => $spec['accent'],
            'state'      => $state,
            'me'         => 'joined',
            'kind_label' => $copy['kinds'][$challenge['kind']]['label'],
            'kind_line'  => $copy['kinds'][$challenge['kind']]['label'] . ' · ' . $spec['label'],
            'left'       => $left,
            'deadline'   => in_array($state, ['upcoming', 'running'], true) ? $left . ' · ' . tf('t/m %s', i18n_date($end, 'day_mon')) : $left,
            'period'     => $period,
            'subtitle'   => $period . ' · ' . challenge_lower_first($left),
            'start'      => $start->format('Y-m-d'),
            'end'        => $end->format('Y-m-d'),
            'people'     => $people,
            'people_title' => $versus ? $copy['detail']['standings'] : $copy['detail']['contributions'],
            'count_text' => tn($count, '%d deelnemer', '%d deelnemers'),
            'faces'      => challenge_faces($people),
            'pending'    => $pending === [] ? null : [
                'people' => $pending,
                'text'   => count($pending) === 1
                    ? tf('%s is uitgenodigd', $pendingNames[0])
                    : tf('%s zijn uitgenodigd', challenge_list_names($pendingNames)),
            ],
            'code'       => $open && $challenge['code'] !== null ? (string) $challenge['code'] : null,
            'share_text' => $open && $challenge['code'] !== null
                ? tf('Doe mee met “%s” in Ownify: vul code %s in bij Community › Uitdagingen.', (string) $challenge['name'], (string) $challenge['code'])
                : null,
            'note'       => tf('Telt mee: %s uit gekoppelde bronnen.', $spec['what']) . ' ' . challenge_creator_text($challenge, $userId),
            'can_leave'  => $open,
            'invitable'  => $open ? challenge_invitable($friends, $challenge, $userId) : [],
        ];

        if (!$versus) {
            $target  = (float) $challenge['target'];
            $reached = $target > 0 && $total >= $target;
            $percent = $target > 0 ? ($reached ? (int) floor(100 * $total / $target) : min(99, (int) floor(100 * $total / $target))) : 0;
            $done    = max(0, min($length, challenge_days_between($start, $today)));
            $expected = $target * $done / max(1, $length);
            $diff    = $total - $expected;
            $diffRounded = $metric === 'distance' ? round((float) units_out(abs($diff), 'km'), 1) : round(abs($diff));

            $pace = match (true) {
                $state === 'upcoming'  => tf('Begint %s', challenge_day_text($start)),
                $state === 'ended'     => $reached ? t('Gehaald') : t('Niet gehaald'),
                $reached               => t('Doel gehaald'),
                $done === 0            => t('Net begonnen'),
                $diffRounded == 0      => t('Precies op schema'),
                $diff > 0              => tf('%s voor op schema', challenge_amount($metric, abs($diff))),
                default                => tf('%s achter op schema', challenge_amount($metric, abs($diff))),
            };

            /* Each person's piece of the bar, as a share of the target —
               scaled down together once the target is passed, so the bar is
               full and still shows who did what. */
            $scale = $total > $target && $total > 0 ? $target / $total : 1.0;
            $segments = array_map(static fn (array $p): array => [
                'you'  => $p['you'],
                'tone' => $p['tone'],
                'part' => $target > 0 ? round($p['value'] / $target * $scale, 4) : 0.0,
            ], $people);

            $view += [
                'target'      => round($target, 3),
                'total'       => round($total, 3),
                'percent'     => $percent,
                'reached'     => $reached,
                'bar'         => [
                    'type'     => 'stack',
                    'segments' => $segments,
                    'rest'     => $target > 0 ? round(max(0.0, 1 - $total / $target), 4) : 1.0,
                    'label'    => tf('%s van %s', challenge_value_text($metric, $total), challenge_amount($metric, $target)),
                ],
                'tile'        => [
                    'strong' => i18n_number($percent) . '%',
                    'rest'   => tf('%s van %s', challenge_value_text($metric, $total), challenge_value_text($metric, $target)),
                ],
                'line'        => [
                    'strong' => challenge_value_text($metric, $total),
                    'rest'   => tf('van %s', challenge_amount($metric, $target)),
                    'sub'    => $pace,
                ],
                'big'         => [
                    'figure' => challenge_value_text($metric, $total),
                    'of'     => tf('van %s', challenge_amount($metric, $target)),
                    'line'   => $state === 'running' && !$reached ? $pace . ' · ' . tf('dag %d van %d', $elapsed, $length) : $pace,
                    'tone'   => match (true) {
                        $reached                                  => 'ahead',
                        $state !== 'running' || $done === 0       => 'neutral',
                        $diff >= 0                                => 'ahead',
                        default                                   => 'behind',
                    },
                ],
            ];
        } else {
            $place  = $rank !== null ? challenge_place($rank) : '';
            $winner = $standings['winner'];
            $youWon = $winner !== null && (int) $winner['user_id'] === $userId;
            $ahead  = null;
            $second = null;

            foreach ($members as $member) {
                if ((int) $member['user_id'] === $userId) {
                    continue;
                }
                if (round($member['total'], 3) > round($mineTotal, 3)) {
                    $ahead = $member;   /* the last one above you: directly ahead */
                } elseif ($second === null) {
                    $second = $member;
                }
            }

            $gap = match (true) {
                $state === 'upcoming' => tf('Begint %s', challenge_day_text($start)),
                $ahead !== null       => tf('Nog %s tot %s', challenge_amount($metric, $ahead['total'] - $mineTotal), (string) $ahead['username']),
                $second === null      => t('Jij staat bovenaan'),
                round($second['total'], 3) === round($mineTotal, 3)
                                      => tf('Gelijk met %s', (string) $second['username']),
                default               => tf('%s voor op %s', challenge_amount($metric, $mineTotal - $second['total']), (string) $second['username']),
            };

            $result = $winner === null ? '' : ($youWon ? t('Jij won') : tf('%s won', (string) $winner['username']));

            if ($state === 'ended' && $standings['overtime']) {
                $result .= ' ' . t('na verlenging');
            }

            $view += [
                'rank'   => $rank,
                'winner' => $winner === null ? null : challenge_person($winner, $userId),
                'bar'    => [
                    'type'  => 'lead',
                    'value' => $leader > 0 ? round($mineTotal / $leader, 4) : 0.0,
                    'label' => tf('%s van %d', $place, $count),
                ],
                'tile'   => [
                    'strong' => $place,
                    'rest'   => tf('van %d', $count) . ' · ' . challenge_value_text($metric, $mineTotal),
                ],
                'line'   => match ($state) {
                    'ended'    => ['strong' => $result, 'rest' => '', 'sub' => $youWon ? challenge_amount($metric, $mineTotal) : tf('Jij werd %s', $place)],
                    'overtime' => ['strong' => t('Gelijkspel'), 'rest' => '', 'sub' => t('Verlenging tot er een winnaar is')],
                    default    => ['strong' => tf('%s van %d', $place, $count), 'rest' => '', 'sub' => $gap],
                },
                'big'    => [
                    'figure' => $place,
                    'of'     => tf('van %d', $count) . ' · ' . challenge_amount($metric, $mineTotal),
                    'line'   => match ($state) {
                        'ended'    => $result,
                        'overtime' => t('Gelijkspel op de laatste dag. Wie aan het eind van een dag voor staat, wint.'),
                        default    => $gap,
                    },
                    'tone'   => $rank === 1 && $state !== 'upcoming' ? 'ahead' : 'neutral',
                ],
            ];
        }

        return $view;
    }

    /** "lotte en daan", "lotte, daan en mila". */
    function challenge_list_names(array $names): string
    {
        if (count($names) < 2) {
            return (string) ($names[0] ?? '');
        }

        $last = array_pop($names);

        return tf('%s en %s', implode(', ', $names), $last);
    }

    function challenge_creator_text(array $challenge, int $userId): string
    {
        if ($challenge['creator_id'] === null) {
            return '';
        }

        if ((int) $challenge['creator_id'] === $userId) {
            return t('Gemaakt door jou.');
        }

        $name = db_value('SELECT username FROM users WHERE id = ?', [(int) $challenge['creator_id']]);

        return is_string($name) ? tf('Gemaakt door %s.', $name) : '';
    }

    /** Your friends who are not in this challenge yet, and not invited to it. */
    function challenge_invitable(array $friends, array $challenge, int $userId): array
    {
        if ($friends === []) {
            return [];
        }

        $taken = array_map('intval', array_column(db_all(
            "SELECT user_id FROM challenge_members WHERE challenge_id = ? AND status IN ('joined', 'invited', 'declined')",
            [(int) $challenge['id']]
        ), 'user_id'));

        $out = [];

        foreach ($friends as $friend) {
            if (in_array((int) $friend['id'], $taken, true) || (int) $friend['id'] === $userId) {
                continue;
            }

            $out[] = [
                'id'      => (int) $friend['id'],
                'name'    => (string) $friend['username'],
                'initial' => community_initial((string) $friend['username']),
                'accent'  => community_avatar_accent((string) $friend['username']),
                'avatar'  => $friend['avatar'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * A challenge as somebody who is not in it yet sees it — an invitation,
     * or what a code found: what it is, who made it, who is in it, and what
     * the others would see. No totals: those are for participants.
     */
    function challenge_review(array $challenge, int $userId, DateTimeImmutable $today, ?string $code = null): array
    {
        $copy    = challenge_copy();
        $metric  = (string) $challenge['metric'];
        $spec    = challenge_metric($metric);
        $start   = new DateTimeImmutable((string) $challenge['start_date']);
        $end     = new DateTimeImmutable((string) $challenge['end_date']);
        $versus  = $challenge['kind'] === 'versus';
        $rows    = challenge_people((int) $challenge['id']);
        $joined  = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 'joined'));
        $people  = array_map(static fn (array $r): array => challenge_person($r, $userId), $joined);
        $mineRow = challenge_member_row((int) $challenge['id'], $userId);
        $invited = $code === null && $mineRow !== null && $mineRow['status'] === 'invited';

        $standings = ['state' => $today < $start ? 'upcoming' : ($today <= $end ? 'running' : 'ended'),
                      'start' => $start, 'end' => $end, 'decided' => null];
        $left = challenge_left_text($standings, $today);

        $inviter = null;
        if ($invited && $mineRow['invited_by'] !== null) {
            $inviter = db_value('SELECT username FROM users WHERE id = ?', [(int) $mineRow['invited_by']]);
        }

        $creator = $challenge['creator_id'] === null ? null
            : db_value('SELECT username FROM users WHERE id = ?', [(int) $challenge['creator_id']]);

        $facts = [
            [$copy['review']['kind_label'], $versus ? t('Wedstrijd, wie het meest haalt') : t('Samen, naar één doel')],
            [$copy['review']['what'], $versus
                ? tf('De meeste %s', $spec['what'])
                : tf('%s samen', challenge_amount($metric, (float) $challenge['target']))],
            [$copy['review']['period'], challenge_period_text($start, $end) . ' · ' . challenge_lower_first($left)],
        ];

        if (is_string($creator)) {
            $facts[] = [$copy['review']['creator'], $creator];
        }

        $running = $today > $start;

        return [
            'id'       => (int) $challenge['id'],
            'name'     => (string) $challenge['name'],
            'icon'     => $spec['icon'],
            'accent'   => $spec['accent'],
            'invited'  => $invited,
            'code'     => $code,
            'title'    => $invited ? $copy['review']['title_invite'] : $copy['review']['title_code'],
            'by'       => $invited && is_string($inviter)
                ? tf('%s nodigt je uit', $inviter)
                : ($code !== null ? tf('Gevonden met code %s', $code) : ''),
            'facts'    => $facts,
            'people_label' => $copy['review']['people'],
            'faces'    => challenge_faces($people, 5),
            'privacy'  => tf('Als je meedoet, zien de deelnemers je gebruikersnaam en je totaal aan %s voor deze uitdaging. Verder niets.', $spec['what']),
            'late'     => $running ? t('De uitdaging loopt al. Wat je vanaf vandaag doet, telt mee.') : null,
            'joined'   => $running
                ? tf('%s staat nu bij je uitdagingen. Wat je vanaf vandaag doet, telt mee.', (string) $challenge['name'])
                : tf('%s staat nu bij je uitdagingen. Wat je vanaf de start doet, telt mee.', (string) $challenge['name']),
        ];
    }

    /**
     * Everything Community and Uitdagingen show for one person: invitations,
     * the challenges that are on, the ones that ended — and what the sheets
     * need to make one.
     */
    function challenges_for(?int $userId, ?DateTimeImmutable $today = null): array
    {
        $today = challenge_today($today);
        $copy  = challenge_copy();

        $base = [
            'available' => false,
            'invites'   => [],
            'running'   => [],
            'ended'     => [],
            'friends'   => [],
        ];

        if ($userId === null || !challenges_available()) {
            return $base + challenge_sheet_data($today) + ['summary' => '', 'copy' => $copy];
        }

        $friends = friend_list($userId);
        $rows    = db_all(
            "SELECT c.*, m.status AS my_status, m.invited_by AS my_inviter
               FROM challenges c
               JOIN challenge_members m ON m.challenge_id = c.id AND m.user_id = ?
              WHERE m.status IN ('joined', 'invited')
           ORDER BY c.end_date DESC, c.id DESC",
            [$userId]
        );

        $invites = $running = $ended = [];

        foreach ($rows as $row) {
            if ($row['my_status'] === 'invited') {
                if (!challenge_open($row, $today)) {
                    continue;   /* an invitation to something that is over is no invitation */
                }

                $review  = challenge_review($row, $userId, $today);
                $spec    = challenge_metric((string) $row['metric']);
                $inviter = $row['my_inviter'] !== null ? db_one(
                    'SELECT u.id AS user_id, u.username, ' . leaderboard_avatar_select() . '
                       FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?',
                    [(int) $row['my_inviter']]
                ) : null;

                $invites[] = [
                    'id'        => (int) $row['id'],
                    'name'      => (string) $row['name'],
                    'icon'      => $spec['icon'],
                    'accent'    => $spec['accent'],
                    'me'        => 'invited',
                    'kind_line' => $copy['kinds'][$row['kind']]['label'] . ' · ' . $spec['label'],
                    'inviter'   => $inviter === null ? null : challenge_person($inviter, $userId),
                    'invite_text' => $inviter === null
                        ? tf('Uitnodiging voor %s', (string) $row['name'])
                        : tf('nodigt je uit voor %s', (string) $row['name']),
                    'from_text' => $inviter === null ? $copy['review']['see']
                        : tf('Van %s', (string) $inviter['username']) . ' · ' . $copy['review']['see'],
                    'faces'     => $review['faces'],
                    'review'    => $review,
                ];
                continue;
            }

            if (count($ended) >= CHALLENGE_ENDED_SHOWN && (string) $row['end_date'] < $today->format('Y-m-d')) {
                continue;
            }

            $view = challenge_view($row, $userId, $today, $friends);

            if ($view['state'] === 'ended') {
                $ended[] = $view;
            } else {
                $running[] = $view;
            }
        }

        /* On: soonest to end first. Invitations: newest first (as read). */
        usort($running, static fn (array $a, array $b): int => [$a['end'], $a['id']] <=> [$b['end'], $b['id']]);

        $summary = tn(count($running), '%d bezig', '%d bezig');
        if ($invites !== []) {
            $summary .= ' · ' . tn(count($invites), '%d uitnodiging', '%d uitnodigingen');
        }

        $me = db_one(
            'SELECT u.id AS user_id, u.username, ' . leaderboard_avatar_select() . '
               FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?',
            [$userId]
        );

        return [
            'available' => true,
            'you'       => $me === null ? null : challenge_person($me, $userId),
            'invites'   => $invites,
            'running'   => $running,
            'ended'     => $ended,
            'friends'   => array_map(static fn (array $f): array => [
                'id'      => (int) $f['id'],
                'name'    => (string) $f['username'],
                'initial' => community_initial((string) $f['username']),
                'accent'  => community_avatar_accent((string) $f['username']),
                'avatar'  => $f['avatar'] ?? null,
            ], $friends),
            'summary'   => $summary,
            'copy'      => $copy,
        ] + challenge_sheet_data($today);
    }

    /**
     * What Nieuwe uitdaging needs to preview itself without asking: the
     * metrics in the reader's units, the lengths with their dates from today
     * and from next Monday, and the names it would suggest.
     */
    function challenge_sheet_data(DateTimeImmutable $today): array
    {
        $copy    = challenge_copy();
        $monday  = challenge_next_monday($today);
        $lengths = [];

        foreach ($copy['lengths'] as $key => $length) {
            [$s1, $e1] = challenge_dates($key, 'today', $today);
            [$s2, $e2] = challenge_dates($key, 'monday', $today);

            $lengths[$key] = [
                'key'    => $key,
                'label'  => $length['label'],
                'periods' => [
                    'today'  => challenge_period_text($s1, $e1),
                    'monday' => challenge_period_text($s2, $e2),
                ],
                /* "Samen %s in een week": %s is the amount, unit included. */
                'name_pattern' => match ($key) {
                    '2w'    => t('Samen %s in 2 weken'),
                    'month' => t('Samen %s in een maand'),
                    default => t('Samen %s in een week'),
                },
            ];
        }

        return [
            'metrics' => array_values(challenge_metrics()),
            'kinds'   => array_map(static fn (string $key, array $kind): array => ['key' => $key] + $kind,
                array_keys($copy['kinds']), $copy['kinds']),
            'lengths' => array_values($lengths),
            'from'    => [
                ['key' => 'today', 'label' => $copy['create']['from_today']],
                ['key' => 'monday', 'label' => tf('Vanaf %s', challenge_day_text($monday))],
            ],
            'note'    => t('Uitdagingen staan los van je punten op de ranglijst.'),
            'preview' => [
                'together' => t('%s samen'),
                'versus'   => t('Wie de meeste %s haalt'),
                'alone'    => t('Alleen jij, tot iemand meedoet'),
            ],
        ];
    }

    /* ================================================================
       WRITING
       ================================================================ */

    /**
     * Five digits nobody's challenge is using. A code of a challenge that is
     * over is handed on (its old challenge simply has none any more) — only
     * when every fresh try is taken, which takes many thousands of
     * challenges running at once.
     */
    function challenge_new_code(DateTimeImmutable $today): ?string
    {
        for ($try = 0; $try < 40; $try++) {
            $code  = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $taken = db_one('SELECT id, end_date FROM challenges WHERE code = ?', [$code]);

            if ($taken === null) {
                return $code;
            }

            if ($try >= 20 && (string) $taken['end_date'] < $today->format('Y-m-d')) {
                db_run('UPDATE challenges SET code = NULL WHERE id = ?', [(int) $taken['id']]);

                return $code;
            }
        }

        return null;
    }

    /**
     * Makes a challenge, with its maker in it and the chosen friends invited.
     *
     * $input: kind, metric, target (Samen, in what is shown), target_unit
     * ('km' or 'mi' for afstand), length, from, name, invite (user ids).
     *
     * @return array{ok: bool, error?: string, code?: string, id?: int}
     */
    function challenge_create(int $userId, array $input, ?DateTimeImmutable $today = null): array
    {
        $today  = challenge_today($today);
        $copy   = challenge_copy();
        $kind   = (string) ($input['kind'] ?? '');
        $metric = (string) ($input['metric'] ?? '');
        $length = (string) ($input['length'] ?? '1w');
        $from   = (string) ($input['from'] ?? 'today');
        $spec   = challenge_metric($metric);

        if (!isset($copy['kinds'][$kind])) {
            return ['ok' => false, 'error' => 'Kies Samen of Wedstrijd.'];
        }
        if ($spec === null) {
            return ['ok' => false, 'error' => 'Kies wat jullie tellen.'];
        }
        if (!isset($copy['lengths'][$length]) || !in_array($from, ['today', 'monday'], true)) {
            return ['ok' => false, 'error' => 'Kies hoe lang de uitdaging duurt.'];
        }

        $target = null;

        if ($kind === 'together') {
            $typed = i18n_parse_number($input['target'] ?? null);

            if ($typed === null || $typed <= 0) {
                return ['ok' => false, 'error' => 'Kies waar jullie samen naartoe werken.'];
            }

            if ($metric === 'distance') {
                $unit   = ($input['target_unit'] ?? 'km') === 'mi' ? 'mi' : 'km';
                $target = units_canonical($typed, $unit)[0];
            } else {
                $target = round($typed);
            }

            if ($target < 1 || $target > (float) $spec['step'] * 2000) {
                return ['ok' => false, 'error' => 'Dat doel is te groot.'];
            }
        }

        if (challenge_running_count($userId, $today) >= CHALLENGE_RUNNING_MAX) {
            return ['ok' => false, 'error' => tf('Je doet al aan %d uitdagingen mee. Maak er een nieuwe als er een afgelopen is.', CHALLENGE_RUNNING_MAX)];
        }

        [$start, $end] = challenge_dates($length, $from, $today);

        $name = challenge_clean_name((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = challenge_clean_name(challenge_suggest_name($kind, $metric, $target ?? 0.0, $length));
        }

        $code = challenge_new_code($today);
        if ($code === null) {
            return ['ok' => false, 'error' => 'Er kon geen code worden gemaakt. Probeer het nog eens.'];
        }

        $friends = friend_ids($userId);
        $invite  = array_values(array_unique(array_filter(
            array_map('intval', (array) ($input['invite'] ?? [])),
            static fn (int $id): bool => $id > 0 && $id !== $userId && in_array($id, $friends, true)
        )));
        $invite = array_slice($invite, 0, CHALLENGE_MEMBERS_MAX - 1);

        $pdo = db();
        $pdo->beginTransaction();

        try {
            db_run(
                'INSERT INTO challenges (code, creator_id, kind, metric, target, name, start_date, end_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, $userId, $kind, $metric, $target, $name, $start->format('Y-m-d'), $end->format('Y-m-d')]
            );
            $id = (int) db_insert_id();

            db_run(
                "INSERT INTO challenge_members (challenge_id, user_id, status, joined_at) VALUES (?, ?, 'joined', NOW())",
                [$id, $userId]
            );

            foreach ($invite as $friendId) {
                db_run(
                    "INSERT INTO challenge_members (challenge_id, user_id, status, invited_by) VALUES (?, ?, 'invited', ?)",
                    [$id, $friendId, $userId]
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[ownify] challenge not created: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'De uitdaging kon niet worden gemaakt.'];
        }

        return ['ok' => true, 'id' => $id, 'code' => $code, 'name' => $name, 'invited' => count($invite)];
    }

    /**
     * What a code is. Wrong codes count towards the same slowing down as a
     * wrong password (five in fifteen minutes), so codes cannot be tried one
     * after the other.
     *
     * @return array{ok: bool, status: string, error?: string, challenge?: array}
     *         status: found | member | ended | none | wait
     */
    function challenge_lookup(int $userId, string $code, ?DateTimeImmutable $today = null): array
    {
        $today = challenge_today($today);
        $code  = trim($code);

        if (!preg_match('/^\d{5}$/', $code)) {
            return ['ok' => false, 'status' => 'none', 'error' => 'Een code is 5 cijfers.'];
        }

        $identifier = 'u' . $userId;
        $wait = auth_throttle_wait('challenge_code', $identifier);

        if ($wait !== null) {
            return ['ok' => false, 'status' => 'wait', 'error' => auth_throttle_message($wait)];
        }

        $challenge = challenge_find_code($code);

        if ($challenge === null) {
            auth_throttle_fail('challenge_code', $identifier);

            return ['ok' => false, 'status' => 'none', 'error' => 'Geen uitdaging met deze code. Kijk de cijfers nog eens na.'];
        }

        auth_throttle_clear('challenge_code', $identifier);

        $member = challenge_member_row((int) $challenge['id'], $userId);

        if ($member !== null && $member['status'] === 'joined') {
            return ['ok' => true, 'status' => 'member', 'challenge' => $challenge];
        }

        if (!challenge_open($challenge, $today)) {
            return ['ok' => false, 'status' => 'ended',
                    'error' => tf('“%s” is al afgelopen. Meedoen kan niet meer.', (string) $challenge['name'])];
        }

        return ['ok' => true, 'status' => 'found', 'challenge' => $challenge];
    }

    /**
     * Joins a challenge — always the person's own tap on Meedoen. Through an
     * invitation (challenge_id) or a code; nothing else lets anybody in.
     */
    function challenge_join(int $userId, ?int $challengeId, ?string $code, ?DateTimeImmutable $today = null): array
    {
        $today = challenge_today($today);

        if ($code !== null && $code !== '') {
            $found = challenge_lookup($userId, $code, $today);

            if (!$found['ok']) {
                return $found;
            }

            $challenge = $found['challenge'];

            if ($found['status'] === 'member') {
                return ['ok' => true, 'status' => 'member', 'id' => (int) $challenge['id'], 'name' => (string) $challenge['name']];
            }
        } else {
            $challenge = $challengeId !== null ? challenge_find($challengeId) : null;
            $member    = $challenge !== null ? challenge_member_row((int) $challenge['id'], $userId) : null;

            if ($challenge === null || $member === null || !in_array($member['status'], ['invited', 'joined'], true)) {
                return ['ok' => false, 'status' => 'none', 'error' => 'Deze uitnodiging bestaat niet meer.'];
            }

            if ($member['status'] === 'joined') {
                return ['ok' => true, 'status' => 'member', 'id' => (int) $challenge['id'], 'name' => (string) $challenge['name']];
            }

            if (!challenge_open($challenge, $today)) {
                return ['ok' => false, 'status' => 'ended',
                        'error' => tf('“%s” is al afgelopen. Meedoen kan niet meer.', (string) $challenge['name'])];
            }
        }

        $id = (int) $challenge['id'];

        if (challenge_joined_count($id) >= CHALLENGE_MEMBERS_MAX) {
            return ['ok' => false, 'status' => 'full', 'error' => tf('Deze uitdaging is vol: er doen al %d mensen mee.', CHALLENGE_MEMBERS_MAX)];
        }

        if (challenge_running_count($userId, $today) >= CHALLENGE_RUNNING_MAX) {
            return ['ok' => false, 'status' => 'limit',
                    'error' => tf('Je doet al aan %d uitdagingen mee. Meedoen kan weer als er een afgelopen is.', CHALLENGE_RUNNING_MAX)];
        }

        db_run(
            "INSERT INTO challenge_members (challenge_id, user_id, status, joined_at)
             VALUES (?, ?, 'joined', NOW())
             ON DUPLICATE KEY UPDATE status = 'joined', joined_at = NOW()",
            [$id, $userId]
        );

        return ['ok' => true, 'status' => 'joined', 'id' => $id, 'name' => (string) $challenge['name']];
    }

    /** Turns an invitation down. The code still works, should they change their mind. */
    function challenge_decline(int $userId, int $challengeId): array
    {
        $statement = db_run(
            "UPDATE challenge_members SET status = 'declined' WHERE challenge_id = ? AND user_id = ? AND status = 'invited'",
            [$challengeId, $userId]
        );

        return $statement !== null && $statement->rowCount() === 1
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'Deze uitnodiging bestaat niet meer.'];
    }

    /** Leaves a challenge: you are out, and what you contributed with you. */
    function challenge_leave(int $userId, int $challengeId, ?DateTimeImmutable $today = null): array
    {
        $challenge = challenge_find($challengeId);

        if ($challenge === null || !challenge_open($challenge, challenge_today($today))) {
            return ['ok' => false, 'error' => 'Deze uitdaging is al afgelopen.'];
        }

        $statement = db_run(
            "UPDATE challenge_members SET status = 'left', joined_at = NULL WHERE challenge_id = ? AND user_id = ? AND status = 'joined'",
            [$challengeId, $userId]
        );

        return $statement !== null && $statement->rowCount() === 1
            ? ['ok' => true, 'name' => (string) $challenge['name']]
            : ['ok' => false, 'error' => 'Je doet niet mee aan deze uitdaging.'];
    }

    /**
     * Invites friends to a challenge you are in. Only your friends, only
     * while it runs, never somebody who is in it, already invited, or who
     * turned it down.
     */
    function challenge_invite(int $userId, int $challengeId, array $ids, ?DateTimeImmutable $today = null): array
    {
        $today     = challenge_today($today);
        $challenge = challenge_find($challengeId);
        $member    = $challenge !== null ? challenge_member_row($challengeId, $userId) : null;

        if ($challenge === null || $member === null || $member['status'] !== 'joined') {
            return ['ok' => false, 'error' => 'Je doet niet mee aan deze uitdaging.'];
        }

        if (!challenge_open($challenge, $today)) {
            return ['ok' => false, 'error' => 'Deze uitdaging is al afgelopen.'];
        }

        $friends = friend_ids($userId);
        $taken   = (int) db_value(
            "SELECT COUNT(*) FROM challenge_members WHERE challenge_id = ? AND status IN ('joined', 'invited')",
            [$challengeId]
        );
        $sent = [];

        foreach (array_unique(array_map('intval', $ids)) as $friendId) {
            if ($friendId <= 0 || $friendId === $userId || !in_array($friendId, $friends, true)) {
                continue;
            }

            if ($taken + count($sent) >= CHALLENGE_MEMBERS_MAX) {
                break;
            }

            $row = challenge_member_row($challengeId, $friendId);

            if ($row !== null && $row['status'] !== 'left') {
                continue;
            }

            db_run(
                "INSERT INTO challenge_members (challenge_id, user_id, status, invited_by)
                 VALUES (?, ?, 'invited', ?)
                 ON DUPLICATE KEY UPDATE status = 'invited', invited_by = VALUES(invited_by), joined_at = NULL",
                [$challengeId, $friendId, $userId]
            );
            $sent[] = $friendId;
        }

        if ($sent === []) {
            return ['ok' => false, 'error' => 'Kies wie je wilt uitnodigen.'];
        }

        $names = array_column(db_all(
            'SELECT username FROM users WHERE id IN (' . implode(',', array_fill(0, count($sent), '?')) . ') ORDER BY username',
            $sent
        ), 'username');

        return [
            'ok'      => true,
            'count'   => count($sent),
            'message' => count($sent) === 1
                ? tf('Uitnodiging verstuurd aan %s', (string) ($names[0] ?? ''))
                : tf('%d uitnodigingen verstuurd', count($sent)),
        ];
    }
}
