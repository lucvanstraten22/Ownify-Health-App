<?php
/**
 * The six-step create-a-goal flow.
 *
 * It lives outside the deck, next to the account panel, which keeps it clear
 * of the two swipe gestures entirely: the deck's pointer pipeline never sees
 * it, so nothing here can be mistaken for a page swipe or a pull on the
 * assistant.
 *
 * Every step is in the document from the start and switched with a class, so
 * moving between them is a transform rather than a re-render and the answers
 * you already gave are still there when you step back.
 *
 * The source comes before the target. What the target means depends on both
 * the type and where progress comes from — 8 is hours of sleep, 10.000 is
 * steps, 30 is days — so the target step is asked in the source's own unit
 * rather than in a unit typed before anybody said what was being measured.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/goal-progress.php';

$goals  = $data['goals'];
$copy   = $goals['wizard'];
$steps  = $copy['steps'];
$total  = count($steps);
?>
<div class="wizard" data-overlay data-goal-wizard hidden>

    <div class="wizard__scrim" data-wizard-close></div>

    <div class="wizard__panel card" role="dialog" aria-modal="true" aria-labelledby="wizard-title">

        <div class="wizard__head">
            <h2 class="card__eyebrow" id="wizard-title"><?= e($copy['title']) ?></h2>
            <button type="button" class="account__close press" data-wizard-close
                    aria-label="<?= e($copy['close']) ?>"><?= icon('chevron-down') ?></button>
        </div>

        <!-- Progress: one segment per step and one line of text, nothing more. -->
        <div class="wizard__progress">
            <ol class="wizard__bars" role="list" aria-hidden="true">
                <?php for ($n = 1; $n <= $total; $n++): ?>
                    <li class="wizard__bar<?= $n === 1 ? ' is-done' : '' ?>" data-wizard-bar="<?= e((string) $n) ?>"></li>
                <?php endfor; ?>
            </ol>
            <p class="wizard__count" data-wizard-count aria-live="polite">
                Stap 1 van <?= e((string) $total) ?> · <?= e($steps[1]['label']) ?>
            </p>
        </div>

        <div class="wizard__body">

            <!-- ------------------------------------------- 1 · categorie -->
            <section class="wizard__step is-active" data-wizard-step="1" aria-label="<?= e($steps[1]['label']) ?>">
                <h3 class="wizard__title"><?= e($steps[1]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[1]['lede']) ?></p>

                <div class="wizard__grid">
                    <?php foreach ($goals['categories'] as $key => $category): ?>
                        <button type="button" class="wizard-tile press" data-wizard-category="<?= e((string) $key) ?>"
                                data-accent="<?= e($category['accent']) ?>" aria-pressed="false">
                            <span class="icon-tile" aria-hidden="true"><?= icon($category['icon']) ?></span>
                            <span class="wizard-tile__label"><?= e($category['label']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ------------------------------------------- 2 · definitie -->
            <section class="wizard__step" data-wizard-step="2" aria-label="<?= e($steps[2]['label']) ?>" hidden>
                <h3 class="wizard__title"><?= e($steps[2]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[2]['lede']) ?></p>

                <label class="wizard__label" for="wizard-name"><?= e($copy['name_label']) ?></label>
                <input class="wizard__input" type="text" id="wizard-name" data-wizard-name
                       maxlength="120" autocomplete="off" spellcheck="false"
                       placeholder="<?= e($copy['name_hint']) ?>">

                <ul class="wizard__suggestions" role="list" data-wizard-suggestions></ul>

                <p class="wizard__label wizard__label--spaced"><?= e($copy['type_label']) ?></p>
                <div class="wizard__types">
                    <?php foreach ($goals['types'] as $key => $type): ?>
                        <button type="button" class="wizard-type press" data-wizard-type="<?= e((string) $key) ?>"
                                aria-pressed="false">
                            <span class="wizard-type__label"><?= e($type['label']) ?></span>
                            <span class="wizard-type__hint"><?= e($type['hint']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ------------------------------------------ 3 · bijhouden -->
            <?php
            /* The one question the wizard used to never ask.
               The list comes from the metric catalogue, so nothing is offered
               that the app cannot actually read back. Each source says which
               types can use it, and the list shows only those once a type is
               chosen: a weight can be a Mijlpaal but not a Streak, and only
               what builds up over a day can be added up.

               "Geen data mogelijk" sits at the top rather than buried at the
               bottom: plenty of worthwhile goals have no health data behind
               them, and it is a real answer rather than a failure to find
               something. */
            $sourceGroups = goal_wizard_sources();
            ?>
            <section class="wizard__step" data-wizard-step="3" aria-label="<?= e($steps[3]['label']) ?>" hidden>
                <h3 class="wizard__title"><?= e($steps[3]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[3]['lede']) ?></p>

                <p class="wizard__label"><?= e($copy['source_label']) ?></p>

                <div class="wizard__types wizard__types--sources">
                    <button type="button" class="wizard-type press" data-wizard-source="manual"
                            data-source-kind="manual" data-source-key="" data-source-unit=""
                            data-source-types="milestone streak accumulate" aria-pressed="false">
                        <span class="wizard-type__label"><?= e($copy['source_manual']) ?></span>
                        <span class="wizard-type__hint"><?= e($copy['source_manual_hint']) ?></span>
                    </button>

                    <?php foreach ($sourceGroups as $group):
                        $domain = $group['domain'];
                        ?>
                        <p class="wizard__label wizard__label--spaced" data-source-group="<?= e($domain) ?>"><?= e($group['label']) ?></p>
                        <?php foreach ($group['sources'] as $source): ?>
                            <button type="button" class="wizard-type press"
                                    data-wizard-source="<?= e($source['kind'] . ':' . $source['key']) ?>"
                                    data-source-kind="<?= e($source['kind']) ?>"
                                    data-source-key="<?= e($source['key']) ?>"
                                    data-source-unit="<?= e($source['unit']) ?>"
                                    data-source-group="<?= e($domain) ?>"
                                    data-source-types="<?= e(implode(' ', $source['types'])) ?>"
                                    aria-pressed="false">
                                <span class="wizard-type__label"><?= e($source['label']) ?></span>
                                <span class="wizard-type__hint"><?= e($source['hint']) ?></span>
                            </button>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- --------------------------------------------- 4 · streven -->
            <?php
            /* One step, five small blocks, and the chosen type and source
               decide which of them show:

                 Mijlpaal   amount + which way is better
                 Streak     days in a row (+ what makes a day count, from data)
                 Optellen   a total, or a number of days (+ what makes a day
                            count, from data)

               Read from health data, the unit is the source's and cannot be
               typed over; kept by hand, it is whatever the person calls it. */
            ?>
            <section class="wizard__step" data-wizard-step="4" aria-label="<?= e($steps[4]['label']) ?>" hidden>
                <h3 class="wizard__title"><?= e($steps[4]['title']) ?></h3>
                <p class="wizard__lede" data-wizard-target-lede><?= e($steps[4]['lede']) ?></p>

                <!-- Optellen: an amount, or days. -->
                <div class="wizard__field" data-wizard-block="measure" hidden>
                    <p class="wizard__label"><?= e($copy['target_measure']) ?></p>
                    <div class="range-switch range-switch--wide" role="group" aria-label="<?= e($copy['target_measure']) ?>">
                        <button type="button" class="range-switch__option" data-wizard-measure="amount"
                                aria-pressed="false"><?= e($copy['measure_amount']) ?></button>
                        <button type="button" class="range-switch__option" data-wizard-measure="days"
                                aria-pressed="false"><?= e($copy['measure_days']) ?></button>
                    </div>
                </div>

                <!-- A result to reach, or a total to build. -->
                <div class="wizard__field" data-wizard-block="amount" hidden>
                    <label class="wizard__label" for="wizard-target-value" data-wizard-amount-label><?= e($copy['target_best']) ?></label>
                    <div class="wizard__row">
                        <input class="wizard__input wizard__input--number" type="number" id="wizard-target-value"
                               data-wizard-value inputmode="decimal" step="any" min="0" placeholder="100">
                        <input class="wizard__input wizard__input--unit" type="text" id="wizard-target-unit"
                               data-wizard-unit maxlength="12" autocomplete="off" spellcheck="false"
                               placeholder="<?= e($copy['target_unit']) ?>"
                               aria-label="<?= e($copy['target_unit']) ?>">
                        <span class="wizard__suffix" data-wizard-unit-fixed hidden></span>
                    </div>

                    <ul class="wizard__suggestions" role="list" data-wizard-units></ul>
                </div>

                <!-- Days: in a row for a Streak, in total for Optellen. -->
                <div class="wizard__field" data-wizard-block="days" hidden>
                    <label class="wizard__label" for="wizard-target-days" data-wizard-days-label><?= e($copy['target_streak']) ?></label>
                    <div class="wizard__row">
                        <input class="wizard__input wizard__input--number" type="number" id="wizard-target-days"
                               data-wizard-days inputmode="numeric" step="1" min="1" max="365" placeholder="30">
                        <span class="wizard__suffix" data-wizard-days-suffix>dagen op rij</span>
                    </div>
                </div>

                <!-- Mijlpaal: asked outright, because 80 kg is reached from
                     above and from below alike. -->
                <div class="wizard__field" data-wizard-block="direction" hidden>
                    <p class="wizard__label wizard__label--spaced"><?= e($copy['target_better']) ?></p>
                    <div class="range-switch range-switch--wide" role="group" aria-label="<?= e($copy['target_better']) ?>">
                        <button type="button" class="range-switch__option" data-wizard-better="increase"
                                aria-pressed="false"><?= e($copy['better_up']) ?></button>
                        <button type="button" class="range-switch__option" data-wizard-better="decrease"
                                aria-pressed="false"><?= e($copy['better_down']) ?></button>
                    </div>
                </div>

                <!-- A day read from health data: what it has to reach. -->
                <div class="wizard__field" data-wizard-block="daily" hidden>
                    <p class="wizard__label wizard__label--spaced"><?= e($copy['daily_label']) ?></p>
                    <div class="range-switch range-switch--wide" role="group" aria-label="<?= e($copy['daily_label']) ?>">
                        <button type="button" class="range-switch__option is-active" data-wizard-floor="increase"
                                aria-pressed="true"><?= e($copy['daily_floor']) ?></button>
                        <button type="button" class="range-switch__option" data-wizard-floor="decrease"
                                aria-pressed="false"><?= e($copy['daily_ceiling']) ?></button>
                    </div>
                    <div class="wizard__row wizard__row--spaced">
                        <input class="wizard__input wizard__input--number" type="number" inputmode="decimal"
                               min="0" step="any" id="wizard-daily" data-wizard-daily-input placeholder="10000"
                               aria-label="<?= e($copy['daily_label']) ?>">
                        <span class="wizard__suffix" data-wizard-daily-unit></span>
                    </div>
                    <p class="wizard__hint"><?= e($copy['daily_hint']) ?></p>
                </div>

                <!-- A day kept by hand: ticked off, nothing to set. -->
                <p class="wizard__note" data-wizard-block="tick" hidden>
                    <?= icon('check', 'wizard__note-icon') ?><span data-wizard-tick-note></span>
                </p>
            </section>

            <section class="wizard__step" data-wizard-step="5" aria-label="<?= e($steps[5]['label']) ?>" hidden>
                <h3 class="wizard__title"><?= e($steps[5]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[5]['lede']) ?></p>

                <div class="wizard__durations">
                    <?php foreach ($goals['durations'] as $key => $duration): ?>
                        <button type="button" class="wizard-duration press" data-wizard-duration="<?= e((string) $key) ?>"
                                data-days="<?= e((string) $duration['days']) ?>" aria-pressed="false">
                            <span class="wizard-duration__label"><?= e($duration['label']) ?></span>
                            <span class="wizard-duration__meta" data-duration-meta></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <p class="wizard__note" data-wizard-dates hidden></p>
            </section>

            <!-- ---------------------------------------- 5 · confirmation -->
            <section class="wizard__step" data-wizard-step="6" aria-label="<?= e($steps[6]['label']) ?>" hidden>
                <h3 class="wizard__title"><?= e($steps[6]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[6]['lede']) ?></p>

                <dl class="wizard__summary">
                    <div class="wizard__summary-row">
                        <dt><?= e($copy['summary_goal']) ?></dt>
                        <dd data-summary="name">—</dd>
                    </div>
                    <div class="wizard__summary-row">
                        <dt><?= e($copy['summary_category']) ?></dt>
                        <dd data-summary="category">—</dd>
                    </div>
                    <div class="wizard__summary-row">
                        <dt><?= e($copy['summary_target']) ?></dt>
                        <dd data-summary="target">—</dd>
                    </div>
                    <div class="wizard__summary-row">
                        <dt><?= e($copy['summary_source']) ?></dt>
                        <dd data-summary="source">—</dd>
                    </div>
                    <div class="wizard__summary-row">
                        <dt><?= e($copy['summary_duration']) ?></dt>
                        <dd data-summary="duration">—</dd>
                    </div>
                </dl>

                <p class="wizard__label wizard__label--spaced"><?= e($copy['priority_label']) ?></p>
                <div class="range-switch range-switch--wide" role="group" aria-label="<?= e($copy['priority_label']) ?>">
                    <button type="button" class="range-switch__option" data-wizard-priority="primary"
                            aria-pressed="false"><?= e($goals['labels']['primary_chip']) ?></button>
                    <button type="button" class="range-switch__option is-active" data-wizard-priority="secondary"
                            aria-pressed="true">Secundair</button>
                </div>

                <p class="wizard__note wizard__note--quiet" data-wizard-swap hidden>
                    <?= e($copy['priority_swap']) ?>
                </p>
            </section>

            <!-- ------------------------------------------------- closing -->
            <section class="wizard__step wizard__step--done" data-wizard-step="done" aria-label="<?= e($copy['done_title']) ?>" hidden>
                <span class="icon-tile wizard__done-mark" aria-hidden="true"><?= icon('flag') ?></span>
                <h3 class="wizard__title"><?= e($copy['done_title']) ?></h3>

                <div class="wizard__preview" data-wizard-preview></div>

                <p class="wizard__note"><?= icon('lock', 'wizard__note-icon') ?><?= e($copy['done_body']) ?></p>
            </section>

        </div>

        <div class="wizard__foot" data-wizard-foot>
            <button type="button" class="btn press wizard__back" data-wizard-back hidden><?= e($copy['back']) ?></button>
            <p class="wizard__error" data-wizard-error role="alert" hidden></p>
            <button type="button" class="btn press wizard__next" data-wizard-next disabled><?= e($copy['next']) ?></button>
            <button type="button" class="btn press wizard__next" data-wizard-done hidden><?= e($copy['done_close']) ?></button>
        </div>

    </div>
</div>
