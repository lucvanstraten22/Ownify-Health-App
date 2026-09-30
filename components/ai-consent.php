<?php
/**
 * Ownify AI: the question before anything is sent to Google Gemini, and the
 * screen for somebody who said no. The words are config/dashboard.php's
 * ('ai' → 'consent', 'declined'), the same ones the Android app shows.
 */
declare(strict_types=1);

$ai       = $data['ai'];
$view     = $data['ai_view'];
$consent  = $ai['consent'];
$declined = $ai['declined'];
?>
<section class="ai-consent" data-ai-view="consent" aria-labelledby="ai-consent-title" <?= $view === 'consent' ? '' : 'hidden' ?>>
    <div class="orb orb--small" aria-hidden="true">
        <svg class="orb__ring" viewBox="0 0 160 160"><circle cx="80" cy="80" r="74"/></svg>
        <span class="orb__glow"></span>
        <span class="orb__core"></span>
    </div>

    <h1 class="ai-consent__title" id="ai-consent-title"><?= e($consent['title']) ?></h1>
    <p class="ai-consent__intro"><?= e($consent['intro']) ?></p>

    <ul class="ai-consent__points card">
        <?php foreach ($consent['points'] as $point): ?>
            <li><?= e($point) ?></li>
        <?php endforeach; ?>
    </ul>

    <p class="ai-consent__error" data-ai-consent-error role="alert" hidden></p>

    <div class="ai-consent__actions">
        <button type="button" class="btn press ai-btn--primary" data-ai-consent="accept"><?= e($consent['accept']) ?></button>
        <button type="button" class="btn press" data-ai-consent="decline"><?= e($consent['decline']) ?></button>
    </div>
    <p class="ai-consent__footer"><?= e($consent['footer']) ?></p>
</section>

<section class="ai-declined" data-ai-view="declined" aria-labelledby="ai-declined-title" <?= $view === 'declined' ? '' : 'hidden' ?>>
    <div class="orb orb--small orb--still" aria-hidden="true">
        <svg class="orb__ring" viewBox="0 0 160 160"><circle cx="80" cy="80" r="74"/></svg>
        <span class="orb__core"></span>
    </div>
    <h1 class="ai-consent__title" id="ai-declined-title"><?= e($declined['title']) ?></h1>
    <p class="ai-consent__intro"><?= e($declined['body']) ?></p>
    <div class="ai-consent__actions">
        <button type="button" class="btn press" data-ai-review><?= e($declined['review']) ?></button>
    </div>
    <p class="ai-consent__footer"><?= e($consent['footer']) ?></p>
</section>
