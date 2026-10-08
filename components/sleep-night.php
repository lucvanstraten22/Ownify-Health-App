<?php
/**
 * Slaapverloop — the last night, stage by stage (lib/hydrate-sleep.php,
 * docs/SLEEP.md).
 *
 *   the head    the night's date, and how long was slept and how much of
 *               the time in bed
 *   the rows    Wakker, Rusteloosheid, REM, Licht and Diep, top to bottom,
 *               each named on the left with its time over the night
 *   the blocks  each recorded period of a stage on its row, from bedtime at
 *               the left edge to wake time at the right — the night's own
 *               times, never a fixed clock
 *   the reading a finger, a cursor or the arrow keys on the night show the
 *               period there: its stage and when it began and ended, above
 *               the chart (sleep.js)
 *
 * A night without recorded stages keeps its times and empty rows, and says
 * so; no night at all, the rows and a line. Nothing is guessed.
 */
declare(strict_types=1);

$night  = $data['night'];
$rows   = $night['rows'];
$staged = !empty($night['staged']);
$count  = count($rows);
?>
<section class="card sleep-night reveal <?= $staged ? 'is-filled' : 'is-empty' ?>" data-sleep-night
         aria-labelledby="sleep-night-title">

    <div class="card__head sleep-night__head">
        <div class="card__headings">
            <h2 class="card__eyebrow" id="sleep-night-title"><?= e($night['title']) ?></h2>
            <?php if ($night['date'] !== null): ?>
                <p class="card__meta card__meta--small"><?= e($night['date']) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($night['asleep'] !== null): ?>
            <p class="sleep-night__sum">
                <span class="sleep-night__value"><?= e($night['asleep']) ?><span class="sleep-night__unit">u</span></span>
                <span class="sleep-night__word"><?= e($night['asleep_label']) ?><?php if ($night['efficiency'] !== null): ?>
                    · <?= e((string) $night['efficiency']) ?>% <?= e($night['efficiency_label']) ?><?php endif; ?></span>
            </p>
        <?php endif; ?>
    </div>

    <div class="sleep-night__chart" style="--rows: <?= $count ?>;">
        <ul class="sleep-night__labels" role="list">
            <?php foreach ($rows as $row): ?>
                <li class="sleep-night__label" data-stage="<?= e($row['key']) ?>">
                    <span class="sleep-night__name"><?= e($row['label']) ?></span>
                    <?php if ($row['total'] !== null): ?>
                        <span class="sleep-night__total"><?= e($row['total']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="sleep-night__plot"<?php if ($staged): ?>
             data-night-plot data-gesture-own tabindex="0" role="group" aria-roledescription="grafiek"
             aria-label="<?= e($night['aria']) ?>" aria-describedby="sleep-night-hint"<?php else: ?>
             role="img" aria-label="<?= e($night['aria']) ?>"<?php endif; ?>>
            <?php foreach ($rows as $r => $row): ?>
                <span class="sleep-night__lane" aria-hidden="true" style="--row: <?= $r ?>;"></span>
            <?php endforeach; ?>

            <?php foreach ($night['blocks'] as $i => [$r, $from, $to]): ?>
                <span class="sleep-night__block" data-stage="<?= e($rows[$r]['key']) ?>" data-block="<?= $i ?>" aria-hidden="true"
                      style="--row: <?= (int) $r ?>; left: <?= e((string) $from) ?>%; width: <?= e((string) max(0, $to - $from)) ?>%;"></span>
            <?php endforeach; ?>

            <?php if ($staged): ?>
                <div class="goal-chart__tip sleep-night__tip" data-night-tip aria-hidden="true" hidden>
                    <strong class="goal-chart__tip-value" data-tip-stage></strong>
                    <span class="goal-chart__tip-date" data-tip-time></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($night['ticks'] !== []): ?>
        <ul class="chart__axis sleep-night__axis" role="list" aria-hidden="true">
            <?php foreach ($night['ticks'] as $tick): ?>
                <li class="chart__tick" style="left: <?= e((string) $tick['x']) ?>%;"><?= e($tick['label']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($night['note'] !== null): ?>
        <p class="sleep-night__note"><?= e($night['note']) ?></p>
    <?php endif; ?>

    <?php if ($staged): ?>
        <p class="compass-history__hint" id="sleep-night-hint"><?= e($night['hint']) ?></p>
        <p class="sr-only" aria-live="polite" data-night-live></p>
        <?php /* Each block as the reading needs it: [its row, from, to (% of the
                 night), began, ended]; the rows' names in order. */ ?>
        <script type="application/json" data-night-blocks><?= json_encode(
            ['rows' => array_column($rows, 'label'), 'blocks' => $night['blocks']],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
    <?php endif; ?>
</section>
