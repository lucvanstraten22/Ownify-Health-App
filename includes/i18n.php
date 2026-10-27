<?php
/**
 * Language and units — one place for both apps (docs/I18N.md).
 *
 * ---------------------------------------------------------------------------
 * THE LANGUAGE
 * ---------------------------------------------------------------------------
 * Ownify is written in Dutch: every string in the code and the config is the
 * Dutch one, and lang/en.php is the English catalogue, keyed by that Dutch.
 * t('Opslaan') is 'Save' in English and 'Opslaan' in Dutch; a string without
 * an English entry stays Dutch (php tools/i18n-build.php --check lists those).
 *
 * Which language, per request, first one wins:
 *
 *   1. the account's choice           user_profiles.locale ('nl' | 'en')
 *   2. this browser's choice          cookie ownify_lang, for the screens
 *                                      before signing in and after signing out
 *   3. the device                     Accept-Language: the browser's, or the
 *                                      Android app's (its own choice when made,
 *                                      else the phone's language)
 *   4. Dutch
 *
 * Nothing chosen is never written down as a choice: NULL in the database and
 * no cookie mean "follow the device", every time.
 *
 * ---------------------------------------------------------------------------
 * THE UNITS
 * ---------------------------------------------------------------------------
 * Every measurement is stored as it always was — kg, cm, km or m, °C,
 * seconds per km — and stays so. The units (user_profiles.units: metric,
 * the default, or imperial) only decide how a value is SHOWN and how a typed
 * value is READ: units_out() at the presentation boundary, units_in() at the
 * input boundary, both from the stored unit. Nothing is converted twice,
 * nothing is stored rounded, and changing the setting changes no row.
 */

declare(strict_types=1);

if (!defined('I18N_LOCALES')) {
    define('I18N_LOCALES', ['nl', 'en']);
    define('I18N_DEFAULT', 'nl');
    define('I18N_COOKIE', 'ownify_lang');
}

/* Meldingen › Stip bij Voeding: the hours to choose from, and the default. */
if (!defined('NOTICE_RATING_HOUR')) {
    define('NOTICE_RATING_HOUR', 20);
    define('NOTICE_RATING_HOURS', [16, 18, 19, 20, 21, 22]);
}

