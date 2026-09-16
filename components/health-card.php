<?php
/**
 * One of the three health pillars. Built from the same parts as the
 * dashboard's score cards — icon tile, score value, meter — so the two pages
 * read as one product. The whole card is the control that opens the detail.
 */
declare(strict_types=1);

$area  = $data['area'];
$score = $area['score'];
$ratio = score_ratio($score['value'], $score['max']);
?>
<button type="button"
        class="card health-card press <?= state_class($score['value']) ?>"
        data-accent="<?= e($area['accent']) ?>"
        data-detail-open="<?= e($area['id']) ?>"
        aria-label="<?= e($area['label']) ?> — <?= has_value($score['value'])
            ? e((string) $score['value']) . ' van ' . e((string) $score['max'])
            : 'nog geen gegevens' ?>. Open details.">

    <span class="icon-tile" aria-hidden="true"><?= icon($area['icon']) ?></span>

    <span class="score-value score-value--centred">
        <span class="score-value__number" data-count-to="<?= has_value($score['value']) ? e((string) $score['value']) : '' ?>"><?= e(score_text($score['value'])) ?></span>
        <span class="score-value__max">/<?= e((string) $score['max']) ?></span>
    </span>

    <span class="meter" aria-hidden="true">
        <span class="meter__fill" data-bar data-progress="<?= e((string) round($ratio, 4)) ?>"></span>
    </span>

    <span class="health-card__label">
        <?= e($area['label']) ?>
        <?= icon('chevron-right', 'health-card__chevron') ?>
    </span>

</button>
