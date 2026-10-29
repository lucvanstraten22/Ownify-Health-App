<?php
/**
 * Ownify AI: the things the assistant can ask Ownify to do.
 *
 * A fixed list, declared to Gemini and carried out here — never SQL, never a
 * URL, never anything the model makes up. Every argument is checked before
 * it is used, and every tool acts for the signed-in user only: the user id
 * comes from the session or the app's token (api_require_account_user()),
 * never from the model or the request.
 *
 *   read    get_health_summary, get_sleep_summary, get_training_summary,
 *           get_recent_activity, get_nutrition_summary, get_goals,
 *           get_measurement_history — the same blocks as the context
 *           (includes/ai/context.php), for the period the model asks for
 *
 *   change  create_goal, update_goal — these change NOTHING. They check the
 *           change with the same rules as the wizard (goal_create_from_input
 *           in dry-run) and prepare it as a proposal on the assistant's
 *           message. It happens only when its owner confirms it
 *           (api/ai/action.php, or a plain "ja" — includes/ai/assistant.php),
 *           and is checked again at that moment.
 *
 * A research tool (PubMed, say) would be one more entry here, read-only like
 * the others; nothing else would have to change.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/i18n.php';   // language and units (docs/I18N.md)

require_once __DIR__ . '/context.php';
require_once dirname(__DIR__) . '/goal-create.php';

if (!function_exists('ai_tool_declarations')) {

    /** The function declarations sent to Gemini (OpenAPI-style schema, as the API takes it). */
    function ai_tool_declarations(): array
    {
        $days = static fn (int $max, int $default): array => [
            'type'        => 'INTEGER',
            'description' => 'How many days back from today, 1-' . $max . '. Default ' . $default . '.',
        ];

        $goalConfig = config_copy('goals');

        /* The measurements, each with its unit codes, so the model can only
           name something the wizard offers. */
        $measures = [];
        foreach (goal_measurements() as $code => $m) {
            $measures[] = $code . ' (' . $m['category'] . '; types ' . implode('/', $m['types'])
                . ($m['variants'] !== [] ? '; streak ' . implode('/', $m['variants']) : '')
                . '; units ' . implode('/', $m['unit_codes']) . ')';
        }

        return [
            [
                'name'        => 'get_health_summary',
                'description' => "The user's Health Score (overall, sleep, nutrition, training, with their components) and how it moved day by day, plus the last 7 days against the 7 before.",
                'parameters'  => ['type' => 'OBJECT', 'properties' => ['days' => $days(90, 28)]],
            ],
            [
                'name'        => 'get_sleep_summary',
                'description' => "The user's nights (time asleep and in bed, bedtime, wake time, efficiency, stages, awakenings), averages, and sleep-time measurements such as heart rate and HRV.",
                'parameters'  => ['type' => 'OBJECT', 'properties' => ['days' => $days(90, 14)]],
            ],
            [
                'name'        => 'get_training_summary',
                'description' => "The user's workouts (type, duration, distance, speed, heart rate, effort), totals per week, and readiness and training load where measured.",
                'parameters'  => ['type' => 'OBJECT', 'properties' => ['days' => $days(90, 28)]],
            ],
            [
                'name'        => 'get_recent_activity',
                'description' => "The user's everyday movement per day: steps, distance, active minutes, active calories, floors.",
                'parameters'  => ['type' => 'OBJECT', 'properties' => ['days' => $days(90, 14)]],
            ],
            [
                'name'        => 'get_nutrition_summary',
                'description' => "What the user logged about eating per day (their own 1-10 rating, calories, protein, carbs, fat, fibre, sugar, water, meals) and their calculated calorie and protein targets.",
                'parameters'  => ['type' => 'OBJECT', 'properties' => ['days' => $days(60, 14)]],
            ],
            [
                'name'        => 'get_goals',
                'description' => "The user's goals as they stand right now: type, status, priority, progress, current and target values, days met, time left.",
                'parameters'  => ['type' => 'OBJECT', 'properties' => [
                    'include_completed' => ['type' => 'BOOLEAN', 'description' => 'Also the last five completed goals.'],
                ]],
            ],
            [
                'name'        => 'get_measurement_history',
                'description' => "The user's recorded body measurements over time, newest first.",
                'parameters'  => ['type' => 'OBJECT', 'properties' => [
                    'type'  => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['weight', 'body_fat_pct', 'waist_cm', 'lean_mass', 'height']],
                    'limit' => ['type' => 'INTEGER', 'description' => 'How many, 1-100. Default 30.'],
                ], 'required' => ['type']],
            ],
            [
                'name'        => 'create_goal',
                'description' => 'Prepares a NEW goal as a proposal. Nothing is created: the user confirms it with a button. '
                    . 'Use only when the user asked for the goal or agreed to it. It starts today. '
                    . 'Examples: "10,000 steps a day for a month" = measurement steps, type streak, variant daily, target_value 10000, unit steps, duration month. '
                    . '"Train 3 times a week for 3 months" = measurement workouts, type streak, variant period, frequency 3, period week, duration quarter. '
                    . '"Walk 100 km this month" = measurement distance, type accumulate, target_value 100, unit km, duration month. '
                    . '"Weigh 75 kg within half a year" = measurement weight, type milestone, target_value 75, unit kg, direction decrease, duration halfyear. '
                    . '"Bench press 100 kg" = measurement custom, category training, type milestone, target_value 100, unit kg, direction increase, baseline_value (ask the user for their current best).',
                'parameters'  => ['type' => 'OBJECT', 'properties' => [
                    'name'           => ['type' => 'STRING', 'description' => 'Short name in the user\'s words, 2-120 characters, in their language.'],
                    'measurement'    => ['type' => 'STRING', 'format' => 'enum', 'enum' => array_merge(array_keys($goalConfig['measurements']), ['custom']),
                        'description' => 'What it is about. custom = an own habit, kept by hand. One of: ' . implode(', ', $measures) . '.'],
                    'category'       => ['type' => 'STRING', 'format' => 'enum', 'enum' => array_keys($goalConfig['categories']),
                        'description' => 'Only for measurement custom: which category the habit belongs to.'],
                    'type'           => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['milestone', 'streak', 'accumulate'],
                        'description' => 'milestone = reach one result; streak = keep a routine up; accumulate = build a total over the period.'],
                    'variant'        => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['daily', 'period'],
                        'description' => 'Only for streak: daily = a target every day; period = a number of times per week/month/quarter/year.'],
                    'target_value'   => ['type' => 'NUMBER', 'description' => 'Not for a period streak. Milestone: the result to reach; daily streak: what each day must reach; accumulate: the total — all in `unit`.'],
                    'unit'           => ['type' => 'STRING', 'description' => 'One of the measurement\'s unit codes (see measurement). For custom: times, min, h, pages, glasses, reps, km, kg, or custom with custom_unit.'],
                    'custom_unit'    => ['type' => 'STRING', 'description' => 'Only with unit custom: the habit\'s own unit word, max 20 characters.'],
                    'direction'      => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['increase', 'decrease'],
                        'description' => 'Where both make sense: milestone — is higher or lower better; daily streak — at least or at most target_value.'],
                    'frequency'      => ['type' => 'INTEGER', 'description' => 'Only for a period streak: how many times per period.'],
                    'period'         => ['type' => 'STRING', 'format' => 'enum', 'enum' => array_keys($goalConfig['periods']), 'description' => 'Only for a period streak.'],
                    'duration'       => ['type' => 'STRING', 'format' => 'enum', 'enum' => array_keys($goalConfig['durations']), 'description' => 'How long the goal runs from today.'],
                    'baseline_value' => ['type' => 'NUMBER', 'description' => 'Only for a milestone without data to start from (custom, or no measurements yet): the user\'s current value, in `unit`.'],
                ], 'required' => ['name', 'measurement', 'type', 'duration']],
            ],
            [
                'name'        => 'update_goal',
                'description' => 'Prepares a change to one of the user\'s existing goals as a proposal. Nothing changes until the user confirms it with a button. Take goal_id from the goals data.',
                'parameters'  => ['type' => 'OBJECT', 'properties' => [
                    'goal_id' => ['type' => 'INTEGER'],
                    'action'  => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['pause', 'resume', 'primary', 'secondary'],
                        'description' => 'pause, resume a paused goal, make it the primary goal, or make it a secondary goal. A goal finishes by itself: at 100%, or when its end date has passed.'],
                ], 'required' => ['goal_id', 'action']],
            ],
        ];
    }

    /**
     * Carries out one call from Gemini and returns what goes back to it as
     * the function's response. A proposal is put in $turn['proposal'] — one
     * per answer at most.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $turn  per-question state: proposal, tools
     * @return array<string,mixed>
     */
    function ai_tool_run(int $userId, string $name, array $args, array &$turn): array
    {
        $turn['tools'][] = $name;

        try {
            return match ($name) {
                'get_health_summary'      => ai_tool_days($args, 90, 28, static fn (int $d) => [
                    'scores' => ai_block_scores($userId, $d),
                    'last_7_days_vs_previous_7' => ai_block_week($userId),
                ]),
                'get_sleep_summary'       => ai_tool_days($args, 90, 14, static fn (int $d) => ai_block_sleep($userId, $d)),
                'get_training_summary'    => ai_tool_days($args, 90, 28, static fn (int $d) => ai_block_training($userId, $d)),
                'get_recent_activity'     => ai_tool_days($args, 90, 14, static fn (int $d) => ai_block_activity($userId, $d)),
                'get_nutrition_summary'   => ai_tool_days($args, 60, 14, static fn (int $d) => ai_block_nutrition($userId, $d)),
                'get_goals'               => ai_tool_goals($userId, $args),
                'get_measurement_history' => ai_tool_measurements($userId, $args),
                'create_goal'             => ai_tool_create_goal($userId, $args, $turn),
                'update_goal'             => ai_tool_update_goal($userId, $args, $turn),
                default                   => ['error' => 'Unknown tool.'],
            };
        } catch (Throwable $e) {
            error_log('[ownify] ai: tool ' . $name . ' failed: ' . $e::class);

            return ['error' => 'This could not be read just now.'];
        }
    }

    /* ======================================================= argument checks */

    /** An optional whole number within [$min, $max]; null when absent, false when invalid. */
    function ai_arg_int(array $args, string $key, int $min, int $max): int|null|false
    {
        if (!array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
            return null;
        }

        $value = $args[$key];

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        } elseif (is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < $min || $value > $max) {
            return false;
        }

        return $value;
    }

    function ai_tool_days(array $args, int $max, int $default, callable $read): array
    {
        $days = ai_arg_int($args, 'days', 1, $max);

        if ($days === false) {
            return ['error' => 'days must be a whole number from 1 to ' . $max . '.'];
        }

        return $read($days ?? $default);
    }

    function ai_tool_goals(int $userId, array $args): array
    {
        $completed = $args['include_completed'] ?? false;

        if (!is_bool($completed)) {
            return ['error' => 'include_completed must be true or false.'];
        }

        return ['goals' => ai_block_goals($userId, true)] + ($completed ? [] : ['note' => 'Completed goals left out; ask with include_completed true for them.']);
    }

    function ai_tool_measurements(int $userId, array $args): array
    {
        $type  = $args['type'] ?? null;
        $limit = ai_arg_int($args, 'limit', 1, 100);

        if (!in_array($type, ['weight', 'body_fat_pct', 'waist_cm', 'lean_mass', 'height'], true)) {
            return ['error' => 'type must be one of weight, body_fat_pct, waist_cm, lean_mass, height.'];
        }

        if ($limit === false) {
            return ['error' => 'limit must be a whole number from 1 to 100.'];
        }

        $rows = user_measurement_history($userId, $type, $limit ?? 30);

        return $rows === []
            ? ['type' => $type, 'note' => 'Nothing recorded.']
            : ['type' => $type, 'measurements' => array_map(static fn (array $r): array => [
                'date'  => substr((string) $r['measured_at'], 0, 10),
                'value' => ai_round((float) $r['value'], 1),
                'unit'  => $r['unit'],
            ], $rows)];
    }

    /* ============================================================ proposals */

    /**
     * create_goal: checked with the wizard's own rules, and prepared — not
     * made. What is kept is exactly what was checked.
     */
    function ai_tool_create_goal(int $userId, array $args, array &$turn): array
    {
        if (($turn['proposal'] ?? null) !== null) {
            return ['error' => 'Only one change can be proposed per answer. Ask the user about the first one.'];
        }

        $strings = ['name', 'measurement', 'category', 'type', 'variant', 'unit', 'custom_unit', 'direction', 'period', 'duration'];
        $input   = [];

        foreach ($strings as $key) {
            if (!array_key_exists($key, $args) || $args[$key] === null) {
                continue;
            }
            if (!is_string($args[$key])) {
                return ['error' => $key . ' must be text.'];
            }
            $input[$key] = trim($args[$key]);
        }

        foreach (['target_value', 'frequency', 'baseline_value'] as $key) {
            if (!array_key_exists($key, $args) || $args[$key] === null) {
                continue;
            }
            if (!is_int($args[$key]) && !is_float($args[$key]) && !(is_string($args[$key]) && is_numeric($args[$key]))) {
                return ['error' => $key . ' must be a number.'];
            }
            $input[$key] = (string) $args[$key];
        }

        /* It starts today and runs for the looptijd asked for. */
        $today = goal_today();
        $end   = goal_duration_end($today, (string) ($input['duration'] ?? ''));
        if ($end === null) {
            return ['error' => 'duration must be one of ' . implode(', ', array_keys(config_copy('goals')['durations'])) . '.'];
        }
        $input['start_date'] = $today->format('Y-m-d');
        $input['end_date']   = $end->format('Y-m-d');
        unset($input['duration']);

        $check = goal_create_from_input($userId, $input, true);

        if (!$check['ok']) {
            return ['error' => (string) $check['error'], 'note' => 'Nothing was prepared. Explain this to the user or ask for what is missing.'];
        }

        $summary = ai_goal_summary($check['goal'], $input);

        $turn['proposal'] = [
            'kind'    => 'create_goal',
            'input'   => $input,
            'title'   => tf('Nieuw doel: %s', $check['goal']['name']),
            'summary' => $summary,
            /* Kept as Dutch, said in the reader's language when shown. */
            'confirm' => 'Doel toevoegen',
            'decline' => 'Niet nu',
        ];

        return [
            'status'  => 'proposed',
            'proposal' => 'New goal "' . $check['goal']['name'] . '": ' . $summary,
            'note'    => 'Nothing has been created. In one or two sentences, tell the user what you prepared and ask whether they want it; the app shows a button to add it and one to decline.',
        ];
    }

    /** update_goal: the goal must be the user's, and the change must make sense for it now. */
    function ai_tool_update_goal(int $userId, array $args, array &$turn): array
    {
        if (($turn['proposal'] ?? null) !== null) {
            return ['error' => 'Only one change can be proposed per answer. Ask the user about the first one.'];
        }

        $goalId = ai_arg_int($args, 'goal_id', 1, PHP_INT_MAX);
        $action = $args['action'] ?? null;

        if (!is_int($goalId)) {
            return ['error' => 'goal_id must be the id of one of the user\'s goals.'];
        }

        $labels = ai_goal_action_labels();

        if (!is_string($action) || !isset($labels[$action])) {
            return ['error' => 'action must be one of pause, resume, primary, secondary.'];
        }

        $goal = goal_get($userId, $goalId);

        if ($goal === null) {
            return ['error' => 'The user has no goal with this goal_id.'];
        }

        if (in_array($goal['status'], ['completed', 'ended'], true)) {
            return ['error' => 'This goal is finished; its final result is kept and it cannot be changed.'];
        }

        $refusal = match (true) {
            $action === 'pause' && $goal['status'] !== 'active'        => 'Only an active goal can be paused.',
            $action === 'resume' && $goal['status'] !== 'paused'       => 'Only a paused goal can be resumed.',
            $action === 'primary' && $goal['status'] !== 'active'      => 'Only an active goal can be the primary goal.',
            $action === 'primary' && $goal['priority'] === 'primary'   => 'This goal already is the primary goal.',
            $action === 'secondary' && $goal['priority'] !== 'primary' => 'This goal already is a secondary goal.',
            default => null,
        };

        if ($refusal !== null) {
            return ['error' => $refusal];
        }

        $turn['proposal'] = [
            'kind'    => 'update_goal',
            'goal_id' => $goalId,
            'action'  => $action,
            'title'   => t($labels[$action]['title']) . ': ' . $goal['name'],
            'summary' => t($labels[$action]['summary']),
            /* Kept as Dutch, the canonical words; said in the reader's
               language whenever it is shown (ai_message_view()). */
            'confirm' => $labels[$action]['confirm'],
            'decline' => 'Niet nu',
        ];

        return [
            'status'  => 'proposed',
            'proposal' => $action . ' goal "' . $goal['name'] . '"',
            'note'    => 'Nothing has changed yet. In one sentence, tell the user what you prepared and ask whether they want it; the app shows a button to confirm and one to decline.',
        ];
    }

    function ai_goal_action_labels(): array
    {
        return [
            'pause'     => ['title' => 'Doel pauzeren',     'summary' => 'Het doel telt tijdelijk niet mee; je kunt het later hervatten.', 'confirm' => 'Pauzeren',        'done' => 'Doel ‘%s’ gepauzeerd.'],
            'resume'    => ['title' => 'Doel hervatten',    'summary' => 'Het doel telt weer mee.',                                        'confirm' => 'Hervatten',        'done' => 'Doel ‘%s’ hervat.'],
            'primary'   => ['title' => 'Primair doel maken', 'summary' => 'Je huidige primaire doel wordt dan secundair.',                 'confirm' => 'Primair maken',    'done' => '‘%s’ is nu je primaire doel.'],
            'secondary' => ['title' => 'Secundair maken',   'summary' => 'Een ander actief doel wordt dan primair.',                       'confirm' => 'Secundair maken',  'done' => '‘%s’ is nu een secundair doel.'],
        ];
    }

    /**
     * One line that says what a proposed goal is, in the app's words — in
     * the reader's language and units: the goal as checked (its stored,
     * metric figures), shown as the board would show it.
     */
    function ai_goal_summary(array $goal, array $input): string
    {
        $config      = config_copy('goals');
        $measurement = goal_measurement($goal['measurement_code']) ?? goal_measurement(null);
        $unit        = $goal['unit_code'] !== null ? goal_unit($goal['unit_code']) : goal_unit_custom((string) $goal['custom_unit']);
        $parts       = [$config['types'][$goal['goal_type']]['label'] ?? $goal['goal_type']];

        if ($goal['streak_variant'] === 'period') {
            $parts[] = tf('%1$d keer per %2$s', (int) $goal['frequency'], (string) $config['periods'][$goal['period_unit']]['label']);
        } elseif ($goal['streak_variant'] === 'daily') {
            $daily   = goal_value_text((float) $goal['target_value'], $unit);
            $parts[] = $goal['direction'] === 'decrease' ? tf('per dag maximaal %s', $daily) : tf('per dag minstens %s', $daily);
        } else {
            $parts[] = goal_value_text((float) $goal['target_value'], $unit);
            if ($goal['goal_type'] === 'milestone') {
                $parts[] = tf('vanaf %s', goal_value_text((float) $goal['baseline_value'], $unit));
            }
        }

        $parts[] = $goal['tracking'] === 'auto'
            ? tf('uit je gegevens: %s', mb_strtolower((string) $measurement['label']))
            : t('zelf bijhouden');

        $parts[] = tf('%1$s t/m %2$s', i18n_date($goal['start_date'], 'day_mon'), i18n_date($goal['end_date'], 'day_mon'));

        return implode(' · ', $parts);
    }

    /**
     * Carries out a proposal its owner has just confirmed — checked again
     * now, with the rules as they stand now: the goal may have been deleted
     * or every place filled since it was proposed.
     *
     * @return array{ok: bool, message: string}
     */
    function ai_action_execute(int $userId, array $action): array
    {
        if (($action['kind'] ?? null) === 'create_goal' && is_array($action['input'] ?? null)) {
            $result = goal_create_from_input($userId, $action['input']);

            return $result['ok']
                ? ['ok' => true, 'message' => tf('Doel ‘%s’ toegevoegd. Je vindt het bij Doelen.', $result['goal']['name'])]
                : ['ok' => false, 'message' => tf('Het doel kon niet worden toegevoegd: %s.', rtrim((string) $result['error'], '.'))];
        }

        if (($action['kind'] ?? null) === 'update_goal') {
            $goalId = (int) ($action['goal_id'] ?? 0);
            $verb   = (string) ($action['action'] ?? '');
            $labels = ai_goal_action_labels();
            $goal   = goal_get($userId, $goalId);

            if ($goal === null || in_array($goal['status'], ['completed', 'ended'], true) || !isset($labels[$verb])) {
                return ['ok' => false, 'message' => t('Dit doel bestaat niet meer.')];
            }

            $ok = goal_apply_action($userId, $goalId, $verb);

            return $ok === true
                ? ['ok' => true, 'message' => tf($labels[$verb]['done'], $goal['name'])]
                : ['ok' => false, 'message' => t('Deze wijziging kon niet worden opgeslagen.')];
        }

        return ['ok' => false, 'message' => t('Dit voorstel kan niet worden uitgevoerd.')];
    }
}
