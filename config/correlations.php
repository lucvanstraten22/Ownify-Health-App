<?php
/**
 * Combined insights: how a person's own sleep, training, activity, food and
 * heart measurements move together (docs/CORRELATIONS.md). Ownify's own
 * statistics on the person's own data — no AI service — next to approved
 * research where there is any (config/research.php).
 *
 * ---------------------------------------------------------------------------
 * WHAT IS TESTED
 * ---------------------------------------------------------------------------
 * A fixed list of pairs, never every combination: a known, small number of
 * comparisons is what keeps chance findings in check (the false-discovery
 * control counts all of them). Each pair names its exposure, its outcome,
 * how the days line up (`lag`: 0 the same day, 1 the night or day after,
 * 2 two after; `week` per ISO week), how big a difference must be to matter
 * (`outcomes`, Ownify's own data rules) and the research behind it, if any.
 *
 * ---------------------------------------------------------------------------
 * A PATTERN IN YOUR DATA IS NOT A CAUSE
 * ---------------------------------------------------------------------------
 * Every sentence says what went together, how often, over how many days;
 * never why. The labels below are about the person's own data and are kept
 * apart from the research's (Zeer betrouwbaar, Betrouwbaar, Beperkt
 * onderbouwd).
 *
 * The thresholds in `tiers` and `outcomes` are Ownify's data rules, chosen
 * with a simulation of self-tracking data (round-1 report, 28 October 2026)
 * and approved by the owner (D5); not research.
 */

declare(strict_types=1);

