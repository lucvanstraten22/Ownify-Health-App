<?php
/**
 * OwnifyAI: the conversation, and what shows before there is one — the
 * Ownify logo, "Wat wil je weten?", what the assistant is for, and three
 * questions to start with. The logo is the official one (docs/BRANDING.md),
 * never redrawn.
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
        <span class="ai-logo ai-logo--large" aria-hidden="true">
            <img src="<?= e(asset('assets/brand/ownify-icon-192.png')) ?>" alt="" width="64" height="64" decoding="async">
        </span>

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
