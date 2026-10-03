<?php
/**
 * The setup — what a new account sees before the app: "Hoe moet Ownify voor
 * jou werken?" Not a tour and not a form: four short steps, every one but
 * the first skippable, and nothing asked that Ownify does not use.
 *
 *   1  Focus      what the person most wants to understand
 *   2  Gegevens   the health data they already have (Health Connect)
 *   3  Over jou   a birth date, a height, a weight — each with its reason
 *   4  Doel       an optional first goal, through the normal wizard, and a
 *                 suggestion when their own data can carry one
 *
 * index.php shows it while the account's setup is pending — the database
 * says so, not the browser — and the app after api/setup/finish.php. Every
 * answer is saved by the endpoint that always saves it (profile/update,
 * profile/onboarding, goals/create), so a reload or another device picks up
 * where it was. The words are config/setup.php's, through `setup`
 * (lib/hydrate-setup.php) — the same the Ownify app's SetupScreen shows.
 */
declare(strict_types=1);

$app   = $data['app'];
$setup = $data['setup'];
$steps = $setup['steps'];
$total = count($steps);
$byId  = array_column($steps, null, 'id');

$focus   = $byId['focus'];
$connect = $byId['connect'];
$profile = $byId['profile'];
$goal    = $byId['goal'];
$source  = $connect['source'];
?>
<!DOCTYPE html>
<html lang="<?= e($app['locale']) ?>" data-theme="<?= e(app_theme()) ?>" data-focus="<?= e($data['focus']) ?>">
<head>
    <?php component('document-head', $data + ['styles' => [
        'theme', 'components', 'goals', 'setup',
    ]]); ?>
