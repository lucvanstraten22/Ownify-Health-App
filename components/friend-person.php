<?php
/**
 * One person in the account panel's Vrienden page: picture, username, where
 * you stand, and what you can do about it.
 *
 *   incoming   a request waiting for you — Accepteren / Weigeren
 *   sent       a request you sent — "Verzoek verstuurd"
 *   friend     a friend — Verwijderen, which asks first
 *   template   the empty shape friends.js fills in for a search result
 *
 * Only public fields: the username and the picture. The id is the other
 * account's, and the server checks every action against the signed-in
 * account's own pair, whatever id is sent.
 */
declare(strict_types=1);

$person = $data['person'];
$kind   = $data['person_kind'];
$id     = (int) ($person['user_id'] ?? $person['id'] ?? 0);
$name   = (string) ($person['username'] ?? '');
$avatar = $person['avatar_path'] ?? $person['avatar'] ?? null;

$status = match ($kind) {
    'incoming' => 'Wil vrienden met je worden',
    'sent'     => 'Verzoek verstuurd',
    default    => '',
};
?>
<li class="friend<?= $kind === 'incoming' ? ' friend--request' : '' ?>" data-friend
    <?php if ($kind !== 'template'): ?>data-user-id="<?= e((string) $id) ?>" data-username="<?= e($name) ?>"<?php endif; ?>>

    <span class="account__avatar friend__avatar" data-friend-avatar aria-hidden="true">
        <?php if (!empty($avatar)): ?>
            <img src="<?= e((string) $avatar) ?>" alt="">
        <?php else: ?>
            <?= icon('user') ?>
        <?php endif; ?>
    </span>

    <span class="friend__text">
        <span class="friend__name" data-friend-name><?= e($name) ?></span>
        <span class="friend__status" data-friend-status <?= $status === '' ? 'hidden' : '' ?>><?= e($status) ?></span>
    </span>

    <?php if ($kind === 'incoming'): ?>
        <span class="friend__actions friend__actions--wide" data-friend-actions>
            <button type="button" class="btn press" data-friend-action="accept">Accepteren</button>
            <button type="button" class="btn press" data-friend-action="decline">Weigeren</button>
        </span>
    <?php elseif ($kind === 'friend'): ?>
        <span class="friend__actions" data-friend-actions>
            <button type="button" class="btn btn--link press" data-friend-action="remove-ask">Verwijderen</button>
        </span>

        <?php /* The one step between a tap and a friendship gone. The safe
                 answer comes first, under the thumb that just tapped. */ ?>
        <span class="friend__confirm" data-friend-confirm hidden>
            <span class="friend__confirm-text"><?= e($name) ?> verwijderen uit je vrienden?</span>
            <span class="friend__actions friend__actions--wide">
                <button type="button" class="btn press" data-friend-action="remove-cancel">Annuleren</button>
                <button type="button" class="btn confirm__yes--final press" data-friend-action="remove">Verwijderen</button>
            </span>
        </span>
    <?php else: ?>
        <span class="friend__actions friend__actions--wide" data-friend-actions hidden></span>
    <?php endif; ?>

</li>
