<?php
/**
 * Transactional e-mail (28.0; Gmail SMTP set up in 34.1). docs/MAIL.md.
 *
 * NOTHING SECRET GOES IN THIS FILE: it is in Git and deployed in the clear.
 * Everything here is public — the server, the port, the sender address. The
 * one secret, the Gmail App Password, is read from (later wins):
 *
 *   1. config/mail.local.php        beside this file; git-ignored, never
 *                                   deployed (the convention every *.local.php
 *                                   here follows)
 *   2. <web root>/../private/mail.local.php
 *                                   OUTSIDE the web root — on Hestia the
 *                                   domain's own private/ folder, beside
 *                                   public_html. The recommended place on the
 *                                   server: nothing there can ever be served.
 *   3. the environment              OWNIFY_SMTP_PASSWORD (and the others below)
 *
 * See config/mail.local.php.example. Not configured = nothing is sent, and
 * what needs mail says it is not available yet. Signing in never depends on it.
 */
declare(strict_types=1);

$settings = [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'encryption' => 'starttls',           // STARTTLS on 587; 'smtps' (implicit TLS) belongs with 465
    'username'   => 'noreply.ownify@gmail.com',
    'password'   => '',                   // the 16-character Google App Password — never here (see above)
    'from'       => 'noreply.ownify@gmail.com',   // Gmail sends only as the account itself
    'from_name'  => 'Ownify',
    'public_url' => 'https://ownify.acits.nl',    // every link in a mail is built from this, never from the request
    /* Reopened by hand, only after a real delivery test and the Release B
       tests. Release A (28.0) ignores it: registering with e-mail stays
       closed whatever it says (account_email_registration_open()). */
    'email_registration' => false,
];

/* Where the settings were read from, by name only — never their values
   (tools/mail-test.php and check-config say which file is in use). */
$sources = [];

foreach ([
    __DIR__ . '/mail.local.php',
    dirname(__DIR__, 2) . '/private/mail.local.php',
] as $local) {
    /* @: outside the web root PHP's open_basedir may not allow a look. */
    if (@is_file($local) && @is_readable($local)) {
        $settings  = array_replace($settings, (array) require $local);
        $sources[] = $local;
    }
}

foreach (['OWNIFY_SMTP_HOST' => 'host', 'OWNIFY_SMTP_PORT' => 'port',
          'OWNIFY_SMTP_USERNAME' => 'username', 'OWNIFY_SMTP_PASSWORD' => 'password',
          'OWNIFY_MAIL_FROM' => 'from', 'OWNIFY_PUBLIC_URL' => 'public_url'] as $variable => $key) {
    $value = getenv($variable);
    if ($value !== false && $value !== '') {
        $settings[$key] = $key === 'port' ? (int) $value : $value;
        $sources[] = '$' . $variable;
    }
}

/* Google shows the App Password in groups of four; the spaces are not part of it. */
$settings['password'] = preg_replace('/\s+/', '', (string) $settings['password']);
$settings['_sources'] = $sources;

return $settings;
