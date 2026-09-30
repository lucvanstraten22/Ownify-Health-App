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

                // adding a friend — the account's person moved left, a plus beside it
                'user-plus' => '<circle cx="9.4" cy="9" r="3.2"/><path d="M3 19.6a6.4 6.4 0 0 1 12.8 0"/>'
                    . '<path d="M19.2 8.2v5.2M16.6 10.8h5.2"/>',

                // health section — sleep, nutrition and sport together
                'heart'    => '<path d="M20.3 4.9a5 5 0 0 0-7.1 0L12 6.1l-1.2-1.2a5 5 0 1 0-7.1 7.1l1.2 1.2L12 19.4l7.1-6.2 1.2-1.2a5 5 0 0 0 0-7.1Z"/>',

                // community — two people, same proportions as the account icon
                'community' => '<circle cx="9.2" cy="9.4" r="2.9"/><path d="M3.8 19.5a5.4 5.4 0 0 1 10.8 0"/>'
                    . '<circle cx="16.9" cy="8" r="2.2"/><path d="M16.4 13.3a4.6 4.6 0 0 1 3.8 6.2"/>',

                // sleep
                'moon'     => '<path d="M20.2 14.4A8.4 8.4 0 0 1 9.6 3.8a8.4 8.4 0 1 0 10.6 10.6Z"/>',

                // nutrition — a fork and a knife, eating rather than a leaf:
                // the fork's two outer tines round into its middle one, which
                // runs on as the handle; the knife is one straight back and
                // handle with the blade's edge curving out from the tip
                'utensils' => '<path d="M5.2 3.6v5.4a2.6 2.6 0 0 0 5.2 0V3.6"/><path d="M7.8 3.6v16.8"/>'
                    . '<path d="M18.6 3.6v16.8"/><path d="M18.6 3.6c-2.3 1.4-3.6 4-3.6 7.3 0 2 1.1 3.3 3.6 3.7"/>',

                // sport / energy
                'bolt'     => '<path d="M13.2 2.8 5.6 13.2h5.3l-.9 8 7.6-10.4h-5.3l.9-8Z"/>',

                // training
                'dumbbell' => '<path d="M7 6.6v10.8M17 6.6v10.8M3.2 9.4v5.2M20.8 9.4v5.2M7 12h10"/>',

                // overview — concentric rings, echoes the score ring
                // the inner ring is a marker, not a second ring: at 3.4 the two
                // circles carried a third more ink than any other tab icon
                'rings'    => '<circle cx="12" cy="12" r="8.4"/><circle cx="12" cy="12" r="2.4"/>',

                // settings
                'sliders'  => '<path d="M4 8.5h8.5M17.5 8.5H20M4 15.5h3.5M12.5 15.5H20"/><circle cx="15" cy="8.5" r="2.2"/><circle cx="10" cy="15.5" r="2.2"/>',

                // insights — heartbeat
                'pulse'    => '<path d="M3 12.2h4.2l2.3-5.8 3.6 11.4 2.3-5.6H21"/>',

                // patterns / research
                'chart'    => '<path d="M4 4.5v15h15.5"/><path d="M7.6 15.4 11 11.2l2.9 2.4 4.4-6"/>',

                // goals — deliberately not a second ring: `rings` owns that shape
                'flag'     => '<path d="M6 20.6V4"/><path d="M6 5c4.5-2 9 2 13.5 0v8.6c-4.5 2-9-2-13.5 0Z"/>',

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

                // sending a message — an arrow up, the stroke the rest share
                'arrow-up' => '<path d="M12 19.2V5.2M6.4 10.8 12 5.2l5.6 5.6"/>',

                // conversations — one speech bubble, its tail on the left
                'chat'     => '<path d="M7.6 4.6h8.8a2.8 2.8 0 0 1 2.8 2.8v6.2a2.8 2.8 0 0 1-2.8 2.8h-5.2l-4 3.2v-3.2h-.2A2.6 2.6 0 0 1 4.8 13.6V7.4a2.8 2.8 0 0 1 2.8-2.8Z"/>',

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

        /**
         * Optical normalisation, for the icons that have to hold a row
         * together: the five in the tab bar, and the pair in the header.
         *
         * They are all drawn on the same 24 grid, which makes them the same
         * size on paper and not on screen. Measured off the rendered ink, the
         * tab set ran from 13.0 units tall to 21.2 wide, so five icons in five
         * identical boxes still read as five different sizes — and the marks
         * were not centred on the grid either, which is what made the boxes
         * and the eye disagree about where an icon sat.
         *
         * Each entry is [scale, cx, cy], measured rather than judged: cx/cy is
         * where that icon's ink actually centres, and scale brings it to the
         * group's common optical size: a weighted blend of three readings of
         * "same size" — equal ink area, equal largest dimension and equal
         * height — because no one of them is right for shapes this different
         * in aspect. Area carries most of the weight, since equal size is the
         * point; height carries a fifth, because it is what decides how much
         * air falls under an icon and so how even the row of labels reads.
         *
         * The stroke is divided back out by the same scale, so line weight
         * stays 1.6 grid units whatever the shape does. An icon that is not
         * listed is drawn exactly as before: the chevrons and the small
         * in-page marks are deliberately not this size.
         */
        static $optical = [
            'rings'     => [0.9336, 12.00, 12.00],
            'heart'     => [0.8878, 12.00, 11.42],
            'flag'      => [1.0027, 12.75, 12.29],
            'community' => [1.0370, 12.15, 12.67],
            'sliders'   => [1.1601, 12.00, 12.00],
            'device'    => [0.9680, 12.00, 12.00],
            'user'      => [1.0331, 12.00, 12.71],
        ];

        $body = $icons[$name];

        if (isset($optical[$name])) {
            [$scale, $cx, $cy] = $optical[$name];
            $body = sprintf(
                '<g transform="translate(12 12) scale(%s) translate(%s %s)" stroke-width="%s">%s</g>',
                $scale,
                -$cx,
                -$cy,
                round(1.6 / $scale, 4),
                $body
            );
        }

        return sprintf(
            '<svg class="icon%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"'
            . ' stroke-linecap="round" stroke-linejoin="round" focusable="false"%s>%s</svg>',
            $class !== '' ? ' ' . e($class) : '',
            $decorative ? ' aria-hidden="true"' : '',
            $body
        );
    }
}

