<?php
/**
 * Settings › Account › Inloggen, Release B (35.0, docs/ACCOUNT-SECURITY.md):
 * the rows when the account e-mails are on. The same rows as Release A's —
 * settings-field, the status dot, the chevron — and the same field editor
 * (settings.js, type `fields`) for everything that asks something:
 *
 *   E-mailadres          the address and whether it is confirmed; Wijzigen
 *                        (new address + current password) — or, without a
 *                        password, Google first
 *   Inloggen met e-mail  a Google account adds an address (Google first)
 *   Bevestiging          "Stuur bevestiging" for an unconfirmed address, or
 *                        why it cannot be confirmed (another account has it)
 *   Wachtwoord           Wijzigen; or Instellen for a Google account with a
 *                        confirmed address (Google first)
 *   Google               Koppel Google (password, then Google) or Loskoppelen
 *                        (password; two steps: the row, then the editor)
 *
 * A row that cannot act yet says what is missing instead of hiding.
 */
declare(strict_types=1);

$signin    = $data['signin'];
$googleOn  = (bool) $data['googleOn'];
$reauthFor = $data['auth']['reauth_for'] ?? [];
$email     = $signin['email'];
$hasPw     = !empty($signin['password']);
$verified  = $email !== null ? !empty($signin['email_verified']) : $signin['google_email'] !== null;
$address   = $email ?? $signin['google_email'];

/** A field-editor spec as the row carries it. */
$spec = static fn (array $spec): string => e(json_encode($spec, JSON_UNESCAPED_UNICODE));

/** The editor that only says "Google first" and goes there (api/auth/reauth.php). */
$googleFirst = static fn (string $purpose, string $note): array => [
    'type'     => 'fields',
    'inputs'   => [],
    'note'     => $note,
    'save'     => t('Naar Google'),
    'endpoint' => 'api/auth/reauth.php',
    'fields'   => ['action' => 'google', 'purpose' => $purpose],
    'next'     => $purpose,
];

$passwordInput = ['name' => 'password', 'label' => t('Huidig wachtwoord'), 'type' => 'password', 'autocomplete' => 'current-password'];
?>

<?php /* ---------------------------------------------------- E-mailadres */ ?>
<?php if ($email !== null && !empty($signin['can_change_email'])):
    $ready  = $hasPw || in_array('change_email', $reauthFor, true);
    $editor = $ready ? [
        'type'     => 'fields',
        'inputs'   => array_values(array_filter([
            ['name' => 'email', 'label' => t('Nieuw e-mailadres'), 'type' => 'email', 'autocomplete' => 'email'],
            $hasPw ? $passwordInput : null,
        ])),
        'note'     => t('We sturen een link naar het nieuwe adres. Tot je die opent, blijft alles zoals het is.'),
        'save'     => t('Stuur link'),
        'endpoint' => 'api/account/email.php',
    ] : $googleFirst('change_email', t('Bevestig eerst met Google. Daarna vul je binnen 5 minuten je nieuwe adres in.'));
    ?>
    <button type="button" class="settings-field press is-editable is-filled" data-signin-row="email"
            data-field-edit="email" data-field-label="<?= e(t('E-mailadres wijzigen')) ?>"
            data-field-input="<?= $spec($editor) ?>" <?= $ready ? 'data-signin-ready' : '' ?>>
        <span class="settings-field__text">
            <span class="settings-field__label"><?= e(t('E-mailadres')) ?></span>
            <span class="settings-field__note">
                <?php if (!empty($signin['pending_email'])): ?>
                    <?= e(tf('%s wacht op bevestiging', (string) $signin['pending_email'])) ?>
                <?php elseif ($verified): ?>
                    <span class="status" data-tone="on"><span class="status__dot" aria-hidden="true"></span><span class="status__text"><?= e(t('Bevestigd')) ?></span></span>
                <?php else: ?>
                    <span class="status" data-tone="warn"><span class="status__dot" aria-hidden="true"></span><span class="status__text"><?= e(t('Niet bevestigd')) ?></span></span>
                <?php endif; ?>
            </span>
        </span>
        <span class="settings-field__value"><?= e($email) ?></span>
        <?= icon('chevron-right', 'settings-field__mark') ?>
    </button>
<?php else: ?>
    <div class="settings-field <?= $address !== null ? 'is-filled' : 'is-empty' ?>" data-signin-row="email">
        <span class="settings-field__text">
            <span class="settings-field__label"><?= e(t('E-mailadres')) ?></span>
            <span class="settings-field__note">
                <?php if ($address === null): ?>
                    <?= e(t('Je logt in met Google')) ?>
                <?php elseif ($verified): ?>
                    <span class="status" data-tone="on"><span class="status__dot" aria-hidden="true"></span><span class="status__text"><?= e($email !== null ? t('Bevestigd') : t('Bevestigd door Google')) ?></span></span>
                <?php else: ?>
                    <span class="status" data-tone="warn"><span class="status__dot" aria-hidden="true"></span><span class="status__text"><?= e(t('Niet bevestigd')) ?></span></span>
                <?php endif; ?>
            </span>
        </span>
        <span class="settings-field__value"><?= e($address ?? t('Niet ingesteld')) ?></span>
    </div>
