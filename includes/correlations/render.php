<?php
/**
 * Combined insights as both apps show them (docs/CORRELATIONS.md): the
 * sentence in the person's language and units, the label, Chart A's two
 * rows, the numbers, the research and the method — written from the stored
 * numbers at read time. Nothing here works anything out again.
 *
 *   data['insights']['items']    Overzicht › Nuttige inzichten: four rows
 *   data['insights']['open']     its head opens the Inzichten page
 *   data['correlations']         the Inzichten page and each insight's own
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/i18n.php';
require_once dirname(__DIR__) . '/research.php';
require_once __DIR__ . '/store.php';

if (!function_exists('corr_hydrate')) {

    /** config/correlations.php's texts in the request's language. */
    function corr_copy(): array
    {
        return (array) config_copy('correlations')['copy'];
    }

    /** The detail id an insight opens. */
    function corr_detail_id(string $code): string
    {
        return 'insight-' . $code;
    }

    /**
     * The four on Overzicht, the Inzichten page and every insight's detail.
     * Leaves $data as it is until migration 029 is in.
     *
     * @param array<string, array{0: int, 1: string}> $used  research details the page needs
     */
    function corr_hydrate(array $data, int $userId, bool $insightsRan, ?int $age, array &$used): array
    {
        $stored = correlations_today($userId, null, $insightsRan);
        if ($stored === null) {
            return $data;
        }

        $cfg  = corr_config();
        $copy = corr_copy();
        $rows = [];
        foreach ($stored['pairs'] as $code => $r) {
            $rows[$code] = corr_row($code, (array) $cfg['pairs'][$code], $r, $copy, $stored);
        }

        /* Overzicht: four, or the empty word when nothing has any data. */
        $items = [];
        foreach (corr_overview($stored['pairs'], $cfg, $age) as $code) {
            $items[] = $rows[$code];
        }
        $strong = array_filter($items, static fn ($i) => $i['state'] === 'filled') !== [];

        $data['insights']['title']    = (string) $copy['card_title'];
        $data['insights']['items']    = $items;
        $data['insights']['state']    = $items === [] ? 'empty' : ($strong ? 'filled' : 'waiting');
        $data['insights']['subtitle'] = (string) ($items === [] ? $copy['empty'] : $copy['card_subtitle']);
        $data['insights']['combined'] = true;
        $data['insights']['open']     = ['detail' => 'insights', 'label' => (string) $copy['card_open']];

        /* The Inzichten page: by label, strongest first (R6). */
        $groups = [];
        foreach (['clear', 'pattern', 'possible', 'none', 'unclear'] as $tier) {
            $in = array_values(array_filter($rows, static fn ($row) => $row['tier']['key'] === $tier));
            if ($in === []) {
                continue;
            }
            $groups[] = [
                'tier'  => $tier,
                'label' => (string) $copy['tiers'][$tier],
                'means' => corr_tier_means($tier, $copy, $stored['tested']),
                'rows'  => $in,
            ];
        }

        $details = [];
        foreach ($stored['pairs'] as $code => $r) {
            $details[corr_detail_id($code)] = corr_detail_view($code, (array) $cfg['pairs'][$code], $r, $rows[$code], $copy, $stored, $age, $used);
        }

        $data['correlations'] = [
            'page' => [
                'title'  => (string) $copy['page_title'],
                'lede'   => (string) $copy['page_lede'],
                'back'   => (string) $copy['back'],
                'empty'  => (string) $copy['empty'],
                'groups' => $groups,
            ],
            'details' => $details,
            'labels'  => [
                'seen'     => (string) $copy['detail']['seen'],
                'numbers'  => (string) $copy['detail']['numbers'],
                'research' => (string) $copy['detail']['research'],
                'method'   => (string) $copy['detail']['method'],
                'back'     => (string) $copy['page_title'],
            ],
        ];

        return $data;
    }

    /** What a label means; for Mogelijk patroon with how many could be chance. */
    function corr_tier_means(string $tier, array $copy, int $tested): string
    {
        $text = (string) $copy['tier_means'][$tier];
        if ($tier !== 'possible') {
            return $text;
        }
        $expected = $tested * (float) corr_config()['chance_alpha'];
        $lo = max(1, (int) floor($expected));
        $hi = max($lo, (int) ceil($expected));

        return sprintf($text, $tested, $lo === $hi ? (string) $lo : tf('%1$d à %2$d', $lo, $hi));
    }

    /**
     * One insight as a row: title, sentence, label, where it belongs.
     *
     * @return array<string, mixed>
     */
    function corr_row(string $code, array $pair, array $r, array $copy, array $stored): array
    {
        $text = (array) $copy['pairs'][$code];
        $tier = (string) $r['tier'];
        $look = corr_look((string) $pair['areas'][1]);

        return [
            'code'   => $code,
            'slot'   => 'combined',
            'title'  => (string) $text['title'],
            'body'   => corr_sentence($code, $pair, $r, $copy, $stored),
            'tier'   => ['key' => $tier, 'label' => (string) $copy['tiers'][$tier]],
            'own'    => ($pair['claims'] ?? []) === [] ? (string) $copy['own_data_short'] : null,
            'state'  => in_array($tier, ['clear', 'pattern'], true) ? 'filled' : 'waiting',
            'detail' => corr_detail_id($code),
        ] + $look;
    }

    /** The marker of a row: the area the insight is about (heart belongs to Training). */
    function corr_look(string $area): array
    {
        return match ($area) {
            'sleep'     => ['icon' => 'pulse', 'accent' => 'sleep'],
            'nutrition' => ['icon' => 'utensils', 'accent' => 'nutrition'],
            default     => ['icon' => 'bolt', 'accent' => 'training'],
        };
    }

    /** The sentence for a stored result. */
    function corr_sentence(string $code, array $pair, array $r, array $copy, array $stored): string
    {
        $text = (array) $copy['pairs'][$code];
        $tier = (string) $r['tier'];

        if (in_array($tier, ['clear', 'pattern', 'possible'], true)) {
            $unit = (string) corr_config()['outcomes'][$pair['outcome']]['unit'];

            return sprintf((string) $text[(float) $r['effect'] >= 0 ? 'up' : 'down'],
                corr_amount($unit, abs((float) $r['effect'])), (int) $r['n_high'], (int) $r['n_low']);
        }
        if ($tier === 'none') {
            return sprintf((string) $text['none'], corr_span($r, $stored));
        }
        if (is_array($r['need'] ?? null)) {
            [$one, $many] = (array) $text[$r['need']['kind'] === 'group' ? 'need_group' : 'need_days'];
            $count = (int) $r['need']['count'];

            return sprintf($count === 1 ? (string) $one : (string) $many, $count);
        }

        return sprintf((string) $copy['unclear_text'], (int) $r['n']);
    }

    /** How many days a sentence covers: from the first paired day to the window's end (90 once the history is long enough). */
    function corr_span(array $r, array $stored): int
    {
        $days  = (int) corr_config()['window_days'];
        $first = $r['first'] ?? null;
        if (!is_string($first) || $stored['to'] === '') {
            return min($days, (int) $r['n']);
        }

        return max(1, min($days, (int) round((strtotime($stored['to'] . ' 12:00:00') - strtotime($first . ' 12:00:00')) / 86400) + 1));
    }

    /** An amount of an outcome's unit, as a sentence names it. */
    function corr_amount(string $unit, float $value): string
    {
        $u = (array) corr_copy()['amounts'];

        return match ($unit) {
            'minutes'  => sprintf((string) $u[(int) round($value) === 1 ? 'minutes_one' : 'minutes_other'], i18n_number(round($value))),
            'percent'  => sprintf((string) $u['percent'], i18n_number(round($value))),
            'bpm'      => sprintf((string) $u['bpm'], i18n_number_trim($value, 1)),
            'steps'    => sprintf((string) $u['steps'], i18n_number(round($value / 10) * 10)),
            'kcal'     => sprintf((string) $u['kcal'], i18n_number(round($value / 10) * 10)),
            'points'   => sprintf((string) $u['points'], i18n_number(round($value))),
            'g_per_kg' => sprintf((string) $u['g_per_kg'], i18n_number_trim($value, 2)),
            'litres'   => (string) units_format($value, 'l', 2),
            default    => i18n_number_trim($value, 1),
        };
    }

    /** A chart value as its row shows it. */
    function corr_chart_value(string $kind, float $value): string
    {
        return match ($kind) {
            'duration' => sprintf('%d:%02d', intdiv((int) round($value), 60), (int) round($value) % 60),
            'clock'    => (static function (float $v): string {
                $m = ((int) round($v) + 12 * 60) % 1440;
                $m = $m < 0 ? $m + 1440 : $m;

                return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            })($value),
            'ms'       => i18n_number(round($value)) . ' ms',
            'bpm'      => i18n_number_trim($value, 1) . ' bpm',
            'steps'    => i18n_number(round($value / 10) * 10),
            'kcal'     => i18n_number(round($value / 10) * 10) . ' kcal',
            'share'    => i18n_number(round($value)) . '%',
            'g_per_kg' => i18n_number_trim($value, 2) . ' g/kg',
            'litres'   => (string) units_format($value, 'l', 2),
            default    => i18n_number_trim($value, 1),
        };
    }

    /**
     * Chart A (D2): the two groups as two meter rows — the label, how many
     * days, the value, and a bar from zero, the longer one not quite full.
     *
     * @return ?array{rows: list<array>, note: string}
     */
    function corr_chart(string $code, array $pair, array $r, array $copy): ?array
    {
        $chart = $r['chart'] ?? null;
        if (!is_array($chart) || !in_array($r['tier'], ['clear', 'pattern', 'possible', 'none'], true)) {
            return null;
        }
        $text  = (array) $copy['pairs'][$code];
        $kind  = (string) $chart['kind'];
        $high  = (float) $chart['high'];
        $low   = (float) $chart['low'];
        $scale = max(abs($high), abs($low)) * 1.1;
        $weekly = $pair['lag'] === 'week';
        $count = static fn (int $n): string => $weekly ? tn($n, '%d week', '%d weken') : tn($n, '%d dag', '%d dagen');
        $fill  = static fn (float $v): float => $scale > 0 ? round(max(0.0, min(1.0, $v / $scale)), 3) : 0.0;

        return [
            'rows' => [
                ['label' => (string) $text['high'], 'count' => $count((int) $r['n_high']), 'value' => corr_chart_value($kind, $high), 'fill' => $fill($high), 'tone' => 'high'],
                ['label' => (string) $text['low'],  'count' => $count((int) $r['n_low']),  'value' => corr_chart_value($kind, $low),  'fill' => $fill($low),  'tone' => 'low'],
            ],
            'note' => (string) $copy['detail']['adjusted'],
        ];
    }

    /**
     * One insight's own page.
     *
     * @return array<string, mixed>
     */
    function corr_detail_view(string $code, array $pair, array $r, array $row, array $copy, array $stored, ?int $age, array &$used): array
    {
        $d    = (array) $copy['detail'];
        $tier = (string) $r['tier'];
        $unit = (string) corr_config()['outcomes'][$pair['outcome']]['unit'];

        /* The numbers, once there is something to count. */
        $numbers = [];
        if (!empty($r['tested'])) {
            $signed = static fn (?float $v): string => $v === null ? '–'
                : ($v < 0 ? '−' : ($v > 0 ? '+' : '')) . corr_amount($unit, abs($v));
            if (in_array($tier, ['clear', 'pattern', 'possible', 'none'], true)) {
                $numbers[] = ['label' => (string) $d['difference'], 'value' => $signed($r['effect'])];
                if ($r['lo'] !== null && $r['hi'] !== null) {
                    $numbers[] = ['label' => (string) $d['interval'], 'value' => sprintf((string) $d['interval_value'], $signed($r['lo']), $signed($r['hi']))];
                }
            }
            $numbers[] = ['label' => (string) $d['rho'], 'value' => str_replace('-', '−', i18n_number((float) $r['rho'], 2))];
            $numbers[] = ['label' => (string) $d['p'], 'value' => corr_p((float) $r['p'])];
            if ($r['q'] !== null) {
                $numbers[] = ['label' => sprintf((string) $d['q'], (int) $stored['tested']), 'value' => corr_p((float) $r['q'])];
            }
        }
        $numbers[] = ['label' => (string) $d['days'], 'value' => sprintf((string) $d['days_value'], (int) $r['n'], (int) $r['n_high'], (int) $r['n_low'])];
        $numbers[] = ['label' => (string) $d['period'], 'value' => sprintf((string) $d['period_value'], (int) corr_config()['window_days'])];

        /* The research: every approved claim that holds for this age, with
           its label as it applies to Ownify's data here. */
        $research = [];
        foreach (corr_pair_claims($pair, $age) as $key) {
            $claim = research_claim($key);
            if ($claim === null || !research_age_fits($claim, $age)) {
                continue;
            }
            $strength = research_strength_for((string) $claim['strength'], (string) $pair['applicability']);
            if ($strength === null) {
                continue;
            }
            $id = research_detail_id((int) $claim['id'], (string) $pair['applicability']);
            $used[$id] = [(int) $claim['id'], (string) $pair['applicability']];
            $research[] = [
                'research'  => $id,
                'statement' => t((string) $claim['statement']),
                'strength'  => ['key' => $strength, 'label' => research_strength_label($strength)],
                'label'     => (string) (config_copy('insights')['copy']['research'] ?? ''),
            ];
        }

        $concordance = $r['concordance'] ?? null;

        return [
            'id'          => corr_detail_id($code),
            'code'        => $code,
            'title'       => $row['title'],
            'tier'        => $row['tier'] + ['means' => corr_tier_means($tier, $copy, (int) $stored['tested'])],
            'body'        => $row['body'],
            'not_cause'   => in_array($tier, ['clear', 'pattern', 'possible'], true) ? (string) $copy['not_cause'] : null,
            'chart'       => corr_chart($code, $pair, $r, $copy),
            'numbers'     => $numbers,
            'concordance' => is_string($concordance) && $research !== [] ? (string) $copy['concordance'][$concordance] : null,
            'research'    => $research,
            'own_data'    => $research === [] ? (string) $copy['own_data'] : null,
            'method'      => [
                'paragraphs' => array_map(
                    static fn ($p) => str_contains((string) $p, '%d') ? sprintf((string) $p, count((array) corr_config()['pairs'])) : (string) $p,
                    array_values((array) $d['method_text'])
                ),
                'sources_title' => (string) $d['method_sources'],
                'sources'       => corr_method_sources(),
            ],
        ] + corr_look((string) $pair['areas'][1]);
    }

    /** A p- or q-value as people read it: "0,03", "< 0,001". */
    function corr_p(float $p): string
    {
        return $p < 0.001 ? '< ' . i18n_number(0.001, 3) : i18n_number($p, $p < 0.01 ? 3 : 2);
    }

    /**
     * The method's own sources (MTH-01 … 03): how the statistics are done,
     * not claims about health — listed as publications to open.
     *
     * @return list<array{title: string, citation: string, href: string, language: string}>
     */
    function corr_method_sources(): array
    {
        static $out = null;
        if ($out !== null) {
            return $out;
        }
        $out = [];
        foreach ((array) (corr_config()['method_sources'] ?? []) as $code) {
            $source = research_source((string) $code);
            if ($source === null || $source['status'] !== 'approved') {
                continue;
            }
            $out[] = [
                'title'    => (string) $source['title'],
                'citation' => t((string) $source['citation']),
                'href'     => (string) $source['url'],
                'language' => (string) $source['language'],
            ];
        }

        return $out;
    }
}