if (!function_exists('i18n_locale')) {

    /* ============================================================ locale */

    /**
     * The request's language, and whether it was chosen. Settable, so the
     * app's endpoints can apply the account once they know whose token it is.
     *
     * With them the account's other Voorkeuren (migration 024): where its
     * week starts and the three accessibility choices.
     *
     * @return array{locale: string, chosen: ?string, units: string, week_start: string, text_large: bool, contrast_more: bool, motion_off: bool}
     */
    function &i18n_state(): array
    {
        static $state = null;

        if ($state === null) {
            $chosen = i18n_valid($_COOKIE[I18N_COOKIE] ?? null);
            $state  = [
                'locale' => $chosen ?? i18n_from_header($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null),
                'chosen' => $chosen,
                'units'  => 'metric',
                'week_start'    => 'monday',
                'text_large'    => false,
                'contrast_more' => false,
                'motion_off'    => false,
                'rating_notice_hour' => NOTICE_RATING_HOUR,
                'user'   => null,
            ];
        }

        return $state;
    }

    function i18n_locale(): string
    {
        return i18n_state()['locale'];
    }

    function i18n_valid(mixed $locale): ?string
    {
        return is_string($locale) && in_array($locale, I18N_LOCALES, true) ? $locale : null;
    }

    /**
     * The device's language from an Accept-Language header: its first
     * language — the one the browser or phone is set to — when Ownify has
     * it, else Dutch. "en-GB,en;q=0.9,nl;q=0.8" is English, "nl-NL,en;q=0.8"
     * Dutch, and "de-DE,de;q=0.9,en;q=0.8" Dutch too: a German device is not
     * an English one.
     */
    function i18n_from_header(?string $header): string
    {
        if ($header === null || trim($header) === '') {
            return I18N_DEFAULT;
        }

        $ranked = [];
        foreach (explode(',', $header) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag  = strtolower(trim($bits[0]));
            $q    = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)/', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($tag !== '') {
                $ranked[] = [$q, -$i, substr($tag, 0, 2)];
            }
        }

        rsort($ranked);

        foreach ($ranked as [$q, , $language]) {
            if ($q > 0 && $language !== '*') {
                return in_array($language, I18N_LOCALES, true) ? $language : I18N_DEFAULT;
            }
        }

        return I18N_DEFAULT;
    }

    /**
     * The account's choices, once it is known whose request this is: its
     * language when it chose one (else the browser's or the device's, as
     * before) and its units.
     */
    function i18n_use_account(?int $userId): void
    {
        $state = &i18n_state();

        if ($userId === null || $state['user'] === $userId || !function_exists('db_available') || !db_available()) {
            return;
        }

        $row = i18n_columns_present()
            ? db_one('SELECT locale, units' . (preferences_columns_present() ? ', week_start, text_large, contrast_more, motion_off' : '')
                . ' FROM user_profiles WHERE user_id = ?', [$userId])
            : null;

        $state['user'] = $userId;

        if ($row !== null) {
            /* The account decides, also when it chose nothing: then the
               device does — never a cookie another account left behind. */
            $chosen          = i18n_valid($row['locale'] ?? null);
            $state['chosen'] = $chosen;
            $state['locale'] = $chosen ?? i18n_from_header($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);

            /* The website keeps the account's choice in the browser too, so
               the opening screen after signing out stays in it. */
            if (PHP_SAPI !== 'cli' && empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
                && i18n_valid($_COOKIE[I18N_COOKIE] ?? null) !== $chosen) {
                i18n_remember_in_browser($chosen);
            }
        }

        $state['units'] = ($row['units'] ?? null) === 'imperial' ? 'imperial' : 'metric';

        $state['week_start']    = ($row['week_start'] ?? null) === 'sunday' ? 'sunday' : 'monday';
        $state['text_large']    = !empty($row['text_large']);
        $state['contrast_more'] = !empty($row['contrast_more']);
        $state['motion_off']    = !empty($row['motion_off']);

        /* Meldingen › Stip bij Voeding (migration 026): read on its own, so
           an account keeps everything above before the migration is in. */
        $state['rating_notice_hour'] = NOTICE_RATING_HOUR;
        if ($row !== null && notice_columns_present()) {
            $hour = db_value('SELECT rating_notice_hour FROM user_profiles WHERE user_id = ?', [$userId]);
            if ($hour !== null && in_array((int) $hour, NOTICE_RATING_HOURS, true)) {
                $state['rating_notice_hour'] = (int) $hour;
            }
        }
    }

    /** Migration 026: the hour the Voeding dot appears. */
    function notice_columns_present(): bool
    {
        static $present = null;

        return $present ??= (bool) db_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = 'rating_notice_hour'"
        );
    }

    /** The hour from which a red dot asks for today's Voeding cijfer: 20 unless the account chose another. */
    function rating_notice_hour(): int
    {
        return (int) (i18n_state()['rating_notice_hour'] ?? NOTICE_RATING_HOUR);
    }

    /** Migration 024: the week's first day and the accessibility choices. */
    function preferences_columns_present(): bool
    {
        static $present = null;

        return $present ??= (bool) db_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = 'motion_off'"
        );
    }

    /* ========================================================= Voorkeuren */

    /** Where the account's week starts: 'monday' (the default) or 'sunday'. */
    function week_start(): string
    {
        return i18n_state()['week_start'] ?? 'monday';
    }

    /** The first day of the week as ISO numbers it: 1 Monday, 7 Sunday. */
    function week_start_iso(): int
    {
        return week_start() === 'sunday' ? 7 : 1;
    }

    /**
     * The first day of the week $date lies in, as the account counts weeks.
     */
    function week_first_day(DateTimeImmutable $date): DateTimeImmutable
    {
        $back = ((int) $date->format('N') - week_start_iso() + 7) % 7;

        return $date->modify('-' . $back . ' day');
    }

    /**
     * A week's weekday initials, in the language on screen, from the
     * account's first day: M D W D V Z Z, or Z M D W D V Z from Sunday.
     *
     * @return list<string>
     */
    function week_initials(): array
    {
        $days = i18n_locale() === 'en' ? ['M', 'T', 'W', 'T', 'F', 'S', 'S'] : ['M', 'D', 'W', 'D', 'V', 'Z', 'Z'];

        return week_start() === 'sunday' ? array_merge([$days[6]], array_slice($days, 0, 6)) : $days;
    }

    /**
     * Toegankelijkheid, as the account chose it: larger text, more contrast,
     * no animations — each also when the device says otherwise.
     *
     * @return array{text_large: bool, contrast_more: bool, motion_off: bool}
     */
    function accessibility_choices(): array
    {
        $state = i18n_state();

        return [
            'text_large'    => (bool) ($state['text_large'] ?? false),
            'contrast_more' => (bool) ($state['contrast_more'] ?? false),
            'motion_off'    => (bool) ($state['motion_off'] ?? false),
        ];
    }

    /** Migration 021: the units column (and a locale that may be NULL). */
    function i18n_columns_present(): bool
    {
        static $present = null;

        return $present ??= (bool) db_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = 'units'"
        );
    }

    /** For a test or a script that renders for somebody: set them outright. */
    function i18n_force(string $locale, string $units = 'metric', ?string $chosen = null): void
    {
        $state = &i18n_state();
        $state['locale'] = i18n_valid($locale) ?? I18N_DEFAULT;
        $state['chosen'] = $chosen;
        $state['units']  = $units === 'imperial' ? 'imperial' : 'metric';
    }

    /** Whether the language was chosen ('nl'/'en') or follows the device (null). */
    function i18n_chosen(): ?string
    {
        return i18n_state()['chosen'];
    }

    /** The browser's own record of a choice (null: forget it — follow the device). */
    function i18n_remember_in_browser(?string $locale): void
    {
        if (headers_sent()) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);

        setcookie(I18N_COOKIE, $locale ?? '', [
            'expires'  => $locale === null ? time() - 3600 : time() + 365 * 24 * 3600,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    /* ======================================================= translating */

    /** The English catalogue: Dutch => English. */
    function i18n_catalog(): array
    {
        static $catalog = null;

        return $catalog ??= (array) require dirname(__DIR__) . '/lang/en.php';
    }

    /** A built-in Dutch string in the request's language. */
    function t(string $dutch): string
    {
        if (i18n_locale() === 'nl' || $dutch === '') {
            return $dutch;
        }

        return i18n_catalog()[$dutch] ?? $dutch;
    }

    /** sprintf over t(): tf('Nog %d dagen', 3). */
    function tf(string $dutch, mixed ...$args): string
    {
        return vsprintf(t($dutch), $args);
    }

    /** One or many: tn(3, '%d nacht', '%d nachten') → "3 nights". */
    function tn(int|float $n, string $one, string $many): string
    {
        return sprintf(t(abs($n) == 1 ? $one : $many), $n);
    }

    /**
     * The fields of a copy file that name something rather than say it — an
     * id, an area, an icon, a format. Never translated, also when a Dutch
     * word happens to be spelled the same ('training').
     */
    define('I18N_IDENTIFIERS', ['id', 'key', 'area', 'icon', 'type', 'kind', 'format', 'state', 'scale', 'tone',
        'availability', 'dots', 'per_day', 'locale', 'theme_color', 'theme_color_light', 'opens', 'provider',
        'selected', 'domain', 'grid', 'lower', 'layout', 'units', 'accent',
        /* config/goals.php's catalogue (docs/GOALS.md): codes, never words. */
        'category', 'default_direction', 'keywords', 'unit_code', 'unit_codes', 'imperial_unit', 'source_kind',
        'source_key', 'canonical']);

    /**
     * Every built-in string in a config tree, translated: the copy files
     * (config/settings.php, health.php…) are Dutch, so each string value with
     * an English entry becomes it. Keys never change, and nothing under an
     * identifier field (I18N_IDENTIFIERS) does.
     */
    function i18n_tree(array $tree): array
    {
        if (i18n_locale() === 'nl') {
            return $tree;
        }

        $catalog = i18n_catalog();
        $walk    = static function (array $node) use (&$walk, $catalog): array {
            foreach ($node as $key => $value) {
                if (is_string($key) && in_array($key, I18N_IDENTIFIERS, true)) {
                    continue;
                }
                if (is_array($value)) {
                    $node[$key] = $walk($value);
                } elseif (is_string($value) && $value !== '' && isset($catalog[$value])) {
                    $node[$key] = $catalog[$value];
                }
            }
            return $node;
        };

        return $walk($tree);
    }

    /** A copy config file, in the request's language. */
    function config_copy(string $name): array
    {
        static $cache = [];

        $key = $name . '|' . i18n_locale();

        return $cache[$key] ??= i18n_tree((array) require dirname(__DIR__) . '/config/' . basename($name) . '.php');
    }

    /* ======================================================= formatting */

    /** A number as it is written: "1.234,5" in Dutch, "1,234.5" in English. */
    function i18n_number(float|int $value, int $decimals = 0, bool $grouping = true): string
    {
        [$point, $group] = i18n_locale() === 'en' ? ['.', ','] : [',', '.'];

        return number_format((float) $value, $decimals, $point, $grouping ? $group : '');
    }

    /** As i18n_number(), without trailing zeros: 5,0 → "5", 5,50 → "5,5". */
    function i18n_number_trim(float|int $value, int $decimals = 1, bool $grouping = true): string
    {
        $text  = i18n_number($value, $decimals, $grouping);
        $point = i18n_locale() === 'en' ? '.' : ',';

        return str_contains($text, $point) ? rtrim(rtrim($text, '0'), $point) : $text;
    }

    /**
     * A number typed by a person, in either way of writing it: "72,5",
     * "72.5", "1.234,5" and "1,234.5" are all read the way they were meant.
     */
    function i18n_parse_number(mixed $text): ?float
    {
        if (is_int($text) || is_float($text)) {
            return (float) $text;
        }

        $text = trim(str_replace([' ', "\u{00A0}"], '', (string) $text));

        if ($text === '' || !preg_match('/^-?[0-9.,]+$/', $text)) {
            return null;
        }

        $lastComma = strrpos($text, ',');
        $lastDot   = strrpos($text, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $text    = str_replace($decimal === ',' ? '.' : ',', '', $text);
            $text    = str_replace(',', '.', $text);
        } elseif ($lastComma !== false) {
            $text = str_replace(',', '.', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    /** Month names: [full, short] for 1–12. */
    function i18n_month(int $month, bool $short = false): string
    {
        static $names = [
            'nl' => [['januari', 'jan'], ['februari', 'feb'], ['maart', 'mrt'], ['april', 'apr'], ['mei', 'mei'], ['juni', 'jun'],
                     ['juli', 'jul'], ['augustus', 'aug'], ['september', 'sep'], ['oktober', 'okt'], ['november', 'nov'], ['december', 'dec']],
            'en' => [['January', 'Jan'], ['February', 'Feb'], ['March', 'Mar'], ['April', 'Apr'], ['May', 'May'], ['June', 'Jun'],
                     ['July', 'Jul'], ['August', 'Aug'], ['September', 'Sep'], ['October', 'Oct'], ['November', 'Nov'], ['December', 'Dec']],
        ];

        return $names[i18n_locale()][max(1, min(12, $month)) - 1][$short ? 1 : 0];
    }

    /** Weekday names, 0 = Sunday … 6 = Saturday: full, or the two-letter short form. */
    function i18n_weekday(int $day, bool $short = false): string
    {
        static $names = [
            'nl' => [['zondag', 'zo'], ['maandag', 'ma'], ['dinsdag', 'di'], ['woensdag', 'wo'], ['donderdag', 'do'], ['vrijdag', 'vr'], ['zaterdag', 'za']],
            'en' => [['Sunday', 'Sun'], ['Monday', 'Mon'], ['Tuesday', 'Tue'], ['Wednesday', 'Wed'], ['Thursday', 'Thu'], ['Friday', 'Fri'], ['Saturday', 'Sat']],
        ];

        return $names[i18n_locale()][(($day % 7) + 7) % 7][$short ? 1 : 0];
    }

    /**
     * A date in words. Formats:
     *   day_month        "5 oktober"      / "October 5"
     *   day_mon          "5 okt"          / "Oct 5"
     *   weekday_day_month "maandag 5 oktober" / "Monday, October 5"
     *   day_month_year   "5 oktober 2026" / "October 5, 2026"
     *   day_mon_year     "5 okt 2026"     / "Oct 5, 2026"
     *   month_year       "oktober 2026"   / "October 2026"
     *   month            "oktober"        / "October"
     *   mon              "okt"            / "Oct"
     *   numeric          "5-10-2026"      / "10/5/2026"
     */
    function i18n_date(DateTimeInterface|string $date, string $format = 'day_month'): string
    {
        $d       = is_string($date) ? new DateTimeImmutable($date) : $date;
        $day     = (int) $d->format('j');
        $month   = (int) $d->format('n');
        $year    = $d->format('Y');
        $weekday = (int) $d->format('w');
        $en      = i18n_locale() === 'en';

        return match ($format) {
            'day_mon'           => $en ? i18n_month($month, true) . ' ' . $day : $day . ' ' . i18n_month($month, true),
            'weekday_day_month' => $en ? i18n_weekday($weekday) . ', ' . i18n_month($month) . ' ' . $day
                                       : i18n_weekday($weekday) . ' ' . $day . ' ' . i18n_month($month),
            'day_month_year'    => $en ? i18n_month($month) . ' ' . $day . ', ' . $year : $day . ' ' . i18n_month($month) . ' ' . $year,
            'day_mon_year'      => $en ? i18n_month($month, true) . ' ' . $day . ', ' . $year : $day . ' ' . i18n_month($month, true) . ' ' . $year,
            'month_year'        => i18n_month($month) . ' ' . $year,
            'month'             => i18n_month($month),
            'mon'               => i18n_month($month, true),
            'numeric'           => $en ? $month . '/' . $day . '/' . $year : $day . '-' . $month . '-' . $year,
            default             => $en ? i18n_month($month) . ' ' . $day : $day . ' ' . i18n_month($month),
        };
    }

    /* ============================================================= units */

    function units_system(): string
    {
        return i18n_state()['units'];
    }

    function units_imperial(): bool
    {
        return units_system() === 'imperial';
    }

    /**
     * How a stored unit is shown in the request's units: the unit it becomes,
     * the factor and offset that get it there, and its decimals.
     *
     * @return array{unit: string, factor: float, offset: float, decimals: int}
     */
    function units_spec(string $stored): array
    {
        $metric = ['unit' => $stored, 'factor' => 1.0, 'offset' => 0.0, 'decimals' => 1];

        if (!units_imperial()) {
            return $metric;
        }

        return match ($stored) {
            'kg'                 => ['unit' => 'lb',    'factor' => 2.20462262185, 'offset' => 0.0, 'decimals' => 1],
            'km'                 => ['unit' => 'mi',    'factor' => 1 / 1.609344,  'offset' => 0.0, 'decimals' => 1],
            'm'                  => ['unit' => 'ft',    'factor' => 1 / 0.3048,    'offset' => 0.0, 'decimals' => 0],
            'cm'                 => ['unit' => 'in',    'factor' => 1 / 2.54,      'offset' => 0.0, 'decimals' => 1],
            '°C', 'C'            => ['unit' => '°F',    'factor' => 9 / 5,         'offset' => 32.0, 'decimals' => 1],
            'km/u', 'km/h'       => ['unit' => 'mph',   'factor' => 1 / 1.609344,  'offset' => 0.0, 'decimals' => 1],
            'min/km'             => ['unit' => 'min/mi', 'factor' => 1.609344,     'offset' => 0.0, 'decimals' => 0],
            default              => $metric,
        };
    }

    /** A stored value as the person sees it (null stays null). Never rounded here. */
    function units_out(?float $value, string $stored): ?float
    {
        if ($value === null) {
            return null;
        }

        $spec = units_spec($stored);

        return $value * $spec['factor'] + $spec['offset'];
    }

    /** A value the person typed, in their units, as it is stored. */
    function units_in(?float $value, string $stored): ?float
    {
        if ($value === null) {
            return null;
        }

        $spec = units_spec($stored);

        return ($value - $spec['offset']) / $spec['factor'];
    }

    /**
     * A value typed in a NAMED unit, as it is stored: 160 'lb' is 72.57 'kg',
     * 71 'in' is 180.34 'cm'. For a form that says which unit it was typed
     * in (a height_unit, a weight_unit), so an app that predates the units
     * — and sends cm and kg — is never converted by mistake. A metric unit
     * comes back as it is.
     *
     * @return array{0: float, 1: string}  [value, stored unit]
     */
    function units_canonical(float $value, string $unit): array
    {
        return match ($unit) {
            'lb'     => [$value / 2.20462262185, 'kg'],
            'in'     => [$value * 2.54, 'cm'],
            'ft'     => [$value * 0.3048, 'm'],
            'mi'     => [$value * 1.609344, 'km'],
            '°F'     => [($value - 32) * 5 / 9, '°C'],
            'mph'    => [$value * 1.609344, 'km/h'],
            'min/mi' => [$value / 1.609344, 'min/km'],
            default  => [$value, $unit],
        };
    }

    /** The unit a stored unit is shown in: 'kg' → 'lb' when imperial. */
    function units_label(string $stored): string
    {
        return units_spec($stored)['unit'];
    }

    /**
     * A stored value, shown: converted, rounded for reading and written in
     * the request's language — "72,5 kg", "159.8 lb", "1,2 km", "0.7 mi".
     * Height in imperial is feet and inches: "5 ft 11 in".
     */
    function units_format(?float $value, string $stored, ?int $decimals = null, bool $withUnit = true): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($stored === 'cm' && units_imperial() && $value >= 60) {
            return units_feet_inches($value);
        }

        $spec  = units_spec($stored);
        $shown = i18n_number_trim($value * $spec['factor'] + $spec['offset'], $decimals ?? $spec['decimals']);

        return $withUnit ? $shown . ' ' . $spec['unit'] : $shown;
    }

    /** A height in cm as feet and inches: 180 → "5 ft 11 in". */
    function units_feet_inches(float $cm): string
    {
        $inches = (int) round($cm / 2.54);

        return intdiv($inches, 12) . ' ft ' . ($inches % 12) . ' in';
    }

    /**
     * A height typed in imperial, in cm: "5'11", "5 ft 11 in", "5 11",
     * "5′11″" or plain inches ("71"). Null when it is none of those.
     */
    function units_parse_feet_inches(string $text): ?float
    {
        $text = strtolower(trim(str_replace(['′', '″', '’', '”', '"'], ["'", '', "'", '', ''], $text)));

        /* A lone number is inches ("71"), but a whole 2 to 8 is feet: nobody
           is six inches tall ("6" is 6 ft). "6 in" says otherwise. */
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(in|inch|inches)?$/', $text, $m)) {
            $inches = (float) str_replace(',', '.', $m[1]);
            if (($m[2] ?? '') === '' && ctype_digit($m[1]) && $inches >= 2 && $inches <= 8) {
                return $inches * 12 * 2.54;
            }
            return $inches > 0 && $inches < 120 ? $inches * 2.54 : null;
        }

        if (preg_match("/^(\\d+)\\s*(?:'|ft|feet|foot)?\\s*(\\d+(?:[.,]\\d+)?)?\\s*(?:in|inch|inches)?$/", $text, $m)) {
            $feet   = (int) $m[1];
            $inches = isset($m[2]) && $m[2] !== '' ? (float) str_replace(',', '.', $m[2]) : 0.0;
            if ($feet < 2 || $feet > 8 || $inches >= 12) {
                return null;
            }
            return ($feet * 12 + $inches) * 2.54;
        }

        return null;
    }

    /** A pace stored as seconds per km, shown as "5:42 /km" or "9:11 /mi". */
    function units_pace(?float $secondsPerKm): ?string
    {
        if ($secondsPerKm === null || $secondsPerKm <= 0) {
            return null;
        }

        $seconds = (int) round($secondsPerKm * units_spec('min/km')['factor']);

        return sprintf('%d:%02d /%s', intdiv($seconds, 60), $seconds % 60, units_imperial() ? 'mi' : 'km');
    }
}