<?php endif; ?>

<?php /* --------------------------------- a Google account adds an address */ ?>
<?php if (!empty($signin['can_add_email'])):
    $ready  = in_array('add_email', $reauthFor, true);
    $editor = $ready ? [
        'type'     => 'fields',
        'inputs'   => [['name' => 'email', 'label' => t('E-mailadres'), 'type' => 'email', 'autocomplete' => 'email']],
        'note'     => t('We sturen een link naar dit adres. Daarna kun je ook een wachtwoord instellen.'),
        'save'     => t('Stuur link'),
        'endpoint' => 'api/account/email.php',
    ] : $googleFirst('add_email', t('Bevestig eerst met Google. Daarna vul je binnen 5 minuten het adres in.'));
    ?>
    <button type="button" class="settings-field press is-editable is-empty" data-signin-row="email-add"
            data-field-edit="email-add" data-field-label="<?= e(t('E-mailadres toevoegen')) ?>"
            data-field-input="<?= $spec($editor) ?>" <?= $ready ? 'data-signin-ready' : '' ?>>
        <span class="settings-field__text">
            <span class="settings-field__label"><?= e(t('Inloggen met e-mail')) ?></span>
            <span class="settings-field__note">
                <?= e(!empty($signin['pending_email'])
                    ? tf('%s wacht op bevestiging', (string) $signin['pending_email'])
                    : t('Voeg een adres toe om ook met een wachtwoord in te loggen')) ?>
            </span>
        </span>
        <span class="settings-field__value"><?= e(t('Toevoegen')) ?></span>
        <?= icon('chevron-right', 'settings-field__mark') ?>
    </button>
<?php endif; ?>

<?php /* ---------------------------------------------------- Bevestiging */ ?>
<?php if (!empty($signin['can_verify'])): ?>
    <button type="button" class="settings-field press is-editable is-empty" data-signin-row="verify" data-signin-verify>
        <span class="settings-field__text">
            <span class="settings-field__label"><?= e(t('Bevestiging')) ?></span>
            <span class="settings-field__note" data-signin-verify-note role="status"><?= e(t('Stuur een link naar je adres om het te bevestigen')) ?></span>
        </span>
        <span class="settings-field__value"><?= e(t('Stuur bevestiging')) ?></span>
        <?= icon('chevron-right', 'settings-field__mark') ?>
    </button>
<?php elseif (!empty($signin['email_taken'])): ?>
    <div class="settings-field is-empty" data-signin-row="verify">
        <span class="settings-field__text">
            <span class="settings-field__label"><?= e(t('Bevestiging')) ?></span>
            <span class="settings-field__note"><?= e(t('Dit adres hoort al bij een ander Ownify-account. Kies een ander adres via E-mailadres.')) ?></span>
        </span>
        <span class="settings-field__value">—</span>
    </div>
<?php endif; ?>

<?php /* ----------------------------------------------------- Wachtwoord */ ?>
<?php if (!empty($signin['can_change_password'])): ?>
    <button type="button" class="settings-field press is-editable is-filled" data-signin-row="password"
            data-field-edit="password"
            data-field-label="<?= e(t('Wachtwoord wijzigen')) ?>"
            data-field-input="<?= e(json_encode(['type' => 'passwords', 'endpoint' => 'api/account/password.php'])) ?>">
        <span class="settings-field__text">
            <span class="settings-field__label"><?= e(t('Wachtwoord')) ?></span>
            <span class="settings-field__note"><?= e(t('Inloggen met je gebruikersnaam of e-mailadres')) ?></span>
        </span>
        <span class="settings-field__value"><?= e(t('Wijzigen')) ?></span>
        <?= icon('chevron-right', 'settings-field__mark') ?>
    </button>
<?php elseif (!empty($signin['can_set_password'])):
    $ready  = in_array('set_password', $reauthFor, true);
    $editor = $ready ? [
        'type'     => 'fields',
        'inputs'   => [
            ['name' => 'password', 'label' => t('Nieuw wachtwoord'), 'type' => 'password', 'autocomplete' => 'new-password', 'min' => 8],
            ['name' => 'password_confirm', 'label' => t('Herhaal nieuw wachtwoord'), 'type' => 'password', 'autocomplete' => 'new-password', 'min' => 8],
        ],
        'note'     => t('Minimaal 8 tekens. Daarna kun je ook met je e-mailadres en wachtwoord inloggen.'),
        'save'     => t('Instellen'),
        'endpoint' => 'api/account/set-password.php',
    ] : $googleFirst('set_password', t('Bevestig eerst met Google. Daarna stel je binnen 5 minuten je wachtwoord in.'));
    ?>
    <button type="button" class="settings-field press is-editable is-empty" data-signin-row="password"
            data-field-edit="password" data-field-label="<?= e(t('Wachtwoord instellen')) ?>"
            data-field-input="<?= $spec($editor) ?>" <?= $ready ? 'data-signin-ready' : '' ?>>
        <span class="settings-field__text">
            <span class="settings-field__label"><?= e(t('Wachtwoord')) ?></span>
            <span class="settings-field__note"><?= e(t('Niet ingesteld')) ?></span>
        </span>
        <span class="settings-field__value"><?= e(t('Instellen')) ?></span>
        <?= icon('chevron-right', 'settings-field__mark') ?>
    </button>
