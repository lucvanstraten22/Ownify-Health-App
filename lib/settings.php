<?php
/**
 * Settings helpers — integration status, profile values and row summaries.
 *
 * The templates never decide what a value means. They are handed a row that
 * already knows its label, its value and whether that value is a fact, a
 * blank or a preference, so the same markup renders "Nederlands" and "Nog
 * niet ingesteld" without a branch.
 *
 * Nothing here queries the database. The account screen reads the record
 * app_auth() has already loaded for the signed-in user — their own record,
 * never anyone else's — and shows empty states for everything that is not
 * filled in yet.
 */

declare(strict_types=1);

if (!function_exists('settings_prepare')) {
    /**
     * Resolves the integrations, the profile and the row summaries that depend
     * on them, so the page and its detail screens agree on every number.
     */
    function settings_prepare(array $settings, array $auth): array
    {
        $demo = !empty($settings['demo']);

        $connected = 0;
        foreach ($settings['integrations'] as $index => $integration) {
            $state = ($demo && isset($integration['demo']))
                ? $integration['demo']
                : ['status' => 'disconnected', 'last_sync' => null];

            $integration['status']    = $state['status'];
            $integration['last_sync'] = $state['last_sync'] ?? null;
            $integration['connected'] = $integration['status'] === 'connected';

            if ($integration['connected']) {
                $connected++;
            }

            $settings['integrations'][$index] = $integration;
        }

        $settings['connected_count'] = $connected;
        $settings['profile']         = settings_profile($auth);

        /* The devices row on the main page counts what the devices screen
           shows, rather than stating it a second time. */
        $settings = settings_set_row_value($settings, 'devices', settings_device_summary($connected));

        /* The account row names the person it belongs to. */
        $settings = settings_set_row_value(
            $settings,
            'account',
            $auth['signed_in'] ? (string) $auth['user']['username'] : 'Niet ingelogd'
        );

        return $settings;
    }
}

if (!function_exists('settings_device_summary')) {
    /** How many sources are attached, in words a person would use. */
    function settings_device_summary(int $connected): string
    {
        if ($connected === 0) {
            return 'Geen verbonden';
        }

        return $connected === 1 ? '1 verbonden' : $connected . ' verbonden';
    }
}

if (!function_exists('settings_set_row_value')) {
    /** Writes a computed value onto one main-page row, wherever it sits. */
    function settings_set_row_value(array $settings, string $id, string $value): array
    {
        foreach ($settings['groups'] as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                if ($row['id'] === $id) {
                    $settings['groups'][$g]['rows'][$r]['value'] = $value;
                }
            }
        }

        return $settings;
    }
}

if (!function_exists('settings_profile')) {
    /**
     * The signed-in user's own profile values, keyed the way the account
     * screen's fields are. Everything missing comes back null, which the
     * field row renders as its empty state.
     */
    function settings_profile(array $auth): array
    {
        $empty = [
            'avatar' => null, 'username' => null, 'first_name' => null, 'last_name' => null,
            'date_of_birth' => null, 'gender' => null, 'age' => null,
            'height' => null, 'weight' => null, 'activity_level' => null, 'member_since' => null,
            /* The same values unformatted. The rows show "178 cm" and "12 april
               1998"; the editor has to put 178 and 1998-04-12 back into an
               input, so both forms travel together rather than the editor
               trying to parse the display text back. */
            'raw' => [
                'first_name' => null, 'last_name' => null, 'date_of_birth' => null,
                'gender' => null, 'height' => null, 'weight' => null,
            ],
        ];

        if (empty($auth['signed_in']) || !is_array($auth['user'])) {
            return $empty;
        }

        $user = $auth['user'];

        return [
            'avatar'        => $user['avatar_path'] ?? null,
            'username'      => $user['username'] ?? null,
            'first_name'    => $user['first_name'] ?? null,
            'last_name'     => $user['last_name'] ?? null,
            'date_of_birth' => settings_date($user['date_of_birth'] ?? null),
            'gender'        => settings_gender($user['gender'] ?? null),
            'age'           => isset($user['age']) && $user['age'] !== null ? $user['age'] . ' jaar' : null,
            'height'        => settings_measurement($user['height'] ?? null),
            'weight'        => settings_measurement($user['weight'] ?? null),
            'activity_level' => settings_activity_level($user['activity_level'] ?? null),
            'member_since'   => settings_member_since($user['created_at'] ?? null),

            'raw' => [
                'first_name'    => $user['first_name'] ?? null,
                'last_name'     => $user['last_name'] ?? null,
                'date_of_birth' => $user['date_of_birth'] ?? null,
                /* 'undisclosed' is the column's default, which means nobody has
                   answered yet — not that somebody chose it. */
                'gender'        => ($user['gender'] ?? 'undisclosed') === 'undisclosed'
                    ? null
                    : $user['gender'],
                'height'        => isset($user['height']['value']) ? (float) $user['height']['value'] : null,
                'weight'        => isset($user['weight']['value']) ? (float) $user['weight']['value'] : null,
            ],
        ];
    }
}

if (!function_exists('settings_measurement')) {
    /** "182 cm" from the measurement row, or null while there is none. */
    function settings_measurement(?array $measurement): ?string
    {
        if ($measurement === null || !isset($measurement['value'])) {
            return null;
        }

        $value = (float) $measurement['value'];
        $text  = rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');

        return trim($text . ' ' . (string) ($measurement['unit'] ?? ''));
    }
}