</head>
<body class="app app--setup">

    <!-- The same ground as the app: the setup is the first room of it. -->
    <div class="app__backdrop" aria-hidden="true"></div>

    <main class="setup" data-setup data-csrf="<?= e($data['auth']['csrf'] ?? '') ?>"
          data-resume="<?= e($setup['resume']) ?>" data-count="<?= e($setup['count']) ?>"
          data-total="<?= e((string) $total) ?>" aria-labelledby="setup-heading">

        <h1 class="sr-only" id="setup-heading"><?= e($setup['title']) ?></h1>

        <header class="setup__top">
            <span class="setup__brand"><?= e($app['name']) ?></span>
            <button type="button" class="setup__link setup__logout press" data-setup-logout><?= e($setup['logout']) ?></button>
        </header>

        <!-- One segment per step and one line, as the goal wizard counts. -->
        <div class="setup__progress">
            <ol class="wizard__bars" role="list" aria-hidden="true">
                <?php foreach ($steps as $n => $step): ?>
                    <li class="wizard__bar<?= $n === 0 ? ' is-done' : '' ?>" data-setup-bar="<?= e($step['id']) ?>"></li>
                <?php endforeach; ?>
            </ol>
            <p class="wizard__count" data-setup-count aria-live="polite">
                <?= e(sprintf($setup['count'], 1, $total)) ?> · <?= e($steps[0]['label']) ?>
            </p>
        </div>

        <div class="setup__body" data-setup-body>

            <!-- ------------------------------------------------ 1 · focus -->
            <section class="setup__step is-active" data-setup-step="focus" data-label="<?= e($focus['label']) ?>"
                     aria-labelledby="setup-focus-title">
                <h2 class="setup__title" id="setup-focus-title"><?= e($focus['title']) ?></h2>
                <p class="setup__lede"><?= e($focus['lede']) ?></p>

                <div class="setup__choices" role="radiogroup" aria-labelledby="setup-focus-title">
                    <?php foreach ($focus['options'] as $option): ?>
                        <button type="button" class="setup-choice press" role="radio"
                                data-setup-focus="<?= e($option['key']) ?>" data-accent="<?= e($option['accent']) ?>"
                                aria-checked="<?= $option['chosen'] ? 'true' : 'false' ?>">
                            <span class="icon-tile" aria-hidden="true"><?= icon($option['icon']) ?></span>
                            <span class="setup-choice__text">
                                <span class="setup-choice__label"><?= e($option['label']) ?></span>
                                <span class="setup-choice__line"><?= e($option['line']) ?></span>
                            </span>
                            <span class="setup-choice__mark" aria-hidden="true"><?= icon('check') ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <p class="setup__error" role="alert" data-setup-error="focus" data-message="<?= e($focus['error']) ?>" hidden></p>
            </section>

            <!-- ---------------------------------------------- 2 · gegevens -->
            <section class="setup__step" data-setup-step="connect" data-label="<?= e($connect['label']) ?>"
                     aria-labelledby="setup-connect-title" hidden>
                <h2 class="setup__title" id="setup-connect-title"><?= e($connect['title']) ?></h2>
                <p class="setup__lede"><?= e($connect['lede']) ?></p>

                <div class="setup-source<?= $source['connected'] ? ' is-connected' : '' ?>">
                    <div class="setup-source__head">
                        <span class="icon-tile" aria-hidden="true"><?= icon($source['icon']) ?></span>
                        <span class="setup-source__text">
                            <span class="setup-source__label"><?= e($source['label']) ?></span>
                            <span class="setup-source__note"><?= e($source['note']) ?></span>
                        </span>
                        <span class="setup-source__status">
                            <span class="setup-source__dot" aria-hidden="true"></span><?= e($source['status']) ?>
                        </span>
                    </div>
                    <p class="setup-source__body"><?= e($source['web']) ?></p>
                    <?php if ($source['last_sync'] !== null): ?>
                        <p class="setup-source__sync"><?= e($source['last_sync']) ?></p>
                    <?php endif; ?>
                </div>

                <p class="setup__quiet"><?= icon('utensils', 'setup__quiet-icon') ?><span><?= e($connect['manual']) ?></span></p>
                <p class="setup__quiet"><?= icon('sliders', 'setup__quiet-icon') ?><span><?= e($connect['later']) ?></span></p>
            </section>

            <!-- ---------------------------------------------- 3 · over jou -->
            <section class="setup__step" data-setup-step="profile" data-label="<?= e($profile['label']) ?>"
                     aria-labelledby="setup-profile-title" hidden>
                <h2 class="setup__title" id="setup-profile-title"><?= e($profile['title']) ?></h2>
                <p class="setup__lede"><?= e($profile['lede']) ?></p>

                <div class="setup__fields">
                    <?php foreach ($profile['fields'] as $field):
                        $input = $field['input'];
                        $id    = 'setup-field-' . $field['key'];
                        ?>
                        <div class="setup-field<?= $field['locked'] ? ' is-locked' : '' ?>">
                            <label class="wizard__label" for="<?= e($id) ?>"><?= e($field['label']) ?></label>

                            <?php if ($field['locked']): ?>
                                <p class="setup-field__value" id="<?= e($id) ?>">
                                    <?= icon('lock', 'setup-field__lock') ?><?= e((string) $field['value']) ?>
                                </p>
                            <?php else: ?>
                                <div class="wizard__row">
                                    <input class="wizard__input<?= $input['type'] === 'number' ? ' wizard__input--number' : '' ?>"
                                           id="<?= e($id) ?>" name="<?= e($field['key']) ?>"
                                           type="<?= e($input['type']) ?>"
                                           data-setup-field="<?= e($field['key']) ?>"
                                           data-endpoint="<?= e($input['endpoint']) ?>"
                                           value="<?= e((string) $input['value']) ?>"
                                           data-initial="<?= e((string) $input['value']) ?>"
                                           <?= isset($input['min']) ? 'min="' . e((string) $input['min']) . '"' : '' ?>
                                           <?= isset($input['max']) ? 'max="' . e((string) $input['max']) . '"' : '' ?>
                                           <?= isset($input['step']) ? 'step="' . e((string) $input['step']) . '"' : '' ?>
                                           <?= $input['type'] === 'number' ? 'inputmode="decimal"' : '' ?>
                                           aria-describedby="<?= e($id) ?>-reason">
                                    <?php if (($input['unit'] ?? '') !== ''): ?>
                                        <span class="wizard__suffix"><?= e($input['unit']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <p class="setup-field__reason" id="<?= e($id) ?>-reason"><?= e($field['reason']) ?></p>
                            <?php if ($field['note'] !== null && !$field['locked']): ?>
                                <p class="setup-field__note"><?= icon('lock', 'setup-field__lock') ?><?= e($field['note']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p class="setup__error" role="alert" data-setup-error="profile" data-message="<?= e($profile['error']) ?>" hidden></p>
            </section>

            <!-- -------------------------------------------------- 4 · doel -->
            <section class="setup__step" data-setup-step="goal" data-label="<?= e($goal['label']) ?>"
                     aria-labelledby="setup-goal-title" hidden>
                <h2 class="setup__title" id="setup-goal-title"><?= e($goal['title']) ?></h2>
                <p class="setup__lede"><?= e($goal['lede']) ?></p>

                <?php $suggestion = $goal['suggestion']; ?>
                <?php if ($suggestion !== null): ?>
                    <div class="setup-suggestion" data-setup-suggestion
                         data-input="<?= e(json_encode($suggestion['input'], JSON_UNESCAPED_UNICODE)) ?>"
                         data-name="<?= e($suggestion['name']) ?>">
                        <p class="card__caption"><?= e($suggestion['eyebrow']) ?></p>
                        <p class="setup-suggestion__name"><?= e($suggestion['name']) ?></p>
                        <p class="setup-suggestion__basis"><?= e($suggestion['basis']) ?></p>
                        <p class="setup-suggestion__summary"><?= e($suggestion['summary']) ?></p>
                        <div class="setup-suggestion__actions">
                            <button type="button" class="btn press setup__primary" data-setup-suggestion-add><?= e($suggestion['add']) ?></button>
                            <button type="button" class="setup__link press" data-setup-suggestion-decline><?= e($suggestion['decline']) ?></button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($goal['goals'] !== []): ?>
                    <p class="setup__done"><?= icon('check', 'setup__quiet-icon') ?><span><?= e(sprintf($goal['added'], $goal['goals'][0])) ?></span></p>
                <?php endif; ?>

                <p class="setup__done" data-setup-added data-template="<?= e($goal['added']) ?>" hidden>
                    <?= icon('check', 'setup__quiet-icon') ?><span data-setup-added-text></span>
                </p>

                <?php if ($goal['can_add'] && $goal['goals'] === []): ?>
                    <button type="button" class="btn press setup__own" data-setup-own aria-haspopup="dialog">
                        <?= icon('plus', 'setup__own-icon') ?><?= e($goal['own']) ?>
                    </button>
                <?php endif; ?>

                <p class="setup__error" role="alert" data-setup-error="goal" data-message="<?= e($goal['error']) ?>" hidden></p>
            </section>

        </div>

        <footer class="setup__foot">
            <button type="button" class="btn press setup__back" data-setup-back hidden><?= e($setup['back']) ?></button>

            <!-- Each step's own way on: one quiet, one primary. -->
            <button type="button" class="btn press setup__skip" data-setup-skip="profile" hidden><?= e($profile['skip']) ?></button>

            <button type="button" class="btn press setup__primary" data-setup-next="focus"
                    <?= array_filter($focus['options'], static fn ($o) => $o['chosen']) === [] ? 'disabled' : '' ?>><?= e($setup['next']) ?></button>
            <button type="button" class="btn press setup__primary" data-setup-next="connect" hidden>
                <?= e($connect['connected'] ? $setup['next'] : $connect['skip']) ?>
            </button>
            <button type="button" class="btn press setup__primary" data-setup-next="profile" hidden><?= e($profile['save']) ?></button>
            <button type="button" class="btn press setup__primary" data-setup-next="goal" hidden><?= e($goal['finish']) ?></button>
        </footer>

    </main>

    <?php
    /* The goal wizard, exactly as Doelen opens it: its words, its panel, its
       script. Only the wizard — the Doelen board itself is not here. */
    ?>
    <script type="application/json" data-goals-copy><?=
        json_encode(goals_script_copy($data['goals']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    ?></script>
    <?php component('goal-wizard', $data); ?>

    <script src="<?= e(asset('assets/js/goal-wizard.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/setup.js')) ?>" defer></script>
</body>
</html>
