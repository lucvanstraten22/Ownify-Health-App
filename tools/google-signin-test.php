<?php
/**
 * The Google ID-token check, tested without Google.
 *
 *     php tools/google-signin-test.php
 *
 * Makes its own RSA keys, signs tokens with them — good ones, and every kind
 * of bad one worth worrying about — and hands them to google_signin_verify(),
 * the one function that decides whether a Google sign-in is believed. The key
 * set is passed in, exactly as the real flow passes in Google's, so nothing
 * here touches the network, the database or a real account.
 *
 * Exit code 0 when every check passes.
 */

declare(strict_types=1);

/* This lives under the document root on a Hestia deploy. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/google-signin.php';

/* Before any output, as a session has to be. It is thrown away at the end. */
session_boot();

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        printf("  PASS  %s\n", $label);
        return;
    }

    $fail++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : "\n          " . $detail);
}

/** A fresh RSA key pair and its public half as a JWK. */
function make_key(string $kid, int $bits = 2048): array
{
    $private = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($private);

    return [
        'private' => $private,
        'jwk'     => [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => google_signin_b64url_encode($details['rsa']['n']),
            'e'   => google_signin_b64url_encode($details['rsa']['e']),
        ],
        'pem'     => $details['key'],
    ];
}

/** Signs claims the way Google does, unless told otherwise. */
function token(array $claims, $private, array $header = ['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']): string
{
    $input = google_signin_b64url_encode(json_encode($header)) . '.' . google_signin_b64url_encode(json_encode($claims));

    openssl_sign($input, $signature, $private, OPENSSL_ALGO_SHA256);

    return $input . '.' . google_signin_b64url_encode($signature);
}

$now      = 1_760_000_000;
$client   = '1234-test.apps.googleusercontent.com';
$nonce    = 'n0nce-' . bin2hex(random_bytes(8));
$key      = make_key('k1');
$other    = make_key('k1');                  // same kid, different key: an impostor
$keys     = [$key['jwk']];

$claims = [
    'iss'            => 'https://accounts.google.com',
    'azp'            => $client,
    'aud'            => $client,
    'sub'            => '109876543210987654321',
    'email'          => 'Someone@Example.com',
    'email_verified' => true,
    'nonce'          => $nonce,
    'iat'            => $now - 10,
    'exp'            => $now + 3590,
];

$verify = static fn (string $jwt, ?array $set = null, ?string $n = null, ?int $at = null): array
    => google_signin_verify($jwt, $set ?? $keys, $client, $n ?? $nonce, $at ?? $now);

echo "\nGoogle ID-token verification\n";
echo str_repeat('-', 72) . "\n";

/* ------------------------------------------------------------ the good one */

$result = $verify(token($claims, $key['private']));
check('a correctly signed token for this app and this sign-in is accepted', $result['ok'], (string) $result['error']);
check('  and its sub comes through', ($result['claims']['sub'] ?? null) === $claims['sub']);

$result = $verify(token(['iss' => 'accounts.google.com'] + $claims, $key['private']));
check('the issuer without https (Google uses both) is accepted', $result['ok'], (string) $result['error']);

$result = $verify(token($claims, $key['private']), null, null, $claims['exp'] + 30);
check('30 seconds past expiry is within the minute of clock leeway', $result['ok'], (string) $result['error']);

/* ------------------------------------------------------- forged signatures */

$good  = token($claims, $key['private']);
[$h, $p, $s] = explode('.', $good);

$tampered = $h . '.' . google_signin_b64url_encode(json_encode(['sub' => 'someone-else'] + $claims)) . '.' . $s;
check('a changed payload with the original signature is refused', !$verify($tampered)['ok']);

check('a token signed by another key under the same kid is refused', !$verify(token($claims, $other['private']))['ok']);

$none = google_signin_b64url_encode(json_encode(['alg' => 'none', 'kid' => 'k1'])) . '.' . $p . '.';
check("alg 'none' with no signature is refused", !$verify($none)['ok']);

$hsInput = google_signin_b64url_encode(json_encode(['alg' => 'HS256', 'kid' => 'k1'])) . '.' . $p;
$hs      = $hsInput . '.' . google_signin_b64url_encode(hash_hmac('sha256', $hsInput, $key['pem'], true));
check('HS256 signed with the public key as the secret is refused', !$verify($hs)['ok']);

