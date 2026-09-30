<?php
/**
 * Ownify AI — a sheet that pulls up over whichever page is showing.
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
             'thinking'  => $ai['thinking'],
             'remaining' => $ai['remaining'],
             'retry'     => $ai['retry'],
             'errors'    => $ai['errors'],
             'delete'    => $ai['delete_chat'],
             'empty'     => $ai['history_empty'],
         ], JSON_UNESCAPED_UNICODE)) ?>"
         aria-label="<?= e($ai['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll screen__scroll--ai" data-scroller data-ai-scroller>

        <!-- The dismissal area. Vertical movement here is the sheet's, not
             the scroller's, which leaves the conversation free to scroll. -->
        <header class="ai-top" data-ai-dismiss>
            <span class="sheet-handle" aria-hidden="true"></span>

            <div class="ai-top__row shell">
                <button type="button" class="pill pill--close press"
                        data-ai-close aria-label="<?= e($ai['close']['aria']) ?>">
                    <?= icon('chevron-down', 'pill__icon') ?>
                    <span class="pill__label"><?= e($ai['close']['label']) ?></span>
                </button>

                <div class="ai-top__actions" data-ai-tools <?= $view === 'chat' ? '' : 'hidden' ?>>
                    <button type="button" class="pill pill--icon press" data-ai-new
                            aria-label="<?= e($ai['new_chat']) ?>" title="<?= e($ai['new_chat']) ?>">
                        <?= icon('plus', 'pill__icon') ?>
                    </button>
                    <button type="button" class="pill pill--icon press" data-ai-history
                            aria-label="<?= e($ai['history']) ?>" title="<?= e($ai['history']) ?>"
                            aria-expanded="false" aria-controls="ai-history">
                        <?= icon('chat', 'pill__icon') ?>
                    </button>
                </div>
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

    <!-- The conversations: a pane over the sheet, never a page of its own. -->
    <div class="ai-history" id="ai-history" data-ai-history-panel hidden>
        <div class="ai-history__card">
            <div class="ai-history__head">
                <h2 class="ai-history__title"><?= e($ai['history']) ?></h2>
                <button type="button" class="btn press ai-history__new" data-ai-new>
                    <?= icon('plus', 'btn__icon') ?><span><?= e($ai['new_chat']) ?></span>
                </button>
            </div>
            <ul class="ai-history__list" data-ai-history-list></ul>
        </div>
    </div>

</section>