if (!function_exists('settings_date')) {
    /** A date of birth as a person writes it: 4 oktober 1998. */
    function settings_date(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        try {
            $value = new DateTimeImmutable($date);
        } catch (Exception $e) {
            return null;
        }

        $months = [
            1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni',
            'juli', 'augustus', 'september', 'oktober', 'november', 'december',
        ];

        return sprintf('%d %s %d', (int) $value->format('j'), $months[(int) $value->format('n')], (int) $value->format('Y'));
    }
}

if (!function_exists('settings_gender')) {
    /**
     * The stored enum in Dutch. 'undisclosed' is the schema default, which
     * means nothing was chosen — so it reads as empty rather than as an answer.
     */
    function settings_gender(?string $gender): ?string
    {
        return match ($gender) {
            'female'     => 'Vrouw',
            'male'       => 'Man',
            'non_binary' => 'Non-binair',
            'other'      => 'Anders',
            default      => null,
        };
    }
}

if (!function_exists('settings_field_value')) {
    /** What a profile field shows: its value, or the right kind of blank. */
    function settings_field_value(array $field, array $profile): ?string
    {
        $value = $profile[$field['key']] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (isset($field['unit']) && !str_contains($value, $field['unit'])) {
            return $value . ' ' . $field['unit'];
        }

        return (string) $value;
    }
}

if (!function_exists('settings_field_blank')) {
    /** The empty state a field gets, which depends on how it is filled in. */
    function settings_field_blank(array $field, string|bool|null $state = null): string
    {
        /* An unanswered one-time field used to read "Bij onboarding", which
           was true when there was nowhere else to answer it. It is answered
           here now, so it says the same thing every other empty field says. */
        return match ($state ?? $field['edit'] ?? true) {
            'locked'  => 'Bij onboarding',
            'derived' => 'Nog onbekend',
            default   => 'Nog niet ingesteld',
        };
    }
}

if (!function_exists('settings_activity_level')) {
    /** The self-declared level, in the words the settings screen uses. */
    function settings_activity_level(?string $level): ?string
    {
        return match ($level) {
            'sedentary' => 'Weinig beweging',
            'light'     => 'Licht actief',
            'moderate'  => 'Gemiddeld actief',
            'active'    => 'Actief',
            'athlete'   => 'Sporter',
            default     => null,
        };
    }
}

if (!function_exists('settings_member_since')) {
    /** Since when the account exists — the one date that is not private. */
    function settings_member_since(?string $createdAt): ?string
    {
        if ($createdAt === null) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($createdAt);
        } catch (Exception $e) {
            return null;
        }

        $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni',
                   'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

        return $months[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
    }
}

if (!function_exists('settings_field_state')) {
    /**
     * How a field behaves right now.
     *
     * The three one-time fields are not permanently locked — they are locked
     * once answered. Before that they have to be fillable, or an account
     * created today could never set a birth date at all. So 'locked' in the
     * config means "locked after the first answer", and this resolves it
     * against what the user has actually stored:
     *
     *   true      editable, as often as you like
     *   'once'    not answered yet: fillable, and permanent once saved
     *   'locked'  answered: shown with a lock, no way in
     *   'derived' calculated from something else, never entered
     */
    function settings_field_state(array $field, array $profile): string|bool
    {
        $edit = $field['edit'] ?? true;

        if ($edit !== 'locked') {
            return $edit;
        }

        $raw = $profile['raw'][$field['key']] ?? null;

        return ($raw === null || $raw === '') ? 'once' : 'locked';
    }
}

if (!function_exists('settings_field_input')) {
    /**
     * What the editor needs to put this field on screen: the kind of input,
     * the value to start from, and the bounds the endpoint will enforce
     * anyway. Nothing here is trusted server-side — it is only so the user
     * meets the limit before the request does.
     */
    function settings_field_input(array $field, array $profile): array
    {
        $key = $field['key'];
        $raw = $profile['raw'][$key] ?? null;

        $input = match ($key) {
            'first_name', 'last_name' => ['type' => 'text', 'maxlength' => 60],
            'date_of_birth'           => ['type' => 'date', 'max' => date('Y-m-d'),
                                          'min' => date('Y-m-d', strtotime('-120 years'))],
            'gender'                  => ['type' => 'choice', 'options' => [
                                             'female'     => 'Vrouw',
                                             'male'       => 'Man',
                                             'non_binary' => 'Non-binair',
                                             'other'      => 'Anders',
                                          ]],
            'height'                  => ['type' => 'number', 'min' => 50, 'max' => 260, 'step' => '0.1'],
            'weight'                  => ['type' => 'number', 'min' => 20, 'max' => 400, 'step' => '0.1'],
            default                   => ['type' => 'text', 'maxlength' => 120],
        };

        $input['value'] = $raw === null ? '' : (string) $raw;
        $input['unit']  = $field['unit'] ?? '';

        /* Which endpoint saves it. The one-time fields go to their own, which
           is the one that refuses a second write. */
        $input['endpoint'] = in_array($key, ['date_of_birth', 'gender'], true)
            ? 'api/profile/onboarding.php'
            : 'api/profile/update.php';

        return $input;
    }
}
