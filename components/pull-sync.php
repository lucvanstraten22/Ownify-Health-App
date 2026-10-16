<?php
/**
 * Gezondheid's pull-to-sync (assets/js/pull-sync.js): the indicator that
 * comes out from under the header as the page is pulled down, and stays as a
 * small capsule — "Aan het synchroniseren…" — for as long as the sync really
 * takes, then says how it went.
 *
 * What a pull can do is said here, from the sources themselves
 * (integration_public()'s can_sync): a connected cloud source with a sync
 * endpoint is asked for new data (Polar); everything else — a phone's Health
 * Connect, which only that phone can send — is refreshed, never "synced".
 * The Android app does the same and also syncs its own phone.
 *
 * For screen readers and keyboards, which cannot pull, the same sync is a
 * button (data-pull-start) that is visible once it has focus.
 */
declare(strict_types=1);

$sources = [];
foreach ($data['settings']['integrations'] ?? [] as $integration) {
    $provider = (string) ($integration['provider'] ?? '');
    if (!empty($integration['connected']) && !empty($integration['can_sync'])
        && preg_match('/^[a-z0-9_]+$/', $provider)) {
        $sources[] = ['provider' => $provider, 'label' => (string) ($integration['label'] ?? $provider)];
    }
}

$config = [
    'sources' => $sources,
    'text'    => [
        'syncing'   => t('Aan het synchroniseren…'),
        'synced'    => t('Gesynchroniseerd'),
        'refreshed' => t('Bijgewerkt'),
        'failed'    => t('Synchroniseren mislukt'),
        'offline'   => t('Ownify is niet bereikbaar. Controleer je internetverbinding en probeer het opnieuw.'),
        'error'     => t('Er ging iets mis bij het synchroniseren. Probeer het opnieuw.'),
        'release'   => t('Loslaten om te synchroniseren'),
    ],
];
?>
<div class="pull-sync" data-pull-sync="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE)) ?>" data-state="idle">
    <div class="pull-sync__pill" data-pull-pill>
        <span class="pull-sync__icon" aria-hidden="true">
            <svg class="pull-sync__ring" viewBox="0 0 24 24" focusable="false">
                <circle class="pull-sync__track" cx="12" cy="12" r="9"/>
                <circle class="pull-sync__arc" data-pull-arc cx="12" cy="12" r="9" pathLength="100"/>
            </svg>
            <?= icon('chevron-down', 'pull-sync__glyph pull-sync__arrow') ?>
            <?= icon('check', 'pull-sync__glyph pull-sync__check') ?>
            <?= icon('info', 'pull-sync__glyph pull-sync__alert') ?>
        </span>
        <span class="pull-sync__label" data-pull-label></span>
        <button type="button" class="pull-sync__retry press" data-pull-retry hidden><?= e(t('Opnieuw')) ?></button>
    </div>
    <p class="sr-only" role="status" aria-live="polite" data-pull-live></p>
</div>
