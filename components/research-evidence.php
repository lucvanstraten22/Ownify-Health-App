<?php
/**
 * The evidence beside an insight or a reference value (docs/INSIGHTS.md):
 * "Bekijk onderzoek" as the glass chip of the goal wizard's categories
 * (.glass--chip), opening pages/research-detail.php over whatever page shows
 * it. How strong the research is (Betrouwbaar, …) is said there, not here.
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
?>
<span class="research-evidence">
    <button type="button" class="glass glass--chip press" data-detail-open="<?= e($target) ?>"><?= e($evidence['label']) ?></button>
</span>
