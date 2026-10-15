<?php
/**
 * The small pieces Uitdagingen is drawn with, on Community, Uitdagingen and
 * a challenge's own page — one place, so a tile, a row and a page draw a
 * person, a bar or a challenge the same way (docs/CHALLENGES.md).
 *
 * Every sentence arrives written (includes/challenges.php, challenge_view());
 * nothing here makes one up, and everything a person wrote — a name, a
 * username — is escaped here, once.
 *
 * Android: ui/screens/community/Challenges.kt draws the same pieces.
 */

declare(strict_types=1);

if (!function_exists('ch_avatar')) {

    /**
     * A person's circle: their picture as the boards show it, over their
     * initial — which shows while it loads, or if it cannot.
     */
    function ch_avatar(array $person, int $size = 0, bool $pending = false): string
    {
        $classes = 'ch-av' . (!empty($person['you']) ? ' ch-av--you' : '') . ($pending ? ' ch-av--pending' : '');
        $style   = $size > 0 ? ' style="--av: ' . $size . 'px"' : '';
        $photo   = !empty($person['avatar'])
            ? '<img class="ch-av__photo" src="' . e((string) $person['avatar']) . '" alt="" loading="lazy" decoding="async">'
            : '';

        return '<span class="' . $classes . '" data-accent="' . e((string) ($person['accent'] ?? 'health')) . '"' . $style . ' aria-hidden="true">'
            . e((string) ($person['initial'] ?? '')) . $photo . '</span>';
    }

    /** Overlapping circles of the first few people, and how many more. */
    function ch_faces(array $faces): string
    {
        $out = '<span class="ch-faces" role="img" aria-label="' . e((string) $faces['label']) . '">';

        foreach ($faces['people'] as $person) {
            $out .= ch_avatar($person);
        }

        if ((int) $faces['more'] > 0) {
            $out .= '<span class="ch-faces__more" aria-hidden="true">+' . (int) $faces['more'] . '</span>';
        }

        return $out . '</span>';
    }

    /**
     * Samen: everybody's piece of the target side by side, you in the
     * colour, the rest in quiet ink, and what is still to go. Wedstrijd:
     * your total against the leader's.
     */
    function ch_bar(array $view, bool $big = false): string
    {
        $bar = $view['bar'];

        if ($bar['type'] === 'lead') {
            return '<span class="ch-lead' . ($big ? ' ch-lead--big' : '') . '" role="img" aria-label="' . e((string) $bar['label']) . '">'
                . '<i style="--v: ' . number_format((float) $bar['value'], 4, '.', '') . '"></i></span>';
        }

        $out = '<span class="ch-stack' . ($big ? ' ch-stack--big' : '') . '" role="img" aria-label="' . e((string) $bar['label']) . '">';

        foreach ($bar['segments'] as $segment) {
            if ((float) $segment['part'] <= 0) {
                continue;
            }

            $out .= '<i class="ch-seg ' . (!empty($segment['you']) ? 'is-you' : 'is-tone-' . (int) $segment['tone']) . '"'
                . ' style="flex-grow: ' . number_format((float) $segment['part'], 4, '.', '') . '"></i>';
        }

        if ((float) $bar['rest'] > 0) {
            $out .= '<i class="ch-seg is-rest" style="flex-grow: ' . number_format((float) $bar['rest'], 4, '.', '') . '"></i>';
        }

        return $out . '</span>';
    }

    /** "Samen" with its metric's icon: the small line above a challenge's name. */
    function ch_kind(string $icon, string $text): string
    {
        return '<span class="ch-kind">' . icon($icon) . '<span>' . e($text) . '</span></span>';
    }

    /** A challenge on Community's carousel. */
    function ch_tile(array $view): string
    {
        return '<button type="button" class="ch-tile press" data-accent="' . e($view['accent']) . '"'
            . ' data-detail-open="challenge-' . (int) $view['id'] . '" data-challenge-from="home">'
            . ch_kind($view['icon'], $view['kind_label'])
            . '<span class="ch-tile__name">' . e($view['name']) . '</span>'
            . ch_bar($view)
            . '<span class="ch-tile__fig"><b>' . e($view['tile']['strong']) . '</b> ' . e($view['tile']['rest']) . '</span>'
            . '<span class="ch-foot">' . ch_faces($view['faces']) . '<span>' . e($view['left']) . '</span></span>'
            . '</button>';
    }

    /** A challenge on Uitdagingen: an invitation, or one you are in. */
    function ch_item(array $view): string
    {
        $invite = ($view['me'] ?? 'joined') === 'invited';

        $out = '<button type="button" class="card ch-item press' . ($invite ? ' ch-item--invite' : '') . '"'
            . ' data-accent="' . e($view['accent']) . '"'
            . ($invite
                ? ' data-challenge-review="' . (int) $view['id'] . '"'
                : ' data-detail-open="challenge-' . (int) $view['id'] . '" data-challenge-from="list"')
            . '>'
            . ch_kind($view['icon'], $view['kind_line'])
            . '<span class="ch-item__name">' . e($view['name']) . '</span>';

        if (!$invite) {
            $out .= ch_bar($view)
                . '<span class="ch-item__fig"><span><b>' . e($view['line']['strong']) . '</b>'
                . ($view['line']['rest'] !== '' ? ' ' . e($view['line']['rest']) : '') . '</span>'
                . '<span class="ch-item__sub">' . e($view['line']['sub']) . '</span></span>';
        }

        return $out . '<span class="ch-foot">' . ch_faces($view['faces'])
            . '<span>' . e($invite ? $view['from_text'] : $view['deadline']) . '</span></span></button>';
    }

    /** An invitation on Community: who sent it, for what, and Bekijken. */
    function ch_invite(array $invite, string $see): string
    {
        $who = $invite['inviter'];

        return '<div class="ch-invite">'
            . ($who !== null ? ch_avatar($who, 32) : '<span class="icon-tile" aria-hidden="true">' . icon('flag') . '</span>')
            . '<p class="ch-invite__text">' . ($who !== null ? '<b>' . e($who['name']) . '</b> ' : '') . e($invite['invite_text']) . '</p>'
            . '<button type="button" class="btn press ch-invite__btn" data-challenge-review="' . (int) $invite['id'] . '">' . e($see) . '</button>'
            . '</div>';
    }

    /** One person on a challenge's page: Samen with their colour key, Wedstrijd with their place and bar. */
    function ch_person(array $person, bool $versus): string
    {
        $you = !empty($person['you']);

        $out = '<li class="ch-person' . ($versus ? ' ch-person--ranked' : '') . ($you ? ' ch-person--you' : '') . '">';

        if ($versus) {
            $out .= '<span class="ch-person__rank">' . (int) $person['rank'] . '</span>';
        } else {
            $out .= '<span class="ch-key ' . ($you ? 'is-you' : 'is-tone-' . (int) $person['tone']) . '" aria-hidden="true"></span>';
        }

        $out .= ch_avatar($person, 32)
            . '<span class="ch-person__text"><span class="ch-person__name">' . e((string) $person['label']) . '</span>'
            . ($versus
                ? '<span class="ch-person__bar" aria-hidden="true"><i style="--v: ' . number_format((float) $person['share'], 4, '.', '') . '"></i></span>'
                : '')
            . '</span>'
            . '<span class="ch-person__value">' . e((string) $person['value_text'])
            . (!$versus ? '<small>' . e((string) $person['share_text']) . '</small>' : '')
            . '</span></li>';

        return $out;
    }
}
