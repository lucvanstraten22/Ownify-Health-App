<?php
/**
 * The night, as one bar of stages.
 *
 * With no data it renders as a dashed empty track with the stages still
 * named, which is the same empty-state idiom the dashboard's meters use.
 */
declare(strict_types=1);

$timeline = $data['area']['timeline'] ?? null;
if ($timeline === null) { return; }

$stages = $timeline['stages'];

$total = 0;
foreach ($stages as $stage) { $total += (int) ($stage['share'] ?? 0); }
$hasData = $total > 0;
?>
<section class="card card--timeline reveal <?= $hasData ? 'is-filled' : 'is-empty' ?>"
         aria-labelledby="timeline-title">

    <div class="card__head card__head--compact">
        <div class="card__headings">
            <h2 class="card__eyebrow" id="timeline-title"><?= e($timeline['title']) ?></h2>
            <p class="card__meta card__meta--small"><?= e($timeline['hint']) ?></p>
        </div>
    </div>

    <div class="hypnogram" role="img"
         aria-label="<?= e($timeline['title']) ?><?= $hasData ? '' : ': nog geen gegevens' ?>">
        <?php if ($hasData): ?>
            <?php foreach ($stages as $stage):
                if ((int) ($stage['share'] ?? 0) <= 0) { continue; }
                ?>
                <span class="hypnogram__stage" data-tone="<?= e($stage['tone']) ?>"
                      style="--share: <?= e((string) $stage['share']) ?>;"></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <ul class="stage-legend" role="list">
        <?php foreach ($stages as $stage): ?>
            <li class="stage-legend__item" data-tone="<?= e($stage['tone']) ?>">
                <span class="stage-legend__dot" aria-hidden="true"></span>
                <span class="stage-legend__label"><?= e($stage['label']) ?></span>
                <span class="stage-legend__value <?= state_class($stage['share']) ?>"><?= has_value($stage['share']) ? e((string) $stage['share']) . '%' : '—' ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

</section>
