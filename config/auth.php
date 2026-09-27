<?php
/**
 * Sign-in providers other than e-mail and password.
 *
 * ---------------------------------------------------------------------------
 * NOTHING SECRET GOES IN THIS FILE
 * ---------------------------------------------------------------------------
 * This file is in the repository, so a client secret written here is published
 * to GitHub, stays in the history after it is removed, and is deployed in the
 * clear. It holds the shape and nothing else.
 *
 * Put the real values in config/auth.local.php, which is git-ignored and not
 * part of a deploy, or in the environment. See config/auth.local.php.example.
 *
 * A provider with no credentials is not broken — it is simply not set up. Its
 * button stays disabled and nothing about signing in changes.
 */

declare(strict_types=1);

$settings = [

    /**
     * Sign in with Google (OpenID Connect).
     *
     * An OAuth 2.0 Client ID of type "Web application" in a Google Cloud
     * project, with the redirect URI below registered on it exactly as written.
     * Only the basic sign-in scopes are asked for — openid, email, profile —
     * so no Google verification review is needed to go live.
     */
    'google' => [
        'client_id'     => '',
        'client_secret' => '',
        // https://your-domain.tld/api/auth/google-callback.php
        'redirect_uri'  => '',

        /* The JoLu app's own OAuth clients (type "Android", one per signing
           key: package name + SHA-1), in the same Google Cloud project as
           the client above. Not secret. The app asks Google for an ID token
           for the Web client above; Google names the Android client that
           asked in the token's `azp`, and only these are believed
           (api/auth/app-google.php). Empty: signing in with Google in the
           app is not offered. */
        'android_client_ids' => [],
    ],
];

$local = __DIR__ . '/auth.local.php';
if (is_file($local)) {
    foreach ((array) require $local as $provider => $override) {
        $settings[$provider] = array_replace($settings[$provider] ?? [], (array) $override);
    }
}

/* The environment wins, so a host that injects secrets rather than storing
   them in a file works without one. */
foreach ([
    'GOOGLE_SIGNIN_CLIENT_ID'     => ['google', 'client_id'],
    'GOOGLE_SIGNIN_CLIENT_SECRET' => ['google', 'client_secret'],
    'GOOGLE_SIGNIN_REDIRECT_URI'  => ['google', 'redirect_uri'],
] as $variable => [$provider, $key]) {
    $value = getenv($variable);
    if ($value !== false && $value !== '') {
        $settings[$provider][$key] = $value;
    }
}

/* A list, so a comma-separated one: debug and release keys have a client each. */
$android = getenv('GOOGLE_SIGNIN_ANDROID_CLIENT_IDS');
if ($android !== false && trim($android) !== '') {
    $settings['google']['android_client_ids'] = array_values(array_filter(array_map('trim', explode(',', $android))));
}

return $settings;
