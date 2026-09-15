<?php
/**
 * Reserved space for the future input interface.
 *
 * Deliberately inert: no field, no caret, no send button, nothing that could
 * be mistaken for a working composer. The dashed hairline is the same
 * empty-state treatment used for the dashboard's meters.
 */
declare(strict_types=1);

$composer = $data['ai']['composer'];
?>
<div class="ai-composer shell">
    <div class="ai-composer__slot" role="note" aria-label="<?= e($composer['aria']) ?>">
        <span class="ai-composer__note"><?= e($composer['note']) ?></span>
    </div>
</div>
