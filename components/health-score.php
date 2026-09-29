<?php
/**
 * Primary block — general health score.
 * The single most dominant element on the page: large ring, large number.
 */
declare(strict_types=1);

$overview     = $data['overview'];
$overall      = $data['scores']['overall'];
$contributors = $data['scores']['contributors'];
$focus        = $data['focus'];
$focusLabel   = $data['focus_labels'][$focus] ?? $data['focus_labels']['general'];

$today  = today_parts();
$ratio  = score_ratio($overall['value'], $overall['max']);
$state  = state_class($overall['value']);
?>
<section class="card card--hero reveal <?= $state ?>" aria-labelledby="overview-title">

    <div class="card__head">
        <div>
            <h1 class="card__title" id="overview-title"><?= e($overview['title']) ?></h1>
            <p class="card__meta"><span class="card__meta-day"><?= e($today['weekday']) ?> </span><?= e($today['date']) ?></p>
        </div>
        <span class="chip chip--focus" title="Gekozen focus">
            <span class="chip__dot" aria-hidden="true"></span>
            <?= e($focusLabel) ?>
        </span>
    </div>

    <div class="score-ring" data-ring data-progress="<?= e((string) round($ratio, 4)) ?>"
         role="img"
         aria-label="<?= e($overall['label']) ?>: <?= has_value($overall['value'])
             ? e((string) $overall['value']) . ' van ' . e((string) $overall['max'])
             : e($overall['caption']) ?>">
        <svg class="score-ring__svg" viewBox="0 0 160 160" aria-hidden="true">
            <defs>
                <linearGradient id="ringGradient" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%"   stop-color="var(--sleep)"/>
                    <stop offset="55%"  stop-color="var(--training)"/>
                    <stop offset="100%" stop-color="var(--nutrition)"/>
                </linearGradient>
            </defs>
            <circle class="score-ring__track" cx="80" cy="80" r="68"/>
            <circle class="score-ring__value" cx="80" cy="80" r="68" data-ring-value/>
        </svg>

        <div class="score-ring__center" aria-hidden="true">
            <p class="score-ring__number" data-count-to="<?= has_value($overall['value']) ? e((string) $overall['value']) : '' ?>">
                <?= e(score_text($overall['value'])) ?>
            </p>
            <p class="score-ring__scale"><?= has_value($overall['value']) ? 'van ' . e((string) $overall['max']) : e($overall['caption']) ?></p>
        </div>
    </div>

    <h2 class="score-ring__label"><?= e($overall['label']) ?></h2>
    <p class="card__lede"><?= e($overall['description']) ?></p>

    <?php /* The three pillars the score averages, each with its own score. The
             dot is the score's colour (data-score, decided on the server), not
             the pillar's category colour. */ ?>
    <ul class="legend" role="list">
        <?php foreach ($contributors as $item): ?>
            <li class="legend__item <?= state_class($item['value']) ?>" data-score="<?= e((string) ($item['score_band'] ?? '')) ?>">
                <span class="legend__dot" aria-hidden="true"></span>
                <span class="legend__label"><?= e($item['label']) ?></span>
                <span class="legend__value"><?= e(score_text($item['value'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if (!has_value($overall['value'])): ?>
        <p class="card__hint"><?= icon('lock', 'card__hint-icon') ?><?= e($overall['empty_hint']) ?></p>
    <?php endif; ?>

</section>
