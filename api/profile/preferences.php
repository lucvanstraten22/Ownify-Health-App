<?php
/**
 * Instellingen › Taal and › Voorkeuren › Eenheden: the signed-in account's
 * language and units (includes/i18n.php, docs/I18N.md). One or both per
 * request:
 *
 *   language   auto | nl | en   auto forgets the choice: the app follows the
 *                               browser's or the phone's language again
 *   units      metric | imperial
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

if ($language === null && $units === null) {
    api_fail('Kies een taal of eenheden.', 422);
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

api_ok([
    'locale'  => i18n_locale(),
    'chosen'  => i18n_chosen(),
    'units'   => units_system(),
    'message' => t('Opgeslagen.'),
]);
