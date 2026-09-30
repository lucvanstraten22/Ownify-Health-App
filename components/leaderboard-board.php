<?php
/**
 * One scope × period board.
 *
 * Every combination is rendered and only the selected one is shown, so
 * switching is a class toggle and each board keeps its own scroll position.
 *
 * Your own row is `position: sticky` with both a top and a bottom offset: it
 * sits in its natural place while it is on screen, and docks to whichever
 * edge it would otherwise leave. That is one element, never a duplicate, and
 * it is how your position stays visible whether you are 7th or 1180th.
 */
declare(strict_types=1);

$community = $data['community'];
$scope     = $data['scope'];
$period    = $data['period'];
$isActive  = !empty($data['board_active']);

$board   = $community['boards'][$scope][$period];
$entries = $board['entries'];
$you     = $board['you'];
$limit   = $community['scopes'][$scope]['limit'];
$empty   = $community['scopes'][$scope]['empty'];

$youInList = has_value($you['rank']) && (int) $you['rank'] <= $limit;
$hasEntries = $entries !== [];

/* Vrienden only — never Nederland: a row of the board's own kind, above #1,
   that opens Vriend toevoegen in the account panel (friends.js). */
$addFriends = $scope === 'friends' && !empty($community['add_friends']);
?>
<div class="board<?= $isActive ? ' is-active' : '' ?>"
     data-board data-scope="<?= e($scope) ?>" data-period="<?= e($period) ?>"
     <?= $isActive ? '' : 'aria-hidden="true" inert' ?>>

    <div class="board__scroll" data-board-scroll>

        <?php if ($addFriends): ?>
            <div class="board-add">
                <button type="button" class="board-row board-row--add press"
                        data-account-open data-friends-add-open>
                    <span class="board-row__rank" aria-hidden="true"></span>
                    <span class="board-row__avatar" aria-hidden="true"><?= icon('user-plus') ?></span>
                    <span class="board-row__name"><?= e($community['add_friends']) ?></span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($hasEntries): ?>

            <ol class="board-list" role="list">
                <?php foreach ($entries as $entry): ?>
                    <?php component('leaderboard-row', $data + [
                        'entry'        => $entry,
                        'entry_sticky' => !empty($entry['self']),
                    ]); ?>
                <?php endforeach; ?>

                <?php if (!$youInList): ?>
                    <li class="board-gap" aria-hidden="true">
                        <span class="board-gap__dots"></span>
                        <span class="board-gap__label"><?= e($community['labels']['outside']) ?></span>
                        <span class="board-gap__dots"></span>
                    </li>

                    <?php component('leaderboard-row', $data + [
                        'entry' => [
                            'rank'   => $you['rank'],
                            'name'   => $community['you']['name'],
                            'points' => $you['points'],
                            'self'   => true,
                            'avatar' => $community['you']['avatar'] ?? null,
                        ],
                        'entry_sticky' => true,
                    ]); ?>
                <?php endif; ?>
            </ol>

        <?php else: ?>

            <div class="card board-empty is-empty">
                <span class="icon-tile" aria-hidden="true"><?= icon('community') ?></span>
                <h2 class="board-empty__title"><?= e($empty['title']) ?></h2>
                <p class="board-empty__body"><?= e($empty['body']) ?></p>
            </div>

            <p class="board-you-label"><?= e($community['labels']['you_hint']) ?></p>
            <ol class="board-list board-list--single" role="list">
                <?php component('leaderboard-row', $data + [
                    'entry' => [
                        'rank'   => $you['rank'],
                        'name'   => $community['you']['name'],
                        'points' => $you['points'],
                        'self'   => true,
                        'avatar' => $community['you']['avatar'] ?? null,
                    ],
                    'entry_sticky' => false,
                ]); ?>
            </ol>

        <?php endif; ?>

    </div>
</div>
