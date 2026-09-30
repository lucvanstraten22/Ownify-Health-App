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

        $goalConfig = require dirname(__DIR__, 2) . '/config/goals.php';

        $sources = [];
        foreach (goal_source_catalogue() as $source) {
            if ($source['kind'] === 'metric') {
                $unit      = goal_source_unit($source['kind'], $source['key'])['word'];
                $sources[] = $source['key'] . ($unit === '' ? '' : ' (' . $unit . ')');
            }
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
                'description' => "The user's workouts (type, duration, distance, speed, heart rate, effort), totals per week, and VO2max, readiness and training load where measured.",
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
                    . 'Use only when the user asked for the goal or agreed to it. '
                    . 'Examples: "run 5 km in under 25 minutes" = milestone, category performance, source_kind manual, target_value 25, target_unit "min", direction decrease. '
                    . '"10,000 steps a day for 30 days" = streak, category activity, source_kind metric, source_key steps, target_value 30, daily_target 10000, direction increase, duration month. '
                    . '"Train 12 times this month" = accumulate, category activity, source_kind workout, source_key sessions, target_value 12, duration month. '
                    . '"Weigh 75 kg" = milestone, category weight, source_kind measurement, source_key weight, target_value 75, direction decrease or increase.',
                'parameters'  => ['type' => 'OBJECT', 'properties' => [
                    'name'         => ['type' => 'STRING', 'description' => 'Short name in the user\'s words, max 120 characters, in their language.'],
                    'type'         => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['milestone', 'streak', 'accumulate']],
                    'category'     => ['type' => 'STRING', 'format' => 'enum', 'enum' => array_keys($goalConfig['categories'])],
                    'source_kind'  => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['manual', 'metric', 'workout', 'measurement'],
                        'description' => 'Where progress comes from: manual = the user keeps it themselves; metric = a health metric; workout = recorded workouts; measurement = a body measurement.'],
                    'source_key'   => ['type' => 'STRING', 'description' => 'For metric: one of ' . implode(', ', $sources)
                        . '. For workout: minutes or sessions. For measurement: weight, body_fat_pct, waist_cm or lean_mass. Empty for manual.'],
                    'target_value' => ['type' => 'NUMBER', 'description' => 'The target, in the unit shown for the source (sleep_duration in hours). For a streak, or an accumulate goal that counts days: the number of days.'],
                    'target_unit'  => ['type' => 'STRING', 'description' => 'Only for manual goals: the unit, e.g. kg, min, km, keer. Max 20 characters.'],
                    'measure'      => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['amount', 'days'], 'description' => 'Only for accumulate: add up an amount, or count days.'],
                    'daily_target' => ['type' => 'NUMBER', 'description' => 'For a streak or day-counting goal from health data: what makes one day count, in the source\'s unit.'],
                    'direction'    => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['increase', 'decrease'],
                        'description' => 'Milestone: whether higher (increase) or lower (decrease) is better. Day-counting goal from health data: whether a day must reach at least (increase) or at most (decrease) daily_target.'],
                    'duration'     => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['week', 'month', 'halfyear', 'year', 'none'], 'description' => 'How long the goal runs. none = no end date.'],
                    'priority'     => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['primary', 'secondary'], 'description' => 'primary makes it the main goal. Default secondary.'],
                ], 'required' => ['name', 'type', 'category', 'source_kind', 'target_value']],
            ],
            [
                'name'        => 'update_goal',
                'description' => 'Prepares a change to one of the user\'s existing goals as a proposal. Nothing changes until the user confirms it with a button. Take goal_id from the goals data.',
                'parameters'  => ['type' => 'OBJECT', 'properties' => [
                    'goal_id' => ['type' => 'INTEGER'],
                    'action'  => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['pause', 'resume', 'complete', 'primary', 'secondary'],
                        'description' => 'pause, resume a paused goal, mark as completed, make it the primary goal, or make it a secondary goal.'],
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

        $strings = ['name', 'type', 'category', 'source_kind', 'source_key', 'target_unit', 'measure', 'direction', 'duration', 'priority'];
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

        foreach (['target_value', 'daily_target'] as $key) {
            if (!array_key_exists($key, $args) || $args[$key] === null) {
                continue;
            }
            if (!is_int($args[$key]) && !is_float($args[$key]) && !(is_string($args[$key]) && is_numeric($args[$key]))) {
                return ['error' => $key . ' must be a number.'];
            }
            $input[$key] = (string) $args[$key];
        }

        if (($input['duration'] ?? '') === 'none') {
            $input['duration'] = '';
        }

        if (($input['source_kind'] ?? '') === 'manual') {
            unset($input['source_key']);
        }

        $check = goal_create_from_input($userId, $input, true);

        if (!$check['ok']) {
            return ['error' => (string) $check['error'], 'note' => 'Nothing was prepared. Explain this to the user or ask for what is missing.'];
        }

        $summary = ai_goal_summary($check['goal'], $input);

        $turn['proposal'] = [
            'kind'    => 'create_goal',
            'input'   => $input,
            'title'   => 'Nieuw doel: ' . $check['goal']['name'],
            'summary' => $summary,
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
            return ['error' => 'action must be one of pause, resume, complete, primary, secondary.'];
        }

        $goal = goal_get($userId, $goalId);

        if ($goal === null || $goal['status'] === 'abandoned') {
            return ['error' => 'The user has no goal with this goal_id.'];
        }

        $refusal = match (true) {
            $action === 'pause' && $goal['status'] !== 'active'        => 'Only an active goal can be paused.',
            $action === 'resume' && $goal['status'] !== 'paused'       => 'Only a paused goal can be resumed.',
            $action === 'complete' && $goal['status'] === 'completed'  => 'This goal is already completed.',
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
            'title'   => $labels[$action]['title'] . ': ' . $goal['name'],
            'summary' => $labels[$action]['summary'],
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
            'complete'  => ['title' => 'Doel afronden',     'summary' => 'Het doel gaat naar Behaald.',                                    'confirm' => 'Afronden',         'done' => 'Doel ‘%s’ afgerond.'],
            'primary'   => ['title' => 'Primair doel maken', 'summary' => 'Je huidige primaire doel wordt dan secundair.',                 'confirm' => 'Primair maken',    'done' => '‘%s’ is nu je primaire doel.'],
            'secondary' => ['title' => 'Secundair maken',   'summary' => 'Een ander actief doel wordt dan primair.',                       'confirm' => 'Secundair maken',  'done' => '‘%s’ is nu een secundair doel.'],
        ];
    }

    /** One line that says what a proposed goal is, in the app's words. */
    function ai_goal_summary(array $goal, array $input): string
    {
        $config = require dirname(__DIR__, 2) . '/config/goals.php';
        $parts  = [$config['types'][$goal['kind']]['label'] ?? $goal['kind']];

        $typed  = (float) str_replace(',', '.', (string) ($input['target_value'] ?? '0'));
        $number = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');

        if ($goal['target_unit'] === 'dagen') {
            $parts[] = goal_days_text($typed);
        } elseif ($goal['source_kind'] !== 'manual') {
            $unit    = goal_source_unit($goal['source_kind'], $goal['source_key'])['word'];
            $parts[] = trim($number($typed) . ' ' . $unit);
        } else {
            $parts[] = trim($number($typed) . ' ' . (string) $goal['target_unit']);
        }

        if ($goal['daily_target'] !== null) {
            $unit    = goal_source_unit($goal['source_kind'], $goal['source_key'])['word'];
            $daily   = (float) str_replace(',', '.', (string) $input['daily_target']);
            $parts[] = ($goal['direction'] === 'decrease' ? 'per dag hoogstens ' : 'per dag minstens ') . trim($number($daily) . ' ' . $unit);
        } elseif ($goal['kind'] === 'milestone') {
            $parts[] = $goal['direction'] === 'decrease' ? 'lager is beter' : 'hoger is beter';
        }

        $source  = goal_source_find($goal['source_kind'], $goal['source_key']);
        $parts[] = $source === null ? 'zelf bijhouden' : 'uit je gegevens: ' . mb_strtolower($source['label']);

        $duration = $input['duration'] ?? '';
        $parts[]  = isset($config['durations'][$duration])
            ? 'looptijd: ' . mb_strtolower($config['durations'][$duration]['label'])
            : 'geen einddatum';

        if ($goal['priority'] === 'primary') {
            $parts[] = 'primair doel';
        }

        return implode(' · ', $parts);
    }

    /**
     * Carries out a proposal its owner has just confirmed — checked again
     * now, with the rules as they stand now: the goal may have been deleted
     * or the three places filled since it was proposed.
     *
     * @return array{ok: bool, message: string}
     */
    function ai_action_execute(int $userId, array $action): array
    {
        if (($action['kind'] ?? null) === 'create_goal' && is_array($action['input'] ?? null)) {
            $result = goal_create_from_input($userId, $action['input']);

            return $result['ok']
                ? ['ok' => true, 'message' => 'Doel ‘' . $result['goal']['name'] . '’ toegevoegd. Je vindt het bij Doelen.']
                : ['ok' => false, 'message' => 'Het doel kon niet worden toegevoegd: ' . rtrim((string) $result['error'], '.') . '.'];
        }

        if (($action['kind'] ?? null) === 'update_goal') {
            $goalId = (int) ($action['goal_id'] ?? 0);
            $verb   = (string) ($action['action'] ?? '');
            $labels = ai_goal_action_labels();
            $goal   = goal_get($userId, $goalId);

            if ($goal === null || $goal['status'] === 'abandoned' || !isset($labels[$verb])) {
                return ['ok' => false, 'message' => 'Dit doel bestaat niet meer.'];
            }

            $ok = goal_apply_action($userId, $goalId, $verb);

            return $ok === true
                ? ['ok' => true, 'message' => sprintf($labels[$verb]['done'], $goal['name'])]
                : ['ok' => false, 'message' => 'Deze wijziging kon niet worden opgeslagen.'];
        }

        return ['ok' => false, 'message' => 'Dit voorstel kan niet worden uitgevoerd.'];
    }
}
