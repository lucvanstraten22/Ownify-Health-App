<?php
/**
 * The account panel: behind the header's account button in the app, and
 * behind the opening screen's two buttons for everyone not signed in.
 *
 * Signed in, it shows the account and what can be changed. Deliberately not
 * a profile page. Behind its Vrienden button is a second page, in the same
 * panel: friends, friend requests and who may send them
 * (components/account-friends.php, run by friends.js).
 *
 * Signed out, it is one of two flows and never both: Inloggen or
 * Registreren, whichever button opened it (account.js). The same form serves
 * both — the one field only registering needs is hidden while logging in —
 * and says which it is in its title.
 *
 * Registering with e-mail is closed in Release A (28.0): in the Registreren
 * flow the form is replaced by one line, "Registreren met e-mail is
 * tijdelijk niet beschikbaar.", and Google stays (docs/ACCOUNT-SECURITY.md).
 * When it is open, registering asks for the password twice.
 *
 * Release B (35.0), with the account e-mails on, adds three steps in the
 * same pattern as "Kies je gebruikersnaam" — no new components:
 *   verify  "Bevestig je e-mailadres": a registration waiting for its link
 *           (Stuur opnieuw, Ik heb bevestigd, Ander adres gebruiken)
 *   forgot  Wachtwoord vergeten (also ?wachtwoord-vergeten)
 *   link    the page a link in an account e-mail opens
 *           (?account-link#token): a tap, or a new password
 *
 * Signing in takes a username and a password. Google is a small mark under
 * the form rather than a full-width button above it — it is secondary. It
 * works once it is configured (includes/google-signin.php); until then it
 * renders disabled.
 *
 * Coming back from Google the panel opens by itself: with the one step a new
 * Google user still has — choosing a username — or with a message saying why
 * signing in did not happen.
 */
declare(strict_types=1);

$auth      = $data['auth'];
$user      = $auth['user'];
$signedIn  = $auth['signed_in'];
$providers = $auth['providers'];
$pending   = $signedIn ? null : ($auth['google_pending'] ?? null);

/* A message left by the Google callback, if it is this panel's to show. */
$flash     = $auth['flash'] ?? null;
$flash     = ($flash !== null && $flash['target'] === 'account') ? $flash : null;
/* Release B: a registration this browser started, still waiting for its link. */
$accountMail  = !empty($auth['account_mail']);
$registration = $signedIn || $pending !== null ? null : ($auth['registration'] ?? null);
$autoOpen  = $flash !== null || $pending !== null || $registration !== null;

