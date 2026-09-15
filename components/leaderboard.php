<?php
/**
 * Compact social layer. Deliberately secondary: smaller type, quieter glass,
 * no names or rankings until real data exists.
 */
declare(strict_types=1);

$board = $data['leaderboard'];
$rows  = $board['rows'];
$self  = $board['self'];
?>
<section class="card card--leaderboard reveal is-empty" aria-labelledby="leaderboard-title">

    <div class="card__head card__head--compact">
        <span class="icon-tile" aria-hidden="true"><?= icon('ranking') ?></span>
        <div class="card__headings">
            <h2 class="card__eyebrow" id="leaderboard-title"><?= e($board['title']) ?></h2>
            <p class="card__meta card__meta--small"><?= e($board['subtitle']) ?></p>
        </div>
    </div>

    <ul class="board" role="list">
        <?php foreach ($rows as $row): ?>
            <li class="board__row <?= state_class($row['name']) ?>">
                <span class="board__rank"><?= e((string) $row['rank']) ?></span>
                <span class="board__avatar" aria-hidden="true"></span>
                <span class="board__name">
                    <?php if (has_value($row['name'])): ?>
                        <?= e((string) $row['name']) ?>
                    <?php else: ?>
                        <span class="skeleton skeleton--line" aria-hidden="true"></span>
                        <span class="sr-only"><?= e($board['empty']) ?></span>
                    <?php endif; ?>
                </span>
                <span class="board__value"><?= e(score_text($row['value'])) ?></span>
            </li>
        <?php endforeach; ?>

        <li class="board__row board__row--self <?= state_class($self['value']) ?>">
            <span class="board__rank"><?= e(score_text($self['rank'])) ?></span>
            <span class="board__avatar board__avatar--self" aria-hidden="true"><?= icon('user') ?></span>
            <span class="board__name"><?= e((string) $self['name']) ?></span>
            <span class="board__value"><?= e(score_text($self['value'])) ?></span>
        </li>
    </ul>

    <p class="card__hint card__hint--plain"><?= e($board['footnote']) ?></p>

</section>
