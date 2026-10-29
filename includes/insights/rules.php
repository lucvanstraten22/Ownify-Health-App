<?php
/**
 * The rules: from derived facts and approved claims to candidate insights
 * (docs/INSIGHTS.md). Pure functions — no database, no clock — so every
 * case can be tested with synthetic facts (tools/insights-test.php).
 *
 * A rule gives a candidate only when
 *   - there is enough recent data (config/insights.php `data`),
 *   - a claim fits the person's age, in the source's own age boundaries —
 *     an age-bound claim needs a known age,
 *   - the claim is approved and active (research_claims()), and
 *   - the strength left after lowering it for an approximate fit is at
 *     least Beperkt onderbouwd.
 * Otherwise it gives nothing, and nothing is said.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/research.php';
require_once __DIR__ . '/facts.php';

if (!function_exists('insights_candidates')) {

    /**
     * Every candidate the facts and claims allow, unranked.
     *
     * @param array $claims research_claims(): claim_key => claim
     * @return list<array<string, mixed>>
     */
    function insights_candidates(array $facts, array $claims, ?array $config = null): array
    {
        $config ??= insights_config();

        $out = [];
        foreach ([
            'insights_rule_sleep_duration', 'insights_rule_sleep_regularity',
            'insights_rule_activity_minutes', 'insights_rule_activity_strength', 'insights_rule_activity_steps',
            'insights_rule_nutrition_fat', 'insights_rule_nutrition_saturated_fat', 'insights_rule_nutrition_fibre',
            'insights_rule_nutrition_protein', 'insights_rule_nutrition_salt',
            'insights_rule_body_bmi',
        ] as $rule) {
            $candidate = $rule($facts, $claims, $config);
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * The claim a rule uses for this age: the first of its [min, max, key]
     * that fits. An age-bound entry needs a known age; the claim's own
     * bounds are checked too.
     */
    function insights_claim_for(array $entries, ?int $age, array $claims): ?array
    {
        foreach ($entries as [$min, $max, $key]) {
            $bound = $min !== null || $max !== null;
            if ($bound && $age === null) {
                continue;
            }
            if ($bound && (($min !== null && $age < $min) || ($max !== null && $age > $max))) {
                continue;
            }
            $claim = $claims[$key] ?? null;
            if ($claim !== null && research_age_fits($claim, $age)) {
                return $claim;
            }
        }

        return null;
    }

    /**
     * A candidate, or null when the rule's relevance for this outcome is
     * nothing or the evidence left after lowering is below Beperkt.
     */
    function insights_candidate(string $rule, string $outcome, array $claim, array $facts, float $quality, array $config): ?array
    {
        $r         = $config['rules'][$rule];
        $relevance = (int) ($r['relevance'][$outcome] ?? 0);
        $strength  = research_strength_for((string) $claim['strength'], (string) $r['applicability']);

        if ($relevance <= 0 || $strength === null) {
            return null;
        }

        $suggest = array_key_exists($outcome, (array) ($r['suggest'] ?? []))
            ? ($r['suggest'][$outcome] ?? (string) $claim['claim_key'])
            : null;

        return [
            'rule'          => $rule,
            'slot'          => (string) $r['slot'],
            'outcome'       => $outcome,
            'claim_key'     => (string) $claim['claim_key'],
            'claim_id'      => (int) $claim['id'],
            'strength'      => $strength,
            'applicability' => (string) $r['applicability'],
            'facts'         => $facts,
            'relevance'     => $relevance,
            'quality'       => max(0.0, min(1.0, $quality)),
            /* The claim behind the suggestion this outcome may become, or null. */
            'suggest_claim' => $suggest,
        ];
    }

    /* ============================================================ sleep */

    /** Is there a recent week of nights to speak about? */
    function insights_sleep_ready(array $s, array $d): bool
    {
        return $s['nights'] >= (int) $d['sleep_min_nights']
            && $s['last_night_days'] !== null && $s['last_night_days'] <= (int) $d['sleep_fresh_days']
            && $s['avg_minutes'] !== null;
    }

    /** Average sleep against the range for the person's age. Never without an age. */
    function insights_rule_sleep_duration(array $facts, array $claims, array $config): ?array
    {
        $s   = $facts['sleep'];
        $age = $facts['profile']['age'];

        if (!insights_sleep_ready($s, $config['data'])) {
            return null;
        }

        $claim = insights_claim_for($config['rules']['sleep.duration']['claims'], $age, $claims);
        if ($claim === null) {
            return null;
        }

        [$band, $ages] = insights_band_of($claim, $age);
        $min = isset($band['min']) ? (float) $band['min'] : null;
        $max = isset($band['max']) ? (float) $band['max'] : null;
        if ($min === null) {
            return null;
        }

        $hours   = (float) $s['avg_minutes'] / 60;
        $outcome = $hours < $min ? 'below' : (($max !== null && $hours > $max) ? 'above' : 'within');

        return insights_candidate('sleep.duration', $outcome, $claim, [
            'nights'      => (int) $s['nights'],
            'avg_minutes' => (int) round((float) $s['avg_minutes']),
            'min_hours'   => (int) $min,
            'max_hours'   => $max === null ? null : (int) $max,
            'age_band'    => $ages,
        ], $s['nights'] / 7, $config);
    }

    /**
     * The values and the age range of the band an age falls in: a claim's
     * own bands, or the claim itself when it has none.
     *
     * @return array{0: array, 1: array{0: ?int, 1: ?int}}
     */
    function insights_band_of(array $claim, ?int $age): array
    {
        $finding = (array) $claim['finding'];

        if (isset($finding['bands'])) {
            foreach ((array) $finding['bands'] as [$min, $max, $values]) {
                if ($age !== null && $age >= (int) $min && ($max === null || $age <= (int) $max)) {
                    return [(array) $values, [(int) $min, $max === null ? null : (int) $max]];
                }
            }

            return [[], [null, null]];
        }

        return [$finding, [$claim['age_min'], $claim['age_max']]];
    }

    /**
     * How much bed- and wake-times vary, against the person's own three
     * weeks before. The source gives no number of minutes, so nothing is
     * judged: a change is only named when it is larger than Ownify's own
     * threshold for "changed" (config data.regularity_change_min).
     */
    function insights_rule_sleep_regularity(array $facts, array $claims, array $config): ?array
    {
        $s = $facts['sleep'];
        $d = $config['data'];

        if (!insights_sleep_ready($s, $d) || $s['bedtime_sd'] === null || $s['wake_sd'] === null) {
            return null;
        }

        $claim = insights_claim_for($config['rules']['sleep.regularity']['claims'], $facts['profile']['age'], $claims);
        if ($claim === null) {
            return null;
        }

        $now  = ((float) $s['bedtime_sd'] + (float) $s['wake_sd']) / 2;
        $prev = ($s['prev_nights'] >= (int) $d['sleep_previous_min'] && $s['prev_bedtime_sd'] !== null && $s['prev_wake_sd'] !== null)
            ? ((float) $s['prev_bedtime_sd'] + (float) $s['prev_wake_sd']) / 2
            : null;

        $change  = (float) $d['regularity_change_min'];
        $outcome = match (true) {
            $prev !== null && $now - $prev >= $change => 'less_steady',
            $prev !== null && $prev - $now >= $change => 'steadier',
            default                                   => 'level',
        };

        /* A steady week on its own is nothing to report. */
        if ($outcome === 'level' && $now < (float) $d['regularity_level_min']) {
            return null;
        }

        return insights_candidate('sleep.regularity', $outcome, $claim, [
            'nights'      => (int) $s['nights'],
            'bedtime_sd'  => (int) round((float) $s['bedtime_sd']),
            'wake_sd'     => (int) round((float) $s['wake_sd']),
            'spread'      => (int) round($now),
            'spread_prev' => $prev === null ? null : (int) round($prev),
            'prev_nights' => (int) $s['prev_nights'],
        ], $s['nights'] / 7, $config);
    }

    /* ========================================================= activity */

    /**
     * Does the person record workouts, and is the sync alive? Without both,
     * a quiet week could be missing data, and nothing is said about it.
     */
    function insights_workouts_ready(array $a, array $d): bool
    {
        return $a['fresh'] && $a['workouts_28'] >= (int) $d['workout_history_min'];
    }

    /** Recorded workout minutes against the Beweegrichtlijn, as a lower bound. */
    function insights_rule_activity_minutes(array $facts, array $claims, array $config): ?array
    {
        $a = $facts['activity'];

        if (!insights_workouts_ready($a, $config['data'])) {
            return null;
        }

        $claim = insights_claim_for($config['rules']['activity.minutes']['claims'], $facts['profile']['age'], $claims);
        if ($claim === null) {
            return null;
        }

        $finding = (array) $claim['finding'];
        if (isset($finding['moderate_minutes_day'])) {
            $per    = 'day';
            $target = (int) $finding['moderate_minutes_day'];
            $value  = (float) $a['minutes_7'] / 7;
        } elseif (isset($finding['moderate_minutes_week'])) {
            $per    = 'week';
            $week   = $finding['moderate_minutes_week'];
            $target = (int) (is_array($week) ? $week[0] : $week);
            $value  = (float) $a['minutes_7'];
            /* The WHO's mix (31.0, D9): a hard minute counts as two
               moderate ones; hard is the person's own, by age. */
            if (!empty($finding['vigorous_counts_double'])) {
                $per    = 'week_who';
                $value += (float) ($a['hard_minutes_7'] ?? 0);
            }
        } else {
            return null;
        }

        return insights_candidate('activity.minutes', $value < $target ? 'below' : 'reached', $claim, [
            'per'      => $per,
            'minutes'  => (int) round($value),
            'target'   => $target,
            'workouts' => (int) $a['workouts_7'],
        ] + ($per === 'week_who' ? ['hard' => (int) round((float) ($a['hard_minutes_7'] ?? 0))] : []), 1.0, $config);
    }

    /** Days with recorded strength training against the Beweegrichtlijn. */
    function insights_rule_activity_strength(array $facts, array $claims, array $config): ?array
    {
        $a = $facts['activity'];

        if (!insights_workouts_ready($a, $config['data'])) {
            return null;
        }

        $claim = insights_claim_for($config['rules']['activity.strength']['claims'], $facts['profile']['age'], $claims);
        /* From 65 (31.0, D9): the WHO's balance and strength on 3 days. */
        $older  = isset($claim['finding']['balance_strength_days_week']);
        $target = $older ? $claim['finding']['balance_strength_days_week'] : ($claim['finding']['strength_bone_per_week'] ?? null);
        if ($claim === null || $target === null) {
            return null;
        }

        return insights_candidate('activity.strength', $a['strength_days_7'] < (int) $target ? 'below' : 'reached', $claim, [
            'days'   => (int) $a['strength_days_7'],
            'target' => (int) $target,
        ] + ($older ? ['per' => 'older'] : []), 1.0, $config);
    }

    /**
     * Average daily steps over complete days. Below the dose–response
     * reference: Ding; from there, Paluch's plateau for the person's age.
     */
    function insights_rule_activity_steps(array $facts, array $claims, array $config): ?array
    {
        $a   = $facts['activity'];
        $d   = $config['data'];
        $age = $facts['profile']['age'];
        $r   = $config['rules']['activity.steps'];

        if (!$a['fresh'] || $a['step_days'] < (int) $d['steps_min_days'] || $a['steps_avg'] === null) {
            return null;
        }

        $ding   = insights_claim_for($r['claims'], $age, $claims);
        $paluch = insights_claim_for($r['plateau'], $age, $claims);
        if ($ding === null) {
            return null;
        }

        $reference = (int) ($ding['finding']['reference_steps'] ?? 0);
        [$band]    = $paluch === null ? [[]] : insights_band_of($paluch, $age);
        $plateau   = isset($band['plateau']) ? [(int) $band['plateau'][0], (int) $band['plateau'][1]] : null;
        $avg       = (float) $a['steps_avg'];

        if ($plateau !== null && $avg >= $plateau[0]) {
            [$outcome, $claim] = ['plateau', $paluch];
        } elseif ($plateau !== null && $reference > 0 && $avg >= $reference) {
            [$outcome, $claim] = ['toward', $paluch];
        } elseif ($reference > 0 && $avg < $reference) {
            [$outcome, $claim] = ['below', $ding];
        } else {
            return null;
        }

        return insights_candidate('activity.steps', $outcome, $claim, [
            'days'      => (int) $a['step_days'],
            'steps_avg' => (int) (round($avg / 10) * 10),
            'reference' => $reference,
            'plateau'   => $plateau,
        ], $a['step_days'] / 7, $config);
    }

    /* ======================================================== nutrition */

    /** Shares and amounts over the days whose log looks complete. */
    function insights_nutrition_ready(array $n, array $d, int $days): bool
    {
        return $n['target_known'] && $days >= (int) $d['nutrition_min_days'];
    }

    function insights_range_outcome(float $value, float $low, ?float $high): string
    {
        return $value < $low ? 'below' : (($high !== null && $value > $high) ? 'above' : 'within');
    }

    function insights_rule_nutrition_fat(array $facts, array $claims, array $config): ?array
    {
        $n = $facts['nutrition'];
        if (!insights_nutrition_ready($n, $config['data'], (int) $n['complete_days']) || $n['fat_share'] === null) {
            return null;
        }

        $claim = insights_claim_for($config['rules']['nutrition.fat']['claims'], $facts['profile']['age'], $claims);
        $range = $claim['finding']['energy_pct'] ?? null;
        if ($claim === null || !is_array($range)) {
            return null;
        }

        return insights_candidate('nutrition.fat', insights_range_outcome((float) $n['fat_share'], (float) $range[0], (float) $range[1]), $claim, [
            'days'  => (int) $n['complete_days'],
            'share' => round((float) $n['fat_share'], 1),
            'low'   => (int) $range[0],
            'high'  => (int) $range[1],
        ], $n['complete_days'] / 7, $config);
    }

    function insights_rule_nutrition_saturated_fat(array $facts, array $claims, array $config): ?array
    {
        $n = $facts['nutrition'];
        if (!insights_nutrition_ready($n, $config['data'], (int) $n['sat_days']) || $n['sat_share'] === null) {
            return null;
        }

        $claim = insights_claim_for($config['rules']['nutrition.saturated_fat']['claims'], $facts['profile']['age'], $claims);
        $max   = $claim['finding']['interim_energy_pct_max'] ?? null;
        if ($claim === null || $max === null) {
            return null;
        }

        return insights_candidate('nutrition.saturated_fat', (float) $n['sat_share'] > (float) $max ? 'above' : 'within', $claim, [
            'days'  => (int) $n['sat_days'],
            'share' => round((float) $n['sat_share'], 1),
            'max'   => (int) $max,
        ], $n['sat_days'] / 7, $config);
    }

    function insights_rule_nutrition_fibre(array $facts, array $claims, array $config): ?array
    {
        $n = $facts['nutrition'];
        if (!insights_nutrition_ready($n, $config['data'], (int) $n['fibre_days']) || $n['fibre_per_1000'] === null) {
            return null;
        }

        $claim = insights_claim_for($config['rules']['nutrition.fibre']['claims'], $facts['profile']['age'], $claims);
        $range = $claim['finding']['g_per_1000_kcal'] ?? null;
        if ($claim === null || !is_array($range)) {
            return null;
        }

        return insights_candidate('nutrition.fibre', insights_range_outcome((float) $n['fibre_per_1000'], (float) $range[0], (float) $range[1]), $claim, [
            'days'  => (int) $n['fibre_days'],
            'value' => round((float) $n['fibre_per_1000'], 1),
            'low'   => (float) $range[0],
            'high'  => (float) $range[1],
        ], $n['fibre_days'] / 7, $config);
    }

    /** Logged protein per kg of a recent weight, against the Dutch norm for adults. */
    function insights_rule_nutrition_protein(array $facts, array $claims, array $config): ?array
    {
        $n = $facts['nutrition'];
        $p = $facts['profile'];
        $d = $config['data'];

        if (!insights_nutrition_ready($n, $d, (int) $n['complete_days']) || $n['protein_g'] === null
            || $p['weight_kg'] === null || $p['weight_days'] === null || $p['weight_days'] > (int) $d['weight_max_age_days']) {
            return null;
        }

        $claim = insights_claim_for($config['rules']['nutrition.protein']['claims'], $p['age'], $claims);
        $norm  = $claim['finding']['g_per_kg_day'] ?? null;
        if ($claim === null || !is_numeric($norm)) {
            return null;
        }

        $perKg = (float) $n['protein_g'] / (float) $p['weight_kg'];

        return insights_candidate('nutrition.protein', $perKg < (float) $norm ? 'below' : 'reached', $claim, [
            'days'      => (int) $n['complete_days'],
            'g_per_kg'  => round($perKg, 2),
            'norm'      => (float) $norm,
        ], $n['complete_days'] / 7, $config);
    }

    /** Logged sodium as salt (the source's own factor), against at most 6 g. */
    function insights_rule_nutrition_salt(array $facts, array $claims, array $config): ?array
    {
        $n = $facts['nutrition'];
        if (!insights_nutrition_ready($n, $config['data'], (int) $n['sodium_days']) || $n['sodium_mg'] === null) {
            return null;
        }

        $claim  = insights_claim_for($config['rules']['nutrition.salt']['claims'], $facts['profile']['age'], $claims);
        $max    = $claim['finding']['salt_g_day_max'] ?? null;
        $factor = $claim['finding']['salt_per_sodium'] ?? null;
        if ($claim === null || $max === null || $factor === null) {
            return null;
        }

        $salt = (float) $n['sodium_mg'] / 1000 * (float) $factor;

        return insights_candidate('nutrition.salt', $salt > (float) $max ? 'above' : 'within', $claim, [
            'days'   => (int) $n['sodium_days'],
            'salt_g' => round($salt, 1),
            'max'    => (int) $max,
        ], $n['sodium_days'] / 7, $config);
    }

    /* ============================================================= body */

    /** BMI from the newest height and a recent weight, adults only. */
    function insights_rule_body_bmi(array $facts, array $claims, array $config): ?array
    {
        $p = $facts['profile'];

        if ($p['height_cm'] === null || $p['weight_kg'] === null || $p['weight_days'] === null
            || $p['weight_days'] > (int) $config['data']['weight_max_age_days']) {
            return null;
        }

        $claim = insights_claim_for($config['rules']['body.bmi']['claims'], $p['age'], $claims);
        $over  = $claim['finding']['overweight_min'] ?? null;
        $obese = $claim['finding']['obesity_min'] ?? null;
        if ($claim === null || $over === null || $obese === null) {
            return null;
        }

        $metres = (float) $p['height_cm'] / 100;
        $bmi    = (float) $p['weight_kg'] / ($metres * $metres);
        /* A value no person has: a typing error, not a BMI. */
        if ($bmi < 10 || $bmi > 80) {
            return null;
        }

        $outcome = $bmi >= (float) $obese ? 'obesity' : ($bmi >= (float) $over ? 'overweight' : 'below');

        return insights_candidate('body.bmi', $outcome, $claim, [
            'bmi'            => round($bmi, 1),
            'overweight_min' => (int) $over,
            'obesity_min'    => (int) $obese,
        ], 1.0, $config);
    }

    /* ========================================================== ranking */

    /** How strong the evidence as shown is: 3, 2, 1. */
    function insights_evidence_rank(string $strength): int
    {
        return match ($strength) {
            'zeer_betrouwbaar' => 3,
            'betrouwbaar'      => 2,
            default            => 1,
        };
    }

    /**
     * A candidate's place: relevance, then evidence, then data; the same
     * rule and outcome as yesterday in this slot a little lower.
     *
     * @param array<string, array{rule: string, outcome: string}> $yesterday slot => what it showed
     */
    function insights_score(array $candidate, array $yesterday, array $config): int
    {
        $w = $config['rank'];
        $score = (int) $w['relevance'] * (int) $candidate['relevance']
               + (int) $w['evidence'] * insights_evidence_rank((string) $candidate['strength'])
               + (int) round((int) $w['quality'] * (float) $candidate['quality']);

        $before = $yesterday[$candidate['slot']] ?? null;
        if ($before !== null && $before['rule'] === $candidate['rule'] && $before['outcome'] === $candidate['outcome']) {
            $score -= (int) $w['repeat_penalty'];
        }

        return $score;
    }

    /**
     * The best candidate per slot, each with its score. Ties go to the rule
     * listed first in config/insights.php, so the choice never depends on
     * the order anything was read in.
     *
     * @return array<string, array<string, mixed>> slot => candidate
     */
    function insights_choose(array $candidates, array $yesterday, array $config): array
    {
        $order = array_flip(array_keys($config['rules']));
        foreach ($candidates as $i => $candidate) {
            $candidates[$i]['score'] = insights_score($candidate, $yesterday, $config);
        }
        usort($candidates, static fn ($a, $b) => [$b['score'], $order[$a['rule']]] <=> [$a['score'], $order[$b['rule']]]);

        $best = [];
        foreach ($candidates as $candidate) {
            $best[$candidate['slot']] ??= $candidate;
        }

        return $best;
    }

    /**
     * The day's suggestion: the best candidate that may become one, not
     * suggested in the last few days (config lifecycle.suggestion_cooldown_days).
     * Null when none fits — then there is no suggestion, which is fine.
     *
     * @param list<string> $recent "rule.outcome" suggested lately
     */
    function insights_choose_suggestion(array $candidates, array $recent, array $claims, array $yesterday, array $config): ?array
    {
        $order = array_flip(array_keys($config['rules']));
        $pool  = [];

        foreach ($candidates as $candidate) {
            if ($candidate['suggest_claim'] === null || in_array($candidate['rule'] . '.' . $candidate['outcome'], $recent, true)) {
                continue;
            }
            if (!isset($config['copy']['suggestions'][$candidate['rule'] . '.' . $candidate['outcome']])) {
                continue;
            }
            $claim = $claims[$candidate['suggest_claim']] ?? null;
            if ($claim === null) {
                continue;
            }
            $strength = research_strength_for((string) $claim['strength'], (string) $candidate['applicability']);
            if ($strength === null) {
                continue;
            }
            $candidate['slot']      = 'suggestion';
            $candidate['claim_key'] = (string) $claim['claim_key'];
            $candidate['claim_id']  = (int) $claim['id'];
            $candidate['strength']  = $strength;
            $candidate['score']     = insights_score($candidate, $yesterday, $config);
            $pool[] = $candidate;
        }

        if ($pool === []) {
            return null;
        }

        usort($pool, static fn ($a, $b) => [$b['score'], $order[$a['rule']]] <=> [$a['score'], $order[$b['rule']]]);

        return $pool[0];
    }

    /** sha256 of what a candidate says: rule, outcome, claim and its facts. */
    function insights_fingerprint(array $candidate): string
    {
        return hash('sha256', research_json([
            'rule'    => $candidate['rule'],
            'outcome' => $candidate['outcome'],
            'claim'   => (int) $candidate['claim_id'],
            'facts'   => $candidate['facts'],
        ]));
    }
}
