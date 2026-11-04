<?php
/**
 * The evidence beside an insight or a reference value (docs/INSIGHTS.md):
 * "Bekijk onderzoek" as the text link (.text-link, 38.0 — a glass chip
 * before), opening pages/research-detail.php over whatever page shows it.
 * How strong the research is (Betrouwbaar, …) is said there, not here.
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
        <button type="button" class="text-link press" data-detail-open="<?= e('research-' . $evidence['research']) ?>"><?= e($evidence['label']) ?><?= icon('chevron-right', 'text-link__icon') ?></button>
    <?php endforeach; ?>
</span>
