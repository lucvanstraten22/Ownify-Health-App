<?php
/**
 * Transactional e-mail (28.0).
 *
 * NOTHING SECRET GOES IN THIS FILE: it is in Git and deployed in the clear.
 * The App Password goes in config/mail.local.php (git-ignored, never
 * deployed) or the environment. See config/mail.local.php.example.
 *
 * Not configured = nothing is sent, and what needs mail says it is not
 * available yet. Signing in never depends on it.
 */
declare(strict_types=1);

$settings = [
    'host'       => 'smtp.gmail.com',
    'port'       => 465,
    'encryption' => 'smtps',              // implicit TLS; 'starttls' on 587 also works
    'username'   => '',                   // ownify.noreply@gmail.com
    'password'   => '',                   // the 16-character Google App Password — only in mail.local.php
    'from'       => '',                   // ownify.noreply@gmail.com (must be the account itself)
    'from_name'  => 'Ownify',
    'public_url' => '',                   // https://ownify.acits.nl — every link is built from this, never from the request
    /* Reopened by hand, only after a real delivery test and the Release B
       tests. Release A (28.0) ignores it: registering with e-mail stays
       closed whatever it says (account_email_registration_open()). */
    'email_registration' => false,
];

$local = __DIR__ . '/mail.local.php';
if (is_file($local)) {
    $settings = array_replace($settings, (array) require $local);
}

foreach (['OWNIFY_SMTP_USERNAME' => 'username', 'OWNIFY_SMTP_PASSWORD' => 'password',
          'OWNIFY_MAIL_FROM' => 'from', 'OWNIFY_PUBLIC_URL' => 'public_url'] as $variable => $key) {
    $value = getenv($variable);
    if ($value !== false && $value !== '') {
        $settings[$key] = $value;
    }
}

return $settings;
