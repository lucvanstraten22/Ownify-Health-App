<?php
/**
 * The statistics behind Ownify's combined insights (docs/CORRELATIONS.md):
 * pure functions on plain arrays, no database, no clock.
 *
 * The method, step by step, is the one validated on simulated self-tracking
 * data before it was built (round-1 report, 28 October 2026):
 *
 *   1. per variable, remove the person's slow trend (linear + curved over the
 *      window) and weekday pattern by least squares — weekends and slow
 *      drifts move many things at once and are the main source of chance
 *      "patterns"
 *   2. cap outliers at 3.5 robust standard deviations
 *   3. pre-whiten the pair with the exposure's lag-1 dependence, on
 *      consecutive days only
 *   4. Spearman's rank correlation, with an effective number of days that
 *      counts the remaining day-to-day dependence AND the terms removed in
 *      step 1, and a Fisher-z p-value
 *   5. Benjamini–Hochberg over every test in the person's run
 *   6. the same direction in both halves of the window
 *   7. the difference between day groups (Hodges–Lehmann) with a 90%
 *      interval from a moving-block bootstrap (7-day blocks)
 *
 * With 16–17 pairs, step 1–5 keep false alarms per test under 5% and the
 * chance that a person sees any false pattern near 5–8%.
 */

declare(strict_types=1);

