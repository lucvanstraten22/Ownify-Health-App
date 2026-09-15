<?php
/**
 * Primary navigation — exactly five destinations, driven entirely by the
 * `navigation` array in config/dashboard.php (label, icon, order, state).
 *
 * Only the 'overview' destination is built, so the other four render as inert
 * buttons rather than links to pages that do not exist yet.
 */
declare(strict_types=1);

$items = $data['navigation'];
?>
<nav class="tabbar" aria-label="Hoofdnavigatie">
    <ul class="tabbar__list shell" role="list">
        <?php foreach ($items as $item):
            $isActive = !empty($item['active']);
            ?>
            <li class="tabbar__cell">
                <button type="button"
                        class="tab press<?= $isActive ? ' is-active' : '' ?>"
                        data-nav="<?= e($item['id']) ?>"
                        <?= $isActive ? 'aria-current="page"' : '' ?>>
                    <span class="tab__glow" aria-hidden="true"></span>
                    <span class="tab__icon" aria-hidden="true"><?= icon($item['icon']) ?></span>
                    <span class="tab__label"><?= e($item['label']) ?></span>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
