<?php
/**
 * The five-step create-a-goal flow.
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
 * Nothing is saved: there is no goal storage yet. The flow is honest about
 * that on the final screen rather than pretending to have created something.
 */
declare(strict_types=1);

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

        <!-- Progress: five segments and one line of text, nothing more. -->
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

            <!-- ---------------------------------------------- 3 · target -->
            <section class="wizard__step" data-wizard-step="3" aria-label="<?= e($steps[3]['label']) ?>" hidden>
                <h3 class="wizard__title"><?= e($steps[3]['title']) ?></h3>
                <p class="wizard__lede" data-wizard-target-lede><?= e($steps[3]['lede']) ?></p>

                <!-- A number and a unit: weight, reps, distance. -->
                <div class="wizard__field" data-wizard-target="number" hidden>
                    <label class="wizard__label" for="wizard-target-value"><?= e($copy['target_number']) ?></label>
                    <div class="wizard__row">
                        <input class="wizard__input wizard__input--number" type="number" id="wizard-target-value"
                               data-wizard-value inputmode="decimal" step="any" min="0" placeholder="100">
                        <input class="wizard__input wizard__input--unit" type="text" id="wizard-target-unit"
                               data-wizard-unit maxlength="12" autocomplete="off" spellcheck="false"
                               placeholder="<?= e($copy['target_unit']) ?>"
                               aria-label="<?= e($copy['target_unit']) ?>">
                    </div>

                    <ul class="wizard__suggestions" role="list" data-wizard-units></ul>
                </div>

                <!-- How many days you want to manage it. -->
                <div class="wizard__field" data-wizard-target="frequency" hidden>
                    <label class="wizard__label" for="wizard-target-days"><?= e($copy['target_freq']) ?></label>
                    <div class="wizard__row">
                        <input class="wizard__input wizard__input--number" type="number" id="wizard-target-days"
                               data-wizard-days inputmode="numeric" step="1" min="1" max="365" placeholder="30">
                        <span class="wizard__suffix">dagen</span>
                    </div>
                </div>

                <!-- Days in a row, which is a different promise entirely. -->
                <div class="wizard__field" data-wizard-target="days" hidden>
                    <label class="wizard__label" for="wizard-target-streak"><?= e($copy['target_days']) ?></label>
                    <div class="wizard__row">
                        <input class="wizard__input wizard__input--number" type="number" id="wizard-target-streak"
                               data-wizard-streak inputmode="numeric" step="1" min="1" max="365" placeholder="30">
                        <span class="wizard__suffix">dagen op rij</span>
                    </div>
                </div>

                <!-- Nothing to count: it is done or it is not. -->
                <div class="wizard__field" data-wizard-target="none" hidden>
                    <p class="wizard__note"><?= icon('check', 'wizard__note-icon') ?><?= e($copy['target_none']) ?></p>
                </div>
            </section>

            <!-- -------------------------------------------- 4 · duration -->
            <section class="wizard__step" data-wizard-step="4" aria-label="<?= e($steps[4]['label']) ?>" hidden>
                <h3 class="wizard__title"><?= e($steps[4]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[4]['lede']) ?></p>

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
            <section class="wizard__step" data-wizard-step="5" aria-label="<?= e($steps[5]['label']) ?>" hidden>
                <h3 class="wizard__title"><?= e($steps[5]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[5]['lede']) ?></p>

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
