<?php
/**
 * The account panel behind the header's account button.
 *
 * Two states, one dialog: signed out shows the sign-in options, signed in
 * shows the account and what can be changed. Deliberately not a profile page.
 */
declare(strict_types=1);

$auth      = $data['auth'];
$user      = $auth['user'];
$signedIn  = $auth['signed_in'];
$providers = $auth['providers'];
?>
<div class="account" data-account data-csrf="<?= e($auth['csrf']) ?>" hidden>

    <div class="account__scrim" data-account-close></div>

    <div class="account__panel card" role="dialog" aria-modal="true" aria-labelledby="account-title">

        <div class="account__head">
            <h2 class="card__eyebrow" id="account-title">
                <?= $signedIn ? 'Account' : 'Inloggen' ?>
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

        <p class="account__error" data-account-error role="alert" hidden></p>

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

        <?php else: ?>

            <div class="account__providers">
                <?php foreach (['apple' => 'Apple', 'google' => 'Google'] as $key => $label): ?>
                    <button type="button" class="btn account__provider press"
                            data-account-provider="<?= e($key) ?>"
                            <?= $providers[$key] ? '' : 'disabled' ?>>
                        Doorgaan met <?= e($label) ?>
                    </button>
                <?php endforeach; ?>
                <?php if (!$providers['apple'] && !$providers['google']): ?>
                    <p class="account__hint">Apple en Google zijn nog niet gekoppeld.</p>
                <?php endif; ?>
            </div>

            <div class="account__divider"><span>of met e-mail</span></div>

            <div class="range-switch range-switch--wide" role="group" aria-label="Kies inloggen of registreren">
                <button type="button" class="range-switch__option is-active" data-account-mode="login" aria-pressed="true">Inloggen</button>
                <button type="button" class="range-switch__option" data-account-mode="register" aria-pressed="false">Account maken</button>
            </div>

            <form class="account__form" data-account-form="email" novalidate>
                <div class="account__field" data-account-only="register" hidden>
                    <label class="account__label" for="account-new-username">Gebruikersnaam</label>
                    <input class="account__input" type="text" id="account-new-username" name="username"
                           minlength="3" maxlength="30" autocomplete="username" spellcheck="false">
                </div>

                <div class="account__field">
                    <label class="account__label" for="account-email">E-mailadres</label>
                    <input class="account__input" type="email" id="account-email" name="email"
                           autocomplete="email" required>
                </div>

                <div class="account__field">
                    <label class="account__label" for="account-password">Wachtwoord</label>
                    <input class="account__input" type="password" id="account-password" name="password"
                           minlength="8" autocomplete="current-password" required>
                </div>

                <button type="submit" class="btn account__submit press" data-account-submit>Inloggen</button>
            </form>

        <?php endif; ?>

    </div>
</div>
