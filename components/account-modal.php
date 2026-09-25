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
            <?php if ($signedIn): ?>
                <?php /* Back from the Vrienden page to the account. Only there. */ ?>
                <button type="button" class="account__close account__back press" data-friends-back
                        aria-label="Terug naar account" hidden>
                    <?= icon('chevron-left') ?>
                </button>
            <?php endif; ?>

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

            <?php
            /* The Vrienden button says where things stand without opening it. */
            $friendCount  = count($data['community']['friends'] ?? []);
            $requestCount = count($data['community']['pending'] ?? []);
            ?>
            <div class="account__view" data-account-view="main">

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

            <div class="account__form">
                <p class="account__label" id="account-friends-label">Vrienden</p>
                <button type="button" class="account__nav press" data-friends-open
                        aria-labelledby="account-friends-label account-friends-summary">
                    <span class="account__nav-text" id="account-friends-summary" data-friends-summary>
                        <span><?= e($friendCount === 0 ? 'Nog geen vrienden' : $friendCount . ($friendCount === 1 ? ' vriend' : ' vrienden')) ?></span>
                        <?php if ($requestCount > 0): ?>
                            <span class="account__badge"><?= e($requestCount . ($requestCount === 1 ? ' verzoek' : ' verzoeken')) ?></span>
                        <?php endif; ?>
                    </span>
                    <?= icon('chevron-right', 'account__nav-icon') ?>
                </button>
            </div>

            <form class="account__form" data-account-form="logout">
                <button type="submit" class="btn btn--ghost press account__logout">Uitloggen</button>
            </form>

            </div>

            <?php component('account-friends', $data); ?>

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

            <?php /* Google is secondary: a small mark, no label. It is a
                     brand mark rather than an icon — four-colour — so it
                     cannot come from icons.php, which is one stroked family
                     by design. */ ?>
            <div class="account__socials">
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

            <?php if (!$providers['google']): ?>
                <p class="account__hint account__hint--centred">Google is nog niet gekoppeld.</p>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>
