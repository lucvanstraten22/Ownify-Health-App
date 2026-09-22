<?php
/**
 * One goal in full — the layer behind a goal card.
 *
 * Same layer mechanism as a health detail page: above the rail, below the
 * dock, so the tab bar and the assistant stay reachable from inside it.
 *
 * Information order follows how much it matters:
 *   1  how far you are          — the hero bar and the percentage
 *   2  the three numbers        — now, target, time left
 *   3  how it has moved         — the progress line
 *   4  what feeds it            — the health data behind the number
 *   5  what you can do about it — priority, pause, delete
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
                        <div class="metric-row <?= state_class($goal['current_label']) ?>">
                            <span class="metric-row__label"><?= e($labels['current']) ?></span>
                            <span class="metric-row__value"><?= e((string) ($goal['current_label'] ?? '—')) ?></span>
                        </div>
                        <div class="metric-row <?= state_class($goal['start_label']) ?>">
                            <span class="metric-row__label">Gestart</span>
                            <span class="metric-row__value"><?= e((string) ($goal['start_label'] ?? '—')) ?></span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-row__label"><?= $goal['is_completed'] ? 'Looptijd' : 'Eindigt' ?></span>
                            <span class="metric-row__value"><?= e((string) ($goal['is_completed']
                                ? ($goal['took_label'] ?? '—')
                                : ($goal['end_label'] ?? $labels['no_deadline']))) ?></span>
                        </div>
                    </div>

                    <p class="card__hint card__hint--plain" data-goal-note>
                        <?= e($goal['is_paused'] ? $copy['paused_body'] : (string) ($goal['note'] ?? '')) ?>
                    </p>
                </section>

                <!-- ---------------------------------- per-day results -->
                <?php if ($goal['days'] !== []): ?>
                    <?php
                    /* One block per day, for a goal that has to be met again
                       every day rather than reached once.

                       Three states, not two. A day with no data is not a day
                       that failed — a phone that was not syncing yet says
                       nothing about whether somebody walked — so it is drawn
                       as an empty outline and counted as neither. Turning it
                       red would be inventing a failure. */
                    ?>
                    <section class="card reveal" aria-labelledby="goal-days-<?= e($id) ?>">
                        <div class="card__head">
                            <div class="card__head-group">
                                <span class="icon-tile" aria-hidden="true"><?= icon('check') ?></span>
                                <h2 class="card__eyebrow" id="goal-days-<?= e($id) ?>"><?= e($copy['days'] ?? 'Per dag') ?></h2>
                            </div>
                            <span class="chip chip--muted">
                                <?= e($goal['days_met'] . ' van ' . $goal['days_total']) ?>
                            </span>
                        </div>

                        <ol class="day-blocks" role="list"
                            aria-label="<?= e(sprintf('%d van %d dagen gehaald', $goal['days_met'], $goal['days_total'])) ?>">
                            <?php foreach ($goal['days'] as $day): ?>
                                <li class="day-block is-<?= e($day['state']) ?>"
                                    title="<?= e(goals_date_short(new DateTimeImmutable($day['date']))
                                        . ' · ' . match ($day['state']) {
                                            'met'     => 'gehaald',
                                            'missed'  => 'niet gehaald',
                                            'unknown' => 'geen gegevens',
                                            default   => 'nog niet geweest',
                                        }) ?>"></li>
                            <?php endforeach; ?>
                        </ol>

                        <p class="card__hint card__hint--plain">
                            <?= e($copy['days_note'] ?? 'Dagen zonder gegevens tellen niet mee als gemist.') ?>
                        </p>
                    </section>
                <?php endif; ?>

                <!-- ----------------------- manual entry, when it is theirs -->
                <?php if ($goal['needs_input'] && !$goal['is_completed']): ?>
                    <?php
                    /* Only ever shown for a goal the person keeps themselves.
                       An automatic goal reads itself, and an entry box beside
                       a self-updating figure is two numbers claiming to be the
                       same thing. */
                    $ticks = in_array($goal['type'], ['habit', 'streak'], true);
                    ?>
                    <section class="card card--check reveal" data-goal-manual="<?= e($id) ?>"
                             aria-labelledby="goal-check-<?= e($id) ?>">
                        <div class="card__head card__head--compact">
                            <span class="icon-tile" aria-hidden="true"><?= icon('check') ?></span>
                            <h2 class="card__eyebrow" id="goal-check-<?= e($id) ?>"><?= e($copy['manual']) ?></h2>
                        </div>

                        <?php if ($ticks): ?>
                            <button type="button" class="btn goal-check__button press" data-goal-tick="<?= e($id) ?>">
                                <?= icon('check', 'goal-check__icon') ?>
                                Vandaag gelukt
                            </button>
                        <?php else: ?>
                            <div class="goal-entry">
                                <label class="sr-only" for="goal-value-<?= e($id) ?>">
                                    <?= e($copy['manual_value'] ?? 'Huidige waarde') ?>
                                </label>
                                <input class="wizard__input goal-entry__input" type="number" step="any"
                                       inputmode="decimal" id="goal-value-<?= e($id) ?>"
                                       data-goal-value="<?= e($id) ?>"
                                       placeholder="<?= e((string) ($goal['current_label'] ?? $copy['manual_value'] ?? 'Huidige waarde')) ?>">
                                <button type="button" class="btn press" data-goal-save="<?= e($id) ?>">
                                    <?= e($copy['manual_save'] ?? 'Opslaan') ?>
                                </button>
                            </div>
                        <?php endif; ?>

                        <p class="field-editor__error" role="alert" data-goal-error hidden></p>
                        <p class="card__hint card__hint--plain"><?= e($copy['manual_note']) ?></p>
                    </section>
                <?php endif; ?>

                <!-- ------------------------------------------------- 3 -->
                <section class="card card--trend reveal <?= $goal['has_history'] ? 'is-filled' : 'is-empty' ?>"
                         aria-labelledby="goal-history-<?= e($id) ?>">

                    <div class="card__head">
                        <div class="card__head-group">
                            <span class="icon-tile" aria-hidden="true"><?= icon('chart') ?></span>
                            <h2 class="card__eyebrow" id="goal-history-<?= e($id) ?>"><?= e($copy['history']) ?></h2>
                        </div>
                        <span class="chip chip--muted"><?= $goal['history_step'] === 'week' ? 'Per week' : 'Per dag' ?></span>
                    </div>

                    <div class="chart" data-chart>
                        <div class="chart__range is-active" data-range="goal">
                            <svg class="chart__svg" viewBox="0 0 <?= (int) $width ?> <?= (int) $height ?>"
                                 preserveAspectRatio="none" role="img"
                                 aria-label="<?= e($copy['history']) ?><?= $goal['has_history'] ? '' : ': nog geen verloop' ?>">

                                <?php foreach ([0.25, 0.5, 0.75] as $line): ?>
                                    <line class="chart__grid" x1="0" x2="<?= (int) $width ?>"
                                          y1="<?= round(12 + $line * ($height - 24), 1) ?>"
                                          y2="<?= round(12 + $line * ($height - 24), 1) ?>"/>
                                <?php endforeach; ?>

                                <g class="chart__series">
                                    <?php foreach ($chart['area'] as $path): ?>
                                        <path class="chart__area" d="<?= e($path) ?>"/>
                                    <?php endforeach; ?>
                                    <?php foreach ($chart['line'] as $path): ?>
                                        <path class="chart__line" d="<?= e($path) ?>" data-draw/>
                                    <?php endforeach; ?>
                                    <?php foreach ($chart['dots'] as $dot): ?>
                                        <circle class="chart__dot" cx="<?= e((string) $dot[0]) ?>" cy="<?= e((string) $dot[1]) ?>" r="2.5"/>
                                    <?php endforeach; ?>
                                </g>
                            </svg>

                            <ul class="chart__axis" role="list">
                                <li class="chart__tick" style="left: 0;"><?= e((string) ($goal['start_label'] ?? 'Start')) ?></li>
                                <li class="chart__tick" style="left: 100%;"><?= $goal['is_completed'] ? 'Behaald' : 'Nu' ?></li>
                            </ul>
                        </div>

                        <?php if (!$goal['has_history']): ?>
                            <p class="chart__empty"><?= e($copy['history_empty']) ?></p>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ------------------------------------------------- 4 -->
                <section class="card reveal" aria-labelledby="goal-sources-<?= e($id) ?>">
                    <div class="card__head card__head--compact">
                        <span class="icon-tile" aria-hidden="true"><?= icon('pulse') ?></span>
                        <h2 class="card__eyebrow" id="goal-sources-<?= e($id) ?>"><?= e($copy['sources']) ?></h2>
                    </div>

                    <?php if ($goal['source_list'] === []): ?>
                        <p class="card__lede card__lede--small">Dit doel is nog niet aan gezondheidsgegevens gekoppeld.</p>
                    <?php else: ?>
                        <div class="metric-rows">
                            <?php foreach ($goal['source_list'] as $source): ?>
                                <div class="metric-row" data-accent="<?= e($source['accent']) ?>">
                                    <span class="metric-row__badge goal-source__badge" aria-hidden="true"><?= icon($source['icon']) ?></span>
                                    <span class="metric-row__label goal-source__label">
                                        <strong><?= e($source['label']) ?></strong>
                                        <span class="goal-source__note"><?= e($source['note']) ?></span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <p class="card__hint"><?= icon('lock', 'card__hint-icon') ?>Voortgang werkt straks automatisch bij zodra deze bronnen gegevens leveren.</p>
                </section>

                <!-- -------------------------------------- recent activity -->
                <?php if ($goal['activity'] !== []): ?>
                    <section class="card reveal" aria-labelledby="goal-activity-<?= e($id) ?>">
                        <div class="card__head card__head--compact">
                            <span class="icon-tile" aria-hidden="true"><?= icon('ranking') ?></span>
                            <h2 class="card__eyebrow" id="goal-activity-<?= e($id) ?>"><?= e($copy['activity']) ?></h2>
                        </div>

                        <div class="metric-rows">
                            <?php foreach ($goal['activity'] as $entry): ?>
                                <div class="metric-row">
                                    <span class="metric-row__label goal-source__label">
                                        <strong><?= e($entry['label']) ?></strong>
                                        <span class="goal-source__note"><?= e($entry['meta']) ?></span>
                                    </span>
                                    <span class="metric-row__value"><?= e($entry['value']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
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

                            <p class="goal-manage__state" data-goal-action="is-primary"
                               <?= $goal['is_primary'] ? '' : 'hidden' ?>>
                                <?= icon('flag', 'goal-manage__state-icon') ?>
                                <span><?= e($copy['is_primary']) ?><span class="goal-manage__hint">Kies bij een ander doel “<?= e($copy['make_primary']) ?>” om te wisselen.</span></span>
                            </p>

                            <button type="button" class="btn goal-manage__button press" data-goal-action="promote"
                                    <?= $goal['is_primary'] ? 'hidden' : '' ?>>
                                <?= icon('flag', 'goal-manage__icon') ?>
                                <?= e($copy['make_primary']) ?>
                            </button>

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