if (!function_exists('corr_ranks')) {

    /** Average ranks (1-based), ties sharing their mean rank. */
    function corr_ranks(array $v): array
    {
        $v     = array_values($v);
        $order = array_keys($v);
        usort($order, static fn ($a, $b) => $v[$a] <=> $v[$b] ?: $a <=> $b);
        $r = array_fill(0, count($v), 0.0);
        $i = 0;
        $n = count($order);
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $v[$order[$j + 1]] == $v[$order[$i]]) {
                $j++;
            }
            $mean = ($i + $j) / 2 + 1;
            for ($k = $i; $k <= $j; $k++) {
                $r[$order[$k]] = $mean;
            }
            $i = $j + 1;
        }

        return $r;
    }

    function corr_mean(array $v): float
    {
        return $v === [] ? 0.0 : array_sum($v) / count($v);
    }

    function corr_median(array $v): ?float
    {
        $v = array_values($v);
        $n = count($v);
        if ($n === 0) {
            return null;
        }
        sort($v);
        $m = intdiv($n, 2);

        return $n % 2 ? (float) $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
    }

    /** Pearson's r of two equal-length lists; 0 when either is constant. */
    function corr_pearson(array $a, array $b): float
    {
        $a = array_values($a);
        $b = array_values($b);
        $n = min(count($a), count($b));
        if ($n < 3) {
            return 0.0;
        }
        $ma = corr_mean(array_slice($a, 0, $n));
        $mb = corr_mean(array_slice($b, 0, $n));
        $sab = $sa = $sb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $da = $a[$i] - $ma;
            $db = $b[$i] - $mb;
            $sab += $da * $db;
            $sa  += $da * $da;
            $sb  += $db * $db;
        }

        return ($sa > 0 && $sb > 0) ? $sab / sqrt($sa * $sb) : 0.0;
    }

    function corr_spearman(array $a, array $b): float
    {
        return corr_pearson(corr_ranks($a), corr_ranks($b));
    }

    /** Lag-1 autocorrelation of a series in order. */
    function corr_lag1(array $v): float
    {
        $v = array_values($v);

        return count($v) < 4 ? 0.0 : corr_pearson(array_slice($v, 0, -1), array_slice($v, 1));
    }

    /**
     * Residuals after removing the slow trend and the weekday pattern
     * (step 1), by least squares on intercept, t, t² and six weekday
     * dummies — fewer terms when there are few days. Returns the residuals
     * keyed like $values and how many terms were fitted (k).
     *
     * $weekdayTerms false leaves the weekday pattern in: for a pair whose
     * exposure IS the day of the week (weekend against weekdays).
     *
     * @param array<string, float> $values  keyed by Y-m-d
     * @return array{0: array<string, float>, 1: int}
     */
    function corr_residuals(array $values, string $windowFrom, int $windowDays, bool $weekdayTerms = true): array
    {
        $dates = array_keys($values);
        $n     = count($dates);
        if ($n < 4) {
            return [$values, 0];
        }
        $start = strtotime($windowFrom . ' 12:00:00');
        $rows  = [];
        foreach ($dates as $date) {
            $t   = (strtotime($date . ' 12:00:00') - $start) / 86400 / max(1, $windowDays);
            $dow = (int) date('N', strtotime($date . ' 12:00:00'));   // 1 (Mon) … 7 (Sun)
            $row = [1.0, $t];
            if ($n >= 28) {
                $row[] = $t * $t;
                for ($d = 2; $d <= 7 && $weekdayTerms; $d++) {
                    $row[] = $dow === $d ? 1.0 : 0.0;
                }
            } elseif ($n >= 14 && $weekdayTerms) {
                $row[] = $dow >= 6 ? 1.0 : 0.0;   // weekend only
            }
            $rows[] = $row;
        }
        $k    = count($rows[0]);
        $beta = corr_least_squares($rows, array_values($values));
        $out  = [];
        foreach ($dates as $i => $date) {
            $fit = 0.0;
            for ($j = 0; $j < $k; $j++) {
                $fit += $beta[$j] * $rows[$i][$j];
            }
            $out[$date] = (float) $values[$date] - $fit;
        }

        return [$out, $k];
    }

    /** Least squares by the normal equations (k ≤ 9), Gaussian elimination. */
    function corr_least_squares(array $x, array $y): array
    {
        $k = count($x[0]);
        $m = array_fill(0, $k, array_fill(0, $k + 1, 0.0));
        foreach ($x as $i => $row) {
            for ($a = 0; $a < $k; $a++) {
                for ($b = 0; $b < $k; $b++) {
                    $m[$a][$b] += $row[$a] * $row[$b];
                }
                $m[$a][$k] += $row[$a] * $y[$i];
            }
        }
        for ($i = 0; $i < $k; $i++) {
            $p = $i;
            for ($r = $i + 1; $r < $k; $r++) {
                if (abs($m[$r][$i]) > abs($m[$p][$i])) {
                    $p = $r;
                }
            }
            [$m[$i], $m[$p]] = [$m[$p], $m[$i]];
            if (abs($m[$i][$i]) < 1e-12) {
                continue;
            }
            for ($r = 0; $r < $k; $r++) {
                if ($r === $i) {
                    continue;
                }
                $f = $m[$r][$i] / $m[$i][$i];
                for ($c = $i; $c <= $k; $c++) {
                    $m[$r][$c] -= $f * $m[$i][$c];
                }
            }
        }
        $beta = [];
        for ($i = 0; $i < $k; $i++) {
            $beta[] = abs($m[$i][$i]) < 1e-12 ? 0.0 : $m[$i][$k] / $m[$i][$i];
        }

        return $beta;
    }

    /** Caps values beyond 3.5 robust standard deviations from the median (step 2). */
    function corr_winsorize(array $values, float $limit = 3.5): array
    {
        $median = corr_median($values);
        if ($median === null) {
            return $values;
        }
        $mad = corr_median(array_map(static fn ($v) => abs($v - $median), $values)) * 1.4826;
        if ($mad <= 0) {
            return $values;
        }
        $lo = $median - $limit * $mad;
        $hi = $median + $limit * $mad;

        return array_map(static fn ($v) => max($lo, min($hi, $v)), $values);
    }

    /** Two-sided normal tail. */
    function corr_normal_p(float $z): float
    {
        return min(1.0, 2 * 0.5 * corr_erfc(abs($z) / M_SQRT2));
    }

    /** erfc, Numerical Recipes' Chebyshev approximation (|error| < 1.2e-7). */
    function corr_erfc(float $x): float
    {
        $z = abs($x);
        $t = 1 / (1 + 0.5 * $z);
        $r = $t * exp(-$z * $z - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418 + $t * (-0.18628806
            + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587 + $t * (-0.82215223 + $t * 0.17087277)))))))));

        return $x >= 0 ? $r : 2 - $r;
    }

    /**
     * Steps 3–4 for one pair: pre-whitening on consecutive days, Spearman's
     * rho, the effective number of days and the p-value.
     *
     * @param list<array{0: string, 1: float, 2: float}> $pairs  [date, x, y] in date order (residuals)
     * @return array{rho: float, p: float, n: int, n_eff: float}
     */
    function corr_test(array $pairs, int $fittedTerms): array
    {
        $n = count($pairs);
        if ($n < 8) {
            return ['rho' => 0.0, 'p' => 1.0, 'n' => $n, 'n_eff' => 0.0];
        }

        /* Pre-whitening with the exposure's own lag-1 dependence, only where
           the day before is in the series too. */
        $phi = corr_lag1(array_column($pairs, 1));
        $phi = max(-0.9, min(0.9, $phi));
        $xw = $yw = [];
        for ($i = 1; $i < $n; $i++) {
            $gap = (strtotime($pairs[$i][0] . ' 12:00:00') - strtotime($pairs[$i - 1][0] . ' 12:00:00')) / 86400;
            if ((int) round($gap) !== 1) {
                continue;
            }
            $xw[] = $pairs[$i][1] - $phi * $pairs[$i - 1][1];
            $yw[] = $pairs[$i][2] - $phi * $pairs[$i - 1][2];
        }
        /* Too few consecutive days to pre-whiten: the series as they are. */
        if (count($xw) < max(8, (int) floor($n * 0.6))) {
            $xw = array_column($pairs, 1);
            $yw = array_column($pairs, 2);
        }

        $rx  = corr_ranks($xw);
        $ry  = corr_ranks($yw);
        $rho = corr_pearson($rx, $ry);
        $a   = corr_lag1($rx);
        $b   = corr_lag1($ry);
        $m   = count($rx);
        $neff = $m * (1 - $a * $b) / (1 + $a * $b) - ($fittedTerms + 1);
        $neff = max(4.0, min((float) ($m - $fittedTerms - 1), $neff));
        $r    = max(-0.9999, min(0.9999, $rho));
        $z    = 0.5 * log((1 + $r) / (1 - $r)) * sqrt(max(1.0, $neff - 3));

        return ['rho' => $rho, 'p' => corr_normal_p($z), 'n' => $n, 'n_eff' => $neff];
    }

    /**
     * Benjamini–Hochberg q-values (step 5), in the order given.
     *
     * @param array<string, float> $p
     * @return array<string, float>
     */
    function corr_bh(array $p): array
    {
        $m = count($p);
        if ($m === 0) {
            return [];
        }
        asort($p);
        $keys = array_keys($p);
        $vals = array_values($p);
        $q    = array_fill(0, $m, 1.0);
        $min  = 1.0;
        for ($i = $m - 1; $i >= 0; $i--) {
            $min   = min($min, $vals[$i] * $m / ($i + 1));
            $q[$i] = min(1.0, $min);
        }

        return array_combine($keys, $q);
    }

    /** Same sign of rho in both halves of the window (step 6). */
    function corr_stable(array $pairs, float $rho): ?bool
    {
        $n = count($pairs);
        $h = intdiv($n, 2);
        if ($h < 8) {
            return null;
        }
        $first  = corr_spearman(array_column(array_slice($pairs, 0, $h), 1), array_column(array_slice($pairs, 0, $h), 2));
        $second = corr_spearman(array_column(array_slice($pairs, $h), 1), array_column(array_slice($pairs, $h), 2));

        return ($first > 0) === ($rho > 0) && ($second > 0) === ($rho > 0) && $first != 0.0 && $second != 0.0;
    }

    /** Hodges–Lehmann estimate of the shift between two groups (step 7). */
    function corr_hodges_lehmann(array $high, array $low): ?float
    {
        if ($high === [] || $low === []) {
            return null;
        }
        $d = [];
        foreach ($high as $a) {
            foreach ($low as $b) {
                $d[] = $a - $b;
            }
        }

        return corr_median($d);
    }

    /** A small seeded generator (xorshift32), so a person's numbers are the same on every load. */
    function corr_rng(int $seed): Closure
    {
        $state = ($seed & 0x7fffffff) ?: 2463534242;

        return static function (int $max) use (&$state): int {
            $state ^= ($state << 13) & 0xffffffff;
            $state ^= $state >> 17;
            $state ^= ($state << 5) & 0xffffffff;
            $state &= 0xffffffff;

            return $state % max(1, $max);
        };
    }

    /**
     * 90% interval of the group difference by a moving-block bootstrap of
     * the days (7-day blocks, $b resamples).
     *
     * $mean: the difference of means instead of medians, for a yes/no
     * outcome (trained or not), where a median says nothing.
     *
     * @param list<array{0: bool, 1: float}> $days  [in the high/exposed group, outcome residual] in date order
     * @return array{0: ?float, 1: ?float}
     */
    function corr_bootstrap(array $days, int $seed, int $b = 400, int $block = 7, bool $mean = false): array
    {
        $n = count($days);
        if ($n < 10) {
            return [null, null];
        }
        $rand = corr_rng($seed);
        $est  = [];
        for ($r = 0; $r < $b; $r++) {
            $high = $low = [];
            $taken = 0;
            while ($taken < $n) {
                $start = $rand($n);
                for ($k = 0; $k < $block && $taken < $n; $k++, $taken++) {
                    [$g, $v] = $days[($start + $k) % $n];
                    if ($g) {
                        $high[] = $v;
                    } else {
                        $low[] = $v;
                    }
                }
            }
            if (count($high) >= 3 && count($low) >= 3) {
                $est[] = $mean ? corr_mean($high) - corr_mean($low) : corr_median($high) - corr_median($low);
            }
        }
        if (count($est) < $b / 2) {
            return [null, null];
        }
        sort($est);
        $at = static fn (float $q): float => $est[(int) max(0, min(count($est) - 1, floor($q * (count($est) - 1))))];

        return [$at(0.05), $at(0.95)];
    }
}
