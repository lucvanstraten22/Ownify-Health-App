<?php
/**
 * Fixed mobile navigation — exactly five items, driven by config/dashboard.php.
 * Only "Overzicht" exists in this version, so the other items are inert
 * buttons rather than links to pages that do not exist yet.
 * "Community" ships as label-only: its icon concept is not decided.
 */
declare(strict_types=1);

$items = $data['navigation'];
?>
<nav class="tabbar" aria-label="Hoofdnavigatie">
    <ul class="tabbar__list shell" role="list">
        <?php foreach ($items as $item):
            $isActive   = !empty($item['active']);
            $hasIcon    = !empty($item['icon']);
            ?>
            <li class="tabbar__cell">
                <button type="button"
                        class="tab press<?= $isActive ? ' is-active' : '' ?><?= $hasIcon ? '' : ' tab--label-only' ?>"
                        data-nav="<?= e($item['id']) ?>"
                        <?= $isActive ? 'aria-current="page"' : '' ?>>
                    <span class="tab__glow" aria-hidden="true"></span>
                    <?php if ($hasIcon): ?>
                        <span class="tab__icon" aria-hidden="true"><?= icon($item['icon']) ?></span>
                    <?php endif; ?>
                    <span class="tab__label"><?= e($item['label']) ?></span>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
