<?php
/**
 * The evidence beside an insight or a reference value (docs/INSIGHTS.md):
 * "Bekijk onderzoek" as the glass chip of the goal wizard's categories
 * (.glass--chip), opening pages/research-detail.php over whatever page shows
 * it. How strong the research is (Betrouwbaar, …) is said there, not here.
 *
 * $data['evidence']: ['research' => id, 'strength' => ['key', 'label'], 'label' => 'Bekijk onderzoek'],
 * or $data['evidences']: a list of those, shown side by side.
 */
declare(strict_types=1);

$details   = $data['research']['details'] ?? [];
/* One, or (31.0) several in one row — each its own label, e.g. "Bij jouw
   leeftijd" beside "Bekijk onderzoek" — as $data['evidences']. */
$evidences = array_values(array_filter(
    isset($data['evidences']) ? (array) $data['evidences'] : [$data['evidence'] ?? null],
    static fn ($ev) => is_array($ev) && isset($details[$ev['research'] ?? ''])
));

if ($evidences === []) {
    return;
}
?>
<span class="research-evidence">
    <?php foreach ($evidences as $evidence): ?>
        <button type="button" class="glass glass--chip press" data-detail-open="<?= e('research-' . $evidence['research']) ?>"><?= e($evidence['label']) ?></button>
    <?php endforeach; ?>
</span>
