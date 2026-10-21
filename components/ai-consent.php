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
    <span class="ai-logo ai-logo--consent" aria-hidden="true">
        <img src="<?= e(asset('assets/brand/ownify-icon-192.png')) ?>" alt="" width="56" height="56" decoding="async">
    </span>

    <h1 class="ai-consent__title" id="ai-consent-title"><?= e($consent['title']) ?></h1>
    <p class="ai-consent__intro"><?= e($consent['intro']) ?></p>

    <ul class="ai-consent__points card">
        <?php foreach ($consent['points'] as $point): ?>
            <li><?= e($point) ?></li>
        <?php endforeach; ?>
    </ul>

    <p class="ai-consent__error" data-ai-consent-error role="alert" hidden></p>

    <div class="ai-consent__actions">
        <button type="button" class="btn btn--primary press" data-ai-consent="accept"><?= e($consent['accept']) ?></button>
        <button type="button" class="btn press" data-ai-consent="decline"><?= e($consent['decline']) ?></button>
    </div>
    <p class="ai-consent__footer"><?= e($consent['footer']) ?></p>
</section>

<section class="ai-declined" data-ai-view="declined" aria-labelledby="ai-declined-title" <?= $view === 'declined' ? '' : 'hidden' ?>>
    <span class="ai-logo ai-logo--consent ai-logo--off" aria-hidden="true">
        <img src="<?= e(asset('assets/brand/ownify-icon-192.png')) ?>" alt="" width="56" height="56" decoding="async">
    </span>
    <h1 class="ai-consent__title" id="ai-declined-title"><?= e($declined['title']) ?></h1>
    <p class="ai-consent__intro"><?= e($declined['body']) ?></p>
    <div class="ai-consent__actions">
        <button type="button" class="btn press" data-ai-review><?= e($declined['review']) ?></button>
    </div>
    <p class="ai-consent__footer"><?= e($consent['footer']) ?></p>
</section>
