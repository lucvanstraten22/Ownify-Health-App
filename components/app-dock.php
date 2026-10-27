<?php
/**
 * The dock: everything pinned to the bottom of the app, above the page rail
 * and below the assistant sheet.
 *
 * It is also the assistant's grab area. `touch-action: none` here is what
 * makes the upward swipe possible at all — everywhere else vertical movement
 * belongs to the browser, which is how ordinary scrolling stays untouched.
 */
declare(strict_types=1);
?>
<div class="app-dock" data-ai-grab>

    <?php /* In the desktop layout the dock is the sidebar (desktop.css): the
             name on top, the tabs, and at its foot what the header's buttons
             and the handle open. Not shown on a phone. */ ?>
    <p class="sidebar__brand"><?= e($data['app']['name']) ?></p>

    <button type="button" class="ai-handle" data-ai-open
            aria-label="<?= e($data['ai']['open']['aria']) ?>">
        <span class="ai-handle__grip" aria-hidden="true"></span>
    </button>

    <?php component('bottom-navigation', $data); ?>

    <div class="sidebar__foot">
        <button type="button" class="sidebar__item press" data-ai-open>
            <?php /* The Ownify logo, in the sidebar's grey (desktop.css). */ ?>
            <span class="sidebar__icon sidebar__logo" aria-hidden="true">
                <img src="<?= e(asset('assets/brand/ownify-icon-192.png')) ?>" alt="" width="22" height="22" decoding="async">
            </span>
            <span class="sidebar__label"><?= e(t('OwnifyAI')) ?></span>
        </button>
        <button type="button" class="sidebar__item press" data-devices-open
                aria-haspopup="dialog" aria-controls="devices-popup" aria-expanded="false">
            <span class="sidebar__icon" aria-hidden="true"><?= icon('device') ?></span>
            <span class="sidebar__label"><?= e(t('Apparaten')) ?></span>
        </button>
        <button type="button" class="sidebar__item press" data-account-open>
            <?php /* The profile picture when there is one, as on the header's
                     button; account.js swaps it here too after an upload. */
            $avatar = $data['auth']['user']['avatar_path'] ?? null; ?>
            <span class="notice-frame">
                <span class="sidebar__icon sidebar__avatar" aria-hidden="true" data-account-avatar>
                    <?php if ($avatar !== null && $avatar !== ''): ?>
                        <img src="<?= e($avatar) ?>" alt="">
                    <?php else: ?>
                        <?= icon('user') ?>
                    <?php endif; ?>
                </span>
                <?= notice_dot('account', 'beside') ?>
            </span>
            <span class="sidebar__label"><?= e((string) ($data['auth']['user']['username'] ?? t('Account'))) ?></span>
        </button>
    </div>

</div>
