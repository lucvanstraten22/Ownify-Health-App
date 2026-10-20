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

require_once dirname(__DIR__) . '/includes/i18n.php';   // language and units (docs/I18N.md)
require_once dirname(__DIR__) . '/includes/goal-catalogue.php';

if (!function_exists('goals_prepare')) {
    /**
     * Builds the page model: expanded goals, split into Actief and Afgerond,
     * in their order, plus what the header says about the goal limit (`limits`
     * → `active` in config/goals.php).
     *
     *   Actief     running and paused goals: the primary one first, then the
     *              secondary ones furthest along (goals_secondary_order())
     *   Afgerond   reached and ended goals: the highest final percentage
     *              first, and between equals the newest first
     */
    function goals_prepare(array $config, ?DateTimeImmutable $today = null): array
    {
        $today  = $today ?? new DateTimeImmutable('today');
        $source = $config['goals'];

        $active    = [];
        $completed = [];

        foreach ($source as $goal) {
            $expanded = goals_expand($goal, $config, $today);

            if ($expanded['is_completed']) {
                $completed[] = $expanded;
            } else {
                $active[] = $expanded;
            }
        }

        usort($active, static fn (array $a, array $b): int => goals_rank($a) <=> goals_rank($b));

        $completed = goals_sort_stable($completed, 'goals_completed_compare');

        /* Exactly one primary, always: a board that lost it takes the goal a
           delete would hand it to (goals_successor()). */
        if ($active !== [] && !$active[0]['is_primary']) {
            $active = goals_secondary_order($active);
            $active[0]['priority']   = 'primary';
            $active[0]['is_primary'] = true;
        }

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

if (!function_exists('goals_completed_compare')) {
    /**
     * Afgerond's order: the highest final percentage first; between equal
     * percentages the goal made most recently first.
     */
    function goals_completed_compare(array $a, array $b): int
    {
        $order = ($b['final_percent'] ?? 0) <=> ($a['final_percent'] ?? 0);

        if ($order !== 0) {
            return $order;
        }

        return [(string) ($b['created_at'] ?? ''), (int) $b['id']] <=> [(string) ($a['created_at'] ?? ''), (int) $a['id']];
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
    /** Turns one hydrated goal into everything the templates and the app need. */
    function goals_expand(array $goal, array $config, DateTimeImmutable $today): array
    {
        $goal += [
            'type' => 'milestone', 'variant' => null, 'priority' => 'secondary', 'status' => 'active',
            'percent' => 0, 'current_label' => null, 'target_label' => null, 'note' => null,
            'tracking' => 'manual', 'final_percent' => null, 'outcome' => null,
        ];

        /* --- category, type ------------------------------------------- */
        $category = $config['categories'][$goal['category']] ?? reset($config['categories']);
        $goal['category_key']   = $goal['category'];
        $goal['category_label'] = $category['label'];
        $goal['icon']           = $category['icon'];
        $goal['accent']         = $category['accent'];
        $goal['type_label']     = $config['types'][$goal['type']]['label'] ?? '';
        $goal['variant_label']  = $goal['variant'] !== null ? ($config['streak_variants'][$goal['variant']]['label'] ?? null) : null;
        $goal['kind_label']     = $goal['variant_label'] === null ? $goal['type_label'] : $goal['type_label'] . ' · ' . $goal['variant_label'];

        /* --- state ----------------------------------------------------- */
        $goal['is_primary']   = $goal['priority'] === 'primary';
        $goal['is_paused']    = $goal['status'] === 'paused';
        $goal['is_completed'] = in_array($goal['status'], ['completed', 'ended'], true);
        $goal['is_reached']   = $goal['status'] === 'completed';

        $goal['ratio'] = score_ratio($goal['percent'], 100);

        /* --- dates ------------------------------------------------------ */
        $start = new DateTimeImmutable((string) $goal['start_date']);
        $end   = new DateTimeImmutable((string) $goal['end_date']);
        $done  = $goal['completed_at'] !== null ? new DateTimeImmutable(substr((string) $goal['completed_at'], 0, 10)) : null;

        $goal['start_label'] = goals_date_short($start);
        $goal['end_label']   = goals_date_short($end);
        $goal['done_label']  = $done === null ? null : goals_date_short($done);

        $goal['days_left'] = $goal['is_completed'] ? null : (int) $today->diff($end)->format('%r%a');

        $goal['took_label'] = $done === null ? null : goals_span_text(max(1, (int) $start->diff($done)->days + 1));

        $goal['remaining'] = goals_remaining_text($goal, $config);

        /* Both readings of the line under the bar, so pausing and resuming
           on the page needs no copy of its own. */
        $goal['line_active']   = goals_deadline_line($goal, $config, false);
        $goal['line_paused']   = goals_deadline_line($goal, $config, true);
        $goal['deadline_line'] = $goal['is_paused'] ? $goal['line_paused'] : $goal['line_active'];

        $duration = goal_duration_of($start, $end);
        $goal['duration_label'] = $duration === null ? null : ($config['durations'][$duration]['label'] ?? null);

        /* --- where its figures come from -------------------------------- */
        $shape = $config['sources'][$goal['tracking'] === 'auto' ? 'auto' : 'manual'];
        $goal['source_list'] = [[
            'key'   => $goal['tracking'],
            'label' => $goal['tracking'] === 'auto' ? (string) $goal['measurement_label'] : $shape['label'],
            'note'  => $goal['tracking'] === 'auto' ? $shape['note'] : $shape['note'],
        ] + $shape];

        /* Only a goal kept by hand, and still running, asks for anything. */
        $goal['needs_input'] = !empty($goal['is_manual']) && !$goal['is_completed'];

        /* The Overzicht card and the bar's reading: the goal's own numbers. */
        $goal['reading'] = match (true) {
            $goal['current_label'] === null           => $goal['target_label'],
            $goal['type'] === 'milestone'             => tf('%1$s, doel %2$s', $goal['current_label'], (string) $goal['target_label']),
            $goal['type'] === 'accumulate'            => tf('%1$s van %2$s', $goal['current_label'], (string) $goal['target_label']),
            default                                   => $goal['current_label'],
        };

        /* --- the Verloop ------------------------------------------------ */
        require_once __DIR__ . '/goal-chart.php';

        $goal['chart'] = goal_chart_build($goal, $goal['series'] ?? ['points' => [], 'mode' => 'value', 'target' => null]);
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

        if ($days < 0) {
            return t('Periode voorbij');
        }

        if ($days === 0) {
            return $config['labels']['last_day'];
        }

        return tf('Nog %s', goals_span_text($days));
    }
}

if (!function_exists('goals_span_text')) {
    /** A length of time, rounded to the unit a person would actually say. */
    function goals_span_text(int $days): string
    {
        if ($days >= 330) {
            $years = (int) round($days / 365);
            return $years === 1 ? t('1 jaar') : tf('%d jaar', $years);
        }

        if ($days >= 60) {
            $months = (int) round($days / 30);
            return tf('%d maanden', $months);
        }

        /* Days stay days for a good six weeks: "nog 18 dagen" is what a
           person counting down actually says. */
        if ($days >= 45) {
            $weeks = (int) round($days / 7);
            return tf('%d weken', $weeks);
        }

        return tn($days, '%d dag', '%d dagen');
    }
}

if (!function_exists('goals_deadline_line')) {
    /**
     * The line under the bar: time left and the date it runs to; finished,
     * when and how it ended. `$paused` overrides the goal's own status so
     * both readings can be produced up front.
     */
    function goals_deadline_line(array $goal, array $config, ?bool $paused = null): string
    {
        $paused = $paused ?? $goal['is_paused'];

        if ($goal['is_completed']) {
            if ($goal['is_reached']) {
                $line = sprintf($config['labels']['completed_on'], (string) $goal['done_label']);
                if ($goal['took_label'] !== null) {
                    $line .= ' · ' . sprintf($config['labels']['took'], $goal['took_label']);
                }
                return $line;
            }

            return sprintf($config['labels']['ended_on'], (string) $goal['done_label']);
        }

        if ($paused) {
            return $config['labels']['paused_line'];
        }

        return (string) $goal['remaining'] . ' · ' . tf('t/m %s', $goal['end_label']);
    }
}

if (!function_exists('goals_date_short')) {
    /** "4 okt" — short enough to sit on one line beside the time remaining. */
    function goals_date_short(DateTimeInterface $date): string
    {
        return i18n_date($date, 'day_mon');
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
     * The words and the catalogue goals.js and goal-wizard.js work from, as
     * the page embeds them (`[data-goals-copy]`): the Doelen page's, and the
     * setup's, which opens the same wizard (pages/setup.php). The app reads
     * the same through api/app/state.php (`goals`).
     */
    function goals_script_copy(array $goals): array
    {
        return [
            'labels'       => $goals['labels'],
            'detail'       => $goals['detail'],
            'limits'       => $goals['limits'],
            'views'        => array_map(static fn (array $v): string => $v['label'], $goals['views']),
            'categories'   => $goals['categories'],
            'measurements' => $goals['measurements'],
            'custom'       => $goals['custom'],
            'goal_units'   => $goals['goal_units'],
            'types'        => $goals['types'],
            'streak_variants' => $goals['streak_variants'],
            'periods'      => $goals['periods'],
            'durations'    => $goals['durations'],
            'wizard'       => $goals['wizard'],
            'user_auto'    => $goals['user_auto'] ?? [],
            'units_system' => units_system(),
            'week_start'   => week_start(),
        ];
    }
}