<?php else: ?>
    <div class="settings-field <?= $hasPw ? 'is-filled' : 'is-empty' ?>" data-signin-row="password">
        <span class="settings-field__text">
            <span class="settings-field__label"><?= e(t('Wachtwoord')) ?></span>
            <span class="settings-field__note">
                <?= e($hasPw ? t('Dit kan even niet. Probeer het later opnieuw.')
                    : ($email === null ? t('Voeg eerst een e-mailadres toe.') : t('Bevestig eerst je e-mailadres.'))) ?>
            </span>
        </span>
        <span class="settings-field__value"><?= e($hasPw ? t('Ingesteld') : t('Niet ingesteld')) ?></span>
    </div>
<?php endif; ?>

<?php /* --------------------------------------------------------- Google */ ?>
<?php if (!empty($signin['google']) && !empty($signin['can_remove_google'])): ?>
    <button type="button" class="settings-field press is-editable is-filled" data-signin-row="google"
            data-field-edit="google" data-field-label="<?= e(t('Google loskoppelen')) ?>"
            data-field-input="<?= $spec([
                'type'     => 'fields',
                'inputs'   => [$passwordInput],
                'note'     => t('Je logt daarna in met je gebruikersnaam of e-mailadres en je wachtwoord. Je andere apparaten worden uitgelogd.'),
                'save'     => t('Loskoppelen'),
                'tone'     => 'caution',
                'endpoint' => 'api/account/google.php',
                'fields'   => ['action' => 'remove'],
            ]) ?>">
        <span class="settings-field__text">
            <span class="settings-field__label">Google</span>
            <span class="settings-field__note"><?= e(t('Gekoppeld — je kunt ook met Google inloggen')) ?></span>
        </span>
        <span class="settings-field__value"><?= e($signin['google_email'] ?? t('Gekoppeld')) ?></span>
        <?= icon('chevron-right', 'settings-field__mark') ?>
    </button>
<?php elseif (!empty($signin['google'])): ?>
    <div class="settings-field is-filled" data-signin-row="google">
        <span class="settings-field__text">
            <span class="settings-field__label">Google</span>
            <span class="settings-field__note">
                <?= e(!$hasPw ? t('Gekoppeld. Loskoppelen kan zodra je een wachtwoord hebt.')
                    : (!$verified ? t('Gekoppeld. Loskoppelen kan zodra je e-mailadres bevestigd is.') : t('Gekoppeld — je kunt ook met Google inloggen'))) ?>
            </span>
        </span>
        <span class="settings-field__value"><?= e($signin['google_email'] ?? t('Gekoppeld')) ?></span>
        <?= icon('check', 'settings-field__mark') ?>
    </div>
<?php elseif ($googleOn && !empty($signin['can_link_google'])): ?>
    <button type="button" class="settings-field press is-editable is-empty" data-signin-row="google"
            data-field-edit="google" data-field-label="<?= e(t('Google koppelen')) ?>"
            data-field-input="<?= $spec([
                'type'     => 'fields',
                'inputs'   => [$passwordInput],
                'note'     => t('Daarna kies je je Google-account. Je kunt dan ook met Google inloggen.'),
                'save'     => t('Naar Google'),
                'endpoint' => 'api/auth/oauth.php',
                'fields'   => ['provider' => 'google', 'mode' => 'link'],
            ]) ?>">
        <span class="settings-field__text">
            <span class="settings-field__label">Google</span>
            <span class="settings-field__note"><?= e(t('Koppel Google om daarmee in te loggen')) ?></span>
        </span>
        <span class="settings-field__value"><?= e(t('Koppel Google')) ?></span>
        <?= icon('chevron-right', 'settings-field__mark') ?>
    </button>
<?php else: ?>
    <div class="settings-field is-empty" data-signin-row="google">
        <span class="settings-field__text">
            <span class="settings-field__label">Google</span>
            <?php if ($googleOn): ?>
                <span class="settings-field__note"><?= e(t('Bevestig eerst je e-mailadres.')) ?></span>
            <?php endif; ?>
        </span>
        <span class="settings-field__value"><?= e($googleOn ? t('Niet gekoppeld') : t('Nog niet beschikbaar')) ?></span>
    </div>
<?php endif; ?>
