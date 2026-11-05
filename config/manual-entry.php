<?php
/**
 * Sleep and training entered by hand (40.0, docs/MANUAL-ENTRY.md): the two
 * entry pages, the remove action on a training, and what they say when
 * something is not right. In Dutch; the English is in lang/en/manual-entry.php.
 *
 * The two pages are one layout (pages/manual-entry.php, ManualEntry.kt): the
 * same intro, one card of fields, the same buttons. Only `sleep` and
 * `training` differ.
 *
 * The training types are Health Connect's own names (ExerciseSessionRecord,
 * OwnifyAndroid/.../IngestPayload.kt), the ones a synced training is stored
 * under, so a training entered here is read exactly as a synced one: its
 * label on Training (health_activity_label()), the score's strength types
 * (config/scoring.php), the points.
 */
declare(strict_types=1);

return [

    /* What both pages share. */
    'common' => [
        'date'    => 'Datum',
        'save'    => 'Opslaan',
        'saving'  => 'Opslaan…',
        'cancel'  => 'Annuleren',
        'minutes' => 'min',
        'kcal'    => 'kcal',
        'optional' => 'Optioneel',
        'offline' => 'Opslaan lukt nu niet. Controleer je verbinding en probeer het opnieuw.',
    ],

    'sleep' => [
        'detail'   => 'entry-sleep',
        'back'     => 'Slaap',
        'add'      => 'Slaap invoeren',
        'title'    => 'Slaap invoeren',
        'lede'     => 'Wanneer je ging slapen en wanneer je opstond. Staat er al een nacht op die ochtend, dan pas je die aan.',
        'date'     => 'Ochtend',
        'date_note' => 'De ochtend waarop je opstond. Een nacht hoort bij de ochtend waarop hij eindigt.',
        'bedtime'  => 'Naar bed',
        'wakeup'   => 'Opgestaan',
        /* The period the three fields make, worked out as they are typed. */
        'span'     => '%1$s tot %2$s · %3$s',
        /* Under the fields, on both pages: what saving does besides. */
        'note'     => 'Als je hartslag in die tijd is gemeten, komt die bij de nacht. Andere metingen van de nacht blijven staan.',
        'saved'    => 'Nacht opgeslagen',
        'updated'  => 'Nacht aangepast',
    ],

    'training' => [
        'detail'   => 'entry-training',
        'back'     => 'Training',
        'add'      => 'Training invoeren',
        'title'    => 'Training invoeren',
        'lede'     => 'Een training die je niet hebt opgenomen, of die niet is doorgekomen.',
        'note'     => 'Als je hartslag in die tijd is gemeten, komt die bij de training. Staat er op dat tijdstip al een training, dan wordt deze niet opgeslagen.',
        'activity' => 'Activiteit',
        'start'    => 'Begintijd',
        'duration' => 'Duur',
        'kcal'     => 'Verbrande calorieën',
        'saved'    => 'Training opgeslagen',
        'types'    => [
            'running'           => 'Hardlopen',
            'walking'           => 'Wandelen',
            'hiking'            => 'Wandeltocht',
            'biking'            => 'Fietsen',
            'swimming_pool'     => 'Zwemmen',
            'strength_training' => 'Krachttraining',
            'yoga'              => 'Yoga',
            'pilates'           => 'Pilates',
            'rowing'            => 'Roeien',
            'elliptical'        => 'Crosstrainer',
            'dancing'           => 'Dansen',
            'soccer'            => 'Voetbal',
            'tennis'            => 'Tennis',
            'other_workout'     => 'Andere training',
        ],
    ],

    /* On a training's own page: goal-detail.php's delete and its confirmation. */
    'remove' => [
        'button'   => 'Training verwijderen',
        'confirm'  => 'Weet je het zeker? De training verdwijnt uit je overzichten, je score en je punten.',
        'imported' => 'Alleen uit Ownify: waar hij vandaan kwam, zoals Health Connect, blijft hij staan. Een volgende synchronisatie zet hem niet terug.',
        'yes'      => 'Verwijderen',
        'no'       => 'Annuleren',
    ],

    'errors' => [
        'date'        => 'Kies een geldige datum.',
        'time'        => 'Vul een geldige tijd in.',
        'same_time'   => 'Naar bed en opgestaan kunnen niet hetzelfde tijdstip zijn.',
        'future'      => 'Dat ligt nog in de toekomst.',
        'type'        => 'Kies een activiteit.',
        'duration'    => 'Vul een duur in van 1 tot 1440 minuten.',
        'kcal'        => 'Vul een aantal calorieën in van 0 tot 20.000, of laat het leeg.',
        'overlap'     => 'Er staat al een activiteit geregistreerd op dit tijdstip.',
        'sleep_clash' => 'Er begint al een andere slaap op dat tijdstip.',
        'gone'        => 'Deze training bestaat niet meer.',
        'save'        => 'Opslaan is niet gelukt. Probeer het opnieuw.',
    ],
];
