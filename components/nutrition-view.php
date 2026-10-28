<?php
/**
 * Voeding, drawn (lib/hydrate-nutrition.php, docs/NUTRITION.md), under the
 * score:
 *
 *   1  Hoe at je?     your cijfer as a slider, saved when it is let go, for
 *                     the day shown
 *   2  Samenstelling  the day — today or one of the six before, ‹ › — as a
 *                     doughnut of the energy from carbohydrates, protein and
 *                     fat, the size of the score's ring, and those three as
 *                     rows that open in place (a tap on the ring opens its
 *                     row): grams, share, the range as a green band with your
 *                     marker, a bubble, and their details
 *   3  Energie, Water two of Ownify's small area charts, side by side, each
 *                     opening its own page (components/area-chart.php)
 *
 * Every number and word is the server's; nutrition.js only shows the day
 * chosen, opens a row and saves the slider. The seven days are all drawn
 * here, one shown, so stepping between them needs no request.
 */
declare(strict_types=1);

$view    = $data['view'];
$rating  = $view['rating'];
$comp    = $view['composition'];
$days    = $view['days'];

/* The doughnut: the same outer edge as the score's ring above it (r 68 +
   3.5 of 80), in a 244 box drawn at --hero-ring. */
$size = 244.0;
$c    = $size / 2;
$r    = $c * 0.894;
$w    = $size * 0.12;
$arc  = static function (float $a0, float $a1) use ($c, $r, $w): string {
    $ri  = $r - $w;
    $p   = static fn (float $a, float $rr): string => round($c + $rr * cos($a), 2) . ' ' . round($c + $rr * sin($a), 2);
    $big = $a1 - $a0 > M_PI ? 1 : 0;

    return 'M' . $p($a0, $r) . 'A' . round($r, 2) . ' ' . round($r, 2) . ' 0 ' . $big . ' 1 ' . $p($a1, $r)
        . 'L' . $p($a1, $ri) . 'A' . round($ri, 2) . ' ' . round($ri, 2) . ' 0 ' . $big . ' 0 ' . $p($a0, $ri) . 'Z';
};
$bubble = static fn (?string $verdict, ?string $text): string => $verdict === null || $text === null ? ''
    : '<span class="glass glass--chip nutrition-bubble" data-verdict="' . e($verdict) . '">' . e($text) . '</span>';
?>
<section class="card nutrition-rating-card reveal" data-nutrition-slider aria-labelledby="nutrition-rating-title">
    <div class="card__head card__head--compact">
        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('sparkle') ?><?= function_exists('notice_dot') ? notice_dot('rating') : '' ?></span>
        <h2 class="card__eyebrow" id="nutrition-rating-title"><?= e($rating['title']) ?></h2>
    </div>

    <div class="rating-slider" data-rating-slider>
        <p class="rating-slider__top">
            <span class="rating-slider__value" data-rating-shown>–</span>
            <span class="rating-slider__question" data-rating-question></span>
        </p>
        <div class="rating-slider__track" data-rating-track data-gesture-own role="slider" tabindex="0"
             aria-label="<?= e($rating['label']) ?>" aria-valuemin="1" aria-valuemax="10" aria-valuenow="6">
            <span class="rating-slider__rail" aria-hidden="true"></span>
            <span class="rating-slider__fill" aria-hidden="true" data-rating-fill></span>
            <span class="rating-slider__ticks" aria-hidden="true"><?= str_repeat('<i></i>', 10) ?></span>
            <span class="glass rating-slider__thumb" aria-hidden="true" data-rating-thumb></span>
        </div>
        <p class="rating-slider__scale" aria-hidden="true"><span>1</span><span>5</span><span>10</span></p>
        <p class="status rating-slider__saved" data-tone="neutral" role="status" data-rating-saved>
            <span class="status__dot" aria-hidden="true"></span>
            <span class="status__text" data-rating-saved-text></span>
        </p>
    </div>
    <?php /* What the Dutch guidelines count as eating well — context for the
             cijfer, never a score of it (docs/INSIGHTS.md). */
    $reference = $data['insights']['references']['nutrition.rating'] ?? null;
    if ($reference !== null && is_file(dirname(__DIR__) . '/components/research-evidence.php')) {
        component('research-evidence', $data + ['evidence' => $reference]);
    } ?>