if (!function_exists('icon_solid')) {
    /**
     * The solid version of an icon, for a coloured tile (`.icon-tile--solid`):
     * the shape filled with currentColor instead of outlined. An icon that is
     * only a line (pulse, chart) has nothing to fill, so it is the same line
     * drawn heavier (fill="none" and its own stroke-width). An icon without a
     * solid version here is its outline, as icon() draws it.
     */
    function icon_solid(string $name, string $class = ''): string
    {
        static $solid = [
            'moon'     => '<path d="M20.2 14.4A8.4 8.4 0 0 1 9.6 3.8a8.4 8.4 0 1 0 10.6 10.6Z"/>',
            'utensils' => '<path d="M5 3.3a.7.7 0 0 1 1.4 0V8h1.1V3.3a.7.7 0 0 1 1.4 0V8H10V3.3a.7.7 0 0 1 1.4 0v5.5a3.2 3.2 0 0 1-2.3 3.1v8.4a1 1 0 0 1-2 0v-8.4A3.2 3.2 0 0 1 5 8.8Z"/>'
                . '<path d="M18.9 3.1c.5-.2 1 .1 1 .6v16.6a1 1 0 0 1-2 0v-5.1c-2.3-.4-3.7-2-3.7-4.4 0-3.4 1.7-6.2 4.7-7.7Z"/>',
            'dumbbell' => '<rect x="7.6" y="10.8" width="8.8" height="2.4" rx=".6"/><rect x="5" y="5.6" width="3.4" height="12.8" rx="1.3"/>'
                . '<rect x="15.6" y="5.6" width="3.4" height="12.8" rx="1.3"/><rect x="2" y="8.4" width="2.6" height="7.2" rx="1.1"/>'
                . '<rect x="19.4" y="8.4" width="2.6" height="7.2" rx="1.1"/>',
            'bolt'     => '<path d="M13.2 2.8 5.6 13.2h5.3l-.9 8 7.6-10.4h-5.3l.9-8Z"/>',
            'flag'     => '<rect x="5.1" y="3.2" width="1.8" height="18.2" rx=".9"/><path d="M6.9 5c4.4-2 8.6 2 13 0v8.6c-4.4 2-8.6-2-13 0Z"/>',
            'sparkle'  => '<path d="M12 3.6 13.7 9 19 10.8 13.7 12.6 12 18l-1.7-5.4L5 10.8 10.3 9 12 3.6Z"/><path d="M18.4 17.2l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7.7-2Z"/>',
            'pulse'    => '<path fill="none" stroke-width="2.4" d="M3 12.2h4.2l2.3-5.8 3.6 11.4 2.3-5.6H21"/>',
            'chart'    => '<path fill="none" stroke-width="2.4" d="M4 4.5v15h15.5"/><path fill="none" stroke-width="2.4" d="M7.6 15.4 11 11.2l2.9 2.4 4.4-6"/>',
        ];

        if (!isset($solid[$name])) {
            return icon($name, $class);
        }

        return sprintf(
            '<svg class="icon icon--solid%s" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="0"'
            . ' stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">%s</svg>',
            $class !== '' ? ' ' . e($class) : '',
            $solid[$name]
        );
    }
}
