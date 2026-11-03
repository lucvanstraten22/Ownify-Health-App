<?php
/**
 * Telefoon on a wide screen (docs/DESKTOP.md): the website as a phone shows
 * it, in an iPhone. The screen is a same-origin <iframe> of this very page at
 * an iPhone's size (393 × 852, iPhone 15 / 16 Pro), so what is in it is the
 * phone's own website — its breakpoints, its viewport, its scripts — never a
 * copy. In there the head's script (document-head.php) sees the frame and
 * gives the page the iPhone's safe areas and no visible scrollbars; nothing
 * else changes.
 *
 * The frame, the Dynamic Island, the status bar and the home indicator are
 * drawn here, over the screen, and do nothing: a preview, not an iOS.
 * phone-sim.js loads the screen only while this layout is shown, fits the
 * device to the window and keeps the time and the status bar's ink (the
 * page's own theme in the phone) current. Hidden in every other layout.
 */
declare(strict_types=1);
?>
<div class="phone-sim" data-phone-sim>
    <?php component('view-switch', ['view_switch_place' => 'header']); ?>

    <div class="phone-sim__stage">
        <div class="phone-sim__device" data-phone-sim-device>
            <span class="phone-sim__button phone-sim__button--action" aria-hidden="true"></span>
            <span class="phone-sim__button phone-sim__button--volume-up" aria-hidden="true"></span>
            <span class="phone-sim__button phone-sim__button--volume-down" aria-hidden="true"></span>
            <span class="phone-sim__button phone-sim__button--side" aria-hidden="true"></span>

            <div class="phone-sim__bezel">
                <div class="phone-sim__screen">
                    <iframe class="phone-sim__frame" data-phone-screen
                            title="<?= e(t('Ownify op een telefoon')) ?>"
                            width="393" height="852" loading="eager"></iframe>

                    <div class="phone-sim__status" aria-hidden="true">
                        <span class="phone-sim__time" data-phone-sim-time>9:41</span>
                        <span class="phone-sim__island"></span>
                        <span class="phone-sim__icons">
                            <svg class="phone-sim__signal" viewBox="0 0 18 12" focusable="false">
                                <rect x="0" y="8" width="3" height="4" rx="1"/>
                                <rect x="5" y="5.5" width="3" height="6.5" rx="1"/>
                                <rect x="10" y="3" width="3" height="9" rx="1"/>
                                <rect x="15" y="0" width="3" height="12" rx="1"/>
                            </svg>
                            <svg class="phone-sim__wifi" viewBox="0 0 16 12" focusable="false">
                                <path d="M8 2.4c2.3 0 4.4.9 6 2.4l1.2-1.3A10.3 10.3 0 0 0 8 .6 10.3 10.3 0 0 0 .8 3.5L2 4.8a8.5 8.5 0 0 1 6-2.4Z"/>
                                <path d="M8 5.9c1.4 0 2.6.5 3.6 1.4l1.2-1.3A7 7 0 0 0 8 4.1 7 7 0 0 0 3.2 6l1.2 1.3c1-.9 2.2-1.4 3.6-1.4Z"/>
                                <path d="M8 9.3c.5 0 1 .2 1.3.5L8 11.2 6.7 9.8c.3-.3.8-.5 1.3-.5Z"/>
                            </svg>
                            <span class="phone-sim__battery">
                                <span class="phone-sim__battery-level"></span>
                            </span>
                        </span>
                    </div>

                    <span class="phone-sim__home" aria-hidden="true"></span>
                </div>
            </div>
        </div>
    </div>
</div>
