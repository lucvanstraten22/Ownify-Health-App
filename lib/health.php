<?php
/**
 * Health helpers — demo handling, shared-metric resolution and chart geometry.
 *
 * The chart maths lives here rather than in JavaScript so the markup is
 * complete on arrival: the lines are drawn server-side and JavaScript only
 * animates and switches between them.
 */

declare(strict_types=1);

if (!function_exists('health_prepare')) {
    /**
     * Applies the review-only demo values when config/health.php has demo on.
     * With demo off — the shipped state — this returns the config untouched
     * and every value stays null.
     */
    function health_prepare(array $health): array
    {
        if (empty($health['demo'])) {
            return $health;
        }

        return health_fill_demo($health);
    }
}

if (!function_exists('health_fill_demo')) {
    /** Walks the tree and copies `demo` over `value` / `values` / `share`. */
    function health_fill_demo(array $node): array
    {
        if (array_key_exists('demo', $node)) {
            foreach (['value', 'values', 'share'] as $slot) {
                if (array_key_exists($slot, $node)) {
                    $node[$slot] = $node['demo'];
                }
            }
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $node[$key] = health_fill_demo($child);
            }
        }

        return $node;
    }
}

if (!function_exists('health_overall_score')) {
    /**
     * The dashboard's health score: the plain average of the three Gezondheid
     * pillars, Slaap + Voeding + Training, divided by three.
     *
     * Derived on every render rather than stored anywhere, which is what makes
     * it follow its inputs: change an area's score — in config now, from the
     * database later — and the ring moves with it. There is no second copy to
     * keep in step and no way for the two to disagree.
     *
     * All three are required. Dividing a two-pillar total by three would read
     * a missing score as a zero and quietly understate the day, so until every
     * pillar has a value the card keeps its empty state.
     *
     * Each area is taken as a share of its own max before averaging, so the
     * result stays correct if a pillar is ever scored out of something other
     * than 100.
     */
    function health_overall_score(array $health, int $max = 100): ?int
    {
        $areas = $health['areas'] ?? [];

        if ($areas === []) {
            return null;
        }

        $total = 0.0;

        foreach ($areas as $area) {
            $value   = $area['score']['value'] ?? null;
            $areaMax = (float) ($area['score']['max'] ?? 100);

            if (!has_value($value) || $areaMax <= 0) {
                return null;
            }

            $total += (float) $value / $areaMax;
        }

        return (int) round($total / count($areas) * $max);
    }
}

if (!function_exists('health_contributor_scores')) {
    /**
     * Fills each ring-legend row with the score of the area it names.
     *
     * Derived on every render, from the same source as the overall score, so
     * the legend and the number in the middle of the ring can never drift
     * apart: they read the same three values.
     */
    function health_contributor_scores(array $contributors, array $health): array
    {
        foreach ($contributors as $index => $row) {
            $area = $health['areas'][$row['area']] ?? null;
            $contributors[$index]['value'] = $area['score']['value'] ?? null;
        }

        return $contributors;
    }
}

if (!function_exists('health_metric')) {
    /**
     * Resolves one metric reference against the shared registry.
     * The registry owns what a metric *is*; the area owns its value. That is
     * what lets HRV appear in Slaap and in Training from one definition.
     */
    function health_metric(array $entry, array $registry): array
    {
        $definition = $registry[$entry['key']] ?? ['label' => $entry['key'], 'unit' => ''];

        return array_merge(
            ['unit' => '', 'availability' => null, 'value' => null],
            $definition,
            array_diff_key($entry, ['demo' => null])
        );
    }
}

if (!function_exists('health_series_has_data')) {
    /** True when at least one point in a series carries a value. */
    function health_series_has_data(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('health_chart')) {
    /**
     * Turns a series of 0–100 values into SVG geometry.
     *
     * Gaps are respected: a run of consecutive readings becomes one smooth
     * path, a lone reading becomes a dot, and an empty series returns nothing
     * at all so the caller can render its empty state instead.
     *
     * @return array{line: string[], area: string[], dots: array<array{0: float, 1: float}>, points: array<?array{0: float, 1: float}>}
     */
    function health_chart(
        array $values,
        float $width = 300.0,
        float $height = 120.0,
        float $max = 100.0,
        float $padX = 8.0
    ): array {
        $padY = 12.0;
        $count = count($values);

        $result = ['line' => [], 'area' => [], 'dots' => [], 'points' => []];

        if ($count === 0) {
            return $result;
        }

        $span = $count > 1 ? ($width - 2 * $padX) / ($count - 1) : 0.0;
        $plot = $height - 2 * $padY;

        $points = [];
        foreach ($values as $i => $value) {
            if ($value === null) {
                $points[] = null;
                continue;
            }

            $ratio = $max > 0 ? max(0.0, min(1.0, (float) $value / $max)) : 0.0;
            $points[] = [
                round($padX + $i * $span, 2),
                round($padY + (1 - $ratio) * $plot, 2),
            ];
        }

        $result['points'] = $points;

        /* Split into runs of consecutive readings. */
        $runs = [];
        $run = [];
        foreach ($points as $point) {
            if ($point === null) {
                if ($run) { $runs[] = $run; $run = []; }
                continue;
            }
            $run[] = $point;
        }
        if ($run) { $runs[] = $run; }

        foreach ($runs as $segment) {
            if (count($segment) === 1) {
                $result['dots'][] = $segment[0];
                continue;
            }

            $line = health_smooth_path($segment);
            $result['line'][] = $line;
            $result['area'][] = $line
                . ' L ' . $segment[count($segment) - 1][0] . ' ' . round($height, 2)
                . ' L ' . $segment[0][0] . ' ' . round($height, 2) . ' Z';
        }

        return $result;
    }
}

if (!function_exists('health_smooth_path')) {
    /**
     * Catmull-Rom through the points, emitted as cubic beziers. Gives trend
     * lines the soft curve a health app wants without overshooting the data.
     *
     * @param array<array{0: float, 1: float}> $points
     */
    function health_smooth_path(array $points): string
    {
        $count = count($points);
        $tension = 1 / 6;

        $path = 'M ' . $points[0][0] . ' ' . $points[0][1];

        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $points[max(0, $i - 1)];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $points[min($count - 1, $i + 2)];

            $c1x = round($p1[0] + ($p2[0] - $p0[0]) * $tension, 2);
            $c1y = round($p1[1] + ($p2[1] - $p0[1]) * $tension, 2);
            $c2x = round($p2[0] - ($p3[0] - $p1[0]) * $tension, 2);
            $c2y = round($p2[1] - ($p3[1] - $p1[1]) * $tension, 2);

            $path .= ' C ' . $c1x . ' ' . $c1y . ', ' . $c2x . ' ' . $c2y . ', ' . $p2[0] . ' ' . $p2[1];
        }

        return $path;
    }
}
