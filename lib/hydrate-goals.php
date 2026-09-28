<?php
/**
 * Fills the Doelen page from the signed-in user's own goals.
 *
 * config/goals.php keeps the vocabulary — the categories, the types, the
 * durations, the copy. The goals themselves come from the `goals` table, and
 * a user with none gets the page's existing empty state rather than examples.
 *
 * Progress is never invented. Where a goal stands comes from one place,
 * includes/goal-progress.php, which works it out from the user's own rows the
 * way the goal's type says to — the best result, the streak, the total. A
 * goal with nothing recorded reports no percentage at all, and the card
 * already knows how to draw that.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/goals.php';
require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/goal-progress.php';

if (!function_exists('hydrate_goals')) {

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

        /* Recomputed from the user's own rows on every render, and stored and
           completed as a side effect. That is what makes an automatic goal
           feel immediate: import some steps and the bar has already moved by
           the time the page is drawn, with nobody pressing anything.

           It also means the figure on the card cannot disagree with the
           figure on the detail screen, because there is only one of them —
           and there is no fallback to an older stored figure when it has no
           answer. No answer is shown as no answer. */
        $progress = goal_refresh_row($userId, $row, $today);

        /* The refresh may have just finished this goal, so the row is re-read
           rather than reporting a status that is one render out of date. */
        if ($row['status'] === 'active') {
            $row = goal_get($userId, $goalId) ?? $row;
        }

        $kind    = $progress['kind'];
        $isAuto  = !goal_is_manual($row);
        $words   = hydrate_goal_words($row, $progress);

        /* A day ticked off once is ticked off; the button says so rather than
           inviting a second tick that would change nothing. */
        if (($words['entry']['kind'] ?? null) === 'tick') {
            $words['entry']['done'] = ($progress['states'][$today->format('Y-m-d')] ?? null) === 'met';
        }

        /* The real values behind the goal, dated, for the Verloop chart — the
           kilos or the steps themselves, not the percentage. */
        $series = goal_series($userId, $row, $today);

        return [
            'id'       => (string) $goalId,
            'name'     => $row['name'],
            'category' => hydrate_goal_category($row['category'], $config),
            'type'     => $kind,
            'priority' => $row['priority'] === 'primary' ? 'primary' : 'secondary',
            'status'   => $row['status'],

            'percent'       => $progress['percent'] === null ? null : (int) floor($progress['percent']),
            'current_label' => $words['current'],
            'current_title' => $words['current_title'],
            'target_label'  => $words['target'],
            'extra_facts'   => $words['facts'],

            'started_days_ago' => $start === null ? null : (int) $start->diff($today)->days,
            'ends_in_days'     => $end === null ? null : (int) $today->diff($end)->days * ($end < $today ? -1 : 1),
            'duration'         => hydrate_goal_duration($start, $end, $config),

            /* What the person chose, not what the category implies. A goal
               with no source says so; it does not borrow one. */
            'sources'      => hydrate_goal_sources($row['category']),
            'tracking'     => $isAuto ? 'auto' : 'manual',
            'source_kind'  => $row['source_kind'] ?? 'manual',
            'source_key'   => $row['source_key'] ?? null,
            'source_label' => (goal_source_find($row['source_kind'] ?? null, $row['source_key'] ?? null)['label'] ?? null),
            'direction'    => $row['direction'] ?? 'increase',
            'daily_target' => ($row['daily_target'] ?? null) === null ? null : (float) $row['daily_target'],
            'daily_label'  => $words['daily'],

            /* Per-day results for a goal that counts days, each one met,
               missed, not known, or — today — still open. Empty for every
               other kind of goal. */
            'counts_days' => $progress['counts_days'],
            'days'        => $progress['days'],
            'days_met'    => $progress['met'],
            'days_total'  => $progress['days_total'],
            'days_chip'   => $words['days_chip'],
            'days_note'   => $words['days_note'],
            'mode'        => $progress['mode'],

            /* Whether this goal is the person's to fill in by hand, and how. */
            'is_manual'   => !$isAuto,
            'entry'       => $words['entry'],

            'series'       => $series,
            'target_unit'  => $row['target_unit'],
            'history_step' => 'day',
            'activity'     => hydrate_goal_activity($userId, $row, $kind, $progress['counts_days']),

            /* Completed goals are sorted by how recently they finished. */
            'completed_days_ago' => $row['status'] === 'completed'
                ? (int) (new DateTimeImmutable((string) ($row['completed_at'] ?? $row['updated_at'])))->diff($today)->days
                : null,

            'note' => $words['note'],
        ];
    }

    /**
     * A figure from this goal, written the way the goal counts it: days for
     * a goal that counts days, the source's own unit for one read from health
     * data ("7,5 uur", not "450 min"), and whatever the person called it for
     * one they keep themselves.
     */
    function hydrate_goal_figure(array $row, ?float $value, bool $days): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($days) {
            return goal_days_text($value);
        }

        if (!goal_is_manual($row)) {
            return goal_unit_text($value, goal_source_unit($row['source_kind'] ?? null, $row['source_key'] ?? null));
        }

        return hydrate_goal_label($value, $row['target_unit']);
    }

    /**
     * Every sentence the card and the detail page say about a goal that
     * depends on its type — so the three types read as three different
     * things, because they are.
     *
     * @return array{current: ?string, current_title: string, target: ?string, daily: ?string,
     *               facts: list<array{label: string, value: string}>, note: ?string,
     *               days_chip: ?string, days_note: ?string, entry: ?array}
     */
    function hydrate_goal_words(array $row, array $progress): array
    {
        $kind     = $progress['kind'];
        $days     = $progress['counts_days'];
        $manual   = goal_is_manual($row);
        $decrease = ($row['direction'] ?? 'increase') === 'decrease';
        $target   = $row['target_value'] === null ? null : (float) $row['target_value'];

        /* "minstens 10.000 stappen": what one day has to reach to count. */
        $daily = null;
        if (!$manual && ($row['daily_target'] ?? null) !== null) {
            $daily = ($decrease ? 'hoogstens ' : 'minstens ')
                . goal_unit_text((float) $row['daily_target'], goal_source_unit($row['source_kind'], $row['source_key']));
        }

        $out = [
            'current' => null, 'current_title' => 'Nu', 'target' => null, 'daily' => $daily,
            'facts' => [], 'note' => null, 'days_chip' => null, 'days_note' => null, 'entry' => null,
        ];

        switch ($kind) {
            case 'streak':
                $out['current_title'] = 'Huidige streak';
                $out['current']       = $progress['streak'] === null ? null : goal_days_text($progress['streak']);
                $out['target']        = $target === null ? null : goal_days_text($target) . ' op rij';

                if ($progress['longest'] !== null) {
                    $out['facts'][] = ['label' => 'Langste streak', 'value' => goal_days_text($progress['longest'])];
                }

                $out['note'] = $manual
                    ? 'Vink elke dag af die gelukt is. Alleen dagen achter elkaar tellen: een dag zonder vinkje breekt je streak.'
                    : 'Elke dag met ' . $daily . ' telt. Alleen dagen achter elkaar tellen: een dag die dat niet haalt, of zonder gegevens, breekt je streak.';

                $out['days_chip'] = $progress['streak'] === null ? null : 'Nu ' . $progress['streak'] . ' op rij';
                $out['days_note'] = $manual
                    ? 'Vandaag telt zodra je hem afvinkt, en breekt je streak pas als de dag voorbij is.'
                    : 'Een dag zonder gegevens is niet gemist, maar ook niet gehaald: daarna begint je streak opnieuw. Vandaag telt pas als hij gehaald is.';
                $out['entry'] = [
                    'kind'  => 'tick',
                    'label' => 'Vandaag gelukt',
                    'note'  => 'Eén vinkje per dag. Een dag zonder vinkje breekt je streak.',
                ];
                break;

            case 'accumulate':
                $out['current_title'] = 'Totaal';
                $out['current']       = hydrate_goal_figure($row, $progress['total'], $days);
                $out['target']        = hydrate_goal_figure($row, $target, $days);

                if ($days) {
                    $out['note'] = $manual
                        ? 'Vink elke dag af die gelukt is. Elke dag telt op, ook als ze niet achter elkaar liggen.'
                        : 'Elke dag met ' . $daily . ' telt als één dag. Ze hoeven niet achter elkaar te liggen.';
                    $out['days_chip'] = $progress['met'] . ' van ' . ($target === null ? '—' : (int) $target) . ' dagen';
                    $out['days_note'] = 'Dagen zonder gegevens tellen niet mee als gemist, en ook niet als gehaald.';
                    $out['entry']     = [
                        'kind'  => 'tick',
                        'label' => 'Vandaag gelukt',
                        'note'  => 'Eén vinkje per dag. Elke gelukte dag telt op.',
                    ];
                } else {
                    $out['note']  = 'Alles telt op tot één totaal. Een dag zonder gegevens voegt niets toe, maar haalt ook niets af.';
                    $out['entry'] = [
                        'kind'        => 'add',
                        'label'       => 'Hoeveel komt erbij?',
                        'button'      => 'Toevoegen',
                        'placeholder' => 'Hoeveel' . (trim((string) $row['target_unit']) === '' ? '' : ' ' . trim((string) $row['target_unit'])),
                        'note'        => 'Elke invoer telt op bij je totaal, ook twee keer op één dag.',
                    ];
                }
                break;

            default:
                $out['current_title'] = 'Beste resultaat';
                $out['current']       = hydrate_goal_figure($row, $progress['best'], false);
                $out['target']        = hydrate_goal_figure($row, $target, false);

                if ($progress['latest'] !== null && $progress['latest'] != $progress['best']) {
                    $out['facts'][] = ['label' => 'Laatste resultaat', 'value' => (string) hydrate_goal_figure($row, $progress['latest'], false)];
                }

                if ($decrease && $progress['start'] !== null) {
                    $out['facts'][] = ['label' => 'Startpunt', 'value' => (string) hydrate_goal_figure($row, $progress['start'], false)];
                }

                $out['note'] = $decrease
                    ? 'Je laagste resultaat telt, gemeten vanaf je startpunt. Een hoger resultaat later verlaagt je voortgang niet.'
                    : 'Je beste resultaat telt. Een minder goed resultaat later verlaagt je voortgang niet.';

                if ($target === null) {
                    $out['note'] = 'Dit doel heeft geen doelwaarde, dus er is geen percentage. Je beste resultaat wordt wel bijgehouden.';
                }

                $out['entry'] = [
                    'kind'        => 'result',
                    'label'       => 'Nieuw resultaat',
                    'button'      => 'Opslaan',
                    'placeholder' => 'Resultaat' . (trim((string) $row['target_unit']) === '' ? '' : ' in ' . trim((string) $row['target_unit'])),
                    'note'        => 'Vul elk resultaat in dat je haalt. Het beste telt; een minder resultaat verlaagt niets.',
                ];
                break;
        }

        return $out;
    }

    /**
     * The last few days, as the detail page's "Recent" list.
     *
     * For a goal kept by hand these are the person's own entries — the day's
     * best result, the amount added, or the day ticked off. For one read from
     * health data they are the figure the goal stood at on each day.
     */
    function hydrate_goal_activity(int $userId, array $goal, string $kind, bool $countsDays): array
    {
        $rows = db_all(
            'SELECT p.recorded_on, p.current_value, p.percent_complete
               FROM goal_progress p
               JOIN goals g ON g.id = p.goal_id
              WHERE p.goal_id = ? AND g.user_id = ?
           ORDER BY p.recorded_on DESC
              LIMIT 3',
            [(int) $goal['id'], $userId]
        );

        $today  = new DateTimeImmutable('today');
        $manual = goal_is_manual($goal);

        return array_map(static function (array $r) use ($goal, $kind, $countsDays, $manual, $today): array {
            $day   = new DateTimeImmutable((string) $r['recorded_on']);
            $days  = (int) $day->diff($today)->days;
            $value = $r['current_value'] === null ? null : (float) $r['current_value'];

            $text = match (true) {
                $value === null                        => '',
                $manual && $countsDays                 => 'Gelukt',
                $manual && $kind === 'accumulate'      => '+ ' . hydrate_goal_figure($goal, $value, false),
                !$manual && $kind === 'streak'         => goal_days_text($value) . ' op rij',
                default                                => (string) hydrate_goal_figure($goal, $value, $countsDays),
            };

            return [
                'label' => match (true) {
                    $days === 0 => 'Vandaag',
                    $days === 1 => 'Gisteren',
                    default     => $days . ' dagen geleden',
                },
                'meta'  => $r['percent_complete'] === null
                    ? ''
                    : floor((float) $r['percent_complete']) . '%',
                'value' => $text,
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

        /* The four markers fill as the percentage passes them — each at its
           own place on the bar (`at`: 0, 50, 85, 100). A goal with no
           measurable progress yet keeps them all empty rather than guessing. */
        if ($percent !== null) {
            foreach ($card['milestones'] as $index => $milestone) {
                $card['milestones'][$index]['reached'] = $percent >= (int) ($milestone['at'] ?? 100);
            }
        }

        if ($primary['current_label'] !== null && $primary['target_label'] !== null) {
            $card['unit']    = $primary['current_label'] . ' van ' . $primary['target_label'];
            $card['reading'] = $card['unit'];
        }

        return $card;
    }
}
