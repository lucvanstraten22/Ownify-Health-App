<?php
/**
 * Instellingen › Taal and › Voorkeuren: the signed-in account's language,
 * units, first day of the week and accessibility choices (includes/i18n.php,
 * docs/I18N.md). Any of them per request:
 *
 *   language       auto | nl | en   auto forgets the choice: the app follows
 *                                   the browser's or the phone's language again
 *   units          metric | imperial
 *   week           monday | sunday  where a week starts (migration 024)
 *   text_large     0 | 1            Toegankelijkheid › Grotere tekst
 *   contrast_more  0 | 1            Toegankelijkheid › Extra contrast
 *   motion_off     0 | 1            Toegankelijkheid › Animaties uit
 *   rating_notice  16 | 18 | 19 | 20 | 21 | 22
 *                                   Meldingen › Stip bij Voeding: from which
 *                                   hour a dot asks for today's cijfer
 *                                   (migration 026)
 *
 * Kept on the account, so the choice is the same on the website and in the
 * app and survives signing out and in. The website also keeps the language
 * in a cookie, for the screens before signing in. Nothing measured is
 * touched: the units only change how values are shown and typed.
 *
 * The website (session + CSRF) and the app (account token) alike. Answers
 * with what is now in force; the caller reads its pages again.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (!i18n_columns_present()) {
    api_fail('De database is nog niet bijgewerkt voor taal en eenheden (migratie 021).', 503);
}

$language = isset($_POST['language']) ? (string) $_POST['language'] : null;
$units    = isset($_POST['units']) ? (string) $_POST['units'] : null;
$week     = isset($_POST['week']) ? (string) $_POST['week'] : null;

/* The three switches: '1' on, '0' off. */
$switches = [];
foreach (['text_large', 'contrast_more', 'motion_off'] as $key) {
    if (isset($_POST[$key])) {
        if (!in_array((string) $_POST[$key], ['0', '1'], true)) {
            api_fail('Onbekende waarde.', 422);
        }
        $switches[$key] = (string) $_POST[$key] === '1' ? 1 : 0;
    }
}

$notice = isset($_POST['rating_notice']) ? (string) $_POST['rating_notice'] : null;

if ($notice !== null) {
    if (!in_array((int) $notice, NOTICE_RATING_HOURS, true) || (string) (int) $notice !== $notice) {
        api_fail('Onbekend tijdstip.', 422);
    }
    if (!notice_columns_present()) {
        api_fail('De database is nog niet bijgewerkt voor deze instelling (migratie 026).', 503);
    }
}

if ($language === null && $units === null && $week === null && $switches === [] && $notice === null) {
    api_fail('Kies een taal of eenheden.', 422);
}

if (($week !== null || $switches !== []) && !preferences_columns_present()) {
    api_fail('De database is nog niet bijgewerkt voor deze voorkeuren (migratie 024).', 503);
}

if ($week !== null && !in_array($week, ['monday', 'sunday'], true)) {
    api_fail('Onbekende eerste dag van de week.', 422);
}

if ($language !== null && !in_array($language, ['auto', 'nl', 'en'], true)) {
    api_fail('Onbekende taal.', 422);
}

if ($units !== null && !in_array($units, ['metric', 'imperial'], true)) {
    api_fail('Onbekende eenheden.', 422);
}

/* The profile row exists for every account; INSERT … ON DUPLICATE is only a
   guard for one that predates it. */
db_run('INSERT IGNORE INTO user_profiles (user_id) VALUES (?)', [$userId]);

if ($language !== null) {
    $chosen = $language === 'auto' ? null : $language;
    db_run('UPDATE user_profiles SET locale = ? WHERE user_id = ?', [$chosen, $userId]);

    /* The browser's own copy, for the opening screen after signing out. The
       app keeps its own (OwnifyLanguage). */
    if (api_bearer_token() === null) {
        i18n_remember_in_browser($chosen);
    }

    $state = &i18n_state();
    $state['chosen'] = $chosen;
    $state['locale'] = $chosen ?? i18n_from_header($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
}

if ($units !== null) {
    db_run('UPDATE user_profiles SET units = ? WHERE user_id = ?', [$units === 'imperial' ? 'imperial' : null, $userId]);
    $state = &i18n_state();
    $state['units'] = $units;
}

if ($week !== null) {
    db_run('UPDATE user_profiles SET week_start = ? WHERE user_id = ?', [$week === 'sunday' ? 'sunday' : null, $userId]);
    $state = &i18n_state();
    $state['week_start'] = $week;
}

if ($notice !== null) {
    $hour = (int) $notice;
    db_run('UPDATE user_profiles SET rating_notice_hour = ? WHERE user_id = ?', [$hour === NOTICE_RATING_HOUR ? null : $hour, $userId]);
    $state = &i18n_state();
    $state['rating_notice_hour'] = $hour;
}

foreach ($switches as $key => $on) {
    /* $key is one of the three names above, never the request's own text. */
    db_run('UPDATE user_profiles SET ' . $key . ' = ? WHERE user_id = ?', [$on, $userId]);
    $state = &i18n_state();
    $state[$key] = (bool) $on;
}

api_ok([
    'locale'  => i18n_locale(),
    'chosen'  => i18n_chosen(),
    'units'   => units_system(),
    'week'    => week_start(),
    'rating_notice' => rating_notice_hour(),
    /* As privacy.php answers a switch: its key and whether it is on now. */
    ...accessibility_choices(),
    'message' => t('Opgeslagen.'),
]);