</section>

<section class="card nutrition-composition reveal" data-nutrition aria-labelledby="nutrition-composition-title">
    <div class="card__head card__head--compact">
        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('utensils') ?></span>
        <h2 class="card__eyebrow" id="nutrition-composition-title"><?= e($comp['title']) ?></h2>
    </div>

    <div class="nutrition-daynav">
        <button type="button" class="glass glass--round glass--small press" data-nutrition-step="1" aria-label="<?= e($comp['older']) ?>"><?= icon('chevron-left') ?></button>
        <p class="nutrition-daynav__name" data-nutrition-name aria-live="polite"><?= e($days[0]['name']) ?></p>
        <button type="button" class="glass glass--round glass--small press" data-nutrition-step="-1" aria-label="<?= e($comp['newer']) ?>" disabled><?= icon('chevron-right') ?></button>
    </div>

    <?php foreach ($days as $i => $day): ?>
        <div class="nutrition-day" data-nutrition-day="<?= $i ?>" <?= $i === 0 ? '' : 'hidden' ?>>
            <div class="nutrition-day__grid">
                <div class="nutrition-ring" data-ring-box>
                    <?php if ($day['kcal'] === null): ?>
                        <svg class="nutrition-ring__svg" viewBox="0 0 <?= (int) $size ?> <?= (int) $size ?>" aria-hidden="true">
                            <circle class="nutrition-ring__empty" cx="<?= $c ?>" cy="<?= $c ?>" r="<?= round($r - $w / 2, 2) ?>"/>
                        </svg>
                        <div class="nutrition-ring__center"><p class="nutrition-ring__sub"><?= e($day['note'] ?? '') ?></p></div>
                    <?php else:
                        $a = -M_PI / 2; $gap = 0.035; ?>
                        <svg class="nutrition-ring__svg" viewBox="0 0 <?= (int) $size ?> <?= (int) $size ?>"
                             role="img" aria-label="<?= e(implode(', ', array_map(static fn ($m) => $m['label'] . ' ' . i18n_number((float) $m['share'], 0) . '%', $day['macros']))) ?>">
                            <?php foreach ($day['macros'] as $m):
                                $a1 = $a + ((float) $m['share'] / 100) * 2 * M_PI; ?>
                                <path class="nutrition-ring__part" data-macro="<?= e($m['key']) ?>" data-part="<?= e($m['key']) ?>"
                                      d="<?= e($arc($a + $gap / 2, $a1 - $gap / 2)) ?>"/>
                                <?php $a = $a1; endforeach; ?>
                        </svg>
                        <div class="nutrition-ring__center" aria-hidden="true">
                            <div data-ring-total>
                                <p class="nutrition-ring__value"><?= e($day['centre']['value']) ?></p>
                                <p class="nutrition-ring__sub"><?= e($day['centre']['sub']) ?></p>
                            </div>
                            <?php foreach ($day['macros'] as $m): ?>
                                <div data-ring-macro="<?= e($m['key']) ?>" hidden>
                                    <p class="nutrition-ring__label"><?= e($m['label']) ?></p>
                                    <p class="nutrition-ring__value"><?= e(i18n_number((float) $m['share'], 0)) ?> %</p>
                                    <p class="nutrition-ring__sub"><?= e($m['facts'][0]['value'] ?? '') ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="nutrition-rows">
                    <?php foreach ($day['macros'] as $m): $id = 'nutrition-' . $i . '-' . $m['key']; ?>
                        <div class="nutrition-row" data-macro="<?= e($m['key']) ?>">
                            <button type="button" class="nutrition-row__head press" data-row-toggle="<?= e($m['key']) ?>"
                                    aria-expanded="false" aria-controls="<?= e($id) ?>" <?= $m['logged'] ? '' : 'disabled' ?>>
                                <span class="nutrition-row__key" aria-hidden="true"></span>
                                <span class="nutrition-row__name"><?= e($m['label']) ?></span>
                                <span class="nutrition-row__grams"><?= e($m['head']) ?></span>
                                <?php if ($m['logged']): ?><?= icon('chevron-right', 'nutrition-row__chevron') ?><?php endif; ?>
                            </button>
                            <?php if ($m['logged']): ?>
                                <p class="nutrition-row__sub">
                                    <?php foreach ($m['sub'] as $line): ?><span><?= e($line) ?></span><?php endforeach; ?>
                                </p>
                                <?= $bubble($m['verdict'], $m['verdict_text']) ?>
                                <?php if ($m['meter'] !== null):
                                    $pct = static fn (float $v) => round(max(0, min(100, $v / (float) $m['meter']['max'] * 100)), 2); ?>
                                    <span class="nutrition-meter" aria-hidden="true">
                                        <?php if ($m['meter']['low'] !== null): ?>
                                            <span class="nutrition-meter__band" style="left: <?= $pct((float) $m['meter']['low']) ?>%; width: <?= $pct((float) $m['meter']['high']) - $pct((float) $m['meter']['low']) ?>%;"></span>
                                        <?php endif; ?>
                                        <span class="nutrition-meter__value" style="width: <?= $pct((float) $m['meter']['value']) ?>%;"></span>
                                        <span class="nutrition-meter__mark" style="left: <?= $pct((float) $m['meter']['value']) ?>%;"></span>
                                    </span>
                                <?php endif; ?>
                                <div class="nutrition-row__more" id="<?= e($id) ?>">
                                    <div>
                                        <dl class="nutrition-row__facts">
                                            <?php foreach ($m['facts'] as $f): ?>
                                                <dt><?= e($f['label']) ?></dt><dd><?= e($f['value']) ?></dd>
                                            <?php endforeach; ?>
                                        </dl>
                                        <?php if ($m['why'] !== null): ?><p class="nutrition-row__why"><?= e($m['why']) ?></p><?php endif; ?>
                                        <?php if ($m['details'] !== []): ?>
                                            <ul class="nutrition-row__details" role="list">
                                                <?php foreach ($m['details'] as $d): ?>
                                                    <li>
                                                        <span class="nutrition-row__detail"><?= e($d['label']) ?></span>
                                                        <?php if ($d['value'] !== null): ?>
                                                            <strong><?= e($d['value']) ?></strong>
                                                            <?php if ($d['extra'] !== null): ?><span class="nutrition-row__extra"><?= e($d['extra']) ?></span><?php endif; ?>
                                                            <?= $bubble($d['verdict'], $d['verdict_text']) ?>
                                                        <?php else: ?>
                                                            <span class="nutrition-row__extra"><?= e($d['verdict_text']) ?></span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <?php /* The research behind this row's guideline (docs/INSIGHTS.md):
                                                 fat's range, and that carbohydrates have none. */
                                        $reference = $data['insights']['references']['nutrition.' . $m['key']] ?? null;
                                        if ($reference !== null && is_file(dirname(__DIR__) . '/components/research-evidence.php')) {
                                            component('research-evidence', $data + ['evidence' => $reference]);
                                        } ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php /* The hint only where there is a ring to tap; an empty day says why in the ring itself. */ ?>
            <?php if ($day['kcal'] !== null): ?><p class="nutrition-day__hint"><?= e($comp['hint']) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php /* The days for the script: each one's name and cijfer, and the slider's words. */ ?>
    <script type="application/json" data-nutrition-days><?= json_encode([
        'days'   => array_map(static fn (array $d): array => ['date' => $d['date'], 'name' => $d['name'], 'word' => $d['word'], 'rating' => $d['rating']], $days),
        'rating' => $rating,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</section>

<?php component('area-charts-grid', $data + ['grid_charts' => $view['charts'], 'chart_area' => 'nutrition']); ?>
