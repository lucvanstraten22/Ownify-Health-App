<?php
/**
 * Ownify AI: where a question is typed. Sits at the foot of the sheet and
 * stays there — above the keyboard on a phone (ai-chat.js follows the visual
 * viewport). A line above it says when the assistant cannot answer, and why;
 * one below it how many of today's messages are left.
 */
declare(strict_types=1);

$ai       = $data['ai'];
$view     = $data['ai_view'];
$session  = $data['ai_session'];
$composer = $ai['composer'];
$usage    = $session['usage'] ?? ['remaining' => 0, 'limit' => 0];
?>
<div class="ai-composer shell" data-ai-composer <?= $view === 'chat' ? '' : 'hidden' ?>>
    <p class="ai-notice" data-ai-notice role="status" <?= empty($session['notice']) ? 'hidden' : '' ?>><?= e((string) ($session['notice'] ?? '')) ?></p>

    <form class="ai-composer__form" data-ai-form novalidate>
        <label class="sr-only" for="ai-input"><?= e($composer['aria']) ?></label>
        <textarea class="ai-composer__input" id="ai-input" data-ai-input rows="1" maxlength="2000"
                  placeholder="<?= e($composer['placeholder']) ?>" enterkeyhint="send" autocomplete="off"></textarea>
        <button type="submit" class="ai-send press" data-ai-send aria-label="<?= e($composer['send']) ?>" disabled>
            <?= icon('arrow-up') ?>
        </button>
    </form>

    <p class="ai-composer__meta" data-ai-remaining><?= e(sprintf($ai['remaining'], (int) $usage['remaining'], (int) $usage['limit'])) ?></p>
</div>
