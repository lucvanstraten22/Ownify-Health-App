<?php
/**
 * One goal, as a card.
 *
 * The same component renders the primary goal, a secondary goal and a
 * completed one — priority and status are modifiers, never a different card.
 * That is deliberate: a secondary goal that is one tap from becoming primary
 * should not have to change shape when it does.
 *
 * Reading order matches how the brief describes the question the page answers:
 * what am I working toward → how far am I → when does it end.
 */
declare(strict_types=1);

$goal    = $data['goal'];
$labels  = $data['goals']['labels'];
$variant = $data['goal_variant'] ?? 'secondary';

$classes = ['card', 'goal-card', 'press', 'goal-card--' . $variant, state_class($goal['percent'])];
if ($goal['is_paused'])    { $classes[] = 'is-paused'; }
if ($goal['is_completed']) { $classes[] = 'is-done'; }

$reading = has_value($goal['percent'])
    ? $goal['percent'] . ' procent van ' . ($goal['target_label'] ?? 'je doel')
    : 'nog geen voortgang';
?>
<button type="button"
        class="<?= e(implode(' ', $classes)) ?>"
        data-accent="<?= e($goal['accent']) ?>"
        data-detail-open="goal-<?= e($goal['id']) ?>"
        data-goal-card="<?= e($goal['id']) ?>"
        data-goal-priority="<?= e($goal['priority']) ?>"
        data-goal-status="<?= e($goal['status']) ?>"
        data-goal-name="<?= e($goal['name']) ?>"
        data-line-active="<?= e($goal['line_active']) ?>"
        data-line-paused="<?= e($goal['line_paused']) ?>"
        aria-label="<?= e(sprintf($labels['open_aria'], $goal['name'])) ?> — <?= e($reading) ?>">

    <span class="goal-card__top">
        <span class="icon-tile" aria-hidden="true"><?= icon($goal['icon']) ?></span>

        <span class="goal-card__heads">
            <span class="goal-card__category"><?= e($goal['category_label']) ?></span>
            <span class="goal-card__name"><?= e($goal['name']) ?></span>
        </span>

        <?php if ($goal['is_completed']): ?>
            <span class="goal-card__done" aria-hidden="true"><?= icon('award') ?></span>
        <?php endif; ?>
    </span>

    <span class="goal-card__figures">
        <span class="goal-card__percent">
            <span class="goal-card__percent-value" data-count-to="<?= has_value($goal['percent']) ? e((string) $goal['percent']) : '' ?>"><?= e(score_text($goal['percent'])) ?></span><?php if (has_value($goal['percent'])): ?><span class="goal-card__percent-sign">%</span><?php endif; ?>
        </span>

        <span class="goal-card__target"><?= e((string) ($goal['target_label'] ?? '—')) ?></span>
    </span>

    <span class="meter meter--goal" aria-hidden="true">
        <span class="meter__fill" data-bar data-progress="<?= e((string) round($goal['ratio'], 4)) ?>"></span>
    </span>

    <span class="goal-card__foot">
        <?php if (!$goal['is_completed']): ?>
            <span class="chip chip--quiet goal-card__flag" data-goal-flag
                  <?= $goal['is_paused'] ? '' : 'hidden' ?>><?= e($labels['paused_chip']) ?></span>
        <?php endif; ?>
        <span class="goal-card__deadline" data-goal-deadline><?= e($goal['deadline_line']) ?></span>
        <?= icon('chevron-right', 'goal-card__chevron') ?>
    </span>

</button>
