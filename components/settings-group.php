<?php
/**
 * A group of settings rows: one label, one card, hairline-separated rows.
 */
declare(strict_types=1);

$group = $data['group'];
?>
<section class="settings-group reveal" aria-labelledby="settings-group-<?= e($data['group_index']) ?>">

    <h2 class="settings-eyebrow" id="settings-group-<?= e($data['group_index']) ?>"><?= e($group['label']) ?></h2>

    <div class="card settings-card">
        <?php foreach ($group['rows'] as $row): ?>
            <?php component('settings-row', ['row' => $row] + $data); ?>
        <?php endforeach; ?>
    </div>

</section>
