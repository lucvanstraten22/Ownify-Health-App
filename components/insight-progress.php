<?php
/**
 * A combined insight still gathering what it needs (38.0, docs/CORRELATIONS.md):
 * only its bar — no percentage, no "Nog onduidelijk", no line under it.
 * How much is still needed ("Nog 3 dagen", 38.1) is the bar's tooltip:
 * the goal bar's own reading (.goal__track, .goal__tip, dashboard.js) —
 * shown while a pointer is over it, while a finger rests on it, or while it
 * (or the row it is in) has the keyboard's focus; gone when that ends.
 *
 * $data['progress']: ['share' => 0…1, 'text' => the tooltip, 'label' => what
 *                     a screen reader hears (default: the tooltip), 'id' => unique,
 *                     'focusable' => false inside a button (the row is focused)]
 */
declare(strict_types=1);

$progress  = $data['progress'];
$share     = max(0.0, min(1.0, (float) $progress['share']));
$tipId     = 'insight-lock-' . $progress['id'];
$focusable = $progress['focusable'] ?? true;
?>
<?php /* Focusable, so it has a role and a name (A14, 50.0): the bar, read with its tip. */ ?>
<span class="goal__track insight-lock" data-goal-track<?= $focusable ? ' tabindex="0" role="group" aria-label="' . e((string) ($progress['label'] ?? $progress['text'])) . '"' : '' ?> aria-describedby="<?= e($tipId) ?>">
    <span class="meter insight-lock__meter" role="img" aria-label="<?= e((string) ($progress['label'] ?? $progress['text'])) ?>">
        <span class="meter__fill" data-bar data-progress="<?= e((string) round($share, 4)) ?>"></span>
    </span>
    <span class="goal__tip insight-lock__tip" id="<?= e($tipId) ?>" role="tooltip" style="--p: <?= e((string) round($share * 100, 2)) ?>"><?= e((string) $progress['text']) ?></span>
</span>
