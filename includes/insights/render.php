<?php
/**
 * Stored insights as both apps show them (docs/INSIGHTS.md): the sentence in
 * the person's language and units, written from the stored facts at read
 * time, the evidence label, and the key of its "Uit onderzoek blijkt" detail.
 *
 * Every sentence is Ownify's own template (30.1: no AI service rewords
 * them). Nothing here works anything out again.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/i18n.php';
require_once dirname(__DIR__) . '/research.php';
require_once __DIR__ . '/facts.php';

if (!function_exists('insights_render')) {

    /** config/insights.php's texts in the request's language. */
    function insights_copy(): array
    {
        return (array) config_copy('insights')['copy'];
    }

    /** Where each slot belongs: the icon and accent of its Overzicht row. */
    function insights_slot_look(string $slot): array
    {
        return match ($slot) {
            'sleep'     => ['icon' => 'moon', 'accent' => 'sleep'],
            'nutrition' => ['icon' => 'utensils', 'accent' => 'nutrition'],
            'training'  => ['icon' => 'dumbbell', 'accent' => 'training'],
            default     => ['icon' => 'sparkle', 'accent' => 'neutral'],
        };
    }

    /** Minutes as Ownify writes a duration everywhere: 6:40. */
    function insights_hours(int|float $minutes): string
    {
        $minutes = (int) round($minutes);

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Which sentence a stored insight is, and what goes into it — from its
     * facts alone, formatted for the language shown now.
     *
     * @return array{0: string, 1: list<int|string>}  [variant, arguments]
     */
    function insights_sentence(string $rule, string $outcome, array $f): array
    {
        $n  = static fn ($v, int $d = 0): string => i18n_number((float) $v, $d);
        $nt = static fn ($v, int $d = 1): string => i18n_number_trim((float) $v, $d);

        return match ($rule) {
            'sleep.duration' => [
                $outcome . ($f['max_hours'] === null ? '_min' : '_range'),
                [(int) $f['nights'], insights_hours((int) $f['avg_minutes']), (int) $f['min_hours'], (int) ($f['max_hours'] ?? 0)],
            ],
            'sleep.regularity' => [
                $outcome,
                [(int) $f['nights'], (int) $f['bedtime_sd'], (int) $f['wake_sd'], (int) ($f['spread_prev'] ?? 0), (int) $f['spread']],
            ],
            'activity.minutes' => [
                $outcome . '_' . $f['per'],
                [$n($f['minutes']), (int) $f['target'], $n($f['hard'] ?? 0)],
            ],
            'activity.strength' => [
                $outcome . (($f['per'] ?? null) === 'older' ? '_older' : ''),
                [(int) $f['days'], (int) $f['target']],
            ],
            'activity.steps' => [
                $outcome,
                [(int) $f['days'], $n($f['steps_avg']), $n($f['reference']),
                 $n($f['plateau'][0] ?? 0), $n($f['plateau'][1] ?? 0)],
            ],
            'nutrition.fat'           => [$outcome, [(int) $f['days'], $nt($f['share']), (int) $f['low'], (int) $f['high']]],
            'nutrition.saturated_fat' => [$outcome, [(int) $f['days'], $nt($f['share']), (int) $f['max']]],
            'nutrition.fibre'         => [$outcome, [(int) $f['days'], $nt($f['value']), $nt($f['low']), $nt($f['high'])]],
            'nutrition.protein'       => [$outcome, [(int) $f['days'], $nt($f['g_per_kg'], 2), $nt($f['norm'], 2)]],
            'nutrition.salt'          => [$outcome, [(int) $f['days'], $nt($f['salt_g']), (int) $f['max']]],
            'body.bmi'                => [$outcome, [$nt($f['bmi']), (int) $f['overweight_min'], (int) $f['obesity_min']]],
            default                   => [$outcome, []],
        };
    }

    /** The template sentence of a stored insight, or null when it has none. */
    function insights_template(array $row, ?array $copy = null): ?string
    {
        $copy ??= insights_copy();
        $facts  = json_decode((string) $row['facts_json'], true);
        if (!is_array($facts)) {
            return null;
        }

        [$variant, $args] = insights_sentence((string) $row['rule_key'], (string) $row['outcome'], $facts);
        $template = $copy['bodies'][$row['rule_key']][$variant] ?? null;

        return is_string($template) ? vsprintf($template, $args) : null;
    }

    /**
     * One stored insight, ready to show. $kind 'suggestion' gives the
     * suggestion's sentence instead of the insight's.
     *
     * @return array<string, mixed>|null
     */
    function insights_render(array $row, string $kind = 'insight', ?array $copy = null): ?array
    {
        $copy ??= insights_copy();
        $rule   = (string) $row['rule_key'];

        if ($kind === 'suggestion') {
            $body = $copy['suggestions'][$rule . '.' . $row['outcome']] ?? null;
        } else {
            $body = insights_template($row, $copy);
        }

        if (!is_string($body) || $body === '') {
            return null;
        }

        $strength = (string) $row['strength'];

        return [
            'id'          => (int) $row['id'],
            'slot'        => (string) $row['slot'],
            'rule'        => $rule,
            'outcome'     => (string) $row['outcome'],
            'title'       => (string) ($copy['titles'][$rule] ?? ''),
            'body'        => $body,
            'strength'    => [
                'key'   => $strength,
                'label' => (string) (config_copy('research')['strengths'][$strength]['label'] ?? ''),
            ],
            'research'    => research_detail_id((int) $row['claim_id'], (string) $row['applicability']),
            'research_label' => (string) $copy['research'],
        ] + insights_slot_look((string) $row['slot']);
    }

    /* ===================================================== the two apps */

    /**
     * Overzicht's Nuttige inzichten, the suggestion, each page's own insight
     * and the research behind all of them — for app_page_data(), so the
     * website and the Android app show the same stored result.
     */
    function insights_hydrate(array $data, int $userId): array
    {
        if (!insights_installed()) {
            return $data;
        }

        $today = insights_today($userId);
        $copy  = insights_copy();
        /* Who the research may be shown to (config/research-eligibility.php):
           everything below shows only research this person belongs to. */
        $person = research_person(user_account($userId));

        $used  = [];   // research detail ids, with what they need

        /* Each slot's insight, rendered once and used wherever it shows —
           not when its research no longer holds for the person (a stored
           insight from before 38.0, or a profile that changed since). */
        $items = [];
        foreach ($today['slots'] as $slot => $row) {
            if (!insights_row_eligible($row, $person)) {
                continue;
            }
            $item = insights_render($row, 'insight', $copy);
            if ($item !== null) {
                $items[$slot] = $item;
                $used[$item['research']] = [(int) $row['claim_id'], (string) $row['applicability']];
            }
        }

        /* Overzicht: one row per slot, in config order; an empty slot keeps
           its quiet placeholder. */
        $rows = [];
        foreach (['sleep', 'nutrition', 'training'] as $slot) {
            $rows[] = isset($items[$slot])
                ? $items[$slot] + ['state' => 'filled']
                : insights_slot_look($slot) + [
                    'slot'  => $slot,
                    'title' => (string) $copy['empty'][$slot]['title'],
                    'body'  => (string) $copy['empty'][$slot]['body'],
                    'state' => 'empty',
                ];
        }
        $filled = array_filter($rows, static fn ($r) => $r['state'] === 'filled') !== [];

        $data['insights']['items']    = $rows;
        $data['insights']['state']    = $filled ? 'filled' : 'empty';
        $data['insights']['subtitle'] = (string) ($filled ? $copy['subtitle'] : $copy['subtitle_empty']);

        /* 31.0 (D1): with migration 029 Overzicht shows four combined
           insights instead, and its head opens the Inzichten page; the
           insights above stay on their own pages (context, below). */
        if (function_exists('corr_hydrate')) {
            $data = corr_hydrate($data, $userId, $today['mode'] !== null, $person, $used);
        }

        /* Each page shows its own slot's insight, the same one. */
        $data['insights']['context'] = [
            'sleep'     => $items['sleep'] ?? null,
            'nutrition' => $items['nutrition'] ?? null,
            'training'  => $items['training'] ?? null,
            'body'      => $items['body'] ?? null,
        ];

        /* Eén kleine suggestie. */
        $data['recommendation'] = insights_recommendation($data['recommendation'] ?? [], $today, $copy, $used, $person);

        /* The research next to reference values. */
        $data['insights']['references'] = insights_references($person, $used);

        /* Every detail any of them opens, in the request's language — each
           checked once more, so no page can open research the person does
           not belong to. */
        $data['research'] = [
            'copy'    => research_detail_copy(),
            'details' => insights_research_details($used, $person),
        ];

        return $data;
    }

    /**
     * Whether a stored insight's research still holds for the person: its
     * claim version, checked as any claim is (research_eligible()).
     */
    function insights_row_eligible(array $row, array $person): bool
    {
        $claim = research_claim_by_id((int) ($row['claim_id'] ?? 0));

        return $claim !== null && research_eligible($claim, $person);
    }

    /** Eén kleine suggestie: the day's one, or a calm word that there is none. */
    function insights_recommendation(array $rec, array $today, array $copy, array &$used, ?array $person = null): array
    {
        $row = $today['suggestion'];
        /* A suggestion whose research does not hold for the person is not made to them. */
        if ($row !== null && $person !== null && !insights_row_eligible($row, $person)) {
            $row = null;
        }

        if ($row === null) {
            /* Not worked out yet (a request still busy): the existing empty card. */
            if ($today['evaluated'] === null) {
                return $rec;
            }

            return array_merge($rec, [
                'state'       => 'empty',
                'headline'    => (string) $copy['no_suggestion'],
                'description' => (string) $copy['no_suggestion_text'],
                'note'        => (string) $copy['suggestion_note'],
            ]);
        }

        if ($row['withdrawn_at'] !== null) {
            return array_merge($rec, [
                'state'       => 'withdrawn',
                'headline'    => (string) $copy['no_suggestion'],
                'description' => (string) $copy['withdrawn'],
                'note'        => (string) $copy['suggestion_note'],
            ]);
        }

        $item = insights_render($row, 'suggestion', $copy);
        if ($item === null) {
            return $rec;
        }
        $used[$item['research']] = [(int) $row['claim_id'], (string) $row['applicability']];

        return array_merge($rec, [
            'state'          => 'filled',
            'headline'       => $item['title'],
            'description'    => $item['body'],
            'note'           => (string) $copy['suggestion_note'],
            'strength'       => $item['strength'],
            'research'       => $item['research'],
            'research_label' => $item['research_label'],
            'id'             => $item['id'],
        ]);
    }

    /**
     * The research beside Ownify's own reference values, by where it shows
     * (docs/INSIGHTS.md, "Naast een richtwaarde"), only where the person
     * belongs to the claim's population (research_eligible(): an age-bound
     * claim needs a known age, since 38.0).
     *
     * Only the places that show it, on both apps: a reference nobody shows
     * would be a detail nobody can open. Voeding's detail lines (verzadigd
     * vet, vezels, suiker) lean on their row's own research, whose source
     * (NUT-01) names their norms too.
     *
     * @return array<string, array{research: string, strength: array{key: string, label: string}, label: string}>
     */
    function insights_references(array|int|null $who, array &$used): array
    {
        $person = research_person($who);
        $age    = $person['age'];
        /* Each anchor: its claims in order of preference, the first the
           person may be shown — from 65 PROT-AGE's protein advice, else
           (e.g. it cannot be shown) the Dutch norm. */
        $anchors = [
            'nutrition.fat'        => ['nutrition.fat', 'proxy'],
            'nutrition.carbs'      => ['nutrition.carbohydrates', 'direct'],
            'nutrition.rating'     => ['nutrition.pattern', 'direct'],
            'sleep.stages'         => ['sleep.tracking', 'direct'],
            'training.measurement' => ['measurement.wearables', 'direct'],
            /* 31.0 (D9): the age proposal and the claims that had no place.
               Protein: from 65 the PROT-AGE advice, before it the Dutch norm. */
            'nutrition.protein'    => [$age !== null && $age >= 65 ? ['nutrition.protein.older', 'nutrition.protein'] : 'nutrition.protein', 'proxy'],
            'nutrition.sugar'      => ['nutrition.free_sugars', 'proxy'],
            'nutrition.energy'     => ['nutrition.energy_estimate', 'direct'],
            'training.intensity'   => ['activity.intensity.age', 'direct'],
            /* 50.9: Hartbelasting's guide, the WHO's minutes a week as heart load. */
            'training.load'        => [$age !== null && $age >= 65 ? ['activity.who.older', 'activity.who.adults'] : ['activity.who.adults', 'activity.who.older'], 'proxy'],
            'sleep.age'            => ['sleep.architecture.age', 'direct'],
        ];
        /* What the chip says where it is about your age, beside another one. */
        $ageLabel = ['training.intensity' => true, 'sleep.age' => true, 'nutrition.protein' => $age !== null && $age >= 65];

        $copy = insights_copy();
        $out  = [];
        foreach ($anchors as $anchor => [$keys, $applicability]) {
            $claim = null;
            foreach ((array) $keys as $key) {
                $candidate = research_claim($key);
                if ($candidate !== null && research_eligible($candidate, $person)) {
                    $claim = $candidate;
                    break;
                }
            }
            if ($claim === null) {
                continue;
            }
            $strength = research_strength_for((string) $claim['strength'], $applicability);
            if ($strength === null) {
                continue;
            }
            $id = research_detail_id((int) $claim['id'], $applicability);
            $used[$id] = [(int) $claim['id'], $applicability];
            $out[$anchor] = [
                'research' => $id,
                'strength' => [
                    'key'   => $strength,
                    'label' => (string) (config_copy('research')['strengths'][$strength]['label'] ?? ''),
                ],
                /* "Bij jouw leeftijd" only where the claim is the one for the age. */
                'label'    => (string) (!empty($ageLabel[$anchor]) && ($anchor !== 'nutrition.protein' || $claim['claim_key'] === 'nutrition.protein.older')
                    ? $copy['research_age'] : $copy['research']),
            ];
        }

        return $out;
    }

    /**
     * The details the page can open, by id.
     *
     * @param array<string, array{0: int, 1: string}> $used
     * @return array<string, array<string, mixed>>
     */
    function insights_research_details(array $used, array|int|null $who = null): array
    {
        $person = func_num_args() > 1 ? research_person($who) : null;
        $out = [];
        foreach ($used as $id => [$claimId, $applicability]) {
            if ($person !== null) {
                $claim = research_claim_by_id((int) $claimId);
                if ($claim === null || !research_eligible($claim, $person)) {
                    continue;
                }
            }
            $detail = research_detail($claimId, $applicability);
            if ($detail !== null) {
                $out[$id] = $detail;
            }
        }

        return $out;
    }
}
