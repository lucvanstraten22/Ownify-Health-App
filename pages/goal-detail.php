<?php
/**
 * One goal in full — the layer behind a goal card.
 *
 * Same layer mechanism as a health detail page: above the rail, below the
 * dock, so the tab bar and the assistant stay reachable from inside it.
 *
 * Information order follows how much it matters:
 *   1  the goal                 — how far you are, the numbers, the period
 *   2  Zelf bijhouden           — for a goal you keep yourself, right under it
 *   3  Verloop                  — how it has moved, day by day, and what feeds it
 *   4  Per dag                  — the days, for a goal that counts them
 *   5  Aanpassen                — Primair | Secundair, pause, delete
 *
 * What Wat telt mee and Recent said lives in the Verloop: its footer names the
 * source and how the goal moves, each point the day's own amount and how far
 * the goal was that day.
 */
declare(strict_types=1);

$goal   = $data['goal'];
$goals  = $data['goals'];
$labels = $goals['labels'];
$copy   = $goals['detail'];
$id     = $goal['id'];
$chart  = $goal['chart'];

/* The hero keeps its line short: the dates live in the rows underneath, so
   repeating "t/m 4 okt" here would say the same thing twice. */
$heroActive = $goal['is_completed']
    ? ($goal['end_label'] !== null ? sprintf($labels['completed_on'], $goal['end_label']) : 'Behaald')
    : (string) $goal['remaining'];
$heroPaused = $labels['paused_line'];
$heroLine   = $goal['is_paused'] ? $heroPaused : $heroActive;

