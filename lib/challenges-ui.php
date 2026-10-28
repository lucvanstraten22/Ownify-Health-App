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
            /* The red dot on the invitation's picture (lib/notices.php). */
            . ($who !== null
                ? '<span class="notice-frame notice-frame--round">' . ch_avatar($who, 32) . (function_exists('notice_dot') ? notice_dot('invite:' . (int) $invite['id']) : '') . '</span>'
                : '<span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true">' . icon_solid('target') . (function_exists('notice_dot') ? notice_dot('invite:' . (int) $invite['id']) : '') . '</span>')
            . '<p class="ch-invite__text">' . ($who !== null ? '<b>' . e($who['name']) . '</b> ' : '') . e($invite['invite_text']) . '</p>'
            . '<button type="button" class="btn press ch-invite__btn" data-challenge-review="' . (int) $invite['id'] . '">' . e($see) . '</button>'
            . '</div>';
    }

    /** A challenge's metric on Overview's solid neutral tile. */
    function ch_tile_icon(string $icon, string $size = ''): string
    {
        return '<span class="icon-tile icon-tile--solid icon-tile--neutral' . ($size !== '' ? ' ch-icon--' . $size : '') . '" aria-hidden="true">'
            . icon_solid($icon) . '</span>';
    }

    /**
     * Nieuwe uitdaging and Code invoeren side by side: two glass tiles of
     * the same size, told apart by their mark — a solid tile for making
     * one, an outlined one for joining with a code.
     */
    function ch_actions(array $page): string
    {
        return '<div class="ch-acts">'
            . '<button type="button" class="ch-act press" data-challenge-create>'
            . '<span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true">' . icon('plus') . '</span>'
            . '<span class="ch-act__label">' . e($page['create']) . '</span></button>'
            . '<button type="button" class="ch-act ch-act--code press" data-challenge-join>'
            . '<span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true">' . icon('hash') . '</span>'
            . '<span class="ch-act__label">' . e($page['join']) . '</span></button>'
            . '</div>';
    }

    /** The empty target: dashed rings round the Uitdagingen mark. */
    function ch_target_art(): string
    {
        return '<span class="ch-art" aria-hidden="true"><span class="icon-tile icon-tile--solid icon-tile--neutral ch-art__tile">'
            . icon_solid('target') . '</span></span>';
    }

    /** The challenge on top of Community's stack: the one that ends first. */
    function ch_front(array $view): string
    {
        return '<button type="button" class="cm-fan__front press" data-accent="' . e($view['accent']) . '"'
            . ' data-detail-open="challenge-' . (int) $view['id'] . '" data-challenge-from="home">'
            . '<span class="cm-fan__head">' . ch_tile_icon($view['icon'], 'sm')
            . '<span class="cm-fan__name">' . e($view['name']) . '</span>'
            . '<span class="chip">' . e($view['kind_label']) . '</span></span>'
            . ch_bar($view)
            . '<span class="ch-foot"><span class="cm-fan__fig"><b>' . e($view['tile']['strong']) . '</b> ' . e($view['tile']['rest']) . '</span>'
            . ch_faces($view['faces']) . '</span>'
            . '</button>';
    }

    /**
     * A challenge you are in, on Uitdagingen. Samen: where you stand
     * together, the bar in everybody's pieces and who brought what.
     * Wedstrijd: the top three, each with their bar against the leader.
     */
    function ch_card(array $view): string
    {
        $versus = $view['kind'] === 'versus';

        $out = '<button type="button" class="card ch-card2 press" data-accent="' . e($view['accent']) . '"'
            . ' data-detail-open="challenge-' . (int) $view['id'] . '" data-challenge-from="list">'
            . '<span class="ch-card2__head">' . ch_tile_icon($view['icon'])
            . '<span class="ch-card2__text"><span class="ch-card2__name">' . e($view['name']) . '</span>'
            . '<span class="ch-card2__sub">' . e($view['deadline']) . '</span></span></span>';

        if (!$versus) {
            $out .= '<span class="ch-item__fig"><span><b>' . e($view['line']['strong']) . '</b>'
                . ($view['line']['rest'] !== '' ? ' ' . e($view['line']['rest']) : '') . '</span>'
                . '<span class="ch-item__sub">' . e($view['line']['sub']) . '</span></span>'
                . ch_bar($view, true)
                . '<span class="ch-legend">';
            foreach ($view['people'] as $person) {
                $out .= '<span class="ch-legend__item"><span class="ch-key ' . (!empty($person['you']) ? 'is-you' : 'is-tone-' . (int) $person['tone']) . '" aria-hidden="true"></span>'
                    . e((string) $person['label']) . ' <b>' . e((string) $person['value_text']) . '</b></span>';
            }
            return $out . '</span></button>';
        }

        $out .= '<ol class="ch-standing" role="list">';
        foreach (array_slice($view['people'], 0, 3) as $person) {
            $out .= '<li class="ch-standing__row' . (!empty($person['you']) ? ' is-you' : '') . '">'
                . '<span class="ch-standing__rank">' . (int) $person['rank'] . '</span>'
                . ch_avatar($person, 24)
                . '<span class="ch-person__bar" aria-hidden="true"><i style="--v: ' . number_format((float) $person['share'], 4, '.', '') . '"></i></span>'
                . '<span class="ch-standing__value">' . e((string) $person['value_text']) . '</span></li>';
        }

        return $out . '</ol></button>';
    }

    /** A way to begin when there is nothing yet: Nieuwe uitdaging with this kind and metric chosen. */
    function ch_idea(array $idea, array $metrics): string
    {
        $icon = $metrics[$idea['metric']]['icon'] ?? 'target';

        return '<button type="button" class="ch-idea press" data-challenge-create'
            . ' data-kind="' . e($idea['kind']) . '" data-metric="' . e($idea['metric']) . '">'
            . ch_tile_icon($icon, 'sm')
            . '<span class="ch-idea__text"><span class="ch-idea__title">' . e($idea['title']) . '</span>'
            . '<span class="ch-idea__sub">' . e($idea['sub']) . '</span></span>'
            . icon('plus', 'ch-idea__plus') . '</button>';
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
