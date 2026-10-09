<?php
/**
 * Training's latest sessions (lib/hydrate-training.php, docs/TRAINING.md):
 * the top section's left half. Each a row — its kind, its day and time, how
 * long it lasted — opening its own page (pages/training-session.php). Only
 * Ownify's counted workouts: never the day's ordinary movement.
 *
 *   sessions   {title, empty, items: [{detail, label, date, time, duration, open}]}
 */
declare(strict_types=1);

$sessions = $data['sessions'];
?>
<section class="training-sessions" aria-labelledby="training-sessions-title">
    <h3 class="training-sessions__title" id="training-sessions-title"><?= e($sessions['title']) ?></h3>

    <?php if ($sessions['items'] === []): ?>
        <p class="training-sessions__empty"><?= e($sessions['empty']) ?></p>
    <?php else: ?>
        <ul class="training-sessions__list" role="list">
            <?php foreach ($sessions['items'] as $item): ?>
                <li>
                    <button type="button" class="training-session press" data-detail-open="<?= e($item['detail']) ?>"
                            aria-label="<?= e($item['open']) ?>">
                        <span class="training-session__kind"><?= e($item['label']) ?></span>
                        <span class="training-session__when">
                            <span><?= e($item['date']) ?> · <?= e($item['time']) ?></span>
                            <?php if ($item['duration'] !== null): ?>
                                <span class="training-session__length"><?= e($item['duration']) ?></span>
                            <?php endif; ?>
                        </span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
