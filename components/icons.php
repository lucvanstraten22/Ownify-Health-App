<?php
/**
 * Icon set — one consistent family: 24x24 grid, 1.6 stroke, round caps,
 * no fills. Adding an icon means adding one entry to $ICONS.
 */

declare(strict_types=1);

if (!function_exists('icon')) {

    function icon(string $name, string $class = '', bool $decorative = true): string
    {
        static $icons = null;

        if ($icons === null) {
            $icons = [
                // connected devices — smartwatch
                'device'   => '<rect x="7" y="6" width="10" height="12" rx="3"/><path d="M10 6V3.6h4V6M10 18v2.4h4V18"/><path d="M12 10.6v2.2l1.4 1"/>',

                // account
                'user'     => '<circle cx="12" cy="9" r="3.2"/><path d="M5.6 19.6a6.6 6.6 0 0 1 12.8 0"/>',

                // health section — sleep, nutrition and sport together.
                // Two equal lobes meeting in a clean V, closing to a soft point:
                // symmetric about x=12, so it never leans in a row of five.
                'heart'    => '<path d="M12 19.9 4.9 13.1a4.7 4.7 0 0 1 0-6.8 4.9 4.9 0 0 1 6.7-.1l.4.4.4-.4a4.9 4.9 0 0 1 6.7.1 4.7 4.7 0 0 1 0 6.8Z"/>',

                // community — the person in front drawn whole, the one behind
                // cut off where the front figure covers them, so the two read
                // as depth rather than as two overlapping outlines.
                'community' => '<circle cx="9.5" cy="8.8" r="3.1"/><path d="M3.9 19.4a5.6 5.6 0 0 1 11.2 0"/>'
                    . '<path d="M15.9 6.1a2.6 2.6 0 0 1 0 5.2"/><path d="M17.4 13.9a4.8 4.8 0 0 1 2.7 4.3"/>',

                // sleep
                'moon'     => '<path d="M20.2 14.4A8.4 8.4 0 0 1 9.6 3.8a8.4 8.4 0 1 0 10.6 10.6Z"/>',

                // nutrition
                'leaf'     => '<path d="M11 20.2A7.2 7.2 0 0 1 9.9 6.3C15.4 5.2 16.9 4.7 18.9 2.4c1 2 1.9 4.1 1.9 7.8 0 5.5-4.4 10-9.8 10Z"/><path d="M3.4 21c0-3.1 1.9-5.5 3.1-6.4"/>',

                // sport / energy
                'bolt'     => '<path d="M13.2 2.8 5.6 13.2h5.3l-.9 8 7.6-10.4h-5.3l.9-8Z"/>',

                // training
                'dumbbell' => '<path d="M7 6.6v10.8M17 6.6v10.8M3.2 9.4v5.2M20.8 9.4v5.2M7 12h10"/>',

                // overview — concentric rings, echoes the score ring. The gap
                // between them reads even at 22px when the inner circle is a
                // little under half the outer.
                'rings'    => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="3.2"/>',

                // settings — two tracks, each with its knob at the opposite
                // third, so the glyph balances around its own centre.
                'sliders'  => '<path d="M4 8.6h8.7M17.4 8.6H20M4 15.4h3.2M12.2 15.4H20"/>'
                    . '<circle cx="15.1" cy="8.6" r="2.3"/><circle cx="9.7" cy="15.4" r="2.3"/>',

                // insights — heartbeat
                'pulse'    => '<path d="M3 12.2h4.2l2.3-5.8 3.6 11.4 2.3-5.6H21"/>',

                // patterns / research
                'chart'    => '<path d="M4 4.5v15h15.5"/><path d="M7.6 15.4 11 11.2l2.9 2.4 4.4-6"/>',

                // goals — deliberately not a second ring: `rings` owns that
                // shape. The pole runs the full height and the banner hangs
                // from the top of it, so the glyph sits on the same baseline
                // as the other four.
                'flag'     => '<path d="M6.3 20.6V4.2"/><path d="M6.3 5.2c4.3-1.8 8.6 1.8 12.9 0v8.3c-4.3 1.8-8.6-1.8-12.9 0Z"/>',

                // recommendation
                'sparkle'  => '<path d="M12 3.6 13.7 9 19 10.8 13.7 12.6 12 18l-1.7-5.4L5 10.8 10.3 9 12 3.6Z"/><path d="M18.4 17.2l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7.7-2Z"/>',

                // leaderboard — ascending bars
                'ranking'  => '<path d="M6 20v-4.6M12 20V7.8M18 20v-8.4"/>',

                // badges and milestones
                'award'    => '<circle cx="12" cy="9" r="5.2"/><path d="M8.6 13.4 7.4 20.6l4.6-2.5 4.6 2.5-1.2-7.2"/>',

                // scroll to top
                'chevron'  => '<path d="M6.8 14.4 12 9.2l5.2 5.2"/>',

                // dismiss the assistant sheet
                'chevron-down' => '<path d="M6.8 9.6 12 14.8l5.2-5.2"/>',

                // back out of a detail page / drill into one
                'chevron-left'  => '<path d="M14.4 6.8 9.2 12l5.2 5.2"/>',
                'chevron-right' => '<path d="M9.6 6.8 14.8 12l-5.2 5.2"/>',


                // empty-state marker
                'lock'     => '<rect x="5" y="10.5" width="14" height="9.5" rx="2.6"/><path d="M8.4 10.5V8.2a3.6 3.6 0 0 1 7.2 0v2.3"/>',

                // add a goal
                'plus'     => '<path d="M12 5.4v13.2M5.4 12h13.2"/>',

                // confirmed / achieved
                'check'    => '<path d="M5 12.6 9.8 17.4 19 6.9"/>',

                // pause and resume a goal
                'pause'    => '<path d="M9.4 5.6v12.8M14.6 5.6v12.8"/>',
                'play'     => '<path d="M8.4 5.6 18 12l-9.6 6.4V5.6Z"/>',

                // delete a goal
                'trash'    => '<path d="M4.6 7.2h14.8"/><path d="M9.4 7.2V5.4a1.4 1.4 0 0 1 1.4-1.4h2.4a1.4 1.4 0 0 1 1.4 1.4v1.8"/>'
                    . '<path d="M6.8 7.2 7.7 19a1.6 1.6 0 0 0 1.6 1.5h5.4a1.6 1.6 0 0 0 1.6-1.5l.9-11.8"/>',

                // --- settings categories ---------------------------------

                // privacy
                'shield'   => '<path d="M12 3.4 19.4 6.1v5.8c0 4.2-3 7.4-7.4 8.7-4.4-1.3-7.4-4.5-7.4-8.7V6.1L12 3.4Z"/>',

                // notifications
                'bell'     => '<path d="M18 9.8a6 6 0 1 0-12 0c0 4.8-1.7 6.2-1.7 6.2h15.4S18 14.6 18 9.8Z"/>'
                    . '<path d="M13.9 19.2a2.1 2.1 0 0 1-3.8 0"/>',

                // language
                'globe'    => '<circle cx="12" cy="12" r="8.4"/><path d="M3.6 12h16.8"/>'
                    . '<path d="M12 3.6a13.2 13.2 0 0 1 0 16.8 13.2 13.2 0 0 1 0-16.8Z"/>',

                // units
                'ruler'    => '<rect x="2.8" y="8.8" width="18.4" height="6.4" rx="1.8"/>'
                    . '<path d="M7.4 8.8v2.8M11 8.8v4M14.6 8.8v2.8M18.2 8.8v4"/>',

                // first day of the week
                'calendar' => '<rect x="4" y="5.6" width="16" height="14.8" rx="2.6"/>'
                    . '<path d="M8.4 3.6v3.8M15.6 3.6v3.8M4 10.6h16"/>',

                // accessibility — the standard figure, in this icon family
                'accessibility' => '<circle cx="12" cy="4.8" r="1.9"/><path d="M4.8 8.6h14.4"/>'
                    . '<path d="M12 8.6v5M12 13.6 9.2 20.4M12 13.6l2.8 6.8"/>',

                // about
                'info'     => '<circle cx="12" cy="12" r="8.4"/><path d="M12 11.2v5.1"/><path d="M12 7.9v.1"/>',

                // sign out
                'logout'   => '<path d="M9.8 20.4H5.8a1.8 1.8 0 0 1-1.8-1.8V5.4a1.8 1.8 0 0 1 1.8-1.8h4"/>'
                    . '<path d="M15.4 16.4 19.8 12l-4.4-4.4"/><path d="M19.8 12H9.4"/>',

                // synchronisation
                'sync'     => '<path d="M20.2 11.2a8.2 8.2 0 0 0-14-4.5L4 8.9"/>'
                    . '<path d="M3.8 12.8a8.2 8.2 0 0 0 14 4.5l2.2-2.2"/>'
                    . '<path d="M4 4.6v4.3h4.3M20 19.4v-4.3h-4.3"/>',
            ];
        }

        if (!isset($icons[$name])) {
            return '';
        }

        return sprintf(
            '<svg class="icon%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"'
            . ' stroke-linecap="round" stroke-linejoin="round" focusable="false"%s>%s</svg>',
            $class !== '' ? ' ' . e($class) : '',
            $decorative ? ' aria-hidden="true"' : '',
            $icons[$name]
        );
    }
}
