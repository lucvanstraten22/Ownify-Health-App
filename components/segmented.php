<?php
/**
 * A segmented control, reusing the switch already built for the health trend
 * so both pages share one component rather than two lookalikes.
 */
declare(strict_types=1);

$options  = $data['segment_options'];
$selected = $data['segment_selected'];
$attr     = $data['segment_attr'];
$label    = $data['segment_label'];
?>
<div class="range-switch range-switch--wide" role="radiogroup" aria-label="<?= e($label) ?>">
    <?php foreach ($options as $key => $option):
        $isActive = $key === $selected;
        ?>
        <button type="button"
                class="range-switch__option<?= $isActive ? ' is-active' : '' ?>"
                <?= e($attr) ?>="<?= e((string) $key) ?>"
                role="radio" aria-checked="<?= $isActive ? 'true' : 'false' ?>"><?= e($option['label']) ?></button>
    <?php endforeach; ?>
</div>
