<?php
/**
 * Vrienden — the account panel's second page, behind the Vrienden button.
 *
 *   Vriend toevoegen          a username, then Zoeken: the one account with
 *                             exactly that name, and a request if you can send
 *                             one. Nothing is looked up while you type.
 *   Vriendverzoeken           requests waiting for you — Accepteren or
 *                             Weigeren — and the ones you sent
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
?>
<div class="account__view friends" data-account-view="friends" hidden>

    <!-- ------------------------------------------------ Vriend toevoegen -->
    <section class="friends__section" aria-label="<?= e(t('Vriend toevoegen')) ?>">
        <button type="button" class="btn press friends__add" data-friends-add
                aria-expanded="false" aria-controls="friends-search">
            <?= icon('user-plus', 'friends__add-icon') ?>
            <span><?= e(t('Vriend toevoegen')) ?></span>
        </button>

        <form class="account__form friends__search" id="friends-search" data-friends-search novalidate hidden>
            <label class="account__label" for="friends-username"><?= e(t('Gebruikersnaam')) ?></label>
            <?php /* The search is in the field (50.0): its round arrow, as the
                     password eye and the goal search's clear are, and the
                     keyboard's own search key. */ ?>
            <div class="friends__field">
                <input class="account__input" type="text" id="friends-username" name="username"
                       maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false"
                       enterkeyhint="search" placeholder="<?= e(t('Gebruikersnaam')) ?>" required>
                <button type="submit" class="friends__go press" aria-label="<?= e(t('Zoeken')) ?>"><?= icon('chevron-right') ?></button>
            </div>
            <p class="account__hint"><?= e(t('Vul de volledige gebruikersnaam in.')) ?></p>

            <p class="account__error friends__error" data-friends-search-error role="alert" hidden></p>

            <ul class="friends__list friends__result" role="list" data-friends-result aria-live="polite" hidden></ul>
        </form>
    </section>

    <!-- ------------------------------------------------ Vriendverzoeken -->
    <section class="friends__section" data-friends-requests aria-labelledby="friends-requests-title">
        <h3 class="account__label" id="friends-requests-title"><?= e(t('Vriendverzoeken')) ?></h3>

        <?php if ($incoming === []): ?>
            <p class="account__hint friends__empty"><?= e(t('Geen nieuwe vriendverzoeken.')) ?></p>
        <?php else: ?>
            <ul class="friends__list" role="list">
                <?php foreach ($incoming as $person): ?>
                    <?php component('friend-person', $data + ['person' => $person, 'person_kind' => 'incoming']); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($sent !== []): ?>
            <h4 class="account__label friends__sublabel"><?= e(t('Verstuurd')) ?></h4>
            <ul class="friends__list" role="list">
                <?php foreach ($sent as $person): ?>
                    <?php component('friend-person', $data + ['person' => $person, 'person_kind' => 'sent']); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- --------------------------------------------------------- Vrienden -->
    <section class="friends__section" data-friends-list aria-labelledby="friends-list-title">
        <h3 class="account__label" id="friends-list-title"><?= e(t('Vrienden')) ?> (<?= e((string) count($friends)) ?>)</h3>

        <?php if ($friends === []): ?>
            <p class="account__hint friends__empty"><?= e(t('Je hebt nog geen vrienden. Voeg iemand toe met zijn of haar gebruikersnaam.')) ?></p>
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
        <span class="friend__done"><?= icon('check') ?><span><?= e(t('Verzoek verstuurd')) ?></span></span>
    </template>

</div>