return [

    /* Raise when a pair, a threshold or a text changes meaning: every
       person's run is worked out again on their next load. */
    'engine_version' => '1',

    /* The window: the last 90 days, today not included (a day still
       running is not a short day). */
    'window_days'     => 90,
    'min_paired_days' => 21,
    'min_group_days'  => 5,     // per group of a split, before anything is tested
    'bootstrap'       => 400,   // resamples for the 90% interval
    'chance_alpha'    => 0.10,  // what "Mogelijk patroon" counts as, before correction

    /* The labels, strongest first (D6). */
    'tiers' => [
        'clear'    => ['q' => 0.05, 'rho' => 0.30, 'days' => 45, 'group' => 12],
        'pattern'  => ['q' => 0.10, 'rho' => 0.20, 'days' => 30, 'group' => 8],
        'possible' => ['p' => 0.10],
        'none'     => ['days' => 30],
    ],

    /* How big a difference has to be before it counts, per outcome. HRV:
       half the person's own day-to-day spread (XTH-04). */
    'outcomes' => [
        'sleep_min'  => ['unit' => 'minutes', 'threshold' => 15],
        'onset_dev'  => ['unit' => 'minutes', 'threshold' => 15],
        'onset_clock' => ['unit' => 'minutes', 'threshold' => 15],
        'hrv'        => ['unit' => 'percent', 'threshold' => null, 'swc' => 0.5],
        'sleep_hr'   => ['unit' => 'bpm', 'threshold' => 2],
        'steps'      => ['unit' => 'steps', 'threshold' => 1000],
        'kcal'       => ['unit' => 'kcal', 'threshold' => 150],
        'trained'    => ['unit' => 'points', 'threshold' => 0.15],
        'protein_kg' => ['unit' => 'g_per_kg', 'threshold' => 0.2],
        'water'      => ['unit' => 'litres', 'threshold' => 0.25],
        'week_sleep' => ['unit' => 'minutes', 'threshold' => 15],
    ],

    /* Ownify's own rules for the day table (includes/correlations/frame.php). */
    'frame' => [
        'late_workout_hours' => 4,     // XSA-06: ending at least 4 h before sleep onset made no difference
        'late_caffeine_hours' => 8.8,  // XSN-05: coffee at least 8.8 h before bedtime
        'short_night_minutes' => 330,  // 5.5 h, XSN-02
        'short_night_min_days' => 6,   // fewer such nights: the person's own shortest quarter
        'effort_weight' => ['easy' => 1, 'moderate' => 2, 'hard' => 3, 'unknown' => 2],
        'caffeine_min_days' => 5,      // caffeine logged on at least this many days before its absence means anything
        'hrv_window_before' => 60,     // minutes before sleep start / after its end a nightly HRV may be recorded
        'hrv_window_after'  => 180,
    ],

    /* The pairs (C01–C17). `areas`: the exposure's and the outcome's, for the
       row's marker. `split`: how the days are grouped for the sentence and
       the chart — 'binary' (the exposure is yes or no) or 'median' (above
       the person's own median against at or below it). `direction`: what
       the research leads to expect (+1 more, −1 less, 0 nothing to expect).
       `claims`: primary first; per age group like config/insights.php
       ([age_min, age_max, key]). */
    'pairs' => [
        'C01' => ['exposure' => 'load', 'outcome' => 'sleep_min', 'lag' => 1, 'split' => 'median', 'areas' => ['training', 'sleep'],
                  'direction' => 1, 'applicability' => 'proxy',
                  'claims' => [[18, null, 'cross.activity.sleep'], [18, null, 'cross.exercise.sleep'], [18, null, 'cross.daily.sleep_activity'], [18, null, 'cross.strength.sleep']]],
        'C02' => ['exposure' => 'late_workout', 'outcome' => 'onset_dev', 'lag' => 1, 'split' => 'binary', 'areas' => ['training', 'sleep'],
                  'direction' => 1, 'applicability' => 'direct',
                  'claims' => [[18, null, 'cross.evening_exercise.sleep'], [18, null, 'cross.evening_exercise.review'], [18, 50, 'cross.evening_hie.sleep']]],
        'C03' => ['exposure' => 'hard', 'outcome' => 'hrv', 'lag' => 1, 'split' => 'binary', 'areas' => ['training', 'heart'],
                  'direction' => -1, 'applicability' => 'direct',
                  'claims' => [[18, null, 'cross.training.hrv'], [18, null, 'cross.evening_exercise.sleep'], [18, null, 'hrv.monitoring'],
                               [18, null, 'hrv.age'], [null, null, 'hrv.norms'], [null, null, 'measurement.hrv'], [18, null, 'activity.intensity.bands']]],
        'C04' => ['exposure' => 'hard', 'outcome' => 'sleep_hr', 'lag' => 1, 'split' => 'binary', 'areas' => ['training', 'heart'],
                  'direction' => 1, 'applicability' => 'direct',
                  'claims' => [[18, null, 'cross.training.hr'], [18, null, 'cross.evening_exercise.sleep'], [18, null, 'heart.resting.risk'],
                               [null, null, 'measurement.hrv'], [18, null, 'activity.intensity.bands']]],
        'C05' => ['exposure' => 'hard', 'outcome' => 'hrv', 'lag' => 2, 'split' => 'binary', 'areas' => ['training', 'heart'],
                  'direction' => 0, 'applicability' => 'direct',
                  'claims' => [[18, null, 'cross.training.recovery'], [18, null, 'recovery.age'], [null, null, 'recovery.individual'],
                               [18, null, 'hrv.averaging'], [18, null, 'hrv.change']]],
        'C06' => ['exposure' => 'steps', 'outcome' => 'sleep_min', 'lag' => 1, 'split' => 'median', 'areas' => ['training', 'sleep'],
                  'direction' => 0, 'applicability' => 'direct',
                  'claims' => [[18, null, 'cross.daily.sleep_activity'], [18, null, 'cross.activity.sleep']]],
        'C07' => ['exposure' => 'sleep_min', 'outcome' => 'steps', 'lag' => 0, 'split' => 'median', 'areas' => ['sleep', 'training'],
                  'direction' => 0, 'applicability' => 'direct',
                  'claims' => [[18, null, 'cross.daily.sleep_activity']]],
        'C08' => ['exposure' => 'short_night', 'outcome' => 'kcal', 'lag' => 0, 'split' => 'binary', 'areas' => ['sleep', 'nutrition'],
                  'direction' => 1, 'applicability' => 'proxy',
                  'claims' => [[18, null, 'cross.sleep.energy'], [18, null, 'cross.sleep.diet']]],
        'C09' => ['exposure' => 'sleep_min', 'outcome' => 'trained', 'lag' => 0, 'split' => 'median', 'areas' => ['sleep', 'training'],
                  'direction' => 0, 'applicability' => 'proxy',
                  'claims' => [[18, null, 'cross.sleep.performance'], [18, null, 'sleep.athletes']]],
        'C10' => ['exposure' => 'rating', 'outcome' => 'sleep_min', 'lag' => 1, 'split' => 'median', 'areas' => ['nutrition', 'sleep'],
                  'direction' => 0, 'applicability' => 'direct', 'claims' => []],
        'C11' => ['exposure' => 'late_meal', 'outcome' => 'onset_dev', 'lag' => 1, 'split' => 'median', 'areas' => ['nutrition', 'sleep'],
                  'direction' => 0, 'applicability' => 'direct', 'claims' => []],
        'C12' => ['exposure' => 'trained', 'outcome' => 'protein_kg', 'lag' => 0, 'split' => 'binary', 'areas' => ['training', 'nutrition'],
                  'direction' => 0, 'applicability' => 'proxy',
                  'claims' => [[18, 64, 'nutrition.protein.exercise'], [65, null, 'nutrition.protein.older'], [18, null, 'nutrition.protein'],
                               [65, null, 'nutrition.protein.older.espen'], [18, null, 'nutrition.timing']]],
        'C13' => ['exposure' => 'trained', 'outcome' => 'kcal', 'lag' => 0, 'split' => 'binary', 'areas' => ['training', 'nutrition'],
                  'direction' => 0, 'applicability' => 'proxy', 'claims' => []],
        'C14' => ['exposure' => 'trained', 'outcome' => 'water', 'lag' => 0, 'split' => 'binary', 'areas' => ['training', 'nutrition'],
                  'direction' => 1, 'applicability' => 'proxy',
                  'claims' => [[13, null, 'nutrition.fluid']]],
        'C15' => ['exposure' => 'weekend', 'outcome' => 'onset_clock', 'lag' => 0, 'split' => 'binary', 'areas' => ['sleep', 'sleep'],
                  'direction' => 0, 'applicability' => 'direct', 'weekday_terms' => false,
                  'claims' => [[null, null, 'sleep.regularity']]],
        'C16' => ['exposure' => 'week_load', 'outcome' => 'week_sleep', 'lag' => 'week', 'split' => 'median', 'areas' => ['training', 'sleep'],
                  'direction' => 0, 'applicability' => 'proxy', 'min_weeks' => 8, 'claims' => []],
        'C17' => ['exposure' => 'late_caffeine', 'outcome' => 'sleep_min', 'lag' => 1, 'split' => 'binary', 'areas' => ['nutrition', 'sleep'],
                  'direction' => -1, 'applicability' => 'proxy',
                  'claims' => [[18, null, 'cross.caffeine.sleep']]],
    ],

    /* Overzicht › Nuttige inzichten: four combined insights (D1, R3–R4):
       Duidelijk patroon and Patroon first, then Nog onduidelijk rows for the
       pairs closest to having enough data. */
    'overview_count' => 4,

    /* How the statistics are done (round 1: MTH-01 … 03): listed under "Hoe
       Ownify dit berekent" as publications, not as claims about health. */
    'method_sources' => ['MTH-01', 'MTH-02', 'MTH-03'],

    /* ------------------------------------------------------------- texts */
    'copy' => [
        'card_title'    => 'Nuttige inzichten',
        'card_subtitle' => 'Verbanden tussen je slaap, voeding en training',
        'card_open'     => 'Alle inzichten',
        'page_title'    => 'Inzichten',
        'page_lede'     => 'Hoe je slaap, training, voeding en hartslag samengaan in je eigen gegevens van de afgelopen 90 dagen. Een samenhang, geen oorzaak.',
        'back'          => 'Overzicht',
        'empty'         => 'Nog niets te zien: Ownify heeft eerst een paar weken aan gegevens nodig.',

        'tiers' => [
            'clear'    => 'Duidelijk patroon',
            'pattern'  => 'Patroon',
            'possible' => 'Mogelijk patroon',
            'none'     => 'Geen patroon',
            'unclear'  => 'Nog onduidelijk',
        ],
        'tier_means' => [
            'clear'    => 'Een sterke samenhang die in beide helften van de periode terugkomt, over minstens 45 dagen.',
            'pattern'  => 'Een samenhang die in beide helften van de periode terugkomt, over minstens 30 dagen.',
            'possible' => 'Een samenhang die ook toeval kan zijn. Bij %1$d vergelijkingen verwacht je er ongeveer %2$s zoals deze bij toeval.',
            'none'     => 'Genoeg dagen, en het verschil was kleiner dan wat ertoe doet.',
            'unclear'  => 'Nog te weinig dagen, of geen duidelijk beeld in de ene of de andere richting.',
        ],
        'not_cause'   => 'Een samenhang in jouw gegevens, geen oorzaak.',
        'own_data'    => 'Alleen uit je eigen gegevens: er is geen goedgekeurd onderzoek over dit verband.',
        'own_data_short' => 'Alleen uit je eigen gegevens',
        'concordance' => [
            'match'  => 'Dit komt overeen met wat onderzoek gemiddeld vindt.',
            'differ' => 'Dit is anders dan wat onderzoek gemiddeld vindt. Dat kan: mensen verschillen.',
        ],
        'unclear_text' => 'Er is nog geen duidelijk beeld: over %1$d dagen ging het soms samen en soms niet.',

        /* The insight's own page. */
        'detail' => [
            'seen'       => 'Wat Ownify zag',
            'numbers'    => 'In cijfers',
            'research'   => 'Uit onderzoek blijkt',
            'method'     => 'Hoe Ownify dit berekent',
            'adjusted'   => 'Gecorrigeerd voor je weekdagen en je trend over de periode.',
            'difference' => 'Verschil',
            'interval'   => '90%-interval',
            'rho'        => 'Samenhang (rangcorrelatie)',
            'p'          => 'Kans op toeval',
            'q'          => 'Na correctie voor %d vergelijkingen',
            'days'       => 'Dagen',
            'days_value' => '%1$d dagen, %2$d en %3$d per groep',
            'period'     => 'Periode',
            'period_value' => 'De afgelopen %d dagen',
            'interval_value' => '%1$s tot %2$s',
            'method_text' => [
                'Ownify zet je dagen naast elkaar: een trainingsdag naast de nacht erna, een nacht naast de dag erna. Dagen zonder meting tellen niet mee; niets wordt als nul geteld.',
                'Eerst haalt Ownify je eigen weekdagpatroon en je langzame trend over de periode uit elke reeks, want het weekend en een drukke maand laten veel dingen tegelijk veranderen. Uitschieters tellen beperkt mee.',
                'Dan meet Ownify de samenhang met een rangcorrelatie, rekening houdend met hoe dagen op elkaar lijken. Omdat Ownify %d verbanden tegelijk bekijkt, wordt gecorrigeerd voor het aantal vergelijkingen, zodat toevalstreffers zeldzaam blijven.',
                'Een patroon telt pas als het in beide helften van de periode dezelfde kant op gaat en het verschil groot genoeg is om ertoe te doen.',
            ],
            'method_sources' => 'Over de methode',
        ],

        /* What fills a sentence, by unit ('amounts': 'units' is a key the
           translation leaves alone, I18N_IDENTIFIERS). */
        'amounts' => [
            'minutes_one'   => '%s minuut',
            'minutes_other' => '%s minuten',
            'bpm'           => '%s slagen per minuut',
            'percent'       => '%s%%',
            'steps'         => '%s stappen',
            'kcal'          => '%s kcal',
            'points'        => '%s procentpunt',
            'g_per_kg'      => '%s gram per kilo',
        ],

        /* Per pair: title, the two sentences, the chart's two rows, "no
           pattern", and what is missing. %1$s the difference, %2$d / %3$d the
           days in each group, %4$d all days. */
        'pairs' => [
            'C01' => [
                'title' => 'Training en slaap',
                'up'    => 'Na dagen met meer training sliep je de nacht erna gemiddeld %1$s langer (%2$d dagen met meer training tegenover %3$d met minder).',
                'down'  => 'Na dagen met meer training sliep je de nacht erna gemiddeld %1$s korter (%2$d dagen met meer training tegenover %3$d met minder).',
                'high'  => 'Na meer training',
                'low'   => 'Na minder training',
                'none'  => 'Hoeveel je trainde maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je de nacht erna sliep.',
                'need_days'  => ['Nog 1 dag nodig met een gemeten nacht erna.', 'Nog %d dagen nodig met een gemeten nacht erna.'],
                'need_group' => ['Nog 1 trainingsdag nodig.', 'Nog %d trainingsdagen nodig.'],
            ],
            'C02' => [
                'title' => 'Late training en inslapen',
                'up'    => 'Op avonden waarop je training minder dan 4 uur voor je gewone inslaaptijd eindigde, viel je gemiddeld %1$s later in slaap dan je gewend bent (%2$d avonden tegenover %3$d).',
                'down'  => 'Op avonden waarop je training minder dan 4 uur voor je gewone inslaaptijd eindigde, viel je gemiddeld %1$s eerder in slaap dan je gewend bent (%2$d avonden tegenover %3$d).',
                'high'  => 'Na een late training',
                'low'   => 'Andere avonden',
                'none'  => 'Of je laat op de avond trainde, maakte de afgelopen %1$d dagen geen merkbaar verschil voor wanneer je in slaap viel.',
                'need_days'  => ['Nog 1 nacht nodig met een gemeten inslaaptijd.', 'Nog %d nachten nodig met een gemeten inslaaptijd.'],
                'need_group' => ['Nog 1 avond met een late training nodig.', 'Nog %d avonden met een late training nodig.'],
            ],
            'C03' => [
                'title' => 'Zware training en HRV',
                'up'    => 'De nacht na een zware training was je HRV gemiddeld %1$s hoger dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).',
                'down'  => 'De nacht na een zware training was je HRV gemiddeld %1$s lager dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).',
                'high'  => 'Na een zware training',
                'low'   => 'Na andere dagen',
                'none'  => 'Een zware training maakte de afgelopen %1$d dagen geen merkbaar verschil voor je HRV de nacht erna.',
                'need_days'  => ['Nog 1 nacht met HRV nodig.', 'Nog %d nachten met HRV nodig.'],
                'need_group' => ['Nog 1 zware training met HRV de nacht erna nodig.', 'Nog %d zware trainingen met HRV de nacht erna nodig.'],
            ],
            'C04' => [
                'title' => 'Zware training en hartslag in slaap',
                'up'    => 'De nacht na een zware training was je hartslag in je slaap gemiddeld %1$s hoger dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).',
                'down'  => 'De nacht na een zware training was je hartslag in je slaap gemiddeld %1$s lager dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).',
                'high'  => 'Na een zware training',
                'low'   => 'Na andere dagen',
                'none'  => 'Een zware training maakte de afgelopen %1$d dagen geen merkbaar verschil voor je hartslag in je slaap.',
                'need_days'  => ['Nog 1 nacht met hartslag nodig.', 'Nog %d nachten met hartslag nodig.'],
                'need_group' => ['Nog 1 zware training met hartslag de nacht erna nodig.', 'Nog %d zware trainingen met hartslag de nacht erna nodig.'],
            ],
            'C05' => [
                'title' => 'Herstel na zware training',
                'up'    => 'Twee nachten na een zware training was je HRV gemiddeld %1$s hoger dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).',
                'down'  => 'Twee nachten na een zware training was je HRV gemiddeld %1$s lager dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).',
                'high'  => 'Twee nachten na zware training',
                'low'   => 'Na andere dagen',
                'none'  => 'Twee nachten na een zware training was je HRV niet merkbaar anders dan na andere dagen (de afgelopen %1$d dagen).',
                'need_days'  => ['Nog 1 nacht met HRV nodig.', 'Nog %d nachten met HRV nodig.'],
                'need_group' => ['Nog 1 zware training met HRV twee nachten later nodig.', 'Nog %d zware trainingen met HRV twee nachten later nodig.'],
            ],
            'C06' => [
                'title' => 'Stappen en slaap',
                'up'    => 'Na dagen met meer stappen sliep je de nacht erna gemiddeld %1$s langer (%2$d drukkere dagen tegenover %3$d rustigere).',
                'down'  => 'Na dagen met meer stappen sliep je de nacht erna gemiddeld %1$s korter (%2$d drukkere dagen tegenover %3$d rustigere).',
                'high'  => 'Na meer stappen',
                'low'   => 'Na minder stappen',
                'none'  => 'Hoeveel stappen je zette, maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je de nacht erna sliep.',
                'need_days'  => ['Nog 1 dag nodig met stappen en een gemeten nacht erna.', 'Nog %d dagen nodig met stappen en een gemeten nacht erna.'],
                'need_group' => ['Nog 1 dag nodig met stappen en een gemeten nacht erna.', 'Nog %d dagen nodig met stappen en een gemeten nacht erna.'],
            ],
            'C07' => [
                'title' => 'Slaap en stappen',
                'up'    => 'Na langere nachten zette je de dag erna gemiddeld %1$s meer (%2$d langere nachten tegenover %3$d kortere).',
                'down'  => 'Na langere nachten zette je de dag erna gemiddeld %1$s minder (%2$d langere nachten tegenover %3$d kortere).',
                'high'  => 'Na een langere nacht',
                'low'   => 'Na een kortere nacht',
                'none'  => 'Hoe lang je sliep, maakte de afgelopen %1$d dagen geen merkbaar verschil voor je stappen de dag erna.',
                'need_days'  => ['Nog 1 nacht nodig met stappen de dag erna.', 'Nog %d nachten nodig met stappen de dag erna.'],
                'need_group' => ['Nog 1 nacht nodig met stappen de dag erna.', 'Nog %d nachten nodig met stappen de dag erna.'],
            ],
            'C08' => [
                'title' => 'Korte nachten en eten',
                'up'    => 'Op dagen na een korte nacht logde je gemiddeld %1$s meer (%2$d dagen tegenover %3$d andere volledig gelogde dagen).',
                'down'  => 'Op dagen na een korte nacht logde je gemiddeld %1$s minder (%2$d dagen tegenover %3$d andere volledig gelogde dagen).',
                'high'  => 'Na een korte nacht',
                'low'   => 'Na andere nachten',
                'none'  => 'Een korte nacht maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoeveel je logde.',
                'need_days'  => ['Nog 1 volledig gelogde dag na een gemeten nacht nodig.', 'Nog %d volledig gelogde dagen na een gemeten nacht nodig.'],
                'need_group' => ['Nog 1 volledig gelogde dag na een korte nacht nodig.', 'Nog %d volledig gelogde dagen na een korte nacht nodig.'],
            ],
            'C09' => [
                'title' => 'Slaap en trainen',
                'up'    => 'Na langere nachten trainde je op %1$s meer van de dagen (%2$d langere nachten tegenover %3$d kortere).',
                'down'  => 'Na langere nachten trainde je op %1$s minder van de dagen (%2$d langere nachten tegenover %3$d kortere).',
                'high'  => 'Na een langere nacht',
                'low'   => 'Na een kortere nacht',
                'none'  => 'Hoe lang je sliep, maakte de afgelopen %1$d dagen geen merkbaar verschil voor of je die dag trainde.',
                'need_days'  => ['Nog 1 gemeten nacht nodig.', 'Nog %d gemeten nachten nodig.'],
                'need_group' => ['Nog 1 gemeten nacht nodig.', 'Nog %d gemeten nachten nodig.'],
            ],
            'C10' => [
                'title' => 'Voedingscijfer en slaap',
                'up'    => 'Na dagen met een hoger voedingscijfer sliep je de nacht erna gemiddeld %1$s langer (%2$d dagen tegenover %3$d).',
                'down'  => 'Na dagen met een hoger voedingscijfer sliep je de nacht erna gemiddeld %1$s korter (%2$d dagen tegenover %3$d).',
                'high'  => 'Na een hoger cijfer',
                'low'   => 'Na een lager cijfer',
                'none'  => 'Je voedingscijfer maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je de nacht erna sliep.',
                'need_days'  => ['Nog 1 dag met een cijfer en een gemeten nacht erna nodig.', 'Nog %d dagen met een cijfer en een gemeten nacht erna nodig.'],
                'need_group' => ['Nog 1 dag met een cijfer en een gemeten nacht erna nodig.', 'Nog %d dagen met een cijfer en een gemeten nacht erna nodig.'],
            ],
            'C11' => [
                'title' => 'Laat eten en inslapen',
                'up'    => 'Op avonden waarop je later at dan gewoonlijk, viel je gemiddeld %1$s later in slaap dan je gewend bent (%2$d avonden tegenover %3$d).',
                'down'  => 'Op avonden waarop je later at dan gewoonlijk, viel je gemiddeld %1$s eerder in slaap dan je gewend bent (%2$d avonden tegenover %3$d).',
                'high'  => 'Later gegeten',
                'low'   => 'Eerder gegeten',
                'none'  => 'Hoe laat je at, maakte de afgelopen %1$d dagen geen merkbaar verschil voor wanneer je in slaap viel.',
                'need_days'  => ['Nog 1 volledig gelogde dag met een gemeten nacht erna nodig.', 'Nog %d volledig gelogde dagen met een gemeten nacht erna nodig.'],
                'need_group' => ['Nog 1 volledig gelogde dag met een gemeten nacht erna nodig.', 'Nog %d volledig gelogde dagen met een gemeten nacht erna nodig.'],
            ],
            'C12' => [
                'title' => 'Eiwit op trainingsdagen',
                'up'    => 'Op trainingsdagen at je gemiddeld %1$s meer eiwit dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).',
                'down'  => 'Op trainingsdagen at je gemiddeld %1$s minder eiwit dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).',
                'high'  => 'Trainingsdagen',
                'low'   => 'Rustdagen',
                'none'  => 'Op trainingsdagen at je de afgelopen %1$d dagen ongeveer even veel eiwit als op rustdagen.',
                'need_days'  => ['Nog 1 volledig gelogde dag nodig.', 'Nog %d volledig gelogde dagen nodig.'],
                'need_group' => ['Nog 1 volledig gelogde trainingsdag nodig.', 'Nog %d volledig gelogde trainingsdagen nodig.'],
            ],
            'C13' => [
                'title' => 'Energie op trainingsdagen',
                'up'    => 'Op trainingsdagen logde je gemiddeld %1$s meer dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).',
                'down'  => 'Op trainingsdagen logde je gemiddeld %1$s minder dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).',
                'high'  => 'Trainingsdagen',
                'low'   => 'Rustdagen',
                'none'  => 'Op trainingsdagen logde je de afgelopen %1$d dagen ongeveer even veel als op rustdagen.',
                'need_days'  => ['Nog 1 volledig gelogde dag nodig.', 'Nog %d volledig gelogde dagen nodig.'],
                'need_group' => ['Nog 1 volledig gelogde trainingsdag nodig.', 'Nog %d volledig gelogde trainingsdagen nodig.'],
            ],
            'C14' => [
                'title' => 'Water op trainingsdagen',
                'up'    => 'Op trainingsdagen dronk je gemiddeld %1$s meer dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).',
                'down'  => 'Op trainingsdagen dronk je gemiddeld %1$s minder dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).',
                'high'  => 'Trainingsdagen',
                'low'   => 'Rustdagen',
                'none'  => 'Op trainingsdagen dronk je de afgelopen %1$d dagen ongeveer even veel als op rustdagen.',
                'need_days'  => ['Nog 1 dag met gelogd water nodig.', 'Nog %d dagen met gelogd water nodig.'],
                'need_group' => ['Nog 1 trainingsdag met gelogd water nodig.', 'Nog %d trainingsdagen met gelogd water nodig.'],
            ],
            'C15' => [
                'title' => 'Weekend en inslapen',
                'up'    => 'In het weekend viel je gemiddeld %1$s later in slaap dan doordeweeks (%2$d weekendnachten tegenover %3$d doordeweekse).',
                'down'  => 'In het weekend viel je gemiddeld %1$s eerder in slaap dan doordeweeks (%2$d weekendnachten tegenover %3$d doordeweekse).',
                'high'  => 'Weekend',
                'low'   => 'Doordeweeks',
                'none'  => 'In het weekend viel je de afgelopen %1$d dagen ongeveer even laat in slaap als doordeweeks.',
                'need_days'  => ['Nog 1 gemeten nacht nodig.', 'Nog %d gemeten nachten nodig.'],
                'need_group' => ['Nog 1 gemeten weekendnacht nodig.', 'Nog %d gemeten weekendnachten nodig.'],
            ],
            'C16' => [
                'title' => 'Trainingsweken en slaap',
                'up'    => 'In weken met meer training sliep je gemiddeld %1$s per nacht langer (%2$d drukkere weken tegenover %3$d rustigere).',
                'down'  => 'In weken met meer training sliep je gemiddeld %1$s per nacht korter (%2$d drukkere weken tegenover %3$d rustigere).',
                'high'  => 'Weken met meer training',
                'low'   => 'Weken met minder training',
                'none'  => 'Hoeveel je per week trainde, maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je sliep.',
                'need_days'  => ['Nog 1 week met training en nachten nodig.', 'Nog %d weken met training en nachten nodig.'],
                'need_group' => ['Nog 1 week met training en nachten nodig.', 'Nog %d weken met training en nachten nodig.'],
            ],
            'C17' => [
                'title' => 'Cafeïne en slaap',
                'up'    => 'Na dagen met cafeïne in de 8,8 uur voor je gewone inslaaptijd sliep je gemiddeld %1$s langer (%2$d dagen tegenover %3$d).',
                'down'  => 'Na dagen met cafeïne in de 8,8 uur voor je gewone inslaaptijd sliep je gemiddeld %1$s korter (%2$d dagen tegenover %3$d).',
                'high'  => 'Late cafeïne',
                'low'   => 'Andere dagen',
                'none'  => 'Cafeïne laat op de dag maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je sliep.',
                'need_days'  => ['Nog 1 dag met gelogde cafeïne nodig.', 'Nog %d dagen met gelogde cafeïne nodig.'],
                'need_group' => ['Nog 1 dag met cafeïne laat op de dag nodig.', 'Nog %d dagen met cafeïne laat op de dag nodig.'],
            ],
        ],
    ],
];
