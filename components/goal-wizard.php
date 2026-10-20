<?php
/**
 * Nieuw doel — three steps and a review (docs/GOALS.md):
 *
 *   1 Meting     search, six category bubbles, a short list to choose from
 *   2 Doel       its name, and Mijlpaal, Streak or Optellen
 *   3 Instellen  what it takes, in which unit, which way is better, and when
 *   Controleren  everything at a glance, then "Doel aanmaken"
 *
 * The indicator counts the three steps; the review has no number. Each screen
 * ends in the same pair: a small round back button and the one way forward.
 * Back keeps every answer; back on step one closes the wizard.
 *
 * The steps are built by assets/js/goal-wizard.js from the catalogue the
 * page embeds (goals_script_copy(): categories, measurements, units, types),
 * so the website, the app (GoalWizard.kt) and the server (goal-create.php)
 * offer the same things in the same words. The surfaces are the floating
 * glass of the sync capsule (`.glass`, components.css), at the size of the
 * Health Connect data chips.
 *
 * It lives outside the deck, next to the account panel, so the deck's
 * gestures never see it.
 */
declare(strict_types=1);

$goals = $data['goals'];
$copy  = $goals['wizard'];
$steps = $copy['steps'];
?>
<div class="wizard goal-wizard" data-overlay data-goal-wizard hidden>

    <div class="wizard__scrim" data-wizard-close></div>

    <div class="wizard__panel card" role="dialog" aria-modal="true" aria-labelledby="wizard-title">

        <div class="wizard__head">
            <h2 class="card__eyebrow" id="wizard-title"><?= e($copy['title']) ?></h2>
            <button type="button" class="account__close press" data-wizard-close
                    aria-label="<?= e($copy['close']) ?>"><?= icon('chevron-down') ?></button>
        </div>

        <!-- Steps 1 to 3; the review fills all three and says so. -->
        <div class="wizard__progress">
            <ol class="wizard__bars" role="list" aria-hidden="true">
                <?php foreach ($steps as $n => $step): ?>
                    <li class="wizard__bar<?= $n === 1 ? ' is-done' : '' ?>" data-wizard-bar="<?= e((string) $n) ?>"></li>
                <?php endforeach; ?>
            </ol>
            <p class="wizard__count" data-wizard-count aria-live="polite">
                <?= e(tf('Stap %1$d van %2$d', 1, count($steps))) ?> · <?= e($steps[1]['label']) ?>
            </p>
        </div>

        <div class="wizard__body" data-wizard-body>

            <!-- --------------------------------------------- 1 · meting -->
            <section class="wizard__step is-active" data-wizard-step="1" aria-labelledby="wizard-step-1">
                <h3 class="wizard__title" id="wizard-step-1"><?= e($steps[1]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[1]['lede']) ?></p>

                <div class="goal-search">
                    <?= icon('search', 'goal-search__icon') ?>
                    <input class="goal-search__input" type="search" data-wizard-search
                           enterkeyhint="search" autocomplete="off" spellcheck="false"
                           placeholder="<?= e($copy['search_label']) ?>" aria-label="<?= e($copy['search_label']) ?>">
                    <button type="button" class="goal-search__clear press" data-wizard-search-clear hidden
                            aria-label="<?= e($copy['search_clear']) ?>"><?= icon('close') ?></button>
                </div>

                <div class="goal-bubbles" role="group" aria-label="<?= e($copy['categories']) ?>" data-wizard-categories>
                    <?php foreach ($goals['categories'] as $key => $category): ?>
                        <button type="button" class="glass glass--chip goal-bubble press" data-wizard-category="<?= e((string) $key) ?>"
                                aria-pressed="false"><?= e($category['label']) ?></button>
                    <?php endforeach; ?>
                </div>

                <p class="wizard__label goal-list__label" data-wizard-list-label><?= e($copy['popular']) ?></p>
                <div class="glass goal-list" data-wizard-list role="radiogroup" aria-label="<?= e($copy['results']) ?>"></div>
                <p class="wizard__hint goal-list__empty" data-wizard-empty hidden><?= e($copy['no_results']) ?></p>
                <p class="wizard__hint goal-list__custom" data-wizard-custom-hint aria-live="polite" hidden><?= e($copy['custom_category']) ?></p>
            </section>

            <!-- ----------------------------------------------- 2 · doel -->
            <section class="wizard__step" data-wizard-step="2" aria-labelledby="wizard-step-2" hidden>
                <h3 class="wizard__title" id="wizard-step-2"><?= e($steps[2]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[2]['lede']) ?></p>

                <label class="wizard__label" for="wizard-name"><?= e($copy['name_label']) ?></label>
                <input class="wizard__input" type="text" id="wizard-name" data-wizard-name
                       maxlength="120" autocomplete="off" spellcheck="false" enterkeyhint="done">
                <div class="goal-ideas" role="group" aria-label="<?= e($copy['name_ideas']) ?>" data-wizard-ideas></div>

                <p class="wizard__label wizard__label--spaced" id="wizard-type-label"><?= e($copy['type_label']) ?></p>
                <div class="goal-types" role="radiogroup" aria-labelledby="wizard-type-label" data-wizard-types>
                    <?php foreach ($goals['types'] as $key => $type): ?>
                        <button type="button" class="glass goal-type press" role="radio" aria-checked="false"
                                data-wizard-type="<?= e((string) $key) ?>">
                            <span class="goal-type__label"><?= e($type['label']) ?></span>
                            <span class="goal-type__hint"><?= e($type['hint']) ?></span>
                            <span class="goal-type__example" data-wizard-type-example></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ------------------------------------------ 3 · instellen -->
            <section class="wizard__step" data-wizard-step="3" aria-labelledby="wizard-step-3" hidden>
                <h3 class="wizard__title" id="wizard-step-3"><?= e($steps[3]['title']) ?></h3>
                <p class="wizard__lede"><?= e($steps[3]['lede']) ?></p>
                <div class="goal-setup" data-wizard-setup></div>
            </section>

            <!-- ---------------------------------------- controleren -->
            <section class="wizard__step" data-wizard-step="review" aria-labelledby="wizard-step-review" hidden>
                <h3 class="wizard__title" id="wizard-step-review"><?= e($copy['review']['title']) ?></h3>
                <p class="wizard__lede"><?= e($copy['review']['lede']) ?></p>
                <dl class="glass goal-review" data-wizard-review></dl>
            </section>

            <!-- The calendar: over the step, start then end. -->
            <div class="goal-calendar" data-wizard-calendar hidden role="dialog" aria-modal="false" aria-labelledby="goal-calendar-title">
                <div class="goal-calendar__head">
                    <p class="goal-calendar__title" id="goal-calendar-title" data-calendar-title></p>
                    <div class="goal-calendar__nav">
                        <button type="button" class="glass glass--round glass--small press" data-calendar-prev
                                aria-label="<?= e($copy['calendar_prev']) ?>"><?= icon('chevron-left') ?></button>
                        <p class="goal-calendar__month" data-calendar-month aria-live="polite"></p>
                        <button type="button" class="glass glass--round glass--small press" data-calendar-next
                                aria-label="<?= e($copy['calendar_next']) ?>"><?= icon('chevron-right') ?></button>
                    </div>
                </div>
                <ol class="goal-calendar__weekdays" role="list" aria-hidden="true" data-calendar-weekdays></ol>
                <div class="goal-calendar__grid" role="grid" data-calendar-grid></div>
                <button type="button" class="glass glass--cta goal-calendar__done press" data-calendar-done><?= e($copy['calendar_done']) ?></button>
            </div>

        </div>

        <?php /* The icons the script draws with, from the one icon set. */ ?>
        <?php foreach ($goals['categories'] as $category): ?>
            <template data-wizard-icon="<?= e($category['icon']) ?>"><?= icon($category['icon']) ?></template>
        <?php endforeach; ?>
        <template data-wizard-icon="custom"><?= icon('sparkle') ?></template>
        <template data-wizard-icon="arrow-up"><?= icon('arrow-up') ?></template>
        <template data-wizard-icon="arrow-down"><?= icon('arrow-down') ?></template>
        <template data-wizard-icon="chevron-right"><?= icon('chevron-right') ?></template>
        <template data-wizard-icon="chevron-down"><?= icon('chevron-down') ?></template>

        <div class="wizard__foot goal-wizard__foot" data-wizard-foot>
            <p class="wizard__error" data-wizard-error role="alert" hidden></p>
            <div class="goal-wizard__nav">
                <button type="button" class="glass glass--round press" data-wizard-back
                        aria-label="<?= e($copy['back']) ?>"><?= icon('chevron-left') ?></button>
                <button type="button" class="glass glass--cta goal-wizard__next press" data-wizard-next disabled>
                    <span class="goal-wizard__spinner" aria-hidden="true"></span>
                    <span data-wizard-next-label><?= e($copy['next']) ?></span>
                </button>
            </div>
        </div>

    </div>
</div>
