<?php
/**
 * Goal helpers — expansion, dates and ordering.
 *
 * The templates never calculate: they are handed a goal that already knows its
 * percentage, its ratio, how much time is left and which category it belongs
 * to. That is what lets the same card render a Mijlpaal, a Streak and an
 * Optellen goal without a single branch in the markup.
 *
 * Nothing here invents progress. A percentage arrives on the goal already
 * read from the database by lib/hydrate-goals.php; where it came from — a
 * health metric, a daily confirmation — is the data layer's problem.
 */

declare(strict_types=1);

if (!function_exists('goals_prepare')) {
    /**
     * Builds the page model: expanded goals, split by view, in priority order,
     * plus everything the header needs to know about the goal limit (`limits`
     * → `active` in config/goals.php).
     */
    function goals_prepare(array $config, ?DateTimeImmutable $today = null): array
    {
        $today  = $today ?? new DateTimeImmutable('today');
        $source = $config['goals'];

        $active    = [];
        $completed = [];

        foreach ($source as $goal) {
            $expanded = goals_expand($goal, $config, $today);

            if ($expanded['status'] === 'completed') {
                $completed[] = $expanded;
            } else {
                $active[] = $expanded;
            }
        }

        usort($active, static fn (array $a, array $b): int => goals_rank($a) <=> goals_rank($b));

        /* Most recently finished first — the last thing you achieved is the
           thing you want to see. */
        usort($completed, static fn (array $a, array $b): int
            => ($a['completed_days_ago'] ?? 0) <=> ($b['completed_days_ago'] ?? 0));

        /* Exactly one primary, always. A board that lost its primary — the
           only goal that carried the flag was completed, say — would leave the
           page with a headline slot and nothing in it, so the goal a delete
           would hand it to takes the flag: the first of Secundaire doelen
           (goals_successor()). goal_ensure_primary_after_completion() stores
           this same goal. */
        if ($active !== [] && !$active[0]['is_primary']) {
            $active = goals_secondary_order($active);
            $active[0]['priority']   = 'primary';
            $active[0]['is_primary'] = true;
        }

        /* Secundaire doelen in their order. The primary goal has its slot
           already and is not part of this. */
        $active = array_merge(array_slice($active, 0, 1), goals_secondary_order(array_slice($active, 1)));

        $limit = (int) $config['limits']['active'];
        $used  = count($active);

        $config['active']     = $active;
        $config['completed']  = $completed;
        $config['primary']    = $active[0] ?? null;
        $config['secondary']  = array_slice($active, 1);
        $config['used']       = $used;
        $config['slots_left'] = max(0, $limit - $used);
        $config['can_add']    = $used < $limit;
        $config['all']        = array_merge($active, $completed);

        return $config;
    }
}

if (!function_exists('goals_rank')) {
    /**
     * Sort key. Priority decides first and nothing outranks it: a paused
     * primary goal is still the goal you said mattered most, so it keeps the
     * headline slot and wears its paused state openly. Within one priority,
     * the goals still running come before the paused ones.
     */
    function goals_rank(array $goal): int
    {
        $priority = $goal['priority'] === 'primary' ? 0 : 1;
        $paused   = $goal['status'] === 'paused' ? 1 : 0;

        return $priority * 2 + $paused;
    }
}

if (!function_exists('goals_secondary_order')) {
    /**
     * Secundaire doelen in their order: the running goals furthest along first
     * (goals_secondary_compare()), the paused ones after them as they came.
     */
    function goals_secondary_order(array $goals): array
    {
        $running = array_values(array_filter($goals, static fn (array $g): bool => !$g['is_paused']));
        $paused  = array_values(array_filter($goals, static fn (array $g): bool => $g['is_paused']));

        return array_merge(goals_sort_stable($running, 'goals_secondary_compare'), $paused);
    }
}

