<?php
/**
 * Ownify AI: the conversation, and what shows before there is one — the orb,
 * what the assistant is for, and three questions to start with. The dotted
 * ring is the same idiom the dashboard uses for "no data yet", which is what
 * ties this screen to the rest of the app.
 *
 * The messages themselves are drawn by ai-chat.js from api/ai/state.php and
 * api/ai/chat.php: text and bold only, never HTML from the model.
 */
declare(strict_types=1);

$ai      = $data['ai'];
$view    = $data['ai_view'];
$session = $data['ai_session'];
$empty   = $ai['empty'];
?>
<section class="ai-chat" data-ai-view="chat" aria-label="<?= e($ai['title']) ?>" <?= $view === 'chat' ? '' : 'hidden' ?>>

    <div class="ai-empty" data-ai-empty>
        <div class="orb" data-orb aria-hidden="true">
            <svg class="orb__ring" viewBox="0 0 160 160">
                <circle cx="80" cy="80" r="74"/>
            </svg>
            <span class="orb__glow"></span>
            <span class="orb__core"></span>
        </div>

        <h1 class="ai-empty__title"><?= e($empty['title']) ?></h1>
        <p class="ai-empty__status"><?= e($empty['body']) ?></p>
        <p class="ai-empty__note" data-ai-no-data <?= empty($session['has_data']) ? '' : 'hidden' ?>><?= e($empty['no_data']) ?></p>

        <div class="ai-suggestions" role="list">
            <?php foreach ($empty['suggestions'] as $suggestion): ?>
                <button type="button" class="ai-suggestion press" role="listitem" data-ai-suggestion><?= e($suggestion) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <ol class="ai-thread" data-ai-thread aria-live="polite" aria-relevant="additions" hidden></ol>
</section>
