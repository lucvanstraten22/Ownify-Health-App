<?php
/**
 * Secondary score cards — Slaap and Voeding & Sport.
 * One shared card system: identical radius, padding, icon slot, score slot,
 * metric rows and spacing, so the two read as a single component.
 */
declare(strict_types=1);

$cards = $data['scores']['secondary'];
$focus = $data['focus'];

/** Which card the onboarding focus promotes (null for 'general'). */
$focusMap = [
    'sleep'     => 'sleep',
    'nutrition' => 'nutrition_sport',
    'mobility'  => 'nutrition_sport',
];
$focusedKey = $focusMap[$focus] ?? null;
?>
<section class="section" aria-labelledby="categories-title">
    <h2 class="section__title" id="categories-title">Onderdelen</h2>

    <div class="grid grid--two<?= $focusedKey !== null ? ' grid--stacked' : '' ?>">
        <?php foreach ($cards as $card):
            $isFocused = $focusedKey === $card['key'];
            $metrics   = $isFocused ? $card['focus_metrics'] : $card['metrics'];
            $ratio     = score_ratio($card['value'], $card['max']);
            ?>
            <article class="card card--score reveal <?= state_class($card['value']) ?><?= $isFocused ? ' is-focused' : '' ?>"
                     data-accent="<?= e($card['accent']) ?>">

                <div class="card__head card__head--compact">
                    <span class="icon-tile" aria-hidden="true"><?= icon($card['icon']) ?></span>
                    <span class="card__caption"><?= e($card['caption']) ?></span>
                </div>

                <h3 class="card__subtitle"><?= e($card['label']) ?></h3>

                <p class="score-value">
                    <span class="score-value__number" data-count-to="<?= has_value($card['value']) ? e((string) $card['value']) : '' ?>"><?= e(score_text($card['value'])) ?></span>
                    <span class="score-value__max">/<?= e((string) $card['max']) ?></span>
                </p>

                <div class="meter" role="img"
                     aria-label="<?= e($card['label']) ?>: <?= has_value($card['value']) ? e((string) $card['value']) . ' van ' . e((string) $card['max']) : 'nog geen gegevens' ?>">
                    <span class="meter__fill" data-bar data-progress="<?= e((string) round($ratio, 4)) ?>"></span>
                </div>

                <ul class="metrics" role="list">
                    <?php foreach ($metrics as $metric): ?>
                        <li class="metrics__row" data-accent="<?= e($metric['accent']) ?>">
                            <span class="metrics__dot" aria-hidden="true"></span>
                            <span class="metrics__label"><?= e($metric['label']) ?></span>
                            <span class="metrics__value <?= state_class($metric['value']) ?>"><?= e(score_text($metric['value'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>

            </article>
        <?php endforeach; ?>
    </div>
</section>
