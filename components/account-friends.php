<?php
/**
 * Vrienden — the account panel's second page, behind the Vrienden button.
 *
 *   Vriend toevoegen          a username, then Zoeken: the one account with
 *                             exactly that name, and a request if you can send
 *                             one. Nothing is looked up while you type.
 *   Vriendverzoeken           requests waiting for you — Accepteren or
 *                             Weigeren — and the ones you sent
 *   Vriendverzoeken toestaan  off, nobody can send you a new request
 *   Vrienden (n)              your friends, each with Verwijderen
 *
 * Everything here is read from the database for the signed-in account
 * (lib/hydrate-community.php) and changed through api/friends/, which takes
 * the account from the session. friends.js swaps the lists in again after
 * every change, together with the Friends leaderboard, so both always show
 * what the database now holds.
 */
declare(strict_types=1);

$community = $data['community'];
$incoming  = $community['pending'] ?? [];
$sent      = $community['sent'] ?? [];
$friends   = $community['friends'] ?? [];
$allowed   = !empty($community['allow_requests']);

$notes = [
    'on'  => 'Anderen kunnen je een vriendverzoek sturen.',
    'off' => 'Niemand kan je een nieuw vriendverzoek sturen. Je vrienden blijven.',
];
?>
<div class="account__view friends" data-account-view="friends" hidden>

    <!-- ------------------------------------------------ Vriend toevoegen -->
    <section class="friends__section" aria-label="Vriend toevoegen">
        <button type="button" class="btn press friends__add" data-friends-add
                aria-expanded="false" aria-controls="friends-search">
            <?= icon('plus', 'friends__add-icon') ?>
            <span>Vriend toevoegen</span>
        </button>

        <form class="account__form friends__search" id="friends-search" data-friends-search novalidate hidden>
            <label class="account__label" for="friends-username">Gebruikersnaam</label>
            <div class="account__row">
                <input class="account__input" type="text" id="friends-username" name="username"
                       maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false"
                       placeholder="Gebruikersnaam" required>
                <button type="submit" class="btn press">Zoeken</button>
            </div>
            <p class="account__hint">Vul de volledige gebruikersnaam in.</p>

            <p class="account__error friends__error" data-friends-search-error role="alert" hidden></p>

            <ul class="friends__list friends__result" role="list" data-friends-result aria-live="polite" hidden></ul>
        </form>
    </section>

    <!-- ------------------------------------------------ Vriendverzoeken -->
    <section class="friends__section" data-friends-requests aria-labelledby="friends-requests-title">
        <h3 class="account__label" id="friends-requests-title">Vriendverzoeken</h3>

        <?php if ($incoming === []): ?>
            <p class="account__hint friends__empty">Geen nieuwe vriendverzoeken.</p>
        <?php else: ?>
            <ul class="friends__list" role="list">
                <?php foreach ($incoming as $person): ?>
                    <?php component('friend-person', $data + ['person' => $person, 'person_kind' => 'incoming']); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($sent !== []): ?>
            <h4 class="account__label friends__sublabel">Verstuurd</h4>
            <ul class="friends__list" role="list">
                <?php foreach ($sent as $person): ?>
                    <?php component('friend-person', $data + ['person' => $person, 'person_kind' => 'sent']); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- ---------------------------------------- Vriendverzoeken toestaan -->
    <button type="button" class="account__toggle" role="switch" data-friends-toggle
            aria-checked="<?= $allowed ? 'true' : 'false' ?>"
            data-note-on="<?= e($notes['on']) ?>" data-note-off="<?= e($notes['off']) ?>">
        <span class="account__toggle-text">
            <span class="account__toggle-label">Vriendverzoeken toestaan</span>
            <span class="account__toggle-note" data-friends-toggle-note><?= e($allowed ? $notes['on'] : $notes['off']) ?></span>
        </span>
        <span class="switch<?= $allowed ? ' is-on' : '' ?>" aria-hidden="true"><span class="switch__knob"></span></span>
    </button>

    <!-- --------------------------------------------------------- Vrienden -->
    <section class="friends__section" data-friends-list aria-labelledby="friends-list-title">
        <h3 class="account__label" id="friends-list-title">Vrienden (<?= e((string) count($friends)) ?>)</h3>

        <?php if ($friends === []): ?>
            <p class="account__hint friends__empty">Je hebt nog geen vrienden. Voeg iemand toe met zijn of haar gebruikersnaam.</p>
        <?php else: ?>
            <ul class="friends__list" role="list">
                <?php foreach ($friends as $person): ?>
                    <?php component('friend-person', $data + ['person' => $person, 'person_kind' => 'friend']); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php /* The shape of a search result, filled in by friends.js, and the
             state a sent request turns into. */ ?>
    <template data-friends-template>
        <?php component('friend-person', $data + ['person' => [], 'person_kind' => 'template']); ?>
    </template>

    <template data-friends-sent>
        <span class="friend__done"><?= icon('check') ?><span>Verzoek verstuurd</span></span>
    </template>

</div>
