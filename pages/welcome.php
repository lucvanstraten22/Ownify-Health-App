<?php
/**
 * The opening screen — what everyone who is not signed in sees, and all they
 * see: a first visit, an account that logged out, a session that ran out.
 * index.php decides from the session; nothing of the app is built or sent.
 *
 * The app's name, one line under it, and two ways on at the bottom, where a
 * thumb already is: Inloggen and Registreren. Both open the account panel
 * (components/account-modal.php) — the same panel, forms and endpoints as
 * ever — each on its own flow and nothing else.
 *
 * The mark is the Ownify logo — the icon-only version, as supplied
 * (assets/brand, docs/BRANDING.md). It is decorative here: the name under it
 * says "Ownify" already.
 */
declare(strict_types=1);

$app     = $data['app'];
$welcome = $data['welcome'];
?>
<!DOCTYPE html>
<html lang="<?= e($app['locale']) ?>" data-theme="<?= e(app_theme()) ?>" data-focus="<?= e($data['focus']) ?>">
<head>
    <?php component('document-head', $data + ['styles' => [
        'theme', 'components', 'account', 'welcome',
    ]]); ?>
</head>
<body class="app app--welcome">

    <!-- The same ground as the app, so signing in changes the page, not the room. -->
    <div class="app__backdrop" aria-hidden="true"></div>

    <main class="welcome" aria-labelledby="welcome-title">

        <div class="welcome__stage">
            <div class="welcome__mark" aria-hidden="true">
                <img class="welcome__logo" src="<?= e(asset('assets/brand/ownify-icon.webp')) ?>"
                     alt="" width="512" height="512" decoding="async" draggable="false">
            </div>

            <h1 class="welcome__title" id="welcome-title"><?= e($app['name']) ?></h1>
            <p class="welcome__subtitle"><?= e($welcome['subtitle']) ?></p>
        </div>

        <div class="welcome__actions">
            <button type="button" class="welcome__button press"
                    data-account-open="login" aria-haspopup="dialog">
                <?= e($welcome['login']) ?>
            </button>
            <button type="button" class="welcome__button welcome__button--primary press"
                    data-account-open="register" aria-haspopup="dialog">
                <?= e($welcome['register']) ?>
            </button>
        </div>

    </main>

    <?php component('account-modal', $data); ?>

    <script src="<?= e(asset('assets/js/account.js')) ?>" defer></script>
</body>
</html>
