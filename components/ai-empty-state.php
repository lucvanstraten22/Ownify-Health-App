<?php
/**
 * The assistant's empty state: an abstract glass orb, its name, its status.
 * The dotted ring is the same idiom the dashboard uses for "no data yet",
 * which is what ties this screen to the rest of the app.
 */
declare(strict_types=1);

$ai = $data['ai'];
?>
<div class="ai-empty">

    <div class="orb" data-orb aria-hidden="true">
        <svg class="orb__ring" viewBox="0 0 160 160">
            <circle cx="80" cy="80" r="74"/>
        </svg>
        <span class="orb__glow"></span>
        <span class="orb__core"></span>
    </div>

    <h1 class="ai-empty__title"><?= e($ai['title']) ?></h1>
    <p class="ai-empty__status"><?= e($ai['status']) ?></p>

</div>
