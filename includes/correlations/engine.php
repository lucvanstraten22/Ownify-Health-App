<?php
/**
 * Combined insights, worked out (docs/CORRELATIONS.md): the 17 pairs of
 * config/correlations.php on one person's day table, each with its label
 * (Duidelijk patroon … Nog onduidelijk), its numbers and what is still
 * missing. Pure: a day table in, numbers out — no database, no clock, no
 * text. The sentences are written where they are shown
 * (includes/correlations/render.php), so a language or unit switch never
 * needs a new run.
 */

declare(strict_types=1);

require_once __DIR__ . '/stats.php';

if (!function_exists('corr_evaluate')) {

    /**
     * Every pair's result.
     *
     * @param array{from: string, to: string, days: int, vars: array<string, array<string, float>>} $frame
     * @return array{engine_version: string, from: string, to: string, tested: int, pairs: array<string, array>}
     */
    function corr_evaluate(array $frame, array $cfg, int $seed): array
    {
        $vars    = $frame['vars'];
        $from    = $frame['from'];
        $to      = $frame['to'];
        $days    = (int) $frame['days'];
        $results = [];
        $pValues = [];
        $resid   = [];   // variable|weekday terms => [residuals, k]

        $residuals = static function (string $name, bool $weekdays) use (&$resid, $vars, $from, $days): array {
            $key = $name . ($weekdays ? '|w' : '|t');
            if (!isset($resid[$key])) {
                [$r, $k] = corr_residuals($vars[$name] ?? [], $from, $days, $weekdays);
                $resid[$key] = [corr_winsorize($r), $k];
            }

            return $resid[$key];
        };

        foreach ((array) $cfg['pairs'] as $code => $pair) {
            $results[$code] = corr_evaluate_pair($code, $pair, $vars, $from, $to, $cfg, $residuals, $seed);
            if ($results[$code]['tested']) {
                $pValues[$code] = $results[$code]['p'];
            }
        }

        /* Step 5: one correction over every pair that was tested. */
        $q = corr_bh($pValues);
        foreach ($results as $code => $result) {
            $results[$code]['q'] = $q[$code] ?? null;
            $results[$code] = corr_label($results[$code], (array) $cfg['pairs'][$code], $cfg);
        }

        return [
            'engine_version' => (string) $cfg['engine_version'],
            'from'           => $from,
            'to'             => $to,
            'tested'         => count($pValues),
            'pairs'          => $results,
        ];
    }

    /** One pair: lined-up days, the test, the group difference and the chart's two values. */
    function corr_evaluate_pair(string $code, array $pair, array $vars, string $from, string $to, array $cfg, Closure $residuals, int $seed): array
    {
        $exposure = (string) $pair['exposure'];
        $outcome  = (string) $pair['outcome'];
        $weekly   = $pair['lag'] === 'week';
        $weekdays = (bool) ($pair['weekday_terms'] ?? true);
        $mean     = $outcome === 'trained';      // a yes/no outcome: shares, not medians
        $x        = $vars[$exposure] ?? [];
        $y        = $vars[$outcome] ?? [];
        $out      = (array) $cfg['outcomes'][$outcome];

        $result = [
            'available' => $x !== [] && $y !== [],
            'tested'    => false,
            'n' => 0, 'n_high' => 0, 'n_low' => 0,
            'rho' => null, 'p' => null, 'q' => null, 'stable' => null,
            'effect' => null, 'lo' => null, 'hi' => null, 'threshold' => null,
            'chart' => null, 'need' => null, 'first' => null,
        ];

        /* Line the days up: exposure on day D, outcome on D + lag. */
        $lined = [];
        foreach ($x as $date => $xv) {
            if (!$weekly && ($date < $from || $date > $to)) {
                continue;
            }
            $od = $weekly ? $date : corr_day($date, (int) $pair['lag']);
            if (isset($y[$od])) {
                $lined[] = [$date, $od];
            }
        }
        $n = count($lined);

        /* The groups, on the raw exposure: yes/no, or above the person's
           own median against at or below it. */
        $raw = array_map(static fn ($d) => (float) $x[$d[0]], $lined);
        $cut = $pair['split'] === 'binary' ? 0.5 : ($raw === [] ? 0.0 : corr_median($raw));
        $high = static fn (float $v): bool => $pair['split'] === 'binary' ? $v >= 0.5 : $v > $cut;
        $nHigh = count(array_filter($raw, $high));
        $nLow  = $n - $nHigh;

        $result['first']  = $lined[0][0] ?? null;
        $result['n']      = $n;
        $result['n_high'] = $nHigh;
        $result['n_low']  = $nLow;

        $minDays  = $weekly ? (int) $pair['min_weeks'] : (int) $cfg['min_paired_days'];
        $minGroup = $weekly ? (int) ($pair['min_group'] ?? 3) : (int) $cfg['min_group_days'];

        if ($n < $minDays || min($nHigh, $nLow) < $minGroup) {
            $result['need'] = corr_need($pair, $n, min($nHigh, $nLow), $cfg);

            return $result;
        }

        /* Steps 1–4. */
        [$rx, $kx] = $residuals($exposure, $weekdays);
        [$ry, $ky] = $residuals($outcome, $weekdays);
        $series = [];
        foreach ($lined as [$xd, $yd]) {
            if (isset($rx[$xd], $ry[$yd])) {
                $series[] = [$xd, $rx[$xd], $ry[$yd]];
            }
        }
        $test = corr_test($series, max($kx, $ky));

        /* Step 7: the difference between the groups, on the outcome with
           the person's weekdays and trend taken out. */
        $yHigh = $yLow = $boot = [];
        $yAll  = [];
        foreach ($lined as $i => [$xd, $yd]) {
            if (!isset($ry[$yd])) {
                continue;
            }
            $isHigh = $high($raw[$i]);
            $yAll[] = $ry[$yd];
            if ($isHigh) {
                $yHigh[] = $ry[$yd];
            } else {
                $yLow[] = $ry[$yd];
            }
            $boot[] = [$isHigh, $ry[$yd]];
        }
        $center = static fn (array $v): float => $mean ? corr_mean($v) : (float) corr_median($v);
        $diff   = $mean ? corr_mean($yHigh) - corr_mean($yLow) : (float) corr_hodges_lehmann($yHigh, $yLow);
        [$lo, $hi] = corr_bootstrap($boot, crc32($seed . '|' . $code), (int) $cfg['bootstrap'], 7, $mean);

        /* The threshold that matters, in the outcome's own units; HRV is
           compared on its logarithm, so a percentage of the person's own
           level. */
        $threshold = $out['threshold'] === null ? null : (float) $out['threshold'];
        if ($outcome === 'hrv') {
            $sd = 0.0;
            $all = array_values($ry);
            if (count($all) > 1) {
                $m  = corr_mean($all);
                $sd = sqrt(array_sum(array_map(static fn ($v) => ($v - $m) ** 2, $all)) / (count($all) - 1));
            }
            $threshold = (float) $out['swc'] * $sd;   // in ln units, like $diff
        }

        /* The chart's two values: the person's typical level, moved by each
           group's own difference from all their days. */
        $base = $outcome === 'onset_dev'
            ? corr_median(array_values(array_intersect_key($vars['onset_clock'] ?? [], array_flip(array_column($lined, 1)))))
            : ($mean ? corr_mean(array_map(static fn ($d) => (float) $y[$d[1]], $lined)) : corr_median(array_map(static fn ($d) => (float) $y[$d[1]], $lined)));
        $all = $center($yAll);
        $chartHigh = (float) $base + ($yHigh === [] ? 0.0 : $center($yHigh) - $all);
        $chartLow  = (float) $base + ($yLow === [] ? 0.0 : $center($yLow) - $all);

        /* Shown units: HRV in % and ms, a share in points and %. */
        $show = static fn (?float $v): ?float => $v;
        if ($outcome === 'hrv') {
            $show = static fn (?float $v): ?float => $v === null ? null : (exp($v) - 1) * 100;
            $chartHigh = exp($chartHigh);
            $chartLow  = exp($chartLow);
        } elseif ($mean) {
            $show = static fn (?float $v): ?float => $v === null ? null : $v * 100;
            $chartHigh *= 100;
            $chartLow  *= 100;
        }

        /* Whether the difference matters, and whether its whole interval
           stays within what does not — in the outcome's own units. */
        $result['practical'] = $threshold !== null && abs($diff) >= $threshold;
        $result['within']    = $threshold !== null && $lo !== null && $hi !== null && $lo > -$threshold && $hi < $threshold;

        $result['tested']    = true;
        $result['rho']       = $test['rho'];
        $result['p']         = $test['p'];
        $result['n_eff']     = $test['n_eff'];
        $result['stable']    = corr_stable($series, $test['rho']);
        $result['effect']    = $show($diff);
        $result['lo']        = $show($lo);
        $result['hi']        = $show($hi);
        $result['threshold'] = $show($threshold);
        $result['chart']     = [
            'kind' => corr_chart_kind($outcome),
            'high' => $chartHigh,
            'low'  => $chartLow,
        ];
        $result['need'] = corr_need($pair, $n, min($nHigh, $nLow), $cfg);

        return $result;
    }

    /**
     * What is still missing before the pair can show a Patroon: days, or
     * days in its smaller group. Null when nothing is.
     *
     * @return ?array{kind: string, count: int}
     */
    function corr_need(array $pair, int $n, int $smaller, array $cfg): ?array
    {
        $weekly = $pair['lag'] === 'week';
        $days   = $weekly ? (int) $pair['min_weeks'] : (int) $cfg['tiers']['pattern']['days'];
        $group  = $weekly ? (int) ($pair['min_group'] ?? 3) : (int) $cfg['tiers']['pattern']['group'];

        if ($n < $days) {
            return ['kind' => 'days', 'count' => $days - $n];
        }
        if ($smaller < $group) {
            return ['kind' => 'group', 'count' => $group - $smaller];
        }

        return null;
    }

    /** How the chart shows an outcome. */
    function corr_chart_kind(string $outcome): string
    {
        return match ($outcome) {
            'sleep_min', 'week_sleep' => 'duration',
            'onset_dev', 'onset_clock' => 'clock',
            'hrv'        => 'ms',
            'sleep_hr'   => 'bpm',
            'steps'      => 'steps',
            'kcal'       => 'kcal',
            'trained'    => 'share',
            'protein_kg' => 'g_per_kg',
            'water'      => 'litres',
            default      => 'number',
        };
    }

    /** The label (D6, R2): the strongest whose every condition holds. */
    function corr_label(array $r, array $pair, array $cfg): array
    {
        $t = (array) $cfg['tiers'];
        $r['tier'] = 'unclear';
        $r['concordance'] = null;
        $r['sign'] = 0;

        if (!$r['tested']) {
            return $r;
        }

        $weekly    = $pair['lag'] === 'week';
        $rho       = (float) $r['rho'];
        $effect    = (float) $r['effect'];
        $smaller   = min($r['n_high'], $r['n_low']);
        $practical = (bool) ($r['practical'] ?? false);
        $sameSign  = $effect != 0.0 && ($effect > 0) === ($rho > 0);
        $stable    = $r['stable'] === true;
        $q         = $r['q'] ?? 1.0;

        if (!$weekly && $q <= $t['clear']['q'] && abs($rho) >= $t['clear']['rho'] && $practical && $sameSign && $stable
            && $r['n'] >= $t['clear']['days'] && $smaller >= $t['clear']['group']) {
            $r['tier'] = 'clear';
        } elseif (!$weekly && $q <= $t['pattern']['q'] && abs($rho) >= $t['pattern']['rho'] && $practical && $sameSign && $stable
            && $r['n'] >= $t['pattern']['days'] && $smaller >= $t['pattern']['group']) {
            $r['tier'] = 'pattern';
        } elseif ($r['p'] < $t['possible']['p'] && abs($rho) >= $t['pattern']['rho'] && $practical && $sameSign) {
            $r['tier'] = 'possible';
        } elseif ($r['n'] >= $t['none']['days'] && ($r['within'] ?? false)) {
            $r['tier'] = 'none';
        }

        if (in_array($r['tier'], ['clear', 'pattern', 'possible'], true)) {
            $r['sign'] = $effect > 0 ? 1 : -1;
            $direction = (int) ($pair['direction'] ?? 0);
            if ($direction !== 0) {
                $r['concordance'] = $r['sign'] === $direction ? 'match' : 'differ';
            }
            $r['need'] = null;
        } elseif ($r['tier'] === 'none') {
            $r['need'] = null;
        }

        return $r;
    }

    /**
     * The approved claims of a pair that hold for this age, primary first.
     *
     * @return list<string> claim keys
     */
    function corr_pair_claims(array $pair, ?int $age): array
    {
        $keys = [];
        foreach ((array) ($pair['claims'] ?? []) as [$min, $max, $key]) {
            if ($age !== null && (($min !== null && $age < $min) || ($max !== null && $age > $max))) {
                continue;
            }
            $keys[] = (string) $key;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Overzicht's four (D1, D4, R3): Duidelijk patroon and Patroon first —
     * stronger, then with research, then the stronger correlation — one per
     * outcome; then the Nog onduidelijk pairs closest to enough data.
     *
     * @param array<string, array> $pairs  corr_evaluate()['pairs'] (or stored)
     * @return list<string> pair codes
     */
    function corr_overview(array $pairs, array $cfg, ?int $age): array
    {
        $count = (int) $cfg['overview_count'];
        $rank  = ['clear' => 0, 'pattern' => 1];
        $shown = [];
        $seen  = [];

        $strong = array_filter($pairs, static fn ($r) => isset($rank[$r['tier']]));
        uksort($strong, static function ($a, $b) use ($strong, $rank, $cfg, $age) {
            $ra = $strong[$a];
            $rb = $strong[$b];

            return [$rank[$ra['tier']], corr_pair_claims($cfg['pairs'][$a], $age) === [] ? 1 : 0, -abs((float) $ra['rho']), $a]
               <=> [$rank[$rb['tier']], corr_pair_claims($cfg['pairs'][$b], $age) === [] ? 1 : 0, -abs((float) $rb['rho']), $b];
        });
        foreach (array_keys($strong) as $code) {
            $outcome = (string) $cfg['pairs'][$code]['outcome'];
            if (isset($seen[$outcome]) || count($shown) >= $count) {
                continue;
            }
            $seen[$outcome] = true;
            $shown[] = $code;
        }

        /* Fill up with what is closest to being known — only pairs the
           person has data for at all. */
        $waiting = array_filter($pairs, static fn ($r) => $r['tier'] === 'unclear' && ($r['available'] ?? false) && $r['n'] > 0);
        uksort($waiting, static function ($a, $b) use ($waiting) {
            $na = $waiting[$a]['need']['count'] ?? PHP_INT_MAX;
            $nb = $waiting[$b]['need']['count'] ?? PHP_INT_MAX;

            return [$na, $a] <=> [$nb, $b];
        });
        foreach (array_keys($waiting) as $code) {
            if (count($shown) >= $count) {
                break;
            }
            $shown[] = $code;
        }

        return $shown;
    }
}
