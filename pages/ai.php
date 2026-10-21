<?php
/**
 * OwnifyAI — a sheet that pulls up over whichever page is showing.
 *
 * It is not a destination on the rail: the page underneath keeps its state
 * and its scroll position, so dismissing the sheet always returns the user
 * exactly where they were.
 *
 * One of three screens, chosen by the account's answer to "may Ownify send
 * your data to Google Gemini?" (ai.session.consent, from app_page_data()):
 *
 *   consent    never asked (or asked about other terms): what happens, and
 *              yes or not now
 *   declined   said no: nothing is sent, and the way back to yes
 *   chat       the conversation — the last one, fetched when the sheet
 *              opens (api/ai/state.php); an empty state before the first
 *
 * ai-chat.js switches between them without a reload.
 */
declare(strict_types=1);

$ai      = $data['ai'];
$session = $ai['session'] ?? ['consent' => 'unknown', 'available' => false, 'usage' => ['used' => 0, 'limit' => 0, 'remaining' => 0]];
$view    = match ($session['consent'] ?? 'unknown') {
    'accepted' => 'chat',
    'declined' => 'declined',
    default    => 'consent',
};
?>
<!-- Starts closed and unreachable; ai-sheet.js flips this on open, so the
     state is correct even before (or without) JavaScript. -->
<section class="screen sheet sheet--ai" data-sheet="ai" data-ai data-ai-view-current="<?= e($view) ?>"
         data-ai-session="<?= e(json_encode($session, JSON_UNESCAPED_UNICODE)) ?>"
         data-ai-copy="<?= e(json_encode([
             'title'     => $ai['title'],
             'thinking'  => $ai['thinking'],
             'remaining' => $ai['remaining'],
             'retry'     => $ai['retry'],
             'errors'    => $ai['errors'],
             'delete'    => $ai['delete_chat'],
             'empty'     => $ai['history_empty'],
         ], JSON_UNESCAPED_UNICODE)) ?>"
         aria-label="<?= e($ai['title']) ?>" aria-hidden="true" inert>

    <!-- The sheet's light: green from above, a dawn at the foot (ai.css). -->
    <div class="ai-sky" aria-hidden="true">
        <i class="ai-sky__top"></i>
        <i class="ai-sky__glint"></i>
        <i class="ai-sky__dawn"></i>
    </div>

    <div class="screen__scroll screen__scroll--ai" data-scroller data-ai-scroller>

        <!-- The dismissal area. Vertical movement here is the sheet's, not
             the scroller's, which leaves the conversation free to scroll. -->
        <header class="ai-top" data-ai-dismiss>
            <span class="sheet-handle" aria-hidden="true"></span>

            <!-- A round close on the left; in the middle the island — the Ownify
                 logo, OwnifyAI and how many conversations there are — which
                 opens Gesprekken out of itself. While OwnifyAI thinks it says
                 so, and the logo glows (ai-chat.js). -->
            <div class="ai-top__row shell">
                <button type="button" class="ai-glass ai-round press"
                        data-ai-close aria-label="<?= e($ai['close']['aria']) ?>" title="<?= e($ai['close']['label']) ?>">
                    <?= icon('chevron-down', 'ai-round__icon') ?>
                </button>

                <button type="button" class="ai-glass ai-island press" data-ai-history
                        aria-expanded="false" aria-controls="ai-history"
                        aria-label="<?= e(tf('%s — je gesprekken', $ai['title'])) ?>"
                        <?= $view === 'chat' ? '' : 'disabled' ?>>
                    <span class="ai-logo" aria-hidden="true">
                        <img src="<?= e(asset('assets/brand/ownify-icon-192.png')) ?>" alt="" width="30" height="30" decoding="async">
                    </span>
                    <span class="ai-island__name"><?= e($ai['title']) ?></span>
                    <span class="ai-island__think" aria-hidden="true"><?= e(t('denkt na…')) ?></span>
                    <span class="ai-island__count" data-ai-count aria-hidden="true"></span>
                    <?= icon('chevron-down', 'ai-island__chev') ?>
                </button>

                <span class="ai-top__balance" aria-hidden="true"></span>
            </div>

            <!-- The minute is full for everybody (includes/ai/pace.php): a question
                 sent now waits here and goes by itself at 0 — the sync capsule's
                 glass, counting down (ai-chat.js). -->
            <div class="ai-wait" data-ai-wait data-state="counting" hidden>
                <div class="ai-wait__pill">
                    <span class="ai-wait__icon" aria-hidden="true">
                        <svg class="ai-wait__ring" viewBox="0 0 24 24" focusable="false">
                            <circle class="ai-wait__track" cx="12" cy="12" r="10"/>
                            <circle class="ai-wait__arc" data-ai-wait-arc cx="12" cy="12" r="10" pathLength="100"/>
                        </svg>
                        <span class="ai-wait__num" data-ai-wait-num></span>
                    </span>
                    <span class="ai-wait__label" data-ai-wait-label><?= e(t('Even druk — je vraag wacht')) ?></span>
                    <button type="button" class="ai-wait__cancel press" data-ai-wait-cancel><?= e(t('Annuleren')) ?></button>
                </div>
                <p class="sr-only" role="status" aria-live="polite" data-ai-wait-live></p>
            </div>
        </header>

        <main class="ai-main shell">
            <?php component('ai-consent', $data + ['ai_view' => $view]); ?>
            <?php component('ai-empty-state', $data + ['ai_view' => $view, 'ai_session' => $session]); ?>
        </main>

        <?php component('ai-composer', $data + ['ai_view' => $view, 'ai_session' => $session]); ?>

    </div>

    <!-- Icons ai-chat.js draws into messages and the list. -->
    <template data-ai-icon="check"><?= icon('check') ?></template>
    <template data-ai-icon="trash"><?= icon('trash') ?></template>

    <!-- The conversations: a pane that grows out of the island, never a
         page of its own. Search, a new conversation, and the conversations
         by when they were last used. -->
    <div class="ai-history" id="ai-history" data-ai-history-panel hidden>
        <section class="ai-history__card" role="dialog" aria-modal="true" aria-labelledby="ai-history-title" data-ai-history-card>
            <header class="ai-history__head">
                <h2 class="ai-history__title" id="ai-history-title"><?= e($ai['history']) ?></h2>
                <button type="button" class="ai-glass ai-round ai-round--small press" data-ai-history-close aria-label="<?= e(t('Sluiten')) ?>">
                    <?= icon('chevron', 'ai-round__icon') ?>
                </button>
            </header>

            <label class="ai-history__search">
                <?= icon('search', 'ai-history__search-icon') ?>
                <span class="sr-only"><?= e(t('Zoek in je gesprekken')) ?></span>
                <input type="search" data-ai-history-search placeholder="<?= e(t('Zoek in je gesprekken')) ?>"
                       autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search">
            </label>

            <button type="button" class="ai-history__new press" data-ai-new>
                <?= icon('plus', 'ai-history__new-icon') ?><span><?= e($ai['new_chat']) ?></span>
            </button>

            <div class="ai-history__list" data-ai-history-list></div>
        </section>
    </div>

</section>
