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

                // sleep
                'moon'     => '<path d="M20.2 14.4A8.4 8.4 0 0 1 9.6 3.8a8.4 8.4 0 1 0 10.6 10.6Z"/>',

                // nutrition
                'leaf'     => '<path d="M11 20.2A7.2 7.2 0 0 1 9.9 6.3C15.4 5.2 16.9 4.7 18.9 2.4c1 2 1.9 4.1 1.9 7.8 0 5.5-4.4 10-9.8 10Z"/><path d="M3.4 21c0-3.1 1.9-5.5 3.1-6.4"/>',

                // sport / energy
                'bolt'     => '<path d="M13.2 2.8 5.6 13.2h5.3l-.9 8 7.6-10.4h-5.3l.9-8Z"/>',

                // overview — concentric rings, echoes the score ring
                'rings'    => '<circle cx="12" cy="12" r="8.4"/><circle cx="12" cy="12" r="3.4"/>',

                // settings
                'sliders'  => '<path d="M4 8.5h8.5M17.5 8.5H20M4 15.5h3.5M12.5 15.5H20"/><circle cx="15" cy="8.5" r="2.2"/><circle cx="10" cy="15.5" r="2.2"/>',

                // insights — heartbeat
                'pulse'    => '<path d="M3 12.2h4.2l2.3-5.8 3.6 11.4 2.3-5.6H21"/>',

                // patterns / research
                'chart'    => '<path d="M4 4.5v15h15.5"/><path d="M7.6 15.4 11 11.2l2.9 2.4 4.4-6"/>',

                // goal
                'target'   => '<circle cx="12" cy="12" r="8.2"/><circle cx="12" cy="12" r="3.9"/><circle cx="12" cy="12" r="1.1" fill="currentColor" stroke="none"/>',

                // recommendation
                'sparkle'  => '<path d="M12 3.6 13.7 9 19 10.8 13.7 12.6 12 18l-1.7-5.4L5 10.8 10.3 9 12 3.6Z"/><path d="M18.4 17.2l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7.7-2Z"/>',

                // leaderboard — ascending bars
                'ranking'  => '<path d="M6 20v-4.6M12 20V7.8M18 20v-8.4"/>',

                // scroll to top
                'chevron'  => '<path d="M6.8 14.4 12 9.2l5.2 5.2"/>',

                // empty-state marker
                'lock'     => '<rect x="5" y="10.5" width="14" height="9.5" rx="2.6"/><path d="M8.4 10.5V8.2a3.6 3.6 0 0 1 7.2 0v2.3"/>',
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
