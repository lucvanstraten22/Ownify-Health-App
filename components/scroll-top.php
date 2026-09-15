<?php
/**
 * Floating glass control at the content/navigation transition.
 * Purposeful only: it appears after the user has scrolled past the fold and
 * returns them to the primary score in one tap.
 */
declare(strict_types=1);
?>
<button type="button" class="fab press" data-scroll-top hidden aria-label="Terug naar je dagscore">
    <?= icon('chevron') ?>
</button>
