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

/* The card opens the Scorekompas (pages/score-compass.php): the whole card
   is the button's target, and says where the score is heading. */
$compass   = $data['compass'] ?? null;
$direction = $compass['direction'] ?? null;
$openLabel = $compass === null ? '' : $overall['label'] . ': '
    . (has_value($overall['value']) ? tf('%1$s van %2$s', (string) $overall['value'], (string) $overall['max']) : mb_strtolower($overall['caption']))
    . ($direction !== null ? ', ' . mb_strtolower($direction['label']) : '')
    . '. ' . $compass['open'] . '.';
?>
<section class="card card--hero reveal <?= $state ?><?= $compass !== null ? ' card--opens' : '' ?>" aria-labelledby="overview-title">

    <div class="card__head">
        <div>
            <h1 class="card__title" id="overview-title"><?= e($overview['title']) ?></h1>
            <p class="card__meta"><span class="card__meta-day"><?= e($today['weekday']) ?> </span><?= e($today['date']) ?></p>
        </div>
    </div>

    <div class="score-ring score-ring--glow" data-ring data-ring-paint="glow" data-progress="<?= e((string) round($ratio, 4)) ?>"
         role="img"
         aria-label="<?= e($overall['label']) ?>: <?= has_value($overall['value'])
             ? e(tf('%1$s van %2$s', (string) $overall['value'], (string) $overall['max']))
             : e($overall['caption']) ?>">
        <svg class="score-ring__svg" viewBox="0 0 160 160" aria-hidden="true">
            <defs>
                <?php /* The app's green, lighter where it starts: one score, one colour.
                         (A rainbow of the three pillars suggested segments the
                         score does not have.) */ ?>
                <linearGradient id="ringGradient" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%"   style="stop-color: var(--accent-light)"/>
                    <stop offset="100%" style="stop-color: var(--accent)"/>
                </linearGradient>
            </defs>
            <circle class="score-ring__track" cx="80" cy="80" r="68"/>
            <circle class="score-ring__value" cx="80" cy="80" r="68" data-ring-value/>
        </svg>

        <div class="score-ring__center" aria-hidden="true">
            <p class="score-ring__number" data-count-to="<?= has_value($overall['value']) ? e((string) $overall['value']) : '' ?>">
                <?= e(score_text($overall['value'])) ?>
            </p>
            <p class="score-ring__scale"><?= has_value($overall['value']) ? e(tf('van %s', (string) $overall['max'])) : e($overall['caption']) ?></p>
        </div>
    </div>

    <?php /* Where the score is heading (32.2): the Scorekompas's own direction
             (includes/score-compass.php — the first and the last week of 30
             days, 2 points apart), between the ring and its name. Too little
             history: no direction, no bubble. */ ?>
    <?php if ($direction !== null) component('score-trend', ['direction' => $direction]); ?>

    <h2 class="score-ring__label">
        <?php if ($compass !== null): ?>
            <?php /* Its ::after covers the whole card: the ring, the bubble, the
                     name — one way into the Scorekompas. What presses in is
                     the face inside, never the button, so the cover stays the
                     card's size while it is held. */ ?>
            <button type="button" class="card__open" data-detail-open="score-compass" aria-label="<?= e($openLabel) ?>">
                <span class="card__open-face"><?= e($overall['label']) ?><?= icon('chevron-right', 'card__open-chevron') ?></span>
            </button>
        <?php else: ?>
            <?= e($overall['label']) ?>
        <?php endif; ?>
    </h2>
    <p class="card__lede"><?= e($overall['description']) ?></p>

    <?php /* The three pillars the score averages, each with its own score as a
             small ring drawn like the big one: the arc in the score's colour
             (data-score, decided on the server), not the pillar's category
             colour; no score, no arc. hero-ring.js draws them a moment after
             the big one starts. Each opens its own page (32.2), over the
             Scorekompas's cover. */ ?>
    <ul class="pillar-rings" role="list">
        <?php foreach ($contributors as $item): ?>
            <li class="pillar-ring <?= state_class($item['value']) ?>" data-score="<?= e((string) ($item['score_band'] ?? '')) ?>">
                <span class="pillar-ring__dial" aria-hidden="true">
                    <svg viewBox="0 0 40 40">
                        <circle class="pillar-ring__track" cx="20" cy="20" r="16"/>
                        <circle class="pillar-ring__arc" cx="20" cy="20" r="16" data-pillar data-progress="<?= e((string) round(score_ratio($item['value']), 4)) ?>"/>
                    </svg>
                </span>
                <span class="pillar-ring__value"><?= e(score_text($item['value'])) ?></span>
                <span class="pillar-ring__label"><?= e($item['label']) ?></span>
                <?php if (in_array($item['area'] ?? '', ['sleep', 'nutrition', 'training'], true)): ?>
                    <button type="button" class="pillar-ring__open" data-detail-open="<?= e($item['area']) ?>"
                            aria-label="<?= e($item['label'] . ': ' . score_text($item['value'])) ?>"></button>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if (!has_value($overall['value'])): ?>
        <?php /* B8: connect a source (device), or wait a few days (calendar). */ ?>
        <p class="card__hint"><?= icon($overall['empty_icon'] ?? 'device', 'card__hint-icon') ?><?= e($overall['empty_hint']) ?></p>
        <?php /* B15 (50.0): no source yet — the one way to connect one, right here (Apparaten). */ ?>
        <?php if (($overall['empty_icon'] ?? 'device') === 'device'): ?>
            <button type="button" class="text-link score-ring__connect press" data-devices-open><?= e(t('Apparaat koppelen')) ?><?= icon('chevron-right', 'text-link__icon') ?></button>
        <?php endif; ?>
    <?php endif; ?>

</section>
