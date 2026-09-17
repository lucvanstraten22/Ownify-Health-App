<?php
/**
 * Doelen — one primary goal, up to two secondary ones, and their progress.
 *
 * Kept deliberately short: the page answers four questions (what am I working
 * toward, which one matters most, how far am I, when does it end) and then
 * stops. Everything deeper lives behind the card, in the detail layer.
 *
 * Both views are rendered server-side and switched with a class, exactly like
 * the community boards, so Actief/Behaald costs nothing and works before the
 * JavaScript has run. The empty state ships alongside the list rather than
 * instead of it, so deleting the last goal has something to fall back to.
 */
declare(strict_types=1);

$goals   = $data['goals'];
$labels  = $goals['labels'];
$view    = $goals['default_view'];
$hasAny  = $goals['active'] !== [];

/* Everything the two scripts need to build a sentence, in one place, so no
   Dutch copy is ever written a second time in JavaScript. */
$copy = [
    'labels'    => $labels,
    'detail'    => $goals['detail'],
    'limits'    => $goals['limits'],
    'views'     => array_map(static fn (array $v): string => $v['label'], $goals['views']),
    'categories'=> array_map(
        static fn (array $c): array => ['label' => $c['label'], 'accent' => $c['accent'], 'units' => $c['units']],
        $goals['categories']
    ),
    'types'     => array_map(
        static fn (array $t): array => ['label' => $t['label'], 'target' => $t['target']],
        $goals['types']
    ),
    'durations' => array_map(
        static fn (array $d): array => ['label' => $d['label'], 'days' => $d['days']],
        $goals['durations']
    ),
    'wizard'    => $goals['wizard'],
];
?>
<section class="screen page" data-page="goals" aria-label="Doelen"
         <?= empty($data['page_active']) ? 'aria-hidden="true" inert' : '' ?>>

    <div class="screen__scroll" data-scroller>
        <?php component('header', $data); ?>

        <main class="app__main">
            <div class="shell stack" data-goals>

                <script type="application/json" data-goals-copy><?=
                    json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                ?></script>

                <header class="goals-intro reveal">
                    <div class="goals-intro__row">
                        <div class="goals-intro__text">
                            <h1 class="page-intro__title"><?= e($goals['title']) ?></h1>
                            <p class="page-intro__lede"><?= e($goals['lede']) ?></p>
                        </div>

                        <button type="button" class="goals-add press" data-goal-add
                                aria-label="<?= e($labels['add_aria']) ?>"
                                <?= $goals['can_add'] ? '' : 'disabled aria-disabled="true"' ?>>
                            <?= icon('plus', 'goals-add__icon') ?>
                        </button>
                    </div>

                    <?php
                    component('segmented', $data + [
                        'segment_options'  => $goals['views'],
                        'segment_selected' => $view,
                        'segment_attr'     => 'data-goal-view',
                        'segment_label'    => 'Actieve of behaalde doelen',
                    ]);
                    ?>

                </header>

                <!-- ------------------------------------------------ actief -->
                <div class="goals-view is-active" data-goal-panel="active">

                    <div class="card goals-empty is-empty reveal" data-goals-empty <?= $hasAny ? 'hidden' : '' ?>>
                        <span class="icon-tile" aria-hidden="true"><?= icon('flag') ?></span>
                        <h2 class="goals-empty__title"><?= e($goals['empty']['active']['title']) ?></h2>
                        <p class="goals-empty__body"><?= e($goals['empty']['active']['body']) ?></p>
                        <button type="button" class="btn press goals-empty__cta" data-goal-add>
                            <?= icon('plus', 'goals-empty__cta-icon') ?>
                            <?= e($goals['empty']['active']['cta']) ?>
                        </button>
                    </div>

                    <p class="goals-eyebrow" data-goal-eyebrow="primary"
                       <?= $hasAny ? '' : 'hidden' ?>><?= e($labels['primary']) ?></p>

                    <div class="goals-slot reveal" data-goal-slot="primary">
                        <?php if ($goals['primary'] !== null): ?>
                            <?php component('goal-card', ['goal' => $goals['primary'], 'goal_variant' => 'primary'] + $data); ?>
                        <?php endif; ?>
                    </div>

                    <p class="goals-eyebrow" data-goal-eyebrow="secondary"
                       <?= $goals['secondary'] === [] ? 'hidden' : '' ?>><?= e($labels['secondary']) ?></p>

                    <div class="goals-list reveal" data-goal-slot="secondary">
                        <?php foreach ($goals['secondary'] as $goal): ?>
                            <?php component('goal-card', ['goal' => $goal, 'goal_variant' => 'secondary'] + $data); ?>
                        <?php endforeach; ?>
                    </div>

                    <p class="goals-slots" data-goal-slots
                       <?= $hasAny ? '' : 'hidden' ?>><?= e(goals_slot_note($goals)) ?></p>

                </div>

                <!-- ----------------------------------------------- behaald -->
                <div class="goals-view" data-goal-panel="completed" aria-hidden="true" inert>

                    <?php if ($goals['completed'] === []): ?>

                        <div class="card goals-empty is-empty reveal">
                            <span class="icon-tile" aria-hidden="true"><?= icon('award') ?></span>
                            <h2 class="goals-empty__title"><?= e($goals['empty']['completed']['title']) ?></h2>
                            <p class="goals-empty__body"><?= e($goals['empty']['completed']['body']) ?></p>
                        </div>

                    <?php else: ?>

                        <div class="goals-list reveal" data-goal-slot="completed">
                            <?php foreach ($goals['completed'] as $goal): ?>
                                <?php component('goal-card', ['goal' => $goal, 'goal_variant' => 'completed'] + $data); ?>
                            <?php endforeach; ?>
                        </div>

                        <p class="goals-slots">Behaalde doelen tellen niet mee voor je drie actieve plekken.</p>

                    <?php endif; ?>

                </div>

                <?php if ($data['disclaimer'] !== ''): ?>
                <p class="disclaimer"><?= e($data['disclaimer']) ?></p>
                <?php endif; ?>

            </div>
        </main>
    </div>

</section>