if (!function_exists('goals_secondary_compare')) {
    /**
     * The order of the running secondary goals, the same for the website and
     * the app (which shows the order it is sent):
     *
     *   1. the highest percentage first — the percentage the card shows;
     *   2. a goal with no percentage (no data yet, too little, or no target to
     *      measure against) after every goal that has one. A real 0% is a
     *      percentage, so it comes before them;
     *   3. and 4. between equal percentages, and between goals without one,
     *      the goal that ends soonest first; one without an end date last.
     *
     * Goals still equal keep the order they had (goals_sort_stable).
     */
    function goals_secondary_compare(array $a, array $b): int
    {
        $hasA = $a['percent'] !== null;
        $hasB = $b['percent'] !== null;

        if ($hasA !== $hasB) {
            return $hasA ? -1 : 1;
        }

        if ($hasA && ($order = $b['percent'] <=> $a['percent']) !== 0) {
            return $order;
        }

        $endA = $a['end_date'] ?? null;
        $endB = $b['end_date'] ?? null;

        if ($endA === null || $endB === null) {
            return ($endA === null) <=> ($endB === null);
        }

        return $endA <=> $endB;
    }
}

if (!function_exists('goals_successor')) {
    /**
     * Which goal becomes primary when the primary goal is deleted: the first
     * one under Secundaire doelen, in the order the board shows them
     * (goals_prepare() — nothing is ranked again here). A paused goal comes
     * after every running one there, so it is only chosen when no running
     * secondary goal is left.
     *
     * $shown is the goal the website or the app moved up when the person
     * deleted: the first card on their screen. It is the one kept when it is
     * still on this board and as eligible — a page open since before a sync
     * may show an older order, and the goal the person saw move up is the one
     * that stays. Anything else — nothing sent, a goal that is gone, paused
     * while a running one is left — falls back to the board's own first.
     *
     * $board is goals_prepare() from before the delete. Null when $goalId is
     * not the primary goal (nothing changes) or no secondary goal is left.
     */
    function goals_successor(array $board, string $goalId, ?string $shown = null): ?string
    {
        if ((string) ($board['primary']['id'] ?? '') !== $goalId) {
            return null;
        }

        $candidates = array_values(array_filter(
            $board['secondary'],
            static fn (array $g): bool => (string) $g['id'] !== $goalId
        ));

        if ($candidates === []) {
            return null;
        }

        $first = $candidates[0];

        foreach ($candidates as $goal) {
            if ($shown !== null && (string) $goal['id'] === $shown && ($first['is_paused'] || !$goal['is_paused'])) {
                return (string) $goal['id'];
            }
        }

        return (string) $first['id'];
    }
}

if (!function_exists('goals_sort_stable')) {
    /** usort that keeps equal items in the order they came in, on any PHP. */
    function goals_sort_stable(array $items, callable $compare): array
    {
        $keyed = [];
        foreach (array_values($items) as $position => $item) {
            $keyed[] = [$position, $item];
        }

        usort($keyed, static fn (array $a, array $b): int => $compare($a[1], $b[1]) ?: $a[0] <=> $b[0]);

        return array_column($keyed, 1);
    }
}

