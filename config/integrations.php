<?php
/**
 * Credentials for outside health platforms.
 *
 * ---------------------------------------------------------------------------
 * NOTHING REAL GOES IN THIS FILE
 * ---------------------------------------------------------------------------
 * This file is in the repository, so a client secret written here is published
 * to GitHub, stays in the history after it is removed, and is deployed in the
 * clear. It holds the shape and nothing else.
 *
 * Put the real values in config/integrations.local.php, which is git-ignored
 * and not part of a deploy, or in the environment. See
 * config/integrations.local.php.example.
 *
 * A provider with no credentials is not "broken" — it is simply not set up,
 * and the settings screen says exactly that rather than offering a button
 * that cannot work.
 */

declare(strict_types=1);

$settings = [

    /**
     * Google Health API — the cloud one, read server to server.
     *
     * Obtained from a Google Cloud project: an OAuth 2.0 Client ID of type
     * "Web application", with the redirect URI below registered on it.
     */
    'google_health' => [
        'client_id'     => '',
        'client_secret' => '',
        // Must match a redirect URI registered on the OAuth client, exactly,
        // including https and any trailing path.
        'redirect_uri'  => '',
        // Asked for at consent time. Read-only: Ownify imports, it never writes
        // back to anyone's health account.
        'scopes'        => [
            'https://www.googleapis.com/auth/googlehealth.sleep.readonly',
            'https://www.googleapis.com/auth/googlehealth.activity_and_fitness.readonly',
            'https://www.googleapis.com/auth/googlehealth.health_metrics_and_measurements.readonly',
            'https://www.googleapis.com/auth/googlehealth.nutrition.readonly',
        ],
    ],

    /**
     * Health Connect — the phone one.
     *
     * Nothing to configure, because there is nothing on our side to configure:
     * the data is read by an app on the phone and posted to
     * api/integrations/ingest.php with a device token. No Google Cloud project,
     * no OAuth client, no API key.
     *
     * `app_available` is the one switch: true, because the Ownify Android app
     * reads Health Connect. A phone source with false is shown with the reason
     * it cannot be connected, and no pairing code is offered or issued for it.
     */
    'google_health_connect' => [
        'app_available' => true,
        'store_url'     => '',
    ],

    /**
     * Polar — AccessLink, the cloud one: Polar Flow's data, read server to
     * server (docs/POLAR.md).
     *
     * A client registered at https://admin.polaraccesslink.com, with the
     * redirect URL below added to it EXACTLY. The id and the secret come from
     * the environment (POLAR_CLIENT_ID, POLAR_CLIENT_SECRET) or from
     * config/integrations.local.php — never from this file.
     */
    'polar' => [
        'client_id'     => '',
        'client_secret' => '',
        // Where Polar sends the browser back to. The live site's; a local
        // copy sets its own (POLAR_REDIRECT_URI or the local file).
        'redirect_uri'  => 'https://ownify.acits.nl/api/integrations/polar/callback.php',
        // Read-only, and only what Ownify shows (docs/POLAR.md, "Scopes").
        'scopes'        => [
            'training_sessions:read',
            'activity:read',
            'sleep:read',
            'nightly_recharge:read',
            'continuous_samples:read',
            'devices:read',
            'sports:read',
        ],
        // Polar's own addresses (AccessLink v4). Not secrets; changeable only
        // so the test suite can stand in for Polar (tools/polar-test.php).
        'authorize_url' => 'https://auth.polar.com/oauth/authorize',
        'token_url'     => 'https://auth.polar.com/oauth/token',
        'api_base'      => 'https://www.polaraccesslink.com/v4/data',
        // The first sync reaches this many days back; later ones overlap the
        // last few days, because a watch can sync to Polar Flow days late.
        'initial_days'  => 28,
        'overlap_days'  => 3,
    ],

];

$local = __DIR__ . '/integrations.local.php';
if (is_file($local)) {
    foreach ((array) require $local as $provider => $override) {
        $settings[$provider] = array_replace($settings[$provider] ?? [], (array) $override);
    }
}

/* The environment wins, so a host that injects secrets rather than storing
   them in a file works without one. */
foreach ([
    'GOOGLE_HEALTH_CLIENT_ID'     => ['google_health', 'client_id'],
    'GOOGLE_HEALTH_CLIENT_SECRET' => ['google_health', 'client_secret'],
    'GOOGLE_HEALTH_REDIRECT_URI'  => ['google_health', 'redirect_uri'],
    'POLAR_CLIENT_ID'             => ['polar', 'client_id'],
    'POLAR_CLIENT_SECRET'         => ['polar', 'client_secret'],
    'POLAR_REDIRECT_URI'          => ['polar', 'redirect_uri'],
    'POLAR_AUTHORIZE_URL'         => ['polar', 'authorize_url'],
    'POLAR_TOKEN_URL'             => ['polar', 'token_url'],
    'POLAR_API_BASE'              => ['polar', 'api_base'],
] as $variable => [$provider, $key]) {
    $value = getenv($variable);
    if ($value !== false && $value !== '') {
        $settings[$provider][$key] = $value;
    }
}

return $settings;
