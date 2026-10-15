<?php
/**
 * The one bottom sheet Uitdagingen opens: Nieuwe uitdaging, Code invoeren,
 * Bekijken (an invitation, or what a code found), Vrienden uitnodigen and
 * Uitdaging verlaten all fill it (challenges.js). It rises from the bottom
 * edge, is dragged down or tapped outside to close, and is a [data-overlay]
 * so the page gestures and Escape leave it alone while it is open.
 *
 * What the sheets need to draw themselves comes along as JSON: the copy in
 * the reader's language, the metrics in their units, the lengths with their
 * dates, your friends, and every invitation's review — so nothing is fetched
 * to open one, and no sentence is written a second time in the script.
 */
declare(strict_types=1);

$challenges = $data['challenges'];

$payload = [
    'copy'    => $challenges['copy'],
    'metrics' => $challenges['metrics'],
    'kinds'   => $challenges['kinds'],
    'lengths' => $challenges['lengths'],
    'from'    => $challenges['from'],
    'preview' => $challenges['preview'],
    'friends' => $challenges['friends'],
    'you'     => $challenges['you'] ?? null,
    'units'   => units_system(),
    'reviews' => array_column(array_map(
        static fn (array $invite): array => ['id' => $invite['id'], 'review' => $invite['review']],
        $challenges['invites']
    ), 'review', 'id'),
    'icons'   => array_combine(
        $names = ['plus', 'hash', 'check', 'copy', 'share', 'chevron-down', 'shield', 'calendar', 'flag',
                  'dumbbell', 'bolt', 'pulse', 'moon', 'user-plus', 'trash'],
        array_map(static fn (string $name): string => icon($name), $names)
    ),
    'solid'   => array_combine(
        $solid = ['flag', 'dumbbell', 'bolt', 'pulse', 'moon'],
        array_map(static fn (string $name): string => icon_solid($name), $solid)
    ),
];
?>
<div class="ch-sheet" data-overlay data-challenge-sheet hidden>
    <div class="ch-sheet__scrim" data-sheet-close></div>
    <div class="ch-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="ch-sheet-title" data-sheet-panel>
        <div class="ch-sheet__grab" data-sheet-grab>
            <span class="ch-sheet__grip" aria-hidden="true"></span>
        </div>
        <div class="ch-sheet__content" data-sheet-content></div>
    </div>
</div>

<div class="ch-toast" role="status" aria-live="polite" data-challenge-toast></div>

<script type="application/json" data-challenges-data><?=
    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
?></script>
