<?php
/**
 * Voeding, drawn (docs/NUTRITION.md) — for the website and the app alike:
 * the server works out every number, range and word, and both apps draw it.
 *
 *   rating  your own cijfer per day, 1 to 10 — today and the six days
 *           before, each saved on its own (api/health/rating.php)
 *   days    the same seven days, each its composition: the energy from
 *           carbohydrates, protein and fat (4, 4 and 9 kcal per gram), each
 *           macronutrient's grams, share and range, and its details (fibre
 *           and sugar under carbohydrates, saturated fat under fat)
 *   charts  Energie and Water, two of Ownify's area charts
 *           (lib/area-charts.php), with their bereik as a green zone
 *
 * Every range comes from config/nutrition-reference.php, and nothing is
 * invented: a nutrient no app logged is null ("niet gelogd"), never 0 g; a
 * day without all three macronutrients has no doughnut; a reading without a
 * basis for a verdict gets none. Each day's totals count every meal once
 * over the apps that logged it (config/health-sources.php).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/i18n.php';
require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/nutrition-targets.php';
require_once __DIR__ . '/area-charts.php';

if (!function_exists('hydrate_nutrition')) {

    define('NUTRITION_DAYS', 7);
    define('NUTRITION_MACROS', ['carbs', 'protein', 'fat']);

    /** The reference values: config/nutrition-reference.php, the one place. */
    function nutrition_reference(): array
    {
        static $ref = null;

        return $ref ??= (array) require dirname(__DIR__) . '/config/nutrition-reference.php';
    }

    /**
     * @param array  $area     config/health.php's nutrition area
     * @param array  $periods  config/compass.php's history periods
     */
    function hydrate_nutrition(array $area, array $periods, int $userId, string $today): array
    {
        $ref     = nutrition_reference();
        $known   = db_available() ? nutrition_targets_for_user($userId) : null;
        $targets = $known['targets'] ?? [];
        $perKg   = $known['method']['protein_g_per_kg'] ?? null;
        $energy  = isset($targets['calorie_target_kcal']) ? (float) $targets['calorie_target_kcal'] : null;
        $protein = isset($targets['protein_target_g']) ? (float) $targets['protein_target_g'] : null;

        $from = (new DateTimeImmutable($today))->modify('-' . (NUTRITION_DAYS - 1) . ' days')->format('Y-m-d');

        /* Each nutrient's totals over the week, as every page reads them. */
        $codes  = ['energy', 'carbs', 'protein', 'fat', 'saturated_fat', 'fibre', 'sugar'];
        $totals = [];
        foreach ($codes as $code) {
            $totals[$code] = db_available() ? health_daily_metric_days($userId, $code, $from, $today) : [];
        }
        $ratings = hydrate_nutrition_ratings($userId, $from, $today);

        $days = [];
        for ($i = 0; $i < NUTRITION_DAYS; $i++) {
            $date = (new DateTimeImmutable($today))->modify('-' . $i . ' days')->format('Y-m-d');
            $of   = [];
            foreach ($codes as $code) {
                $of[$code] = isset($totals[$code][$date]) ? (float) $totals[$code][$date] : null;
            }
            $days[] = hydrate_nutrition_day((array) $area['composition'], $ref, $date, $i, $of, $ratings[$date] ?? null, $energy, $protein, $perKg === null ? null : (float) $perKg);
        }

        return [
            'rating'      => hydrate_nutrition_rating_copy((array) $area['rating']),
            'composition' => [
                'title'  => (string) $area['composition']['title'],
                'hint'   => (string) $area['composition']['hint'],
                'older'  => (string) $area['composition']['older'],
                'newer'  => (string) $area['composition']['newer'],
            ],
            'days'        => $days,
            'charts'      => hydrate_nutrition_charts($area, $ref, $periods, $userId, $energy, $today),
        ];
    }

    /** Your own cijfer per day: daily-rating:<date>, typed on that day's slider. */
    function hydrate_nutrition_ratings(int $userId, string $from, string $to): array
    {
        if (!db_available() || health_source_id('manual') === null) {
            return [];
        }

        $out = [];
        foreach (db_all(
            "SELECT external_id, value FROM health_metrics
              WHERE user_id = ? AND source_id = ? AND external_id BETWEEN ? AND ?",
            [$userId, health_source_id('manual'), 'daily-rating:' . $from, 'daily-rating:' . $to]
        ) as $row) {
            $out[substr((string) $row['external_id'], strlen('daily-rating:'))] = (int) round((float) $row['value']);
        }

        return $out;
    }

    function hydrate_nutrition_rating_copy(array $copy): array
    {
        return [
            'title'     => (string) $copy['title'],
            'label'     => (string) $copy['label'],
            'question'  => (string) $copy['question'],
            'saved'     => (string) $copy['saved'],
            'saved_for' => (string) $copy['saved_for'],
            'unsaved'   => (string) $copy['unsaved'],
            'error'     => (string) $copy['error'],
        ];
    }

    /** "Vandaag", "Gisteren", "Eergisteren", then "vrijdag 17 oktober". */
    /** The day as it reads inside a sentence: "hoe at je gisteren?", "8 voor maandag 19 oktober", "how did you eat Monday, October 19?". */
    function hydrate_nutrition_day_word(string $date, int $back): string
    {
        return match ($back) {
            0       => t('vandaag'),
            1       => t('gisteren'),
            2       => t('eergisteren'),
            default => i18n_date($date, 'weekday_day_month'),
        };
    }

    function hydrate_nutrition_day_name(string $date, int $back): string
    {
        return match ($back) {
            0       => t('Vandaag'),
            1       => t('Gisteren'),
            2       => t('Eergisteren'),
            default => i18n_date($date, 'weekday_day_month'),
        };
    }

    /**
     * One day's composition. A verdict only where the reference has a range:
     * 'in', 'low', 'high'; 'none' where it has no basis (total sugar); null
     * where nothing was logged.
     */
    function hydrate_nutrition_day(array $copy, array $ref, string $date, int $back, array $of, ?int $rating, ?float $energyTarget, ?float $proteinTarget, ?float $perKg = null): array
    {
        $per   = $ref['kcal_per_gram'];
        $whole = $of['carbs'] !== null && $of['protein'] !== null && $of['fat'] !== null;
        $kcal  = $whole ? $of['carbs'] * $per['carbs'] + $of['protein'] * $per['protein'] + $of['fat'] * $per['fat'] : null;
        $name  = hydrate_nutrition_day_name($date, $back);

        $macros = [];
        foreach (NUTRITION_MACROS as $key) {
            $grams = $of[$key];
            $mkcal = $grams === null ? null : $grams * $per[$key];
            $share = ($mkcal !== null && $kcal) ? $mkcal / $kcal * 100 : null;
            $rule  = $ref['macros'][$key];

            $verdict = null;
            $range   = null;
            $progress = null;
            if ($rule['measure'] === 'share' && $share !== null) {
                $range   = $rule['range'];
                $verdict = hydrate_nutrition_verdict($share, $range);
            }
            if ($rule['measure'] === 'target' && $grams !== null && $proteinTarget) {
                $progress = $grams / $proteinTarget * 100;
            }

            $macros[] = [
                'key'      => $key,
                'label'    => (string) $copy['macros'][$key],
                'grams'    => $grams,
                'share'    => $share === null ? null : round($share, 1),
                'logged'   => $grams !== null,
                'head'     => $grams === null ? t('niet gelogd') : tf('%s g', i18n_number($grams, 0)),
                'sub'      => array_values(array_filter([
                    $share === null ? null : tf('%s %% van je energie', i18n_number($share, 0)),
                    $rule['measure'] === 'share' ? tf('richtlijn %1$s–%2$s %%', i18n_number($rule['range'][0], 0), i18n_number($rule['range'][1], 0)) : null,
                    $progress === null ? null : tf('%s %% van je doel', i18n_number($progress, 0)),
                ])),
                'verdict'  => $verdict,
                'verdict_text' => hydrate_nutrition_verdict_text($verdict),
                /* The meter: the range as the green band, the marker you. */
                'meter'    => $grams === null ? null : ($rule['measure'] === 'share'
                    ? ($share === null ? null : ['low' => $range[0], 'high' => $range[1], 'max' => 100, 'value' => $share])
                    : ($proteinTarget ? ['low' => null, 'high' => null, 'max' => $proteinTarget * 1.6, 'value' => $grams] : null)),
                'facts'    => $grams === null ? [] : array_values(array_filter([
                    ['label' => t('Gegeten'), 'value' => tf('%1$s g · %2$s kcal', i18n_number($grams, 0), i18n_number($mkcal, 0))],
                    $share === null ? null : ['label' => t('Deel van je energie'), 'value' => i18n_number($share, 1) . ' %'],
                    $rule['measure'] === 'share'
                        ? ['label' => t('Richtlijn'), 'value' => tf('%1$s–%2$s %% van je energie', i18n_number($rule['range'][0], 0), i18n_number($rule['range'][1], 0))]
                        : ($proteinTarget ? ['label' => t('Richtlijn'), 'value' => tf('%1$s g (jouw doel) · %2$s %% van je doel', i18n_number($proteinTarget, 0), i18n_number((float) $progress, 0))] : null),
                ])),
                'why'      => $rule['measure'] === 'target' && $proteinTarget && $perKg
                    ? tf('Jouw doel: %s g per kg lichaamsgewicht bij je activiteit (je profiel).', i18n_number($perKg, 1))
                    : null,
                'details'  => hydrate_nutrition_details($copy, $ref, $key, $of, $kcal),
            ];
        }

        return [
            'date'    => $date,
            'name'    => $name,
            'word'    => hydrate_nutrition_day_word($date, $back),
            'rating'  => $rating,
            'kcal'    => $kcal === null ? null : round($kcal),
            /* The doughnut's centre: the day's kcal, and how far to the target. */
            'centre'  => $kcal === null ? null : [
                'value' => i18n_number($kcal, 0),
                'sub'   => $energyTarget ? tf('kcal · %1$s %% van %2$s', i18n_number($kcal / $energyTarget * 100, 0), i18n_number($energyTarget, 0)) : t('kcal'),
            ],
            'note'    => $kcal === null
                ? (array_filter([$of['carbs'], $of['protein'], $of['fat']], static fn ($v) => $v !== null) === []
                    ? (string) $copy['nothing']
                    : sprintf((string) $copy['too_little'], hydrate_nutrition_day_word($date, $back)))
                : null,
            'macros'  => $macros,
        ];
    }

    function hydrate_nutrition_details(array $copy, array $ref, string $macro, array $of, ?float $kcal): array
    {
        $out = [];
        foreach ($ref['details'] as $key => $rule) {
            if ($rule['of'] !== $macro) {
                continue;
            }
            $grams = $of[$key] ?? null;
            $share = ($grams !== null && $kcal && $key === 'saturated_fat') ? $grams * $ref['kcal_per_gram']['fat'] / $kcal * 100 : null;
            $verdict = match (true) {
                $grams === null            => null,
                $rule['measure'] === 'none' => 'none',
                $rule['measure'] === 'share' => $share === null ? null : hydrate_nutrition_verdict($share, $rule['range']),
                default                     => hydrate_nutrition_verdict($grams, $rule['range']),
            };
            $out[] = [
                'key'          => $key,
                'label'        => (string) $copy['details'][$key],
                'value'        => $grams === null ? null : tf('%s g', i18n_number($grams, 0)),
                'extra'        => $share === null ? null : i18n_number($share, 1) . ' %',
                'verdict'      => $verdict,
                'verdict_text' => $grams === null ? t('niet gelogd') : hydrate_nutrition_verdict_text($verdict),
            ];
        }

        return $out;
    }

    /** Inside its range, under it or over it. */
    function hydrate_nutrition_verdict(float $value, array $range): string
    {
        return $value < (float) $range[0] ? 'low' : ($value > (float) $range[1] ? 'high' : 'in');
    }

    function hydrate_nutrition_verdict_text(?string $verdict): ?string
    {
        return match ($verdict) {
            'in'   => t('Binnen bereik'),
            'low'  => t('Onder bereik'),
            'high' => t('Boven bereik'),
            'none' => t('Geen oordeel'),
            default => null,
        };
    }

    /** Energie and Water: area charts with their bereik and target. */
    function hydrate_nutrition_charts(array $area, array $ref, array $periods, int $userId, ?float $energyTarget, string $today): array
    {
        $charts = (array) $area['charts'];

        if ($energyTarget) {
            $charts['energy']['band'] = [$energyTarget * $ref['energy']['range'][0], $energyTarget * $ref['energy']['range'][1]];
            $charts['energy']['of']   = $energyTarget;
        }
        $charts['energy']['series'][0]['alt']['factor'] = (float) $ref['kj_per_kcal'];
        $charts['water']['band'] = $ref['water']['range'];
        $charts['water']['of']   = $ref['water']['target'];

        $yearAgo = (new DateTimeImmutable($today))->modify('-1 year')->format('Y-m-d');

        return area_charts(
            $charts,
            (array) $area['chart_copy'],
            $periods,
            static fn (array $s): array => area_metric_days($userId, (string) $s['key'], $s, $yearAgo, $today),
            $today
        );
    }
}
