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
     * `app_available` is the one switch. While it is false the devices screen
     * says Health Connect needs an app that does not exist yet, rather than
     * handing out a pairing code with nothing to type it into. Flip it the day
     * the Android app is published; the server side is already finished and
     * tested.
     */
    'google_health_connect' => [
        'app_available' => true,
        'store_url'     => '',
    ],

    'apple_health' => [
        'app_available' => true,
        'store_url'     => '',
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
] as $variable => [$provider, $key]) {
    $value = getenv($variable);
    if ($value !== false && $value !== '') {
        $settings[$provider][$key] = $value;
    }
}

return $settings;