check('an unknown kid is refused', !$verify(token($claims, $key['private'], ['alg' => 'RS256', 'kid' => 'nope']))['ok']);
check('a header without a kid is refused', !$verify(token($claims, $key['private'], ['alg' => 'RS256']))['ok']);
check('an empty key set refuses everything', !$verify($good, [])['ok']);

$weak = make_key('k1', 1024);
check('a 1024-bit key is not trusted even when it verifies', !$verify(token($claims, $weak['private']), [$weak['jwk']])['ok']);

check('a key marked for encryption is not used for signatures',
    !$verify($good, [['use' => 'enc'] + $key['jwk']])['ok']);

/* ------------------------------------------------------------- the claims */

$bad = [
    'a different issuer'                 => ['iss' => 'https://evil.example'],
    'another app as audience'            => ['aud' => 'someone-else.apps.googleusercontent.com'],
    'an audience list with azp elsewhere'=> ['aud' => [$client, 'other'], 'azp' => 'other'],
    'azp naming another app'             => ['azp' => 'other.apps.googleusercontent.com'],
    'an expired token'                   => ['exp' => $now - 61],
    'a token issued in the future'       => ['iat' => $now + 120],
    'exp as a string'                    => ['exp' => (string) ($now + 3590)],
    'the nonce of another sign-in'       => ['nonce' => 'someone-elses-nonce'],
    'an empty subject'                   => ['sub' => ''],
    'a subject too long to store'        => ['sub' => str_repeat('9', 192)],
];

foreach ($bad as $label => $override) {
    check($label . ' is refused', !$verify(token($override + $claims, $key['private']))['ok']);
}

$missing = $claims;
unset($missing['nonce']);
check('a token without a nonce is refused', !$verify(token($missing, $key['private']))['ok']);

$missing = $claims;
unset($missing['exp']);
check('a token without an expiry is refused', !$verify(token($missing, $key['private']))['ok']);

check('verifying against an empty expected nonce refuses everything',
    !google_signin_verify($good, $keys, $client, '', $now)['ok']);

$list = token(['aud' => [$client, 'other']] + $claims, $key['private']);
check('an audience list containing this app, with azp this app, is accepted', $verify($list)['ok']);

/* ------------------------------------------------------------ not a token */

foreach (['' => 'an empty string', 'abc' => 'one part', 'a.b' => 'two parts', 'a.b.c.d' => 'four parts',
          '%%%.%%%.%%%' => 'characters outside base64url', $h . '.' . $p . '.@@@' => 'a mangled signature'] as $junk => $label) {
    check($label . ' is refused', !$verify((string) $junk)['ok']);
}

/* ------------------------------------------------------- the e-mail claim */

check('a verified address is used, lower-cased',
    google_signin_verified_email($claims) === 'someone@example.com');
check("email_verified as the string 'true' counts",
    google_signin_verified_email(['email_verified' => 'true'] + $claims) === 'someone@example.com');
check('an unverified address is not used',
    google_signin_verified_email(['email_verified' => false] + $claims) === null);
check('no email_verified claim means not verified',
    google_signin_verified_email(array_diff_key($claims, ['email_verified' => 1])) === null);
check('something that is not an address is not used',
    google_signin_verified_email(['email' => 'not-an-address'] + $claims) === null);

/* ------------------------------------------------ the waiting identity */

$_SESSION['google_signin_pending'] = ['sub' => 's', 'email' => 'a@b.nl', 'expires' => time() - 1];
check('an identity past its ten minutes is gone', google_signin_pending() === null);
check('  and is removed from the session', !isset($_SESSION['google_signin_pending']));

$_SESSION['google_signin_pending'] = ['sub' => 's', 'email' => 'a@b.nl', 'expires' => time() + 300];
check('one within its ten minutes is still there', google_signin_pending() !== null);

$_SESSION['google_signin_pending'] = ['sub' => 123, 'email' => 'a@b.nl', 'expires' => time() + 300];
check('a malformed one is not believed', google_signin_pending() === null);

$_SESSION['google_signin_pending'] = ['sub' => 's', 'email' => 'a@b.nl', 'expires' => time() - 1];
$result = google_signin_create_account('nieuwe_naam');
check('an expired identity cannot become an account', !$result['ok'] && !empty($result['expired']));

session_destroy();

echo str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
