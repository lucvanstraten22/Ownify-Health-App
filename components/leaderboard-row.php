<?php
/**
 * One leaderboard row: position, avatar, name, points. Nothing else — no
 * health data, no profile, nothing to tap into yet.
 */
declare(strict_types=1);

$entry  = $data['entry'];
$unit   = $data['community']['labels']['unit'];
$isYou  = !empty($entry['self']);
$rank   = $entry['rank'] ?? null;
$name   = $entry['name'] ?? null;
$sticky = !empty($data['entry_sticky']);
?>
<li class="board-row<?= $isYou ? ' board-row--you' : '' ?><?= $sticky ? ' board-row--sticky' : '' ?>"
    <?= $isYou ? 'data-user-row' : '' ?>
    <?= $isYou ? 'aria-label="' . e($data['community']['labels']['you_hint']) . '"' : '' ?>>

    <span class="board-row__rank"><?= e(community_rank($rank)) ?></span>

    <span class="board-row__avatar" data-accent="<?= e(community_avatar_accent($name)) ?>" aria-hidden="true">
        <?php if (has_value($name)): ?>
            <?= e(community_initial($name)) ?>
        <?php else: ?>
            <?= icon('user') ?>
        <?php endif; ?>
    </span>

    <span class="board-row__name"><?= has_value($name) ? e((string) $name) : '—' ?></span>

    <span class="board-row__points <?= state_class($entry['points'] ?? null) ?>">
        <?= e(community_points($entry['points'] ?? null)) ?><span class="board-row__unit"><?= e($unit) ?></span>
    </span>

</li>
