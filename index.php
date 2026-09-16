<?php
/**
 * Application shell.
 *
 * Two independent movements live here:
 *
 *   horizontal — the rail of five pages, one per primary destination
 *   vertical   — the assistant sheet, pulled up over whichever page shows
 *
 * The sheet sits above the rail rather than inside it, which is what lets it
 * return the user to the exact page and scroll position they came from.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/lib/render.php';
require __DIR__ . '/lib/health.php';
require __DIR__ . '/lib/community.php';
require __DIR__ . '/lib/goals.php';
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/components/icons.php';

/** @var array $data */
$data = require __DIR__ . '/config/dashboard.php';

/* Health lives in its own config; health_prepare() is what keeps the
   review-only demo values out of the shipped page. */
$data['health'] = health_prepare(require __DIR__ . '/config/health.php');

/* The dashboard's health score is the average of those three pillars, derived
   here rather than stored, so the ring can never disagree with them. */
$data['scores']['overall']['value'] = health_overall_score(
    $data['health'],
    (int) $data['scores']['overall']['max']
);

/* Community boards are assembled the same way: empty unless demo is on. */
$data['community'] = community_prepare(require __DIR__ . '/config/community.php');

/* Goals: expanded, split into active/completed and put in priority order. */
$data['goals'] = goals_prepare(require __DIR__ . '/config/goals.php');

/* Session state for the header button and the account panel. */
$data['auth'] = app_auth();

/* Settings: integrations resolved, profile read off the signed-in record. */
$data['settings'] = settings_prepare(require __DIR__ . '/config/settings.php', $data['auth']);

$app   = $data['app'];
$focus = $data['focus'];

/** The rail follows the navigation order, so config drives both. */
$startPage  = 'overview';
$startIndex = 0;
foreach ($data['navigation'] as $position => $item) {
    if (!empty($item['active'])) {
        $startPage  = $item['id'];
        $startIndex = $position;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e($app['locale']) ?>" data-focus="<?= e($focus) ?>" data-active-page="<?= e($startPage) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($app['theme_color']) ?>">
    <meta name="color-scheme" content="dark">
    <meta name="description" content="<?= e($app['name']) ?> — dagelijks overzicht van slaap, voeding en sport.">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">

    <title><?= e($app['name']) ?> — <?= e($app['tagline']) ?></title>

    <script>document.documentElement.classList.add('js');</script>

    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/ai.css">
    <link rel="stylesheet" href="assets/css/health.css">
    <link rel="stylesheet" href="assets/css/community.css">
    <link rel="stylesheet" href="assets/css/goals.css">
    <link rel="stylesheet" href="assets/css/settings.css">
    <link rel="stylesheet" href="assets/css/account.css">
</head>
<body class="app">

    <a class="skip-link" href="#main">Naar de inhoud</a>

    <!-- Shared ground behind every layer: the visual thread between them. -->
    <div class="app__backdrop" aria-hidden="true"></div>

    <div class="deck" data-deck>

        <!-- Parked on the starting page server-side, so the rail never has to
             animate into place on load — and lands right without JavaScript. -->
        <div class="rail" data-rail
             style="transform: translate3d(<?= e((string) (-100 * $startIndex)) ?>%, 0, 0);">
            <?php foreach ($data['navigation'] as $position => $item):
                $isActive = $item['id'] === $startPage;
                ?>
                <div class="rail__slot" style="--page-index: <?= e((string) $position) ?>;">
                    <?php
                    $pageData = $data + ['page_active' => $isActive];

                    if ($item['destination'] === 'overview') {
                        page('overview', $pageData);
                    } elseif ($item['destination'] === 'health') {
                        page('health', $pageData);
                    } elseif ($item['destination'] === 'goals') {
                        page('goals', $pageData);
                    } elseif ($item['destination'] === 'community') {
                        page('community', $pageData);
                    } elseif ($item['destination'] === 'settings') {
                        page('settings', $pageData);
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Detail pages: above the rail, below the dock, so the tab bar and
             the assistant stay reachable from inside one. Gezondheid and Doelen
             share this layer because drilling in is the same movement on both. -->
        <div class="detail-stack" data-detail-stack>
            <?php foreach ($data['health']['areas'] as $areaId => $area): ?>
                <?php page('health-detail', $data + ['area' => $area + ['id' => $areaId]]); ?>
            <?php endforeach; ?>

            <?php foreach ($data['goals']['all'] as $goal): ?>
                <?php /* The new key goes first: `+` keeps the left side, and the
                         dashboard config already owns a 'goal' of its own. */ ?>
                <?php page('goal-detail', ['goal' => $goal] + $data); ?>
            <?php endforeach; ?>

            <?php foreach ($data['settings']['pages'] as $pageId => $settingsPage): ?>
                <?php page('settings-detail', $data + [
                    'settings_page'    => $settingsPage,
                    'settings_page_id' => $pageId,
                ]); ?>
            <?php endforeach; ?>
        </div>

        <!-- Dims the page while the assistant is in front of it. -->
        <div class="sheet-scrim" data-scrim aria-hidden="true"></div>

        <?php
        component('app-dock', $data);
        page('ai', $data);
        ?>

    </div>

    <?php
    /* Both live outside the deck, so the deck's pointer pipeline never sees
       them and neither can be mistaken for a swipe. */
    component('goal-wizard', $data);
    component('settings-confirm', $data);
    component('account-modal', $data);
    ?>

    <script src="assets/js/dashboard.js" defer></script>
    <script src="assets/js/interactions.js" defer></script>
    <script src="assets/js/navigation-core.js" defer></script>
    <script src="assets/js/page-navigation.js" defer></script>
    <script src="assets/js/ai-sheet.js" defer></script>
    <script src="assets/js/detail-layer.js" defer></script>
    <script src="assets/js/health-trend.js" defer></script>
    <script src="assets/js/community.js" defer></script>
    <script src="assets/js/goals.js" defer></script>
    <script src="assets/js/goal-wizard.js" defer></script>
    <script src="assets/js/settings.js" defer></script>
    <script src="assets/js/account.js" defer></script>
</body>
</html>