$width  = 300.0;
$height = 96.0;
?>
<article class="detail goal-detail" data-detail="goal-<?= e($id) ?>" data-goal-detail="<?= e($id) ?>"
         data-accent="<?= e($goal['accent']) ?>" data-goal-priority="<?= e($goal['priority']) ?>"
         data-goal-status="<?= e($goal['status']) ?>"
         data-goal-name="<?= e($goal['name']) ?>"
         data-line-active="<?= e($heroActive) ?>"
         data-line-paused="<?= e($heroPaused) ?>"
         data-note-active="<?= e((string) ($goal['note'] ?? '')) ?>"
         data-note-paused="<?= e($copy['paused_body']) ?>"
         aria-label="<?= e($goal['name']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="Terug naar Doelen">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($copy['back']) ?></span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">

                <!-- ----------------------------------------------- 1 + 2 -->
                <section class="card card--goal-hero reveal <?= state_class($goal['percent']) ?>"
                         aria-labelledby="goal-title-<?= e($id) ?>">

                    <div class="goal-hero__head">
                        <span class="icon-tile" aria-hidden="true"><?= icon($goal['icon']) ?></span>
                        <div class="goal-hero__heads">
                            <p class="goal-hero__category"><?= e($goal['category_label']) ?> · <?= e($goal['type_label']) ?></p>
                            <h1 class="goal-hero__name" id="goal-title-<?= e($id) ?>"><?= e($goal['name']) ?></h1>
                        </div>
                    </div>

                    <div class="goal-hero__chips">
                        <span class="chip chip--accent" data-goal-chip="primary"
                              <?= $goal['is_primary'] ? '' : 'hidden' ?>><?= e($labels['primary_chip']) ?></span>
                        <span class="chip chip--quiet" data-goal-chip="paused"
                              <?= $goal['is_paused'] ? '' : 'hidden' ?>><?= e($labels['paused_chip']) ?></span>
                        <?php if ($goal['is_completed']): ?>
                            <span class="chip chip--accent"><?= e($goals['views']['completed']['label']) ?></span>
                        <?php endif; ?>
                        <?php if ($goal['duration_label'] !== null): ?>
                            <span class="chip chip--muted"><?= e($goal['duration_label']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="goal-hero__figures">
                        <p class="goal-hero__percent">
                            <span class="goal-hero__percent-value" data-count-to="<?= has_value($goal['percent']) ? e((string) $goal['percent']) : '' ?>"><?= e(score_text($goal['percent'])) ?></span><?php if (has_value($goal['percent'])): ?><span class="goal-hero__percent-sign">%</span><?php endif; ?>
                        </p>
                        <p class="goal-hero__target"><?= e($labels['target']) ?><strong><?= e((string) ($goal['target_label'] ?? '—')) ?></strong></p>
                    </div>

                    <div class="meter meter--hero" role="img"
                         aria-label="<?= has_value($goal['percent']) ? e((string) $goal['percent']) . '% voltooid' : 'nog geen voortgang' ?>">
                        <span class="meter__fill" data-bar data-progress="<?= e((string) round($goal['ratio'], 4)) ?>"></span>
                    </div>

                    <p class="goal-hero__deadline" data-goal-deadline><?= e($heroLine) ?></p>

                    <div class="metric-rows goal-hero__facts">
                        <?php /* What the percentage is made of, named for the goal's
                                 type: the best result, the streak, the total. */ ?>
                        <div class="metric-row <?= state_class($goal['current_label']) ?>">
                            <span class="metric-row__label"><?= e($goal['current_title'] ?? $labels['current']) ?></span>
                            <span class="metric-row__value"><?= e((string) ($goal['current_label'] ?? '—')) ?></span>
                        </div>
                        <?php foreach ($goal['extra_facts'] ?? [] as $fact): ?>
                            <div class="metric-row">
                                <span class="metric-row__label"><?= e($fact['label']) ?></span>
                                <span class="metric-row__value"><?= e($fact['value']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php /* Gestart and Eindigt (Looptijd, once behaald) together,
                                 under one name: the goal's period. */ ?>
                        <div class="metric-row metric-row--period" data-goal-period>
                            <span class="metric-row__label"><?= e($copy['period'] ?? 'Periode') ?></span>
                            <span class="metric-row__value goal-period">
                                <span class="goal-period__part">Gestart: <?= e((string) ($goal['start_label'] ?? '—')) ?></span>
                                <span class="goal-period__dot" aria-hidden="true">·</span>
                                <span class="goal-period__part"><?= $goal['is_completed'] ? 'Looptijd' : 'Eindigt' ?>: <?= e((string) ($goal['is_completed']
                                    ? ($goal['took_label'] ?? '—')
                                    : ($goal['end_label'] ?? $labels['no_deadline']))) ?></span>
                            </span>
                        </div>
                    </div>

                    <p class="card__hint card__hint--plain" data-goal-note>
                        <?= e($goal['is_paused'] ? $copy['paused_body'] : (string) ($goal['note'] ?? '')) ?>
                    </p>
                </section>

                <!-- ----------------------- manual entry, when it is theirs -->
                <?php if ($goal['needs_input'] && !$goal['is_completed'] && !empty($goal['entry'])): ?>
                    <?php
                    /* Only ever shown for a goal the person keeps themselves.
                       An automatic goal reads itself, and an entry box beside
                       a self-updating figure is two numbers claiming to be the
                       same thing.

                       What it asks follows the type: a Mijlpaal takes a new
                       result (the best one counts), Optellen an amount to add,
                       and a day-counting goal a tick for today. */
                    $entry = $goal['entry'];
                    ?>
                    <section class="card card--check reveal" data-goal-manual="<?= e($id) ?>"
                             aria-labelledby="goal-check-<?= e($id) ?>">
                        <div class="card__head card__head--compact">
                            <span class="icon-tile" aria-hidden="true"><?= icon('check') ?></span>
                            <h2 class="card__eyebrow" id="goal-check-<?= e($id) ?>"><?= e($copy['manual']) ?></h2>
                        </div>

                        <?php if ($entry['kind'] === 'tick'): ?>
                            <button type="button" class="btn goal-check__button press" data-goal-tick="<?= e($id) ?>"
                                    <?= !empty($entry['done']) ? 'disabled' : '' ?>>
                                <?= icon('check', 'goal-check__icon') ?>
                                <?= e(!empty($entry['done']) ? 'Vandaag afgevinkt' : $entry['label']) ?>
                            </button>
                        <?php else: ?>
                            <div class="goal-entry">
                                <label class="sr-only" for="goal-value-<?= e($id) ?>"><?= e($entry['label']) ?></label>
                                <input class="wizard__input goal-entry__input" type="number" step="any" min="0"
                                       inputmode="decimal" id="goal-value-<?= e($id) ?>"
                                       data-goal-value="<?= e($id) ?>"
                                       placeholder="<?= e($entry['placeholder'] ?? $entry['label']) ?>">
                                <button type="button" class="btn press" data-goal-save="<?= e($id) ?>">
                                    <?= e($entry['button'] ?? $copy['manual_save'] ?? 'Opslaan') ?>
                                </button>
                            </div>
                        <?php endif; ?>

                        <p class="field-editor__error" role="alert" data-goal-error hidden></p>
                        <p class="card__hint card__hint--plain"><?= e((string) ($entry['note'] ?? '')) ?></p>
                    </section>
                <?php endif; ?>

                <!-- ------------------------------------------------- 3 -->
                <?php
                /* Verloop: the goal's real values against real dates.
                   lib/goal-chart.php explains the geometry; what matters here
                   is that every dot is a stored value and nothing else is.

                   Points for the scrubbing layer ride on a data attribute as
                   pre-formatted Dutch strings, so the script never has to
                   know how to write "10.425 stappen" or "12 september". */
                $chartPoints = array_map(
                    static fn (array $p): array => ['x' => $p['x'], 'y' => $p['y'], 'd' => $p['d'], 'v' => $p['v'], 'n' => $p['n'] ?? ''],
                    $chart['points']
                );
                ?>
                <section class="card card--trend reveal <?= $goal['has_history'] ? 'is-filled' : 'is-empty' ?>"
                         aria-labelledby="goal-history-<?= e($id) ?>">

                    <div class="card__head">
                        <div class="card__head-group">
                            <span class="icon-tile" aria-hidden="true"><?= icon('chart') ?></span>
                            <h2 class="card__eyebrow" id="goal-history-<?= e($id) ?>"><?= e($copy['history']) ?></h2>
                        </div>
                        <?php if ($goal['has_history'] && $chart['target'] !== null): ?>
                            <?php /* The target line's key: a short stroke of the same
                                     line, so the chip explains the line without a legend box. */ ?>
                            <span class="chip chip--muted goal-chart__key">
                                <span class="goal-chart__key-line" aria-hidden="true"></span><?= e($chart['target']['label']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($goal['has_history']): ?>
                        <div class="chart chart--goal" data-chart>
                            <div class="chart__range is-active" data-range="goal">
                                <div class="goal-chart" data-goal-chart
                                     data-points="<?= e((string) json_encode($chartPoints, JSON_UNESCAPED_UNICODE)) ?>">

                                    <?php if ($chart['axis_unit'] !== ''): ?>
                                        <p class="goal-chart__unit" aria-hidden="true"><?= e($chart['axis_unit']) ?></p>
                                    <?php endif; ?>

                                    <div class="goal-chart__frame">
                                        <div class="goal-chart__plot" data-goal-plot data-gesture-own tabindex="0"
                                             role="group" aria-roledescription="grafiek"
                                             aria-label="<?= e($copy['history'] . ' van ' . $goal['name'] . '. ' . $chart['summary']) ?>"
                                             aria-describedby="goal-chart-hint-<?= e($id) ?>">

                                            <svg class="chart__svg goal-chart__svg"
                                                 viewBox="0 0 <?= (int) $chart['width'] ?> <?= (int) $chart['height'] ?>"
                                                 preserveAspectRatio="none" aria-hidden="true" focusable="false">

                                                <?php foreach ($chart['y_ticks'] as $tick): ?>
                                                    <?php $gy = round($tick['top'] / 100 * $chart['height'], 2); ?>
                                                    <line class="chart__grid goal-chart__grid" x1="0" x2="<?= (int) $chart['width'] ?>"
                                                          y1="<?= e((string) $gy) ?>" y2="<?= e((string) $gy) ?>"/>
                                                <?php endforeach; ?>

                                                <?php if ($chart['target'] !== null): ?>
                                                    <?php $ty = round($chart['target']['top'] / 100 * $chart['height'], 2); ?>
                                                    <line class="goal-chart__target" x1="0" x2="<?= (int) $chart['width'] ?>"
                                                          y1="<?= e((string) $ty) ?>" y2="<?= e((string) $ty) ?>"/>
                                                <?php endif; ?>

                                                <?php /* The wash fades to nothing on its way down. A flat
                                                         fill to the bottom of a 74-82 kg axis reads as a
                                                         quantity from zero, which it is not; a fade keeps
                                                         the app's look without claiming anything. */ ?>
                                                <defs>
                                                    <linearGradient id="goal-wash-<?= e($id) ?>" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="0" class="goal-chart__wash-top"/>
                                                        <stop offset="1" class="goal-chart__wash-bottom"/>
                                                    </linearGradient>
                                                </defs>

                                                <g class="chart__series">
                                                    <?php foreach ($chart['area'] as $path): ?>
                                                        <?php /* Inline, because the shared .chart__area rule sets
                                                                 fill in CSS, and CSS beats an SVG fill attribute. */ ?>
                                                        <path class="chart__area goal-chart__area" d="<?= e($path) ?>"
                                                              style="fill: url(#goal-wash-<?= e($id) ?>);"/>
                                                    <?php endforeach; ?>
                                                    <?php foreach ($chart['line'] as $path): ?>
                                                        <path class="chart__line" d="<?= e($path) ?>" data-draw/>
                                                    <?php endforeach; ?>
                                                </g>
                                            </svg>

                                            <?php /* Dots are HTML, not SVG circles: the SVG stretches to
                                                     the card, and a stretched circle is an ellipse. */ ?>
                                            <?php foreach ($chart['points'] as $point): ?>
                                                <?php if ($point['dot']): ?>
                                                    <span class="goal-chart__dot" aria-hidden="true"
                                                          style="left: <?= e((string) $point['x']) ?>%; top: <?= e((string) $point['y']) ?>%;"></span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>

                                            <span class="goal-chart__end<?= $chart['end']['below'] ? ' is-below' : '' ?><?= ($chart['end']['align'] ?? 'end') !== 'end' ? ' is-' . e($chart['end']['align']) : '' ?>" aria-hidden="true"
                                                  style="left: <?= e((string) $chart['end']['x']) ?>%; top: <?= e((string) $chart['end']['y']) ?>%;"><?= e($chart['end']['label']) ?></span>

                                            <span class="goal-chart__cross" data-goal-cross aria-hidden="true" hidden></span>
                                            <span class="goal-chart__focus" data-goal-focus aria-hidden="true" hidden></span>

                                            <div class="goal-chart__tip" data-goal-tip aria-hidden="true" hidden>
                                                <span class="goal-chart__tip-date" data-tip-date></span>
                                                <strong class="goal-chart__tip-value" data-tip-value></strong>
                                                <span class="goal-chart__tip-note" data-tip-note hidden></span>
                                            </div>
                                        </div>

                                        <ol class="goal-chart__y" role="list" aria-hidden="true">
                                            <?php foreach ($chart['y_ticks'] as $tick): ?>
                                                <li style="top: <?= e((string) $tick['top']) ?>%;"><?= e($tick['label']) ?></li>
                                            <?php endforeach; ?>
                                        </ol>
                                    </div>

                                    <?php /* The period's dates, each under its day (docs/CHARTS.md). */ ?>
                                    <?php component('chart-axis', ['ticks' => $chart['x_ticks'], 'class' => 'goal-chart__x']); ?>

                                    <p class="sr-only" id="goal-chart-hint-<?= e($id) ?>">
                                        Gebruik de pijltjestoetsen om door de metingen te lopen.
                                    </p>
                                    <p class="sr-only" aria-live="polite" data-goal-live></p>

                                    <?php /* The table view: every value reachable without
                                             hovering, which is what a screen reader reads. */ ?>
                                    <table class="sr-only">
                                        <caption><?= e($copy['history'] . ' van ' . $goal['name']) ?></caption>
                                        <thead><tr><th scope="col">Datum</th><th scope="col">Waarde</th><th scope="col">Die dag</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($chart['points'] as $point): ?>
                                                <tr><td><?= e($point['d']) ?></td><td><?= e($point['v']) ?></td><td><?= e($point['n'] ?? '') ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php /* Nothing recorded yet: the existing empty state, unchanged.
                                 A faint grid and one sentence, never an invented line. */ ?>
                        <div class="chart" data-chart>
                            <div class="chart__range is-active" data-range="goal">
                                <svg class="chart__svg" viewBox="0 0 <?= (int) $width ?> <?= (int) $height ?>"
                                     preserveAspectRatio="none" role="img"
                                     aria-label="<?= e($copy['history']) ?>: nog geen verloop">
                                    <?php foreach ([0.25, 0.5, 0.75] as $line): ?>
                                        <line class="chart__grid" x1="0" x2="<?= (int) $width ?>"
                                              y1="<?= round(12 + $line * ($height - 24), 1) ?>"
                                              y2="<?= round(12 + $line * ($height - 24), 1) ?>"/>
                                    <?php endforeach; ?>
                                </svg>

                                <?php /* No history yet: the week from today, its dates (docs/CHARTS.md). */ ?>
                                <?php if (!empty($goal['chart']['x_ticks'])): ?>
                                    <?php component('chart-axis', ['ticks' => $goal['chart']['x_ticks']]); ?>
                                <?php else: ?>
                                    <ul class="chart__axis" role="list">
                                        <li class="chart__tick" style="left: 0;"><?= e((string) ($goal['start_label'] ?? 'Start')) ?></li>
                                        <li class="chart__tick" style="left: 100%;"><?= $goal['is_completed'] ? 'Behaald' : 'Nu' ?></li>
                                    </ul>
                                <?php endif; ?>
                            </div>

                            <p class="chart__empty"><?= e($copy['history_empty']) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php /* What feeds the line — Wat telt mee, which had a block of its
                             own: the source and its rule, or that the person keeps it,
                             and how the goal moves. */ ?>
                    <div class="goal-chart__source" data-goal-source>
                        <?php if ($goal['source_list'] === []): ?>
                            <p class="card__lede card__lede--small">Dit doel is nog niet aan gezondheidsgegevens gekoppeld.</p>
                        <?php else: ?>
                            <?php foreach ($goal['source_list'] as $source): ?>
                                <div class="metric-row" data-accent="<?= e($source['accent']) ?>">
                                    <span class="metric-row__badge goal-source__badge" aria-hidden="true"><?= icon($source['icon']) ?></span>
                                    <span class="metric-row__label goal-source__label">
                                        <strong><?= e($source['label']) ?></strong>
                                        <span class="goal-source__note"><?= e($source['note']) ?></span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <p class="card__hint"><?= icon('lock', 'card__hint-icon') ?><?= e(!empty($goal['is_manual'])
                            ? 'Je voortgang verandert alleen door wat je zelf invult.'
                            : 'Je voortgang werkt zichzelf bij zodra er nieuwe gegevens binnenkomen.') ?></p>
                    </div>
                </section>

                <!-- ---------------------------------- per-day results -->
                <?php if ($goal['days'] !== []): ?>
                    <?php
                    /* One block per day, for a goal that counts days — a
                       Streak, or Optellen in days.

                       Not two states. A day with no data is not a day that
                       failed — a phone that was not syncing yet says nothing
                       about whether somebody walked — so it is drawn as an
                       empty outline, never red. (For a Streak it still ends
                       the run, because it is not a success either; the note
                       under the calendar says so.) Today, until it counts, is
                       open rather than missed. */
                    ?>
                    <section class="card reveal" aria-labelledby="goal-days-<?= e($id) ?>">
                        <div class="card__head">
                            <div class="card__head-group">
                                <span class="icon-tile" aria-hidden="true"><?= icon('check') ?></span>
                                <h2 class="card__eyebrow" id="goal-days-<?= e($id) ?>"><?= e($copy['days'] ?? 'Per dag') ?></h2>
                            </div>
                            <span class="chip chip--muted">
                                <?= e($goal['days_chip'] ?? ($goal['days_met'] . ' van ' . $goal['days_total'])) ?>
                            </span>
                        </div>

                        <div class="day-calendar">
                            <ol class="day-calendar__head" role="list" aria-hidden="true">
                                <?php foreach (['M', 'D', 'W', 'D', 'V', 'Z', 'Z'] as $initial): ?>
                                    <li><?= e($initial) ?></li>
                                <?php endforeach; ?>
                            </ol>

                            <ol class="day-blocks" role="list"
                                aria-label="<?= e(sprintf('%d van %d dagen gehaald', $goal['days_met'], $goal['days_total'])) ?>">
                                <?php foreach ($goal['days'] as $day): ?>
                                    <?php if ($day['state'] === 'before'): ?>
                                        <li class="day-block is-before" aria-hidden="true"></li>
                                    <?php else: ?>
                                        <?php $when = new DateTimeImmutable($day['date']); ?>
                                        <li class="day-block is-<?= e($day['state']) ?>"
                                            title="<?= e(goals_date_short($when) . ' · ' . match ($day['state']) {
                                                'met'     => 'gehaald',
                                                'missed'  => 'niet gehaald',
                                                'unknown' => 'geen gegevens',
                                                'pending' => 'vandaag, nog open',
                                                default   => 'nog niet geweest',
                                            }) ?>"><span><?= e($when->format('j')) ?></span></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ol>
                        </div>

                        <p class="card__hint card__hint--plain">
                            <?= e($goal['days_note'] ?? $copy['days_note']) ?>
                            <?php /* Only when the calendar is showing less than the whole goal. */ ?>
                            <?php if ($goal['days_total'] > count($goal['days'])): ?>
                                <?= e($copy['days_window']) ?>
                            <?php endif; ?>
                        </p>
                    </section>
                <?php endif; ?>

                <!-- ------------------------------------------------- 5 -->
                <?php if (!$goal['is_completed']): ?>
                    <section class="card card--manage reveal" aria-labelledby="goal-manage-<?= e($id) ?>">
                        <div class="card__head card__head--compact">
                            <span class="icon-tile" aria-hidden="true"><?= icon('sliders') ?></span>
                            <h2 class="card__eyebrow" id="goal-manage-<?= e($id) ?>"><?= e($copy['manage']) ?></h2>
                        </div>

                        <div class="goal-manage">

                            <?php /* Primair | Secundair: one selector, the one this goal is
                                     pressed. Secundair hands the primary slot on to the goal a
                                     delete would (goal_set_secondary()); a goal on its own has
                                     no one to hand it to, so it stays Primair. */
                            $alone = count($goals['active'] ?? []) < 2;
                            ?>
                            <div class="range-switch range-switch--wide goal-priority" role="group"
                                 aria-label="<?= e($copy['priority']) ?>" data-goal-priority-switch>
                                <button type="button" class="range-switch__option<?= $goal['is_primary'] ? ' is-active' : '' ?>"
                                        data-goal-action="set-primary"
                                        aria-pressed="<?= $goal['is_primary'] ? 'true' : 'false' ?>"><?= e($copy['priority_primary']) ?></button>
                                <button type="button" class="range-switch__option<?= $goal['is_primary'] ? '' : ' is-active' ?>"
                                        data-goal-action="set-secondary"
                                        aria-pressed="<?= $goal['is_primary'] ? 'false' : 'true' ?>"
                                        <?= $alone && $goal['is_primary'] ? 'disabled aria-disabled="true"' : '' ?>><?= e($copy['priority_secondary']) ?></button>
                            </div>
                            <?php if ($alone && $goal['is_primary']): ?>
                                <p class="goal-manage__hint goal-priority__note"><?= e($copy['priority_alone']) ?></p>
                            <?php endif; ?>
                            <p class="field-editor__error" role="alert" data-goal-priority-error hidden></p>

                            <button type="button" class="btn goal-manage__button press" data-goal-action="pause">
                                <span class="goal-manage__swap" data-goal-pause-icon="pause"
                                      <?= $goal['is_paused'] ? 'hidden' : '' ?>><?= icon('pause', 'goal-manage__icon') ?></span>
                                <span class="goal-manage__swap" data-goal-pause-icon="play"
                                      <?= $goal['is_paused'] ? '' : 'hidden' ?>><?= icon('play', 'goal-manage__icon') ?></span>
                                <span data-goal-pause-label><?= e($goal['is_paused'] ? $copy['resume'] : $copy['pause']) ?></span>
                            </button>

                            <button type="button" class="btn goal-manage__button goal-manage__button--danger press"
                                    data-goal-action="delete">
                                <?= icon('trash', 'goal-manage__icon') ?>
                                <?= e($copy['delete']) ?>
                            </button>

                            <div class="goal-confirm" data-goal-confirm hidden>
                                <p class="goal-confirm__text"><?= e($copy['delete_confirm']) ?></p>
                                <div class="goal-confirm__row">
                                    <button type="button" class="btn press" data-goal-action="delete-cancel"><?= e($copy['delete_no']) ?></button>
                                    <button type="button" class="btn goal-confirm__yes press" data-goal-action="delete-confirm"><?= e($copy['delete_yes']) ?></button>
                                </div>
                            </div>

                        </div>

                    </section>
                <?php endif; ?>

            </div>
        </main>
    </div>

</article>