/* The two flows, and what the panel is called in each. */
$flows     = !$signedIn && $pending === null;
$canRegister = !empty($auth['email_registration']);
$titles    = ['login' => $data['welcome']['login'], 'register' => $data['welcome']['register']];
?>
<div class="account" data-overlay data-account data-csrf="<?= e($auth['csrf']) ?>"
     data-account-session="<?= $signedIn ? 'signed-in' : 'signed-out' ?>"
     <?= $autoOpen ? 'data-account-autoopen' : '' ?> hidden>

    <div class="account__scrim" data-account-close></div>

    <?php /* The eye in every password field, on every screen that has this
             panel (password-reveal.js): drawn once, here. */ ?>
    <template data-password-icons><span data-icon="eye"><?= icon('eye') ?></span><span data-icon="eye-off"><?= icon('eye-off') ?></span></template>

    <div class="account__panel card" role="dialog" aria-modal="true" aria-labelledby="account-title">
        <?php /* The glass and its rim stay put; what is in it scrolls (45.0). */ ?>
        <div class="account__scroll">

        <div class="account__head">
            <?php if ($signedIn): ?>
                <?php /* Back from the Vrienden page to the account. Only there. */ ?>
                <button type="button" class="account__close account__back press" data-friends-back
                        aria-label="<?= e(t('Terug naar account')) ?>" hidden>
                    <?= icon('chevron-left') ?>
                </button>
            <?php endif; ?>

            <?php /* Signed in, or choosing a Google username, this is the
                     account. Otherwise it names the one flow it is on. */ ?>
            <h2 class="card__eyebrow" id="account-title"
                <?php if ($flows): ?>data-account-title data-title-login="<?= e($titles['login']) ?>" data-title-register="<?= e($titles['register']) ?>"<?php endif; ?>>
                <?= e($pending !== null ? t('Kies je gebruikersnaam') : ($flows ? ($registration !== null ? t('Bevestig je e-mailadres') : $titles['login']) : t('Account'))) ?>
            </h2>
            <button type="button" class="account__close press" data-account-close aria-label="<?= e(t('Sluiten')) ?>">
                <?= icon('close') ?>
            </button>
        </div>

        <?php if (!$auth['database']): ?>
            <p class="account__notice">
                <?= e(t('Geen databaseverbinding. Importeer database/schema.sql en controleer config/database.php.')) ?>
            </p>
        <?php endif; ?>

        <?php
        $flashIsError = $flash !== null && $flash['tone'] === 'error';
        /* A fixed address from the code (auth_flash), opened beside the app. */
        $flashLink = $flash !== null && !empty($flash['link'])
            ? ' <a class="account__link" href="' . e($flash['link']['href']) . '" target="_blank" rel="noopener noreferrer">'
                . e($flash['link']['label']) . '</a>'
            : '';
        ?>
        <p class="account__error" data-account-error role="alert" <?= $flashIsError ? '' : 'hidden' ?>><?= $flashIsError ? e($flash['message']) . $flashLink : '' ?></p>

        <?php if ($flash !== null && !$flashIsError): ?>
            <p class="account__notice" data-account-flash role="status"><?= e($flash['message']) . $flashLink ?></p>
        <?php endif; ?>

        <?php if ($signedIn): ?>

            <?php
            /* The Vrienden row says where things stand without opening it. */
            $friendCount  = count($data['community']['friends'] ?? []);
            ?>
            <div class="account__view" data-account-view="main">

            <?php /* B5 (50.0, the owner's pick "List"): the popup is a summary — you
                     as the first row (photo, username, real name), which opens
                     Instellingen › Account where everything is changed; Vrienden
                     with its count and red dot; Uitloggen. */
            $realName = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
            ?>
            <button type="button" class="account__nav account__me press" data-account-goto="settings-account"
                    aria-label="<?= e(tf('%s — Account openen', (string) $user['username'])) ?>">
                <span class="account__avatar friend__avatar" data-account-avatar aria-hidden="true">
                    <?php if (!empty($user['avatar_path'])): ?>
                        <img src="<?= e($user['avatar_path']) ?>" alt="">
                    <?php else: ?>
                        <?= icon('user') ?>
                    <?php endif; ?>
                </span>
                <span class="friend__text">
                    <span class="friend__name" data-account-username><?= e($user['username']) ?></span>
                    <?php if ($realName !== ''): ?><span class="friend__status"><?= e($realName) ?></span><?php endif; ?>
                </span>
                <?= icon('chevron-right', 'account__nav-icon') ?>
            </button>

            <div class="account__form">
                <button type="button" class="account__nav press" data-friends-open
                        aria-label="<?= e(t('Vrienden')) ?>">
                    <span class="account__nav-summary" data-friends-summary>
                        <span class="account__nav-text"><span><?= e(t('Vrienden')) ?></span><?= notice_dot('friends', 'beside') ?></span>
                        <span class="settings-row__value"><?= $friendCount === 0 ? e(t('Nog geen')) : e(i18n_number($friendCount)) ?></span>
                    </span>
                    <?= icon('chevron-right', 'account__nav-icon') ?>
                </button>
            </div>

            <form class="account__form" data-account-form="logout">
                <button type="submit" class="btn settings-logout press account__logout"><?= icon('logout', 'settings-logout__icon') ?><?= e(t('Uitloggen')) ?></button>
            </form>

            </div>

            <?php component('account-friends', $data); ?>

        <?php elseif ($pending !== null): ?>

            <?php /* The one step between Google and an account. Google has
                     already vouched for who this is; the identity waits in the
                     server session, and the account only exists once this
                     form is accepted. */ ?>
            <div class="account__step" data-account-step="google-username" data-step-title="<?= e(t('Kies je gebruikersnaam')) ?>">
                <p class="account__meta">
                    <?= e(tf('Google heeft %s bevestigd. Kies nog een gebruikersnaam; daarna is je account klaar.', (string) $pending['email'])) ?>
                </p>

                <form class="account__form" data-account-form="google-username" novalidate>
                    <div class="account__field">
                        <label class="account__label" for="account-google-username"><?= e(t('Gebruikersnaam')) ?></label>
                        <input class="account__input" type="text" id="account-google-username" name="username"
                               minlength="3" maxlength="30" autocomplete="username" spellcheck="false"
                               autocapitalize="none" required>
                        <p class="account__hint"><?= e(t('3 tot 30 tekens: letters, cijfers, punt, streepje of underscore.')) ?></p>
                    </div>

                    <button type="submit" class="btn btn--primary account__submit press"><?= e(t('Account aanmaken')) ?></button>
                </form>

                <form class="account__form" data-account-form="google-cancel">
                    <button type="submit" class="btn press account__logout"><?= e(t('Annuleren')) ?></button>
                </form>

                <p class="account__hint account__hint--centred">
                    <?= e(tn((int) $pending['minutes'], 'Er wordt niets opgeslagen tot je een gebruikersnaam kiest. Deze stap verloopt over %d minuut.', 'Er wordt niets opgeslagen tot je een gebruikersnaam kiest. Deze stap verloopt over %d minuten.')) ?>
                </p>
            </div>

        <?php else: ?>

            <div data-account-step="form" <?= $registration !== null ? 'hidden' : '' ?>>

            <?php if (!$canRegister): ?>
                <p class="account__hint account__hint--centred" data-account-only="register" hidden>
                    <?= e(t('Registreren met e-mail is tijdelijk niet beschikbaar.')) ?>
                </p>
            <?php endif; ?>

            <form class="account__form" data-account-form="email" novalidate
                  <?= $canRegister ? '' : 'data-account-register-closed' ?>>

                <div class="account__field">
                    <label class="account__label" for="account-username"><?= e(t('Gebruikersnaam')) ?></label>
                    <input class="account__input" type="text" id="account-username" name="username"
                           maxlength="30" autocomplete="username" spellcheck="false" required>
                </div>

                <?php /* Only registration needs an address; signing in resolves
                         the username itself. */ ?>
                <div class="account__field" data-account-only="register" hidden>
                    <label class="account__label" for="account-email"><?= e(t('E-mailadres')) ?></label>
                    <input class="account__input" type="email" id="account-email" name="email"
                           autocomplete="email">
                </div>

                <div class="account__field">
                    <label class="account__label" for="account-password"><?= e(t('Wachtwoord')) ?></label>
                    <input class="account__input" type="password" id="account-password" name="password"
                           minlength="8" autocomplete="current-password" required>
                    <?php if ($accountMail): ?>
                        <p class="account__hint" data-account-only="login">
                            <a class="quiet-link" href="?wachtwoord-vergeten" data-account-forgot><?= e(t('Wachtwoord vergeten?')) ?></a>
                        </p>
                    <?php endif; ?>
                </div>

                <?php /* No repeat field when registering (45.0): the eye in the
                         field shows what was typed. Changing a password keeps it. */ ?>
                <button type="submit" class="btn btn--primary account__submit press" data-account-submit><?= e(t('Inloggen')) ?></button>
            </form>

            <?php /* Google is secondary: a small mark, no label. It is a
                     brand mark rather than an icon — four-colour — so it
                     cannot come from icons.php, which is one stroked family
                     by design. */ ?>
            <div class="account__socials">
                <button type="button" class="social press" data-account-provider="google"
                        aria-label="<?= e(t('Doorgaan met Google')) ?>" <?= $providers['google'] ? '' : 'disabled' ?>>
                    <svg class="social__mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09Z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23Z"/>
                        <path fill="#FBBC05" d="M5.84 14.1c-.22-.66-.35-1.36-.35-2.1s.13-1.44.35-2.1V7.07H2.18A10.99 10.99 0 0 0 1 12c0 1.78.43 3.45 1.18 4.93l3.66-2.83Z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.83C6.71 7.31 9.14 5.38 12 5.38Z"/>
                    </svg>
                </button>
            </div>

            <?php if (!$providers['google']): ?>
                <p class="account__hint account__hint--centred"><?= e(t('Google is nog niet gekoppeld.')) ?></p>
            <?php endif; ?>

            </div>

            <?php if ($accountMail): ?>

                <?php /* A registration waiting for its link (docs/ACCOUNT-SECURITY.md
                         §3). A taken address shows exactly the same. Nothing is
                         signed in until the link is used. */ ?>
                <div class="account__step" data-account-step="verify" data-step-title="<?= e(t('Bevestig je e-mailadres')) ?>" <?= $registration === null ? 'hidden' : '' ?>>
                    <p class="account__meta" data-verify-line
                       data-template="<?= e(t('We hebben een link gestuurd naar %s. Open die link om je account af te maken.')) ?>">
                        <?= $registration !== null ? e(tf('We hebben een link gestuurd naar %s. Open die link om je account af te maken.', (string) $registration['email_masked'])) : '' ?>
                    </p>

                    <form class="account__form" data-account-form="verify-check">
                        <button type="submit" class="btn btn--primary account__submit press"><?= e(t('Ik heb bevestigd')) ?></button>
                    </form>
                    <form class="account__form" data-account-form="verify-resend">
                        <button type="submit" class="btn press account__logout"><?= e(t('Stuur opnieuw')) ?></button>
                    </form>
                    <form class="account__form" data-account-form="verify-cancel">
                        <button type="submit" class="btn press account__logout"><?= e(t('Ander adres gebruiken')) ?></button>
                    </form>

                    <p class="account__hint account__hint--centred" data-verify-status role="status" hidden></p>
                    <p class="account__hint account__hint--centred">
                        <?= e(t('Geen e-mail gekregen? Kijk ook in je spam. De link werkt 24 uur.')) ?>
                    </p>
                </div>

                <?php /* Wachtwoord vergeten (§7B): one answer, whatever the address. */ ?>
                <div class="account__step" data-account-step="forgot" data-step-title="<?= e(t('Wachtwoord vergeten')) ?>" hidden>
                    <p class="account__meta" data-forgot-line role="status"
                       data-ask="<?= e(t('Vul het e-mailadres van je account in. Je krijgt een e-mail met een link om een nieuw wachtwoord te kiezen.')) ?>"><?= e(t('Vul het e-mailadres van je account in. Je krijgt een e-mail met een link om een nieuw wachtwoord te kiezen.')) ?></p>

                    <form class="account__form" data-account-form="forgot" novalidate>
                        <div class="account__field">
                            <label class="account__label" for="account-forgot-email"><?= e(t('E-mailadres')) ?></label>
                            <input class="account__input" type="email" id="account-forgot-email" name="email"
                                   autocomplete="email" spellcheck="false" autocapitalize="none" required>
                        </div>
                        <button type="submit" class="btn btn--primary account__submit press"><?= e(t('Stuur link')) ?></button>
                    </form>
                    <form class="account__form" data-account-form="forgot-back">
                        <button type="submit" class="btn press account__logout"><?= e(t('Terug naar inloggen')) ?></button>
                    </form>
                </div>

            <?php endif; ?>

        <?php endif; ?>

        <?php /* The page a link in an account e-mail opens (§7): opening only
                 reads; the tap — or the new password — uses it. Signed in or
                 not. The words per kind of link come with the page. */
        $linkWords = [
            'verify_signup'  => [t('Bevestig je e-mailadres'), t('Bevestig dat %1$s van jou is, %2$s.'), t('Bevestigen')],
            'verify_link'    => [t('Koppel dit e-mailadres'), t('Koppel %1$s aan je Ownify-account %2$s.'), t('Koppelen')],
            'verify_change'  => [t('Bevestig je nieuwe e-mailadres'), t('Daarna log je met %1$s in op je Ownify-account %2$s.'), t('Bevestigen')],
            'password_reset' => [t('Kies een nieuw wachtwoord'), t('Voor je Ownify-account %2$s. Daarna word je overal uitgelogd.'), t('Wachtwoord opslaan')],
            'revert_email'   => [t('Zet je oude adres terug'), t('Het e-mailadres van %2$s is gewijzigd op %4$s. Hiermee komt %3$s terug en kies je een nieuw wachtwoord. Daarna word je overal uitgelogd.'), t('Zet mijn oude adres terug')],
            'gone'           => [t('Deze link werkt niet meer'), t('Deze link is verlopen of al gebruikt.'), ''],
        ];
        ?>
        <div class="account__step" data-account-step="link" hidden
             data-link-words="<?= e(json_encode($linkWords, JSON_UNESCAPED_UNICODE)) ?>">
            <p class="account__username" data-link-title></p>
            <p class="account__meta" data-link-line role="status"></p>

            <form class="account__form" data-account-form="link" novalidate hidden>
                <div data-link-passwords hidden>
                    <div class="account__field">
                        <label class="account__label" for="account-link-password"><?= e(t('Nieuw wachtwoord')) ?></label>
                        <input class="account__input" type="password" id="account-link-password" name="password"
                               minlength="8" maxlength="200" autocomplete="new-password">
                    </div>
                    <div class="account__field">
                        <label class="account__label" for="account-link-password-confirm"><?= e(t('Herhaal nieuw wachtwoord')) ?></label>
                        <input class="account__input" type="password" id="account-link-password-confirm" name="password_confirm"
                               minlength="8" maxlength="200" autocomplete="new-password">
                    </div>
                    <p class="account__hint"><?= e(t('Minimaal 8 tekens.')) ?></p>
                </div>
                <button type="submit" class="btn btn--primary account__submit press" data-link-submit></button>
            </form>

            <form class="account__form" data-account-form="link-done" hidden>
                <button type="submit" class="btn btn--primary account__submit press"><?= e(t('Doorgaan')) ?></button>
            </form>
        </div>

        </div>
    </div>
</div>
