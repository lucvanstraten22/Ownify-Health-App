<?php
/**
 * Ranglijst — the leaderboard, a page of its own behind Community's card.
 *
 * Nothing about the board changed when it moved here: the same scopes,
 * periods, rows and sticky row of your own (components/leaderboard-board.php,
 * community.js). The controls sit in a fixed head under the back pill and
 * only the board scrolls, so switching scope or period never moves the page
 * around underneath you.
 */
declare(strict_types=1);

$community = $data['community'];
$scope     = $community['default_scope'];
$period    = $community['default_period'];
$title     = $community['labels']['board_head'];
?>
<article class="detail leaderboard-detail" data-detail="leaderboard" data-accent="health"
         aria-label="<?= e($title) ?>" aria-hidden="true" inert>

    <div class="community" data-community>

        <div class="community__head">
            <header class="app-header detail__top" data-header>
                <div class="shell detail__bar">
                    <button type="button" class="pill pill--back press" data-detail-close
                            aria-label="<?= e(t('Terug naar Community')) ?>">
                        <?= icon('chevron-left', 'pill__icon') ?>
                        <span class="pill__label"><?= e(t('Community')) ?></span>
                    </button>
                </div>
            </header>

            <div class="shell community__controls">
                <h1 class="community__title"><?= e($title) ?></h1>

                <?php
                component('segmented', $data + [
                    'segment_options'  => $community['scopes'],
                    'segment_selected' => $scope,
                    'segment_attr'     => 'data-scope-option',
                    'segment_label'    => t('Ranglijst kiezen'),
                ]);

                component('segmented', $data + [
                    'segment_options'  => $community['periods'],
                    'segment_selected' => $period,
                    'segment_attr'     => 'data-period-option',
                    'segment_label'    => t('Periode kiezen'),
                ]);
                ?>
            </div>
        </div>

        <div class="community__board">
            <?php
            foreach ($community['scopes'] as $scopeKey => $scopeConfig) {
                foreach ($community['periods'] as $periodKey => $periodConfig) {
                    component('leaderboard-board', $data + [
                        'scope'        => $scopeKey,
                        'period'       => $periodKey,
                        'board_active' => $scopeKey === $scope && $periodKey === $period,
                    ]);
                }
            }
            ?>
        </div>

    </div>

</article>
