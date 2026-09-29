<?php
/**
 * Router for PHP's built-in server, for tools/app-auth-test.php only:
 *
 *     php -S 127.0.0.1:8300 -t . tools/app-auth-router.php
 *
 * Runs the app as it is, with one difference: where Google is. Its signing
 * keys and its token endpoint are addresses google-signin.php defines only
 * if nothing has yet, so defining them here first points them at a stand-in
 * that serves the test's own keys and ID tokens (OWNIFY_TEST_GOOGLE_JWKS_URL,
 * OWNIFY_TEST_GOOGLE_TOKEN_URL). Everything else — the endpoints, the checks,
 * the database — is the real thing.
 *
 * Only the built-in server runs this. Under Apache it answers 404, and
 * tools/.htaccess refuses the whole directory anyway.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$jwks = getenv('OWNIFY_TEST_GOOGLE_JWKS_URL');

if (is_string($jwks) && $jwks !== '') {
    define('GOOGLE_SIGNIN_JWKS_URL', $jwks);
}

/* And where the website's own Google sign-in exchanges its code, so the test
   can run it end to end too (OWNIFY_TEST_GOOGLE_TOKEN_URL). */
$token = getenv('OWNIFY_TEST_GOOGLE_TOKEN_URL');

if (is_string($token) && $token !== '') {
    define('GOOGLE_SIGNIN_TOKEN_URL', $token);
}

$root = dirname(__DIR__);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/' || $path === '') {
    $path = '/index.php';
}

$file = realpath($root . $path);

/* Only PHP files inside the checkout are run here; anything else is served
   by the built-in server as it would be without a router. */
if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !str_ends_with($file, '.php')) {
    return false;
}

$_SERVER['SCRIPT_NAME']     = $path;
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['PHP_SELF']        = $path;

chdir(dirname($file));
require $file;
