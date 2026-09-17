<?php
/**
 * Fills the Doelen page from the signed-in user's own goals.
 *
 * config/goals.php keeps the vocabulary — the categories, the types, the
 * durations, the copy. The goals themselves come from the `goals` table, and
 * a user with none gets the page's existing empty state rather than examples.
 *
 * Progress is never invented. A goal that names a metric has its current value
 * read from that metric; one that does not uses the dated snapshots in
 * goal_progress; a goal with neither reports no percentage at all, and the
 * card already knows how to draw that.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/goals.php';
require_once dirname(__DIR__) . '/includes/health-data.php';

if (!function_exists('hydrate_goals')) {

    /**
     * The interface calls a goal's measurement 'value' and 'milestone'; the
     * column has always called the same two 'target_value' and 'event'. Both
     * vocabularies are complete and they map one-to-one, so this is a
     * translation rather than a loss — unlike category, which was widened in
     * migration 002 because five values could not hold eight.
     */
    function goal_type_to_db(string $type): string
    {
        return match ($type) {
            'value'     => 'target_value',
            'milestone' => 'event',
            'habit'     => 'habit',
            'streak'    => 'streak',
            default     => 'target_value',
        };
    }

    function goal_type_from_db(string $type): string
    {
        return match ($type) {
            'target_value' => 'value',
            'event'        => 'milestone',
            'habit'        => 'habit',
            'streak'       => 'streak',
            default        => 'value',
        };
    }

    /**
     * @param int|null $userId the authenticated user, or null when signed out
     */
    function hydrate_goals(array $config, ?int $userId, ?DateTimeImmutable $today = null): array
    {
        /* Examples are gone for good: whether or not anyone is signed in, this
           page now shows real goals or none. */
        $config['demo']  = false;
        $config['goals'] = [];

        if ($userId === null || !db_available()) {
            return $config;
        }

        $today = $today ?? new DateTimeImmutable('today');
        $rows  = goals_for_user($userId, null);      // every status; the page splits them

        $goals = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'abandoned') {
                continue;                            // deleted from the user's point of view
            }

            $goals[] = hydrate_goal_row($userId, $row, $config, $today);
        }

        $config['goals'] = $goals;

        return $config;
    }

    /** One database row in the shape the card, the wizard and the detail page expect. */
    function hydrate_goal_row(int $userId, array $row, array $config, DateTimeImmutable $today): array
    {
        $goalId  = (int) $row['id'];
        $start   = hydrate_goal_date($row['start_date']);
        $end     = hydrate_goal_date($row['end_date']);
        $current = hydrate_goal_current($userId, $row);

        $percent = goal_percent($row, $current);

        /* A goal with no target and no snapshot has no percentage. The latest
           snapshot is used when the target cannot produce one. */
        if ($percent === null) {
            $latest = goal_latest_progress($userId, $goalId);
            if ($latest !== null && $latest['percent_complete'] !== null) {
                $percent = (float) $latest['percent_complete'];
            }
        }

        $history = hydrate_goal_history($userId, $goalId);

        return [
            'id'       => (string) $goalId,
            'name'     => $row['name'],
            'category' => hydrate_goal_category($row['category'], $config),
            'type'     => goal_type_from_db((string) $row['goal_type']),
            'priority' => $row['priority'] === 'primary' ? 'primary' : 'secondary',
            'status'   => $row['status'],

            'percent'       => $percent === null ? null : (int) round($percent),
            'current_label' => hydrate_goal_label($current, $row['target_unit']),
            'target_label'  => hydrate_goal_label(
                $row['target_value'] === null ? null : (float) $row['target_value'],
                $row['target_unit']
            ),

            'started_days_ago' => $start === null ? null : (int) $start->diff($today)->days,
            'ends_in_days'     => $end === null ? null : (int) $today->diff($end)->days * ($end < $today ? -1 : 1),
            'duration'         => hydrate_goal_duration($start, $end, $config),

            'sources'      => hydrate_goal_sources($row['category']),
            'history'      => $history,
            'history_step' => 'day',
            'activity'     => hydrate_goal_activity($userId, $goalId, $row['target_unit']),

            /* Completed goals are sorted by how recently they finished. */
            'completed_days_ago' => $row['status'] === 'completed'
                ? (int) (new DateTimeImmutable((string) $row['updated_at']))->diff($today)->days
                : null,

            'note' => null,
        ];
    }

    /**
     * The goal's current value.
     *
     * A metric-backed goal reads today's figure from the health data, which is
     * what makes such a goal keep itself up to date. Everything else uses the
     * newest snapshot the user recorded.
     */
    function hydrate_goal_current(int $userId, array $row): ?float
    {
        if ($row['metric_type_id'] !== null) {
            $code = db_value('SELECT code FROM health_metric_types WHERE id = ?', [(int) $row['metric_type_id']]);

            if ($code !== null) {
                $value = health_daily_metric($userId, (string) $code, date('Y-m-d'));
                if ($value !== null) {
                    return $value;
                }
            }
        }

        $latest = goal_latest_progress($userId, (int) $row['id']);

        return $latest === null || $latest['current_value'] === null
            ? null
            : (float) $latest['current_value'];
    }

    /** The percentage over time, oldest first, for the detail page's sparkline. */
    function hydrate_goal_history(int $userId, int $goalId): array
    {
        $rows = db_all(
            'SELECT p.percent_complete
               FROM goal_progress p
               JOIN goals g ON g.id = p.goal_id
              WHERE p.goal_id = ? AND g.user_id = ? AND p.percent_complete IS NOT NULL
           ORDER BY p.recorded_on
              LIMIT 60',
            [$goalId, $userId]
        );

        return array_map(static fn (array $r): int => (int) round((float) $r['percent_complete']), $rows);
    }

    /** The last few snapshots, as the detail page's "Recent" list. */
    function hydrate_goal_activity(int $userId, int $goalId, ?string $unit): array
    {
        $rows = db_all(
            'SELECT p.recorded_on, p.current_value, p.percent_complete
               FROM goal_progress p
               JOIN goals g ON g.id = p.goal_id
              WHERE p.goal_id = ? AND g.user_id = ?
           ORDER BY p.recorded_on DESC
              LIMIT 3',
            [$goalId, $userId]
        );

        $today = new DateTimeImmutable('today');

        return array_map(static function (array $r) use ($unit, $today): array {
            $day  = new DateTimeImmutable((string) $r['recorded_on']);
            $days = (int) $day->diff($today)->days;

            return [
                'label' => match (true) {
                    $days === 0 => 'Vandaag',
                    $days === 1 => 'Gisteren',
                    default     => $days . ' dagen geleden',
                },
                'meta'  => $r['percent_complete'] === null
                    ? ''
                    : round((float) $r['percent_complete']) . '%',
                'value' => hydrate_goal_label(
                    $r['current_value'] === null ? null : (float) $r['current_value'],
                    $unit
                ) ?? '',
            ];
        }, $rows);
    }

    /** "86 kg", or null when there is no value to label. */
    function hydrate_goal_label(?float $value, ?string $unit): ?string
    {
        if ($value === null) {
            return null;
        }

        $number = fmod($value, 1.0) === 0.0
            ? number_format($value, 0, ',', '.')
            : rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

        return $unit === null || $unit === '' ? $number : $number . ' ' . $unit;
    }

    /** A stored category the interface no longer offers still has to render. */
    function hydrate_goal_category(string $category, array $config): string
    {
        if (isset($config['categories'][$category])) {
            return $category;
        }

        /* The four pre-migration values, mapped onto their nearest current one. */
        return match ($category) {
            'sleep'    => 'health',
            'training' => 'activity',
            'body'     => 'weight',
            default    => 'other',
        };
    }

    /** Which duration bucket the start..end span falls into. */
    function hydrate_goal_duration(?DateTimeImmutable $start, ?DateTimeImmutable $end, array $config): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        $days  = (int) $start->diff($end)->days;
        $best  = null;
        $delta = PHP_INT_MAX;

        foreach ($config['durations'] as $key => $duration) {
            $difference = abs($days - (int) $duration['days']);
            if ($difference < $delta) {
                $delta = $difference;
                $best  = $key;
            }
        }

        return $best;
    }

    /**
     * Which health data would keep a goal current. Derived from the category
     * rather than stored: it is a property of what the goal is about, and a
     * stored copy would be one more thing to keep in step.
     */
    function hydrate_goal_sources(string $category): array
    {
        return match ($category) {
            'health', 'sleep'          => ['sleep'],
            'nutrition'                => ['nutrition'],
            'weight', 'body'           => ['manual'],
            'activity', 'training'     => ['activity', 'training'],
            'strength', 'performance'  => ['training'],
            'habit'                    => ['manual'],
            default                    => [],
        };
    }

    function hydrate_goal_date(?string $date): ?DateTimeImmutable
    {
        if ($date === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($date);
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('hydrate_dashboard_goal')) {
    /**
     * The Overzicht page's goal card, filled from the user's primary goal.
     *
     * The card already knows three states — unset, active, reached — so this
     * only has to say which one applies and hand it the numbers. Without a
     * primary goal it is left exactly as config had it, which is the "no goal
     * yet" design.
     */
    function hydrate_dashboard_goal(array $card, ?array $primary): array
    {
        if ($primary === null) {
            return $card;
        }

        $percent = $primary['percent'];

        $card['state']    = ($percent !== null && $percent >= 100) ? 'reached' : 'active';
        $card['name']     = $primary['name'];
        $card['progress'] = $percent;
        $card['headline'] = $primary['name'];

        /* The card reads "no progress yet" and "no goal yet" as the same
           state, which was true while there were no goals. Now that a goal can
           exist without a measurement against it, the copy has to say which of
           the two it is rather than sending the user back to onboarding. */
        if ($percent === null) {
            $card['description'] = $card['pending'] ?? $card['description'];
            $card['cta']['label'] = 'Doel openen';
        }

        /* The four markers fill as the percentage passes them. A goal with no
           measurable progress yet keeps them all empty rather than guessing. */
        if ($percent !== null) {
            foreach ([0, 50, 90, 100] as $index => $threshold) {
                if (isset($card['milestones'][$index])) {
                    $card['milestones'][$index]['reached'] = $percent >= $threshold;
                }
            }
        }

        if ($primary['current_label'] !== null && $primary['target_label'] !== null) {
            $card['unit'] = $primary['current_label'] . ' van ' . $primary['target_label'];
        }

        return $card;
    }
}
