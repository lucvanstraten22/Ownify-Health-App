<?php
/**
 * Level 2 — the handful of numbers that explain the score.
 * Deliberately a fixed, small set: the long tail lives in the groups below.
 */
declare(strict_types=1);

$registry = $data['health']['metrics'];
$tiles    = $data['tiles'];
$title    = $data['tiles_title'] ?? 'Vandaag';
?>
<section class="card card--tiles reveal" aria-labelledby="tiles-<?= e($data['area']['id']) ?>">

    <h2 class="card__eyebrow" id="tiles-<?= e($data['area']['id']) ?>"><?= e($title) ?></h2>

    <ul class="metric-tiles" role="list">
        <?php foreach ($tiles as $entry):
            $metric = health_metric($entry, $registry);
            ?>
            <li class="metric-tile <?= state_class($metric['value']) ?>">
                <p class="metric-tile__label"><?= e($metric['label']) ?></p>
                <p class="metric-tile__value">
                    <span data-count-to="<?= is_numeric($metric['value']) ? e((string) $metric['value']) : '' ?>"><?= e(score_text($metric['value'])) ?></span>
                    <?php if ($metric['unit'] !== '' && has_value($metric['value'])): ?>
                        <span class="metric-tile__unit"><?= e($metric['unit']) ?></span>
                    <?php endif; ?>
                </p>
            </li>
        <?php endforeach; ?>
    </ul>

</section>