if (!function_exists('goals_expand')) {
    /** Turns one configured goal into everything the templates need. */
    function goals_expand(array $goal, array $config, DateTimeImmutable $today): array
    {
        $goal += [
            'type'          => 'milestone',
            'priority'      => 'secondary',
            'status'        => 'active',
            'percent'       => null,
            'current_label' => null,
            'target_label'  => null,
            'sources'       => [],
            'history'       => [],
            'history_step'  => 'week',
            'activity'      => [],
            'note'          => null,
            'duration'      => null,
        ];

        /* --- category, type ------------------------------------------- */
        $category = $config['categories'][$goal['category']] ?? $config['categories']['other'];
        $goal['category_key']   = $goal['category'];
        $goal['category_label'] = $category['label'];
        $goal['icon']           = $category['icon'];
        $goal['accent']         = $category['accent'];
        $goal['type_label']     = $config['types'][$goal['type']]['label'] ?? '';

        /* --- state ----------------------------------------------------- */
        $goal['is_primary']   = $goal['priority'] === 'primary';
        $goal['is_paused']    = $goal['status'] === 'paused';
        $goal['is_completed'] = $goal['status'] === 'completed';

        /* --- progress -------------------------------------------------- */
        $goal['ratio'] = score_ratio($goal['percent'], 100);

        /* --- dates ------------------------------------------------------ */
        $start = isset($goal['started_days_ago'])
            ? $today->modify('-' . (int) $goal['started_days_ago'] . ' days')
            : null;

        $end = null;
        if ($goal['is_completed'] && isset($goal['completed_days_ago'])) {
            $end = $today->modify('-' . (int) $goal['completed_days_ago'] . ' days');
        } elseif (isset($goal['ends_in_days'])) {
            $end = $today->modify('+' . (int) $goal['ends_in_days'] . ' days');
        }

        $goal['start_date']  = $start;
        $goal['end_date']    = $end;
        $goal['start_label'] = $start ? goals_date_short($start) : null;
        $goal['end_label']   = $end ? goals_date_short($end) : null;

        $goal['days_left'] = ($end && !$goal['is_completed'])
            ? (int) $today->diff($end)->format('%r%a')
            : null;

        $goal['took_label'] = isset($goal['took_days'])
            ? goals_span_text((int) $goal['took_days'])
            : null;

        $goal['remaining'] = goals_remaining_text($goal, $config);

        /* Both readings of the line under the bar. Pausing and resuming is a
           client-side state change on this page, and it must not have to
           reconstruct Dutch copy to swap between them. */
        $goal['line_active'] = goals_deadline_line($goal, $config, false);
        $goal['line_paused'] = goals_deadline_line($goal, $config, true);
        $goal['deadline_line'] = $goal['is_paused'] ? $goal['line_paused'] : $goal['line_active'];

        $goal['duration_label'] = $goal['duration'] !== null
            ? ($config['durations'][$goal['duration']]['label'] ?? null)
            : null;

        /* --- sources ---------------------------------------------------- */
        /* What the person chose when they made the goal, not what its category
           suggests. The old code inferred a source from the category, which
           meant every weight goal claimed to be fed by manual entry and every
           sleep goal claimed to read sleep — whether or not either was true,
           and with nothing behind the claim.

           A goal reading real data names the metric it reads. One the person
           keeps by hand says so. */
        $resolved = [];

        if (($goal['tracking'] ?? 'manual') === 'auto' && !empty($goal['source_label'])) {
            $domain = goals_source_domain($goal['source_kind'] ?? '', $goal['source_key'] ?? '');
            $shape  = $config['sources'][$domain] ?? $config['sources']['manual'];

            /* The chosen source's own name and rule win over the domain's
               generic ones — "Stappen, elke dag minstens 10.000 stappen",
               not "Beweging, stappen en dagelijkse beweging". The domain
               only lends its icon and accent. */
            $resolved[] = [
                'key'   => $domain,
                'label' => $goal['source_label'],
                'note'  => $goal['daily_label'] !== null
                    ? 'Elke dag ' . $goal['daily_label']
                    : ($shape['label'] ?? '') . ' · uit je eigen gegevens',
            ] + $shape;
        } elseif (!empty($goal['is_manual'])) {
            $resolved[] = $config['sources']['manual'] + ['key' => 'manual'];
        }

        $goal['source_list'] = $resolved;

        /* Only a goal the person keeps themselves asks them for anything. An
           automatic one updating itself must never show an entry box, or the
           two numbers would start disagreeing. */
        $goal['needs_input'] = !empty($goal['is_manual']);

        /* --- history ---------------------------------------------------- */
        /* The Verloop chart plots the goal's real values against real dates —
           see lib/goal-chart.php for why it no longer draws the percentage.
           One real point is enough to draw: a single weigh-in is a dot on a
           timeline, not "no history". */
        require_once __DIR__ . '/goal-chart.php';

        $goal['chart'] = goal_chart_build(
            $goal,
            $goal['series'] ?? ['points' => [], 'mode' => 'best', 'target' => null, 'breaks' => false, 'best' => null]
        );
        $goal['has_history'] = $goal['chart']['has_data'];

        return $goal;
    }
}

if (!function_exists('goals_remaining_text')) {
    /**
     * Time left, in the shortest honest form. Deliberately never urgent: a
     * deadline on this page is information, not pressure.
     */
    function goals_remaining_text(array $goal, array $config): ?string
    {
        if ($goal['is_completed']) {
            return null;
        }

        $days = $goal['days_left'];

        if ($days === null) {
            return $config['labels']['no_deadline'];
        }

        if ($days < 0) {
            return 'Periode voorbij';
        }

        if ($days === 0) {
            return $config['labels']['last_day'];
        }

        return 'Nog ' . goals_span_text($days);
    }
}

