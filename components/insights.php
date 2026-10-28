<?php
/**
 * Nuttige inzichten (docs/INSIGHTS.md): one row per area — Slaap, Voeding,
 * Training — each the day's stored insight with its evidence, or a quiet
 * placeholder until there is one. Calmer than the score block.
 *
 * With $data['insights_slot'] it is one area's own card, on that area's page:
 * the same stored insight, the same row, and nothing at all when the area
 * has none — no empty card on a page that is about something else.
 */
declare(strict_types=1);

$insights = $data['insights'];
$slot     = $data['insights_slot'] ?? null;

if ($slot !== null) {
    $item = $insights['context'][$slot] ?? null;
    if ($item === null) {
        return;
    }
    $items = [$item + ['state' => 'filled']];
} else {
    $items = $insights['items'];
}

/* Nothing to show yet: the card stays, quieter (components.css .is-empty). */
$allEmpty = array_filter($items, static fn ($item) => ($item['state'] ?? 'empty') !== 'empty') === [];
$titleId  = 'insights-title' . ($slot === null ? '' : '-' . $slot);
?>
<section class="card card--insights reveal<?= $allEmpty ? ' is-empty' : '' ?>" aria-labelledby="<?= e($titleId) ?>">

    <div class="card__head card__head--compact">
        <span class="icon-tile icon-tile--solid icon-tile--neutral" aria-hidden="true"><?= icon_solid('pulse') ?></span>
        <div class="card__headings">
            <h2 class="card__eyebrow" id="<?= e($titleId) ?>"><?= e($insights['title']) ?></h2>
            <?php if ($slot === null): ?>
                <p class="card__meta card__meta--small"><?= e($insights['subtitle']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <ul class="insights" role="list">
        <?php foreach ($items as $item):
            $empty = ($item['state'] ?? 'empty') === 'empty';
            ?>
            <li class="insights__item<?= $empty ? ' is-empty' : '' ?>" data-accent="<?= e($item['accent']) ?>">
                <span class="insights__marker" aria-hidden="true"><?= icon_solid($item['icon']) ?></span>
                <div class="insights__body">
                    <p class="insights__title"><?= e($item['title']) ?></p>
                    <p class="insights__text"><?= e($item['body']) ?></p>
                    <?php if (!$empty && isset($item['research'])): ?>
                        <?php component('research-evidence', $data + ['evidence' => [
                            'research' => $item['research'],
                            'strength' => $item['strength'],
                            'label'    => $item['research_label'],
                        ]]); ?>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

</section>
