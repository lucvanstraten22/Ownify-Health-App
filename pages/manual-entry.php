<?php
/**
 * A night or a training entered by hand (40.0, docs/MANUAL-ENTRY.md): one
 * layout for both, a page of its own over Slaap or over Training, opened by
 * that page's + only. The back pill, the intro, one card of fields — the
 * goal wizard's pill fields and its select in the glass — a line that says
 * what will happen, the error, and Annuleren | Opslaan. Only the fields and
 * their words differ (config/manual-entry.php).
 *
 * assets/js/manual-entry.js saves it (api/health/sleep-entry.php,
 * api/health/training-entry.php) and, once stored, reads the page again on
 * the area that opened it.
 */
declare(strict_types=1);

$entry  = $data['manual_entry'];
$kind   = $data['entry_kind'];                      // 'sleep' | 'training'
$copy   = $entry[$kind];
$common = $entry['common'];
$id     = (string) $copy['detail'];
$today  = (string) $entry['today'];

/** One field: the label over the pill, a unit after it, a note under it. */
$field = static function (string $key, string $label, string $input, ?string $unit = null, ?string $note = null) use ($id): void { ?>
    <div class="manual-entry__field">
        <label class="wizard__label" for="<?= e($id . '-' . $key) ?>"><?= e($label) ?></label>
        <div class="wizard__row">
            <?= $input ?>
            <?php if ($unit !== null): ?><span class="wizard__suffix"><?= e($unit) ?></span><?php endif; ?>
        </div>
        <?php if ($note !== null): ?><p class="wizard__hint" id="<?= e($id . '-' . $key) ?>-note"><?= e($note) ?></p><?php endif; ?>
    </div>
<?php };
$attr = static fn (string $key): string => 'id="' . e($id . '-' . $key) . '" name="' . e($key) . '" data-entry-field="' . e($key) . '"';
?>
<article class="detail manual-entry" data-detail="<?= e($id) ?>" data-detail-parent="<?= e($kind) ?>" data-accent="<?= e($kind) ?>"
         data-manual-entry="<?= e($kind) ?>" data-today="<?= e($today) ?>"
         aria-label="<?= e($copy['title']) ?>" aria-hidden="true" inert>

    <div class="screen__scroll" data-scroller>

        <header class="app-header detail__top" data-header>
            <div class="shell detail__bar">
                <button type="button" class="pill pill--back press" data-detail-close
                        aria-label="<?= e(tf('Terug naar %s', $copy['back'])) ?>">
                    <?= icon('chevron-left', 'pill__icon') ?>
                    <span class="pill__label"><?= e($copy['back']) ?></span>
                </button>
            </div>
        </header>

        <main class="app__main">
            <div class="shell stack">
                <header class="page-intro reveal">
                    <h1 class="page-intro__title"><?= e($copy['title']) ?></h1>
                    <p class="page-intro__lede"><?= e($copy['lede']) ?></p>
                </header>

                <form class="card manual-entry__card reveal" data-entry-form novalidate>
                    <div class="manual-entry__fields">
                        <?php if ($kind === 'sleep'): ?>
                            <?php $field('date', $copy['date'],
                                '<input class="wizard__input" type="date" ' . $attr('date') . ' value="' . e($today) . '" max="' . e($today) . '" required aria-describedby="' . e($id) . '-date-note">',
                                null, $copy['date_note']); ?>
                            <?php $field('bedtime', $copy['bedtime'],
                                '<input class="wizard__input wizard__input--number" type="time" ' . $attr('bedtime') . ' value="23:00" required>'); ?>
                            <?php $field('wakeup', $copy['wakeup'],
                                '<input class="wizard__input wizard__input--number" type="time" ' . $attr('wakeup') . ' value="07:00" required>'); ?>
                        <?php else: ?>
                            <?php $field('date', $common['date'],
                                '<input class="wizard__input" type="date" ' . $attr('date') . ' value="' . e($today) . '" max="' . e($today) . '" required>'); ?>
                            <?php
                            $options = '';
                            foreach ($copy['types'] as $type) {
                                $options .= '<option value="' . e($type['value']) . '">' . e($type['label']) . '</option>';
                            }
                            $field('type', $copy['activity'],
                                '<span class="glass glass--select manual-entry__select"><select ' . $attr('type') . ' required>' . $options . '</select>' . icon('chevron-down') . '</span>');
                            ?>
                            <?php $field('start', $copy['start'],
                                '<input class="wizard__input wizard__input--number" type="time" ' . $attr('start') . ' value="18:00" required>'); ?>
                            <?php $field('minutes', $copy['duration'],
                                '<input class="wizard__input wizard__input--number" type="number" inputmode="numeric" min="1" max="1440" step="1" ' . $attr('minutes') . ' value="30" required>',
                                $common['minutes']); ?>
                            <?php $field('kcal', $copy['kcal'],
                                '<input class="wizard__input wizard__input--number" type="number" inputmode="numeric" min="0" max="20000" step="1" ' . $attr('kcal') . ' placeholder="' . e($common['optional']) . '">',
                                $common['kcal']); ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($kind === 'sleep'): ?>
                        <?php /* The night the three fields make, as they are typed:
                                 "zo 2 nov 23:00 tot ma 3 nov 07:00 · 8 u". */ ?>
                        <p class="wizard__hint manual-entry__span" data-entry-span data-format="<?= e($copy['span']) ?>" aria-live="polite"></p>
                    <?php endif; ?>
                    <p class="card__hint card__hint--plain"><?= e($copy['note']) ?></p>
                </form>

                <div class="manual-entry__foot reveal">
                    <p class="wizard__error" role="alert" data-entry-error hidden></p>
                    <div class="goal-confirm__row">
                        <button type="button" class="btn press" data-detail-close><?= e($common['cancel']) ?></button>
                        <button type="button" class="glass glass--cta press" data-entry-save
                                data-saving="<?= e($common['saving']) ?>" data-offline="<?= e($common['offline']) ?>"
                                data-endpoint="<?= e($kind === 'sleep' ? 'api/health/sleep-entry.php' : 'api/health/training-entry.php') ?>"><?= e($common['save']) ?></button>
                    </div>
                </div>
            </div>
        </main>
    </div>

</article>
