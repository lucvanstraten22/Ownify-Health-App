<?php
/**
 * One heart-rate plot (lib/hydrate-training.php, docs/TRAINING.md): a day
 * from 00:00 to 24:00, a period's daily averages, or a training session
 * minute by minute — inside a `.chart__range` of a `[data-health-history]`
 * card, so compass-history.js reads it as it reads every chart over time.
 *
 * The four zones are a layer over the values, never a change to them: the
 * line takes each zone's colour where it runs through that zone — grey low,
 * the Training colour higher, its brightest at the top — a faint band marks
 * zones 2 to 4 behind it, and the reading names the zone. A day's points
 * stand five minutes apart; a dot only where one stands alone. A period's
 * points each have their dot.
 *
 *   plot     a day, period or session as the server built it
 *   plot_id  unique on the page: its gradient's id
 *   series   what its one value is called in the reading ("Hartslag")
 *   suffix   the card's id, for the hint the plot is described by
 */
declare(strict_types=1);

$p      = $data['plot'];
$gid    = 'hr-zones-' . $data['plot_id'];
$line   = $p['lines'][0] ?? ['line' => [], 'y' => []];
$zy     = $p['zones_y'] ?? null;
$lone   = isset($p['lone']) ? array_flip($p['lone']) : null;     // null: a dot on every point
$small  = count($p['x']) > 7;
/* Drawn on as every chart's line is — but not a line of hundreds of points,
   whose length health-trend.js cannot measure closely enough: it would stop
   short. */
$draw   = count($p['x']) <= 31;
$w      = (int) $p['width'];
$h      = (int) $p['height'];

/* The zones from the bottom of the box (offset 0) to its top (1): each a
   colour from where it begins to where the next does — hard edges. */
$edges = $zy === null ? null : array_merge([0.0], array_map(static fn (float $y): float => round(max(0, min(1, 1 - $y / 100)), 4), $zy), [1.0]);
?>
<div class="compass-plot heart-plot<?= $p['grid'] !== [] ? ' has-levels' : '' ?>"<?php if ($p['has_data']): ?>
     data-history-plot data-gesture-own tabindex="0" role="group" aria-roledescription="grafiek"
     aria-label="<?= e($p['aria']) ?>" aria-describedby="<?= e($data['suffix']) ?>-hint"<?php else: ?>
     role="img" aria-label="<?= e($p['aria']) ?>"<?php endif; ?>>

    <?php if ($zy !== null && $p['has_data']):
        for ($z = 2; $z <= 4; $z++):
            $bottom = max(0.0, min(100.0, (float) $zy[$z - 2]));
            $top    = $z === 4 ? 0.0 : max(0.0, min(100.0, (float) $zy[$z - 1]));
            if ($bottom - $top <= 0.05) { continue; }
            ?>
            <span class="heart-plot__band" data-zone="<?= $z ?>" aria-hidden="true"
                  style="top: <?= e((string) $top) ?>%; height: <?= e((string) round($bottom - $top, 2)) ?>%;"></span>
        <?php endfor;
    endif; ?>

    <?php foreach ($p['grid'] as $level): ?>
        <span class="compass-plot__level" aria-hidden="true" style="top: <?= e((string) $level['y']) ?>%;"><?= e($level['label']) ?></span>
    <?php endforeach; ?>

    <svg class="chart__svg compass-plot__svg" viewBox="0 0 <?= $w ?> <?= $h ?>"
         preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <?php if ($edges !== null): ?>
            <defs>
                <linearGradient id="<?= e($gid) ?>" gradientUnits="userSpaceOnUse" x1="0" y1="<?= $h ?>" x2="0" y2="0">
                    <?php for ($z = 1; $z <= 4; $z++): ?>
                        <stop offset="<?= e((string) $edges[$z - 1]) ?>" style="stop-color: var(--zone-<?= $z ?>)"/>
                        <stop offset="<?= e((string) $edges[$z]) ?>" style="stop-color: var(--zone-<?= $z ?>)"/>
                    <?php endfor; ?>
                </linearGradient>
            </defs>
        <?php endif; ?>
        <?php foreach ($p['grid'] as $level): ?>
            <line class="chart__grid" x1="0" x2="<?= $w ?>"
                  y1="<?= round($level['y'] / 100 * $h, 1) ?>" y2="<?= round($level['y'] / 100 * $h, 1) ?>"/>
        <?php endforeach; ?>
        <?php if ($p['grid'] === []): ?>
            <line class="chart__grid" x1="0" x2="<?= $w ?>" y1="<?= $h ?>" y2="<?= $h ?>"/>
        <?php endif; ?>
        <g class="chart__series" data-tone="line">
            <?php foreach ($line['line'] as $path): ?>
                <path class="chart__line heart-plot__line" d="<?= e($path) ?>"<?= $draw ? ' data-draw' : '' ?><?= $edges !== null ? ' style="stroke: url(#' . e($gid) . ')"' : '' ?>/>
            <?php endforeach; ?>
        </g>
    </svg>

    <?php foreach ($line['y'] as $i => $top):
        if ($top === null || ($lone !== null && !isset($lone[$i]))) { continue; }
        $zone = $p['zone'][$i] ?? null;
        ?>
        <span class="compass-plot__dot<?= $small ? ' is-small' : '' ?>"<?= $zone !== null ? ' data-zone="' . (int) $zone . '"' : '' ?>
              data-tone="line" aria-hidden="true" style="left: <?= e((string) $p['x'][$i]) ?>%; top: <?= e((string) $top) ?>%;"></span>
    <?php endforeach; ?>

    <?php if ($p['has_data']): ?>
        <span class="goal-chart__cross" data-reading-cross aria-hidden="true" hidden></span>
        <span class="goal-chart__focus" data-reading-focus="heart" data-tone="line" aria-hidden="true" hidden></span>
        <div class="goal-chart__tip health-history__tip" data-reading-tip aria-hidden="true" hidden>
            <span class="goal-chart__tip-date"><span data-tip-date></span><span class="compass-plot__detail" data-tip-detail hidden></span></span>
            <span class="health-history__tip-rows">
                <span class="health-history__tip-row" data-tip-row="heart" data-tone="line" hidden>
                    <span class="health-history__tip-key" aria-hidden="true"></span>
                    <span class="health-history__tip-label"><?= e($data['series']) ?></span>
                    <strong class="goal-chart__tip-value" data-tip-value></strong>
                </span>
            </span>
            <span class="health-history__tip-none" data-tip-none hidden></span>
        </div>
    <?php endif; ?>
</div>

<?php component('chart-axis', ['ticks' => $p['axis'], 'class' => $p['grid'] !== [] ? 'has-levels' : null]); ?>

<?php if (!$p['has_data']): ?>
    <p class="chart__empty"><?= e($p['empty'] ?? $data['empty'] ?? '') ?></p>
<?php else: ?>
    <script type="application/json" data-history-points><?= json_encode(
        $p['points'],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?></script>
<?php endif; ?>
