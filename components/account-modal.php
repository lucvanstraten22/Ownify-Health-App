<?php
/**
 * The account panel: behind the header's account button in the app, and
 * behind the opening screen's two buttons for everyone not signed in.
 *
 * Signed in, it shows the account and what can be changed. Deliberately not
 * a profile page.
 *
 * Signed out, it is one of two flows and never both: Inloggen or
 * Registreren, whichever button opened it (account.js). The same form serves
 * both — the one field only registering needs is hidden while logging in —
 * and says which it is in its title.
 *
 * Signing in takes a username and a password. Apple and Google are two small
 * marks under the form rather than two full-width buttons above it — they are
 * secondary. Google works once it is configured (includes/google-signin.php);
 * Apple is not implemented. Whichever is not available renders disabled.
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
$autoOpen  = $flash !== null || $pending !== null;

/* The two flows, and what the panel is called in each. */
$flows     = !$signedIn && $pending === null;
$titles    = ['login' => $data['welcome']['login'], 'register' => $data['welcome']['register']];
?>
<div class="account" data-overlay data-account data-csrf="<?= e($auth['csrf']) ?>"
     data-account-session="<?= $signedIn ? 'signed-in' : 'signed-out' ?>"
     <?= $autoOpen ? 'data-account-autoopen' : '' ?> hidden>

    <div class="account__scrim" data-account-close></div>

    <div class="account__panel card" role="dialog" aria-modal="true" aria-labelledby="account-title">

        <div class="account__head">
            <?php /* Signed in, or choosing a Google username, this is the
                     account. Otherwise it names the one flow it is on. */ ?>
            <h2 class="card__eyebrow" id="account-title"
                <?php if ($flows): ?>data-account-title data-title-login="<?= e($titles['login']) ?>" data-title-register="<?= e($titles['register']) ?>"<?php endif; ?>>
                <?= e($flows ? $titles['login'] : 'Account') ?>
            </h2>
            <button type="button" class="account__close press" data-account-close aria-label="Sluiten">
                <?= icon('chevron-down') ?>
            </button>
        </div>

        <?php if (!$auth['database']): ?>
            <p class="account__notice">
                Geen databaseverbinding. Importeer <code>database/schema.sql</code> en
                controleer <code>config/database.php</code>.
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

            <div class="account__identity">
                <span class="account__avatar" data-account-avatar>
                    <?php if (!empty($user['avatar_path'])): ?>
                        <img src="<?= e($user['avatar_path']) ?>" alt="">
                    <?php else: ?>
                        <?= icon('user') ?>
                    <?php endif; ?>
                </span>
                <div class="account__identity-text">
                    <p class="account__username" data-account-username><?= e($user['username']) ?></p>
                    <p class="account__meta">Lid sinds <?= e(date('j M Y', strtotime($user['created_at']))) ?></p>
                </div>
            </div>

            <form class="account__form" data-account-form="username" novalidate>
                <label class="account__label" for="account-username">Gebruikersnaam</label>
                <div class="account__row">
                    <input class="account__input" type="text" id="account-username" name="username"
                           value="<?= e($user['username']) ?>" minlength="3" maxlength="30"
                           autocomplete="username" spellcheck="false" required>
                    <button type="submit" class="btn press">Opslaan</button>
                </div>
            </form>

            <form class="account__form" data-account-form="avatar" novalidate>
                <label class="account__label" for="account-avatar">Profielfoto</label>
                <div class="account__row">
                    <input class="account__file" type="file" id="account-avatar" name="avatar"
                           accept="image/jpeg,image/png,image/webp" required>
                    <button type="submit" class="btn press">Uploaden</button>
                </div>
                <p class="account__hint">JPG, PNG of WebP, maximaal 3 MB.</p>
            </form>

            <form class="account__form" data-account-form="logout">
                <button type="submit" class="btn btn--ghost press account__logout">Uitloggen</button>
            </form>

        <?php elseif ($pending !== null): ?>

            <?php /* The one step between Google and an account. Google has
                     already vouched for who this is; the identity waits in the
                     server session, and the account only exists once this
                     form is accepted. */ ?>
            <div class="account__step" data-account-step="google-username">
                <p class="account__username">Kies je gebruikersnaam</p>
                <p class="account__meta">
                    Google heeft <?= e($pending['email']) ?> bevestigd. Kies nog een
                    gebruikersnaam; daarna is je account klaar.
                </p>

                <form class="account__form" data-account-form="google-username" novalidate>
                    <div class="account__field">
                        <label class="account__label" for="account-google-username">Gebruikersnaam</label>
                        <input class="account__input" type="text" id="account-google-username" name="username"
                               minlength="3" maxlength="30" autocomplete="username" spellcheck="false"
                               autocapitalize="none" required>
                        <p class="account__hint">3 tot 30 tekens: letters, cijfers, punt, streepje of underscore.</p>
                    </div>

                    <button type="submit" class="btn account__submit press">Account aanmaken</button>
                </form>

                <form class="account__form" data-account-form="google-cancel">
                    <button type="submit" class="btn btn--ghost press account__logout">Annuleren</button>
                </form>

                <p class="account__hint account__hint--centred">
                    Er wordt niets opgeslagen tot je een gebruikersnaam kiest. Deze stap
                    verloopt over <?= e((string) $pending['minutes']) ?> <?= $pending['minutes'] === 1 ? 'minuut' : 'minuten' ?>.
                </p>
            </div>

        <?php else: ?>

            <form class="account__form" data-account-form="email" novalidate>

                <div class="account__field">
                    <label class="account__label" for="account-username">Gebruikersnaam</label>
                    <input class="account__input" type="text" id="account-username" name="username"
                           maxlength="30" autocomplete="username" spellcheck="false" required>
                </div>

                <?php /* Only registration needs an address; signing in resolves
                         the username itself. */ ?>
                <div class="account__field" data-account-only="register" hidden>
                    <label class="account__label" for="account-email">E-mailadres</label>
                    <input class="account__input" type="email" id="account-email" name="email"
                           autocomplete="email">
                </div>

                <div class="account__field">
                    <label class="account__label" for="account-password">Wachtwoord</label>
                    <input class="account__input" type="password" id="account-password" name="password"
                           minlength="8" autocomplete="current-password" required>
                </div>

                <button type="submit" class="btn account__submit press" data-account-submit>Inloggen</button>
            </form>

            <?php /* Apple and Google are secondary: two small marks, no labels.
                     They are brand marks rather than icons — filled, and in
                     Google's case four-colour — so they cannot come from
                     icons.php, which is one stroked family by design. */ ?>
            <div class="account__socials">
                <button type="button" class="social press" data-account-provider="apple"
                        aria-label="Doorgaan met Apple" <?= $providers['apple'] ? '' : 'disabled' ?>>
                    <svg class="social__mark" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                        <path d="M16.36 12.73c-.02-2.4 1.96-3.55 2.05-3.61-1.12-1.63-2.86-1.86-3.48-1.88-1.48-.15-2.89.87-3.64.87-.75 0-1.91-.85-3.14-.83-1.61.02-3.1.94-3.93 2.38-1.68 2.91-.43 7.22 1.2 9.58.8 1.16 1.75 2.45 3 2.4 1.2-.05 1.66-.78 3.11-.78 1.45 0 1.86.78 3.13.75 1.29-.02 2.11-1.17 2.9-2.34.91-1.34 1.29-2.64 1.31-2.71-.03-.01-2.51-.96-2.53-3.83Z"/>
                        <path d="M14.13 5.63c.66-.8 1.11-1.92.99-3.03-.95.04-2.11.63-2.79 1.43-.61.71-1.15 1.85-1.01 2.94 1.06.08 2.15-.54 2.81-1.34Z"/>
                    </svg>
                </button>

                <button type="button" class="social press" data-account-provider="google"
                        aria-label="Doorgaan met Google" <?= $providers['google'] ? '' : 'disabled' ?>>
                    <svg class="social__mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09Z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23Z"/>
                        <path fill="#FBBC05" d="M5.84 14.1c-.22-.66-.35-1.36-.35-2.1s.13-1.44.35-2.1V7.07H2.18A10.99 10.99 0 0 0 1 12c0 1.78.43 3.45 1.18 4.93l3.66-2.83Z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.83C6.71 7.31 9.14 5.38 12 5.38Z"/>
                    </svg>
                </button>
            </div>

            <?php if (!$providers['apple'] && !$providers['google']): ?>
                <p class="account__hint account__hint--centred">Apple en Google zijn nog niet gekoppeld.</p>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>
