<?php
/* English: config/correlations.php — combined insights (31.0) */
declare(strict_types=1);

return [

    /* ---- the card and the page */
    'Verbanden tussen je slaap, voeding en training' => 'Links between your sleep, nutrition and training',
    'Alle inzichten' => 'All insights',
    'Inzichten' => 'Insights',
    'Hoe je slaap, training, voeding en hartslag samengaan in je eigen gegevens van de afgelopen 90 dagen. Een samenhang, geen oorzaak.'
        => 'How your sleep, training, nutrition and heart rate go together in your own data from the last 90 days. An association, not a cause.',
    'Overzicht' => 'Overview',
    'Nog niets te zien: Ownify heeft eerst een paar weken aan gegevens nodig.' => 'Nothing to see yet: Ownify first needs a few weeks of data.',
    '%1$d à %2$d' => '%1$d to %2$d',
    'Terug naar Overzicht' => 'Back to Overview',
    'Terug naar Inzichten' => 'Back to Insights',

    /* ---- the labels */
    'Duidelijk patroon' => 'Clear pattern',
    'Patroon' => 'Pattern',
    'Mogelijk patroon' => 'Possible pattern',
    'Geen patroon' => 'No pattern',
    'Nog onduidelijk' => 'Still unclear',
    'Een sterke samenhang die in beide helften van de periode terugkomt, over minstens 45 dagen.'
        => 'A strong association that shows in both halves of the period, over at least 45 days.',
    'Een samenhang die in beide helften van de periode terugkomt, over minstens 30 dagen.'
        => 'An association that shows in both halves of the period, over at least 30 days.',
    'Een samenhang die ook toeval kan zijn. Bij %1$d vergelijkingen verwacht je er ongeveer %2$s zoals deze bij toeval.'
        => 'An association that may also be chance. With %1$d comparisons you would expect about %2$s like this by chance.',
    'Genoeg dagen, en het verschil was kleiner dan wat ertoe doet.' => 'Enough days, and the difference was smaller than what matters.',
    'Nog te weinig dagen, of geen duidelijk beeld in de ene of de andere richting.' => 'Too few days yet, or no clear picture either way.',
    'Een samenhang in jouw gegevens, geen oorzaak.' => 'An association in your data, not a cause.',
    'Alleen uit je eigen gegevens: er is geen goedgekeurd onderzoek over dit verband.' => 'From your own data only: there is no approved research about this link.',
    'Alleen uit je eigen gegevens' => 'From your own data only',
    'Dit komt overeen met wat onderzoek gemiddeld vindt.' => 'This matches what research finds on average.',
    'Dit is anders dan wat onderzoek gemiddeld vindt. Dat kan: mensen verschillen.' => 'This differs from what research finds on average. That happens: people differ.',
    'Er is nog geen duidelijk beeld: over %1$d dagen ging het soms samen en soms niet.' => 'There is no clear picture yet: over %1$d days it sometimes went together and sometimes not.',

    /* ---- an insight's own page */
    'Wat Ownify zag' => 'What Ownify saw',
    'In cijfers' => 'In numbers',
    'Hoe Ownify dit berekent' => 'How Ownify works this out',
    'Gecorrigeerd voor je weekdagen en je trend over de periode.' => 'Adjusted for your weekdays and your trend over the period.',
    'Verschil' => 'Difference',
    '90%-interval' => '90% interval',
    'Samenhang (rangcorrelatie)' => 'Association (rank correlation)',
    'Kans op toeval' => 'Chance probability',
    'Na correctie voor %d vergelijkingen' => 'After correcting for %d comparisons',
    'Dagen' => 'Days',
    '%1$d dagen, %2$d en %3$d per groep' => '%1$d days, %2$d and %3$d per group',
    'Periode' => 'Period',
    'De afgelopen %d dagen' => 'The last %d days',
    '%1$s tot %2$s' => '%1$s to %2$s',
    'Ownify zet je dagen naast elkaar: een trainingsdag naast de nacht erna, een nacht naast de dag erna. Dagen zonder meting tellen niet mee; niets wordt als nul geteld.'
        => 'Ownify lines your days up: a training day next to the night after, a night next to the day after. Days without a measurement do not count; nothing is counted as zero.',
    'Eerst haalt Ownify je eigen weekdagpatroon en je langzame trend over de periode uit elke reeks, want het weekend en een drukke maand laten veel dingen tegelijk veranderen. Uitschieters tellen beperkt mee.'
        => 'First Ownify takes your own weekday pattern and your slow trend over the period out of each series, because weekends and a busy month change many things at once. Outliers count only so much.',
    'Dan meet Ownify de samenhang met een rangcorrelatie, rekening houdend met hoe dagen op elkaar lijken. Omdat Ownify %d verbanden tegelijk bekijkt, wordt gecorrigeerd voor het aantal vergelijkingen, zodat toevalstreffers zeldzaam blijven.'
        => 'Then Ownify measures the association with a rank correlation, taking into account how days resemble each other. Because Ownify looks at %d links at once, it corrects for the number of comparisons, so chance hits stay rare.',
    'Een patroon telt pas als het in beide helften van de periode dezelfde kant op gaat en het verschil groot genoeg is om ertoe te doen.'
        => 'A pattern only counts when it goes the same way in both halves of the period and the difference is big enough to matter.',
    'Over de methode' => 'About the method',

    /* ---- units in a sentence */
    '%s minuut' => '%s minute',
    '%s minuten' => '%s minutes',
    '%s slagen per minuut' => '%s beats per minute',
    '%s%%' => '%s%%',
    '%s stappen' => '%s steps',
    '%s kcal' => '%s kcal',
    '%s procentpunt' => '%s percentage points',
    '%s gram per kilo' => '%s grams per kilo',
    '%d dag' => '%d day',
    '%d dagen' => '%d days',
    '%d week' => '%d week',
    '%d weken' => '%d weeks',

    /* ---- C01 */
    'Training en slaap' => 'Training and sleep',
    'Na dagen met meer training sliep je de nacht erna gemiddeld %1$s langer (%2$d dagen met meer training tegenover %3$d met minder).'
        => 'After days with more training you slept on average %1$s longer the night after (%2$d days with more training against %3$d with less).',
    'Na dagen met meer training sliep je de nacht erna gemiddeld %1$s korter (%2$d dagen met meer training tegenover %3$d met minder).'
        => 'After days with more training you slept on average %1$s shorter the night after (%2$d days with more training against %3$d with less).',
    'Na meer training' => 'After more training',
    'Na minder training' => 'After less training',
    'Hoeveel je trainde maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je de nacht erna sliep.'
        => 'Over the last %1$d days, how much you trained made no noticeable difference to how long you slept the night after.',
    'Nog 1 dag nodig met een gemeten nacht erna.' => '1 more day with a measured night after it is needed.',
    'Nog %d dagen nodig met een gemeten nacht erna.' => '%d more days with a measured night after them are needed.',
    'Nog 1 trainingsdag nodig.' => '1 more training day is needed.',
    'Nog %d trainingsdagen nodig.' => '%d more training days are needed.',

    /* ---- C02 */
    'Late training en inslapen' => 'Late training and falling asleep',
    'Op avonden waarop je training minder dan 4 uur voor je gewone inslaaptijd eindigde, viel je gemiddeld %1$s later in slaap dan je gewend bent (%2$d avonden tegenover %3$d).'
        => 'On evenings when your workout ended less than 4 hours before your usual sleep onset, you fell asleep on average %1$s later than usual (%2$d evenings against %3$d).',
    'Op avonden waarop je training minder dan 4 uur voor je gewone inslaaptijd eindigde, viel je gemiddeld %1$s eerder in slaap dan je gewend bent (%2$d avonden tegenover %3$d).'
        => 'On evenings when your workout ended less than 4 hours before your usual sleep onset, you fell asleep on average %1$s earlier than usual (%2$d evenings against %3$d).',
    'Na een late training' => 'After a late workout',
    'Andere avonden' => 'Other evenings',
    'Of je laat op de avond trainde, maakte de afgelopen %1$d dagen geen merkbaar verschil voor wanneer je in slaap viel.'
        => 'Over the last %1$d days, whether you trained late in the evening made no noticeable difference to when you fell asleep.',
    'Nog 1 nacht nodig met een gemeten inslaaptijd.' => '1 more night with a measured sleep onset is needed.',
    'Nog %d nachten nodig met een gemeten inslaaptijd.' => '%d more nights with a measured sleep onset are needed.',
    'Nog 1 avond met een late training nodig.' => '1 more evening with a late workout is needed.',
    'Nog %d avonden met een late training nodig.' => '%d more evenings with a late workout are needed.',

    /* ---- C03 */
    'Zware training en HRV' => 'Hard training and HRV',
    'De nacht na een zware training was je HRV gemiddeld %1$s hoger dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).'
        => 'The night after a hard workout your HRV was on average %1$s higher than after other days (%2$d hard workouts against %3$d other days).',
    'De nacht na een zware training was je HRV gemiddeld %1$s lager dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).'
        => 'The night after a hard workout your HRV was on average %1$s lower than after other days (%2$d hard workouts against %3$d other days).',
    'Na een zware training' => 'After a hard workout',
    'Na andere dagen' => 'After other days',
    'Een zware training maakte de afgelopen %1$d dagen geen merkbaar verschil voor je HRV de nacht erna.'
        => 'Over the last %1$d days, a hard workout made no noticeable difference to your HRV the night after.',
    'Nog 1 nacht met HRV nodig.' => '1 more night with HRV is needed.',
    'Nog %d nachten met HRV nodig.' => '%d more nights with HRV are needed.',
    'Nog 1 zware training met HRV de nacht erna nodig.' => '1 more hard workout with HRV the night after is needed.',
    'Nog %d zware trainingen met HRV de nacht erna nodig.' => '%d more hard workouts with HRV the night after are needed.',

    /* ---- C04 */
    'Zware training en hartslag in slaap' => 'Hard training and heart rate in sleep',
    'De nacht na een zware training was je hartslag in je slaap gemiddeld %1$s hoger dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).'
        => 'The night after a hard workout your heart rate in sleep was on average %1$s higher than after other days (%2$d hard workouts against %3$d other days).',
    'De nacht na een zware training was je hartslag in je slaap gemiddeld %1$s lager dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).'
        => 'The night after a hard workout your heart rate in sleep was on average %1$s lower than after other days (%2$d hard workouts against %3$d other days).',
    'Een zware training maakte de afgelopen %1$d dagen geen merkbaar verschil voor je hartslag in je slaap.'
        => 'Over the last %1$d days, a hard workout made no noticeable difference to your heart rate in sleep.',
    'Nog 1 nacht met hartslag nodig.' => '1 more night with heart rate is needed.',
    'Nog %d nachten met hartslag nodig.' => '%d more nights with heart rate are needed.',
    'Nog 1 zware training met hartslag de nacht erna nodig.' => '1 more hard workout with heart rate the night after is needed.',
    'Nog %d zware trainingen met hartslag de nacht erna nodig.' => '%d more hard workouts with heart rate the night after are needed.',

    /* ---- C05 */
    'Herstel na zware training' => 'Recovery after hard training',
    'Twee nachten na een zware training was je HRV gemiddeld %1$s hoger dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).'
        => 'Two nights after a hard workout your HRV was on average %1$s higher than after other days (%2$d hard workouts against %3$d other days).',
    'Twee nachten na een zware training was je HRV gemiddeld %1$s lager dan na andere dagen (%2$d zware trainingen tegenover %3$d andere dagen).'
        => 'Two nights after a hard workout your HRV was on average %1$s lower than after other days (%2$d hard workouts against %3$d other days).',
    'Twee nachten na zware training' => 'Two nights after hard training',
    'Twee nachten na een zware training was je HRV niet merkbaar anders dan na andere dagen (de afgelopen %1$d dagen).'
        => 'Two nights after a hard workout your HRV was not noticeably different from after other days (the last %1$d days).',
    'Nog 1 zware training met HRV twee nachten later nodig.' => '1 more hard workout with HRV two nights later is needed.',
    'Nog %d zware trainingen met HRV twee nachten later nodig.' => '%d more hard workouts with HRV two nights later are needed.',

    /* ---- C06 */
    'Stappen en slaap' => 'Steps and sleep',
    'Na dagen met meer stappen sliep je de nacht erna gemiddeld %1$s langer (%2$d drukkere dagen tegenover %3$d rustigere).'
        => 'After days with more steps you slept on average %1$s longer the night after (%2$d busier days against %3$d quieter ones).',
    'Na dagen met meer stappen sliep je de nacht erna gemiddeld %1$s korter (%2$d drukkere dagen tegenover %3$d rustigere).'
        => 'After days with more steps you slept on average %1$s shorter the night after (%2$d busier days against %3$d quieter ones).',
    'Na meer stappen' => 'After more steps',
    'Na minder stappen' => 'After fewer steps',
    'Hoeveel stappen je zette, maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je de nacht erna sliep.'
        => 'Over the last %1$d days, how many steps you took made no noticeable difference to how long you slept the night after.',
    'Nog 1 dag nodig met stappen en een gemeten nacht erna.' => '1 more day with steps and a measured night after it is needed.',
    'Nog %d dagen nodig met stappen en een gemeten nacht erna.' => '%d more days with steps and a measured night after them are needed.',

    /* ---- C07 */
    'Slaap en stappen' => 'Sleep and steps',
    'Na langere nachten zette je de dag erna gemiddeld %1$s meer (%2$d langere nachten tegenover %3$d kortere).'
        => 'After longer nights you took on average %1$s more the day after (%2$d longer nights against %3$d shorter ones).',
    'Na langere nachten zette je de dag erna gemiddeld %1$s minder (%2$d langere nachten tegenover %3$d kortere).'
        => 'After longer nights you took on average %1$s fewer the day after (%2$d longer nights against %3$d shorter ones).',
    'Na een langere nacht' => 'After a longer night',
    'Na een kortere nacht' => 'After a shorter night',
    'Hoe lang je sliep, maakte de afgelopen %1$d dagen geen merkbaar verschil voor je stappen de dag erna.'
        => 'Over the last %1$d days, how long you slept made no noticeable difference to your steps the day after.',
    'Nog 1 nacht nodig met stappen de dag erna.' => '1 more night with steps the day after is needed.',
    'Nog %d nachten nodig met stappen de dag erna.' => '%d more nights with steps the day after are needed.',

    /* ---- C08 */
    'Korte nachten en eten' => 'Short nights and eating',
    'Op dagen na een korte nacht logde je gemiddeld %1$s meer (%2$d dagen tegenover %3$d andere volledig gelogde dagen).'
        => 'On days after a short night you logged on average %1$s more (%2$d days against %3$d other completely logged days).',
    'Op dagen na een korte nacht logde je gemiddeld %1$s minder (%2$d dagen tegenover %3$d andere volledig gelogde dagen).'
        => 'On days after a short night you logged on average %1$s less (%2$d days against %3$d other completely logged days).',
    'Na een korte nacht' => 'After a short night',
    'Na andere nachten' => 'After other nights',
    'Een korte nacht maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoeveel je logde.'
        => 'Over the last %1$d days, a short night made no noticeable difference to how much you logged.',
    'Nog 1 volledig gelogde dag na een gemeten nacht nodig.' => '1 more completely logged day after a measured night is needed.',
    'Nog %d volledig gelogde dagen na een gemeten nacht nodig.' => '%d more completely logged days after a measured night are needed.',
    'Nog 1 volledig gelogde dag na een korte nacht nodig.' => '1 more completely logged day after a short night is needed.',
    'Nog %d volledig gelogde dagen na een korte nacht nodig.' => '%d more completely logged days after a short night are needed.',

    /* ---- C09 */
    'Slaap en trainen' => 'Sleep and training',
    'Na langere nachten trainde je op %1$s meer van de dagen (%2$d langere nachten tegenover %3$d kortere).'
        => 'After longer nights you trained on %1$s more of the days (%2$d longer nights against %3$d shorter ones).',
    'Na langere nachten trainde je op %1$s minder van de dagen (%2$d langere nachten tegenover %3$d kortere).'
        => 'After longer nights you trained on %1$s fewer of the days (%2$d longer nights against %3$d shorter ones).',
    'Hoe lang je sliep, maakte de afgelopen %1$d dagen geen merkbaar verschil voor of je die dag trainde.'
        => 'Over the last %1$d days, how long you slept made no noticeable difference to whether you trained that day.',
    'Nog 1 gemeten nacht nodig.' => '1 more measured night is needed.',
    'Nog %d gemeten nachten nodig.' => '%d more measured nights are needed.',

    /* ---- C10 */
    'Voedingscijfer en slaap' => 'Nutrition rating and sleep',
    'Na dagen met een hoger voedingscijfer sliep je de nacht erna gemiddeld %1$s langer (%2$d dagen tegenover %3$d).'
        => 'After days with a higher nutrition rating you slept on average %1$s longer the night after (%2$d days against %3$d).',
    'Na dagen met een hoger voedingscijfer sliep je de nacht erna gemiddeld %1$s korter (%2$d dagen tegenover %3$d).'
        => 'After days with a higher nutrition rating you slept on average %1$s shorter the night after (%2$d days against %3$d).',
    'Na een hoger cijfer' => 'After a higher rating',
    'Na een lager cijfer' => 'After a lower rating',
    'Je voedingscijfer maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je de nacht erna sliep.'
        => 'Over the last %1$d days, your nutrition rating made no noticeable difference to how long you slept the night after.',
    'Nog 1 dag met een cijfer en een gemeten nacht erna nodig.' => '1 more day with a rating and a measured night after it is needed.',
    'Nog %d dagen met een cijfer en een gemeten nacht erna nodig.' => '%d more days with a rating and a measured night after them are needed.',

    /* ---- C11 */
    'Laat eten en inslapen' => 'Eating late and falling asleep',
    'Op avonden waarop je later at dan gewoonlijk, viel je gemiddeld %1$s later in slaap dan je gewend bent (%2$d avonden tegenover %3$d).'
        => 'On evenings when you ate later than usual, you fell asleep on average %1$s later than usual (%2$d evenings against %3$d).',
    'Op avonden waarop je later at dan gewoonlijk, viel je gemiddeld %1$s eerder in slaap dan je gewend bent (%2$d avonden tegenover %3$d).'
        => 'On evenings when you ate later than usual, you fell asleep on average %1$s earlier than usual (%2$d evenings against %3$d).',
    'Later gegeten' => 'Ate later',
    'Eerder gegeten' => 'Ate earlier',
    'Hoe laat je at, maakte de afgelopen %1$d dagen geen merkbaar verschil voor wanneer je in slaap viel.'
        => 'Over the last %1$d days, how late you ate made no noticeable difference to when you fell asleep.',
    'Nog 1 volledig gelogde dag met een gemeten nacht erna nodig.' => '1 more completely logged day with a measured night after it is needed.',
    'Nog %d volledig gelogde dagen met een gemeten nacht erna nodig.' => '%d more completely logged days with a measured night after them are needed.',

    /* ---- C12 */
    'Eiwit op trainingsdagen' => 'Protein on training days',
    'Op trainingsdagen at je gemiddeld %1$s meer eiwit dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).'
        => 'On training days you ate on average %1$s more protein than on rest days (%2$d training days against %3$d rest days).',
    'Op trainingsdagen at je gemiddeld %1$s minder eiwit dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).'
        => 'On training days you ate on average %1$s less protein than on rest days (%2$d training days against %3$d rest days).',
    'Trainingsdagen' => 'Training days',
    'Rustdagen' => 'Rest days',
    'Op trainingsdagen at je de afgelopen %1$d dagen ongeveer even veel eiwit als op rustdagen.'
        => 'Over the last %1$d days you ate about as much protein on training days as on rest days.',
    'Nog 1 volledig gelogde dag nodig.' => '1 more completely logged day is needed.',
    'Nog %d volledig gelogde dagen nodig.' => '%d more completely logged days are needed.',
    'Nog 1 volledig gelogde trainingsdag nodig.' => '1 more completely logged training day is needed.',
    'Nog %d volledig gelogde trainingsdagen nodig.' => '%d more completely logged training days are needed.',

    /* ---- C13 */
    'Energie op trainingsdagen' => 'Energy on training days',
    'Op trainingsdagen logde je gemiddeld %1$s meer dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).'
        => 'On training days you logged on average %1$s more than on rest days (%2$d training days against %3$d rest days).',
    'Op trainingsdagen logde je gemiddeld %1$s minder dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).'
        => 'On training days you logged on average %1$s less than on rest days (%2$d training days against %3$d rest days).',
    'Op trainingsdagen logde je de afgelopen %1$d dagen ongeveer even veel als op rustdagen.'
        => 'Over the last %1$d days you logged about as much on training days as on rest days.',

    /* ---- C14 */
    'Water op trainingsdagen' => 'Water on training days',
    'Op trainingsdagen dronk je gemiddeld %1$s meer dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).'
        => 'On training days you drank on average %1$s more than on rest days (%2$d training days against %3$d rest days).',
    'Op trainingsdagen dronk je gemiddeld %1$s minder dan op rustdagen (%2$d trainingsdagen tegenover %3$d rustdagen).'
        => 'On training days you drank on average %1$s less than on rest days (%2$d training days against %3$d rest days).',
    'Op trainingsdagen dronk je de afgelopen %1$d dagen ongeveer even veel als op rustdagen.'
        => 'Over the last %1$d days you drank about as much on training days as on rest days.',
    'Nog 1 dag met gelogd water nodig.' => '1 more day with logged water is needed.',
    'Nog %d dagen met gelogd water nodig.' => '%d more days with logged water are needed.',
    'Nog 1 trainingsdag met gelogd water nodig.' => '1 more training day with logged water is needed.',
    'Nog %d trainingsdagen met gelogd water nodig.' => '%d more training days with logged water are needed.',

    /* ---- C15 */
    'Weekend en inslapen' => 'Weekend and falling asleep',
    'In het weekend viel je gemiddeld %1$s later in slaap dan doordeweeks (%2$d weekendnachten tegenover %3$d doordeweekse).'
        => 'At weekends you fell asleep on average %1$s later than on weekdays (%2$d weekend nights against %3$d weekday ones).',
    'In het weekend viel je gemiddeld %1$s eerder in slaap dan doordeweeks (%2$d weekendnachten tegenover %3$d doordeweekse).'
        => 'At weekends you fell asleep on average %1$s earlier than on weekdays (%2$d weekend nights against %3$d weekday ones).',
    'Weekend' => 'Weekend',
    'Doordeweeks' => 'Weekdays',
    'In het weekend viel je de afgelopen %1$d dagen ongeveer even laat in slaap als doordeweeks.'
        => 'Over the last %1$d days you fell asleep at about the same time at weekends as on weekdays.',
    'Nog 1 gemeten weekendnacht nodig.' => '1 more measured weekend night is needed.',
    'Nog %d gemeten weekendnachten nodig.' => '%d more measured weekend nights are needed.',

    /* ---- C16 */
    'Trainingsweken en slaap' => 'Training weeks and sleep',
    'In weken met meer training sliep je gemiddeld %1$s per nacht langer (%2$d drukkere weken tegenover %3$d rustigere).'
        => 'In weeks with more training you slept on average %1$s a night longer (%2$d busier weeks against %3$d quieter ones).',
    'In weken met meer training sliep je gemiddeld %1$s per nacht korter (%2$d drukkere weken tegenover %3$d rustigere).'
        => 'In weeks with more training you slept on average %1$s a night shorter (%2$d busier weeks against %3$d quieter ones).',
    'Weken met meer training' => 'Weeks with more training',
    'Weken met minder training' => 'Weeks with less training',
    'Hoeveel je per week trainde, maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je sliep.'
        => 'Over the last %1$d days, how much you trained per week made no noticeable difference to how long you slept.',
    'Nog 1 week met training en nachten nodig.' => '1 more week with training and nights is needed.',
    'Nog %d weken met training en nachten nodig.' => '%d more weeks with training and nights are needed.',

    /* ---- C17 */
    'Cafeïne en slaap' => 'Caffeine and sleep',
    'Na dagen met cafeïne in de 8,8 uur voor je gewone inslaaptijd sliep je gemiddeld %1$s langer (%2$d dagen tegenover %3$d).'
        => 'After days with caffeine in the 8.8 hours before your usual sleep onset you slept on average %1$s longer (%2$d days against %3$d).',
    'Na dagen met cafeïne in de 8,8 uur voor je gewone inslaaptijd sliep je gemiddeld %1$s korter (%2$d dagen tegenover %3$d).'
        => 'After days with caffeine in the 8.8 hours before your usual sleep onset you slept on average %1$s shorter (%2$d days against %3$d).',
    'Late cafeïne' => 'Late caffeine',
    'Andere dagen' => 'Other days',
    'Cafeïne laat op de dag maakte de afgelopen %1$d dagen geen merkbaar verschil voor hoe lang je sliep.'
        => 'Over the last %1$d days, caffeine late in the day made no noticeable difference to how long you slept.',
    'Nog 1 dag met gelogde cafeïne nodig.' => '1 more day with logged caffeine is needed.',
    'Nog %d dagen met gelogde cafeïne nodig.' => '%d more days with logged caffeine are needed.',
    'Nog 1 dag met cafeïne laat op de dag nodig.' => '1 more day with caffeine late in the day is needed.',
    'Nog %d dagen met cafeïne laat op de dag nodig.' => '%d more days with caffeine late in the day are needed.',
];
