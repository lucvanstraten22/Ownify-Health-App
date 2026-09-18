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
require __DIR__ . '/lib/hydrate-health.php';
require __DIR__ . '/lib/hydrate-goals.php';
require __DIR__ . '/lib/hydrate-community.php';
require __DIR__ . '/components/icons.php';

/** @var array $data */
$data = require __DIR__ . '/config/dashboard.php';

/* Session state for the header button and the account panel. It has to come
   first now: who is asking decides what every page below is filled with. */
$data['auth'] = app_auth();

/* The id comes from the SESSION, never from the request. Everything private
   below is read with it, so no query can be pointed at another account by
   changing something a browser can reach. Signed out it is null, and each
   page renders the empty state it already had. */
$userId = current_user_id();

/* The config files describe the shape of each page — which areas exist, what
   they are called, in what unit. The values come from the signed-in user's own
   records; anything they have not recorded stays null and renders empty. */
$data['health'] = hydrate_health(require __DIR__ . '/config/health.php', $userId);

/* The ring is the average of whichever pillars have a score, derived on every
   render rather than stored, so it can never disagree with them — and the
   legend under it reads the same three values. */
$data['scores']['overall']['value'] = health_overall_score(
    $data['health'],
    (int) $data['scores']['overall']['max']
);

$data['scores']['contributors'] = health_contributor_scores(
    $data['scores']['contributors'],
    $data['health']
);

/* Real accounts and real points, or an empty board. */
$data['community'] = hydrate_community(require __DIR__ . '/config/community.php', $userId);

/* Goals: read for this user, then expanded, split by view and ordered. */
$data['goals'] = goals_prepare(hydrate_goals(require __DIR__ . '/config/goals.php', $userId));

/* The line under each page says which of three situations the reader is in.
   An account with data gets no line at all: there is nothing to disclaim. */
$data['disclaimer'] = match (true) {
    !$data['auth']['database'] => $data['disclaimers']['no_database'],
    $userId === null           => $data['disclaimers']['signed_out'],
    $data['scores']['overall']['value'] === null
        && ($data['goals']['used'] ?? 0) === 0 => $data['disclaimers']['no_data'],
    default                    => '',
};

/* The Overzicht page's goal card follows whichever goal is primary. */
$data['goal'] = hydrate_dashboard_goal($data['goal'], $data['goals']['primary'] ?? null);

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

    <?php
    /* Which commit this server is running. The deploy writes VERSION; locally
       there is no such file and nothing is emitted. It is only ever a commit
       hash — no path, no version number anything could be probed with — and it
       turns "is my change live?" from a guess into view-source. */
    $deployed = @file_get_contents(__DIR__ . '/VERSION');
    if (is_string($deployed) && preg_match('/^[0-9a-f]{7,40}$/', trim($deployed))):
        ?>
        <meta name="app-version" content="<?= e(substr(trim($deployed), 0, 7)) ?>">
    <?php endif; ?>

    <script>document.documentElement.classList.add('js');</script>

    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/dashboard.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/ai.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/health.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/community.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/goals.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/settings.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/account.css')) ?>">
</head>
<body class="app">

    <a class="skip-link" href="#main">Naar de inhoud</a>

    <!-- Shared ground behind every layer: the visual thread between them. -->
    <div class="app__backdrop" aria-hidden="true"></div>

    <!--
        The unevenness in the glass reflections.

        Turbulence displaces the highlight, not the backdrop. Displacing the
        backdrop is the obvious way to do refraction and it was measurably the
        wrong one: it re-runs the filter every frame the page scrolls behind
        the bar, and `backdrop-filter: url()` is Chromium-only, so iOS would
        pay nothing and gain nothing. Displacing a static gradient is
        rasterised once, costs nothing per frame and works everywhere — and an
        imperfect reflection is what reads as glass, more than a warped
        background does.
    -->
    <svg class="sr-only" aria-hidden="true" focusable="false" width="0" height="0">
        <filter id="glass-refraction" x="-15%" y="-15%" width="130%" height="130%"
                color-interpolation-filters="sRGB">
            <feTurbulence type="fractalNoise" baseFrequency="0.011 0.02"
                          numOctaves="2" seed="5" result="grain"/>
            <feGaussianBlur in="grain" stdDeviation="1.8" result="softGrain"/>
            <feDisplacementMap in="SourceGraphic" in2="softGrain" scale="19"
                               xChannelSelector="R" yChannelSelector="G"/>
        </filter>
    </svg>

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
    component('settings-editor', $data);
    component('settings-pairing', $data);
    component('account-modal', $data);
    ?>

    <script src="<?= e(asset('assets/js/dashboard.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/interactions.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/navigation-core.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/page-navigation.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/ai-sheet.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/detail-layer.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/health-trend.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/community.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/goals.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/goal-wizard.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/settings.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/account.js')) ?>" defer></script>
</body>
</html>
