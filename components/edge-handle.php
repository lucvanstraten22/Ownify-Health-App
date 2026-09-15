<?php
/**
 * Right-edge affordance on the dashboard: shows that a second layer sits to
 * the right, and opens it on tap for anyone who cannot use the gesture.
 */
declare(strict_types=1);
?>
<button type="button" class="edge-handle" data-navigate="ai"
        aria-label="<?= e($data['ai']['open']['aria']) ?>">
    <span class="edge-handle__grip" aria-hidden="true"></span>
</button>
