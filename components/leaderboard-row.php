<?php
/**
 * One leaderboard row: position, avatar, name, points. Nothing else — no
 * health data, no profile, nothing to tap into yet.
 *
 * The avatar is the person's profile picture, when they have one and show it
 * on the boards (Instellingen → Privacy; the server leaves the path out
 * otherwise), over their initial — which is what shows while the picture
 * loads, or if it cannot.
 */
declare(strict_types=1);

$entry  = $data['entry'];
$unit   = $data['community']['labels']['unit'];
$isYou  = !empty($entry['self']);
$rank   = $entry['rank'] ?? null;
$name   = $entry['name'] ?? null;
$sticky = !empty($data['entry_sticky']);
$avatar = $entry['avatar'] ?? null;
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
        <?php if (has_value($avatar)): ?>
            <img class="board-row__photo" src="<?= e((string) $avatar) ?>" alt="" loading="lazy" decoding="async">
        <?php endif; ?>
    </span>

    <span class="board-row__name"><?= has_value($name) ? e((string) $name) : '—' ?></span>

    <span class="board-row__points <?= state_class($entry['points'] ?? null) ?>">
        <?= e(community_points($entry['points'] ?? null)) ?><span class="board-row__unit"><?= e($unit) ?></span>
    </span>

</li>