if (!function_exists('goals_span_text')) {
    /** A length of time, rounded to the unit a person would actually say. */
    function goals_span_text(int $days): string
    {
        if ($days >= 330) {
            $years = (int) round($days / 365);
            return $years === 1 ? '1 jaar' : $years . ' jaar';
        }

        if ($days >= 60) {
            $months = (int) round($days / 30);
            return $months . ' maanden';
        }

        /* Days stay days for a good six weeks: "nog 18 dagen" is what a
           person counting down actually says, and "3 weken" loses the day. */
        if ($days >= 45) {
            $weeks = (int) round($days / 7);
            return $weeks . ' weken';
        }

        return $days === 1 ? '1 dag' : $days . ' dagen';
    }
}

if (!function_exists('goals_deadline_line')) {
    /**
     * The line under the bar: time left, and the date it runs to.
     * `$paused` overrides the goal's own status so both readings can be
     * produced up front.
     */
    function goals_deadline_line(array $goal, array $config, ?bool $paused = null): string
    {
        $paused = $paused ?? $goal['is_paused'];

        if ($goal['is_completed']) {
            $line = $goal['end_label'] !== null
                ? sprintf($config['labels']['completed_on'], $goal['end_label'])
                : 'Behaald';

            if ($goal['took_label'] !== null) {
                $line .= ' · ' . sprintf($config['labels']['took'], $goal['took_label']);
            }

            return $line;
        }

        /* The card carries a "Gepauzeerd" chip beside this line, so the line
           itself says what pausing means rather than repeating the word. */
        if ($paused) {
            return $config['labels']['paused_line'];
        }

        $line = (string) $goal['remaining'];

        if ($goal['end_label'] !== null) {
            $line .= ' · t/m ' . $goal['end_label'];
        }

        return $line;
    }
}

if (!function_exists('goals_date_short')) {
    /** "4 okt" — short enough to sit on one line beside the time remaining. */
    /**
     * Which of the page's five source shapes a chosen source looks like.
     *
     * Only for the icon and the accent — the label is the real metric's own.
     */
    function goals_source_domain(string $kind, string $key): string
    {
        if ($kind === 'measurement') {
            return 'body';
        }

        if ($kind === 'workout') {
            return 'training';
        }

        return match (true) {
            str_starts_with($key, 'sleep'), $key === 'sleeping_hr'     => 'sleep',
            in_array($key, ['steps', 'distance', 'floors', 'active_minutes', 'active_energy'], true) => 'activity',
            in_array($key, ['water', 'energy', 'protein', 'carbs', 'fat', 'fibre', 'sugar',
                            'sodium', 'saturated_fat', 'nutrition_rating'], true) => 'nutrition',
            default => 'training',
        };
    }

    function goals_date_short(DateTimeInterface $date): string
    {
        $months = [
            1 => 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun',
            'jul', 'aug', 'sep', 'okt', 'nov', 'dec',
        ];

        return sprintf('%d %s', (int) $date->format('j'), $months[(int) $date->format('n')]);
    }
}

if (!function_exists('goals_slot_note')) {
    /**
     * What the page says about the goal limit, in its current state: the
     * places left and the limit itself (`limits` → `active`), so the words
     * follow the configured number.
     */
    function goals_slot_note(array $goals): string
    {
        $left  = $goals['slots_left'];
        $limit = (int) $goals['limits']['active'];

        if ($left === 0) {
            return sprintf($goals['labels']['slots_full'], $left, $limit);
        }

        if ($left === 1) {
            return sprintf($goals['labels']['slots_one'], $left, $limit);
        }

        return sprintf($goals['labels']['slots_free'], $left, $limit);
    }
}

if (!function_exists('goals_script_copy')) {
    /**
     * The words goals.js and goal-wizard.js build their sentences from, as
     * the page embeds them (`[data-goals-copy]`): the Doelen page's, and the
     * setup's, which opens the same wizard (pages/setup.php).
     */
    function goals_script_copy(array $goals): array
    {
        return [
            'labels'    => $goals['labels'],
            'detail'    => $goals['detail'],
            'limits'    => $goals['limits'],
            'views'     => array_map(static fn (array $v): string => $v['label'], $goals['views']),
            'categories'=> array_map(
                static fn (array $c): array => ['label' => $c['label'], 'accent' => $c['accent'], 'units' => $c['units']],
                $goals['categories']
            ),
            'types'     => array_map(
                static fn (array $t): array => ['label' => $t['label'], 'hint' => $t['hint']],
                $goals['types']
            ),
            'durations' => array_map(
                static fn (array $d): array => ['label' => $d['label'], 'days' => $d['days']],
                $goals['durations']
            ),
            'wizard'    => $goals['wizard'],
        ];
    }
}
