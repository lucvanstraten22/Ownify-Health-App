<?php
/**
 * The evidence beside an insight or a reference value (docs/INSIGHTS.md): its
 * label as the glass chip of the goal wizard's categories (.glass--chip), and
 * "Bekijk onderzoek" as a quiet link (.btn--link). Both open the same
 * detail, pages/research-detail.php, over whatever page shows them.
 *
 * $data['evidence']: ['research' => id, 'strength' => ['key', 'label'], 'label' => 'Bekijk onderzoek']
 */
declare(strict_types=1);

$evidence = $data['evidence'] ?? null;
$details  = $data['research']['details'] ?? [];

if (!is_array($evidence) || !isset($details[$evidence['research'] ?? ''])) {
    return;
}

$target = 'research-' . $evidence['research'];
$word   = (string) ($data['research']['copy']['evidence'] ?? '');
?>
<span class="research-evidence">
    <button type="button" class="glass glass--chip press" data-detail-open="<?= e($target) ?>"
            aria-label="<?= e($word . ': ' . $evidence['strength']['label']) ?>"><?= e($evidence['strength']['label']) ?></button>
    <button type="button" class="btn--link press" data-detail-open="<?= e($target) ?>"><?= e($evidence['label']) ?></button>
</span>
