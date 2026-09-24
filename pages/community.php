<?php
/**
 * Community — a leaderboard, and nothing competing with it.
 *
 * The controls sit in a fixed head and only the board scrolls, so switching
 * scope or period never moves the page around underneath you.
 */
declare(strict_types=1);

$community = $data['community'];
$scope     = $community['default_scope'];
$period    = $community['default_period'];
?>
<section class="screen page" data-page="community" aria-label="Community"
         <?= empty($data['page_active']) ? 'aria-hidden="true" inert' : '' ?>>

    <div class="community" data-community>

        <div class="community__head">
            <div class="shell community__controls">
                <h1 class="community__title"><?= e($community['title']) ?></h1>

                <?php
                component('segmented', $data + [
                    'segment_options'  => $community['scopes'],
                    'segment_selected' => $scope,
                    'segment_attr'     => 'data-scope-option',
                    'segment_label'    => 'Ranglijst kiezen',
                ]);

                component('segmented', $data + [
                    'segment_options'  => $community['periods'],
                    'segment_selected' => $period,
                    'segment_attr'     => 'data-period-option',
                    'segment_label'    => 'Periode kiezen',
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

</section>
