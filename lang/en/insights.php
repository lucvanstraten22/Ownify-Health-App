<?php
/* English: config/insights.php — the insights and the suggestion */
declare(strict_types=1);

return [

    /* ---- the cards */
    'Bekijk onderzoek' => 'View research',
    'Altijd een suggestie, nooit een opdracht.' => 'Always a suggestion, never an order.',
    'Deze suggestie past niet meer bij je nieuwste gegevens.' => 'This suggestion no longer fits your latest data.',
    'Verschijnt zodra er genoeg gegevens zijn' => 'Appears once there is enough data',
    'Uit je eigen gegevens, met het onderzoek erbij' => 'From your own data, with the research behind it',
    'Vandaag geen suggestie' => 'No suggestion today',
    'Er is vandaag niets waarbij een kleine suggestie past. Dat is ook een uitkomst.' => 'There is nothing today that a small suggestion fits. That is an outcome too.',
    'Hier verschijnt een inzicht zodra er genoeg nachten zijn gemeten en je leeftijd bekend is.' => 'An insight appears here once enough nights have been measured and your age is known.',
    'Hier verschijnt een inzicht zodra je een paar dagen volledig hebt gelogd.' => 'An insight appears here once you have logged a few complete days.',
    'Hier verschijnt een inzicht zodra er een week aan stappen of trainingen binnen is.' => 'An insight appears here once a week of steps or workouts is in.',
    'Slaap' => 'Sleep',
    'Voeding' => 'Nutrition',
    'Beweging' => 'Activity',

    /* ---- Instellingen → Privacy (30.1: no Gemini switch for insights) */
    'Je inzichten schrijft Ownify zelf. Er gaat niets over naar Gemini, dus hier is niets meer aan of uit te zetten.' => 'Ownify writes your insights itself. Nothing about them goes to Gemini, so there is nothing left to switch on or off here.',
    'Inzichten' => 'Insights',

    /* ---- titles */
    'Slaapduur' => 'Sleep duration',
    'Slaapritme' => 'Sleep rhythm',
    'Spierversterkend bewegen' => 'Muscle-strengthening activity',
    'Stappen' => 'Steps',
    'Vet' => 'Fat',
    'Verzadigd vet' => 'Saturated fat',
    'Eiwit' => 'Protein',
    'Zout' => 'Salt',
    'BMI' => 'BMI',

    /* ---- sleep */
    'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Voor jouw leeftijd wordt minstens %3$d uur aangeraden.'
        => 'Over the last %1$d nights you slept %2$s a night on average, according to your measurements. For your age, at least %3$d hours is recommended.',
    'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Voor jouw leeftijd wordt %3$d tot %4$d uur aangeraden.'
        => 'Over the last %1$d nights you slept %2$s a night on average, according to your measurements. For your age, %3$d to %4$d hours is recommended.',
    'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Dat is minstens de %3$d uur die voor jouw leeftijd wordt aangeraden.'
        => 'Over the last %1$d nights you slept %2$s a night on average, according to your measurements. That is at least the %3$d hours recommended for your age.',
    'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Dat valt binnen de %3$d tot %4$d uur die voor jouw leeftijd wordt aangeraden.'
        => 'Over the last %1$d nights you slept %2$s a night on average, according to your measurements. That is within the %3$d to %4$d hours recommended for your age.',
    'Je sliep de afgelopen %1$d nachten gemiddeld %2$s per nacht, volgens je metingen. Dat is meer dan de %3$d tot %4$d uur die voor jouw leeftijd wordt genoemd; ook meer slaap kan passend zijn.'
        => 'Over the last %1$d nights you slept %2$s a night on average, according to your measurements. That is more than the %3$d to %4$d hours named for your age; more sleep can be appropriate too.',
    'Je bedtijd wisselde de afgelopen %1$d nachten zo\'n %2$d minuten en je opstaantijd zo\'n %3$d minuten. Onderzoek noemt regelmatige tijden van inslapen en opstaan belangrijk voor gezondheid, veiligheid en prestaties.'
        => 'Over the last %1$d nights your bedtime varied by about %2$d minutes and your wake time by about %3$d minutes. Research calls regular times of falling asleep and waking up important for health, safety and performance.',
    'Je slaaptijden wisselden de afgelopen %1$d nachten meer dan in de drie weken ervoor: zo\'n %5$d minuten, tegen %4$d minuten. Onderzoek noemt regelmatige tijden van inslapen en opstaan belangrijk voor gezondheid, veiligheid en prestaties.'
        => 'Over the last %1$d nights your sleep times varied more than in the three weeks before: about %5$d minutes, against %4$d minutes. Research calls regular times of falling asleep and waking up important for health, safety and performance.',
    'Je slaaptijden wisselden de afgelopen %1$d nachten minder dan in de drie weken ervoor: zo\'n %5$d minuten, tegen %4$d minuten. Onderzoek noemt regelmatige tijden van inslapen en opstaan belangrijk voor gezondheid, veiligheid en prestaties.'
        => 'Over the last %1$d nights your sleep times varied less than in the three weeks before: about %5$d minutes, against %4$d minutes. Research calls regular times of falling asleep and waking up important for health, safety and performance.',

    /* ---- activity */
    'Je legde de afgelopen 7 dagen %1$s minuten training vast. De Beweegrichtlijn noemt minstens %2$d minuten matig intensief bewegen per week. Ook wandelen en fietsen tellen mee, en die ziet Ownify niet allemaal.'
        => 'You recorded %1$s minutes of workouts over the last 7 days. The Dutch physical activity guideline names at least %2$d minutes of moderate-intensity activity a week. Walking and cycling count too, and Ownify does not see all of it.',
    'Je legde de afgelopen 7 dagen %1$s minuten training vast: al minstens de %2$d minuten per week uit de Beweegrichtlijn, en dan telt Ownify alleen je vastgelegde trainingen.'
        => 'You recorded %1$s minutes of workouts over the last 7 days: already at least the %2$d minutes a week of the Dutch physical activity guideline, and Ownify only counts your recorded workouts.',
    'Je legde de afgelopen 7 dagen gemiddeld %1$s minuten training per dag vast. De Beweegrichtlijn noemt voor kinderen en jongeren elke dag minstens %2$d minuten bewegen. Ook buitenspelen en fietsen tellen mee, en die ziet Ownify niet allemaal.'
        => 'Over the last 7 days you recorded %1$s minutes of workouts a day on average. The Dutch physical activity guideline names at least %2$d minutes of activity every day for children and young people. Playing outside and cycling count too, and Ownify does not see all of it.',
    'Je legde de afgelopen 7 dagen gemiddeld %1$s minuten training per dag vast: al minstens de %2$d minuten per dag uit de Beweegrichtlijn.'
        => 'Over the last 7 days you recorded %1$s minutes of workouts a day on average: already at least the %2$d minutes a day of the Dutch physical activity guideline.',
    'Je legde de afgelopen 7 dagen op %1$d dagen krachttraining vast. De Beweegrichtlijn noemt minstens %2$d keer per week spier- en botversterkende activiteiten.'
        => 'Over the last 7 days you recorded strength training on %1$d days. The Dutch physical activity guideline names muscle- and bone-strengthening activities at least %2$d times a week.',
    'Je legde de afgelopen 7 dagen op %1$d dagen krachttraining vast: minstens de %2$d keer per week spier- en botversterkende activiteiten uit de Beweegrichtlijn.'
        => 'Over the last 7 days you recorded strength training on %1$d days: at least the %2$d times a week of muscle- and bone-strengthening activities of the Dutch physical activity guideline.',
    'Je zette de afgelopen %1$d dagen gemiddeld %2$s stappen per dag. In onderzoek hangt meer stappen per dag samen met minder gezondheidsrisico\'s; rond %3$s stappen per dag was dat verband al duidelijk.'
        => 'Over the last %1$d days you took %2$s steps a day on average. In research, more steps a day goes together with fewer health risks; at around %3$s steps a day that association was already clear.',
    'Je zette de afgelopen %1$d dagen gemiddeld %2$s stappen per dag. In onderzoek bij volwassenen van jouw leeftijd vlakte het verband met een lager sterfterisico af rond %4$s tot %5$s stappen per dag.'
        => 'Over the last %1$d days you took %2$s steps a day on average. In research among adults of your age, the association with a lower risk of death levelled off at around %4$s to %5$s steps a day.',
    'Je zette de afgelopen %1$d dagen gemiddeld %2$s stappen per dag. In onderzoek bij volwassenen van jouw leeftijd vlakte het verband met een lager sterfterisico af rond %4$s tot %5$s stappen per dag; daar zit je al.'
        => 'Over the last %1$d days you took %2$s steps a day on average. In research among adults of your age, the association with a lower risk of death levelled off at around %4$s to %5$s steps a day; you are already there.',

    /* ---- nutrition */
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam %2$s %% van je energie uit vet. De Gezondheidsraad noemt %3$d tot %4$d %%; de kwaliteit van wat je eet telt daarbij meer dan de hoeveelheid vet.'
        => 'On the %1$d days you seem to have logged in full, %2$s %% of your energy came from fat. The Health Council of the Netherlands names %3$d to %4$d %%; the quality of what you eat matters more than the amount of fat.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam %2$s %% van je energie uit vet: binnen de %3$d tot %4$d %% die de Gezondheidsraad noemt.'
        => 'On the %1$d days you seem to have logged in full, %2$s %% of your energy came from fat: within the %3$d to %4$d %% the Health Council of the Netherlands names.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam %2$s %% van je energie uit verzadigd vet. De Gezondheidsraad noemt zo laag mogelijk, met %3$d %% als tussenstap.'
        => 'On the %1$d days you seem to have logged in full, %2$s %% of your energy came from saturated fat. The Health Council of the Netherlands says as low as possible, with %3$d %% as an interim step.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam %2$s %% van je energie uit verzadigd vet: onder de %3$d %% die de Gezondheidsraad als tussenstap noemt. Het advies is zo laag mogelijk.'
        => 'On the %1$d days you seem to have logged in full, %2$s %% of your energy came from saturated fat: under the %3$d %% the Health Council of the Netherlands names as an interim step. The advice is as low as possible.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram vezels per 1.000 kcal. De Gezondheidsraad noemt %3$s tot %4$s gram per 1.000 kcal.'
        => 'On the %1$d days you seem to have logged in full, you ate %2$s grams of fiber per 1,000 kcal. The Health Council of the Netherlands names %3$s to %4$s grams per 1,000 kcal.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram vezels per 1.000 kcal: binnen de %3$s tot %4$s gram die de Gezondheidsraad noemt.'
        => 'On the %1$d days you seem to have logged in full, you ate %2$s grams of fiber per 1,000 kcal: within the %3$s to %4$s grams the Health Council of the Netherlands names.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram vezels per 1.000 kcal: meer dan de %3$s tot %4$s gram die de Gezondheidsraad noemt.'
        => 'On the %1$d days you seem to have logged in full, you ate %2$s grams of fiber per 1,000 kcal: more than the %3$s to %4$s grams the Health Council of the Netherlands names.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram eiwit per kilogram lichaamsgewicht. De Gezondheidsraad raadt volwassenen %3$s gram per kilogram per dag aan.'
        => 'On the %1$d days you seem to have logged in full, you ate %2$s grams of protein per kilogram of body weight. The Health Council of the Netherlands recommends %3$s grams per kilogram a day for adults.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, at je %2$s gram eiwit per kilogram lichaamsgewicht: minstens de %3$s gram die de Gezondheidsraad volwassenen aanraadt.'
        => 'On the %1$d days you seem to have logged in full, you ate %2$s grams of protein per kilogram of body weight: at least the %3$s grams the Health Council of the Netherlands recommends for adults.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam het natrium in je log neer op zo\'n %2$s gram zout per dag. De Gezondheidsraad noemt maximaal %3$d gram; zout dat je zelf toevoegt, staat meestal niet in je log.'
        => 'On the %1$d days you seem to have logged in full, the sodium in your log came to about %2$s grams of salt a day. The Health Council of the Netherlands names at most %3$d grams; salt you add yourself is usually not in your log.',
    'Op de %1$d dagen die je volledig lijkt te hebben gelogd, kwam het natrium in je log neer op zo\'n %2$s gram zout per dag: onder de %3$d gram die de Gezondheidsraad als maximum noemt. Zout dat je zelf toevoegt, staat meestal niet in je log.'
        => 'On the %1$d days you seem to have logged in full, the sodium in your log came to about %2$s grams of salt a day: under the %3$d grams the Health Council of the Netherlands names as the maximum. Salt you add yourself is usually not in your log.',

    /* ---- body */
    'Met je lengte en gewicht is je BMI %1$s. De WHO gebruikt bij volwassenen %2$d als grens voor overgewicht en %3$d voor obesitas. BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.'
        => 'With your height and weight, your BMI is %1$s. For adults, the WHO uses %2$d as the threshold for overweight and %3$d for obesity. BMI says nothing about your body composition, such as muscle mass.',
    'Met je lengte en gewicht is je BMI %1$s. De WHO noemt bij volwassenen een BMI vanaf %2$d overgewicht en vanaf %3$d obesitas. BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.'
        => 'With your height and weight, your BMI is %1$s. For adults, the WHO calls a BMI from %2$d overweight and from %3$d obesity. BMI says nothing about your body composition, such as muscle mass.',

    /* ---- suggestions */
    'Misschien iets voor deze week: één avond wat eerder naar bed gaan, en kijken hoe die nacht verloopt.' => 'Maybe something for this week: going to bed a little earlier one evening, and seeing how that night goes.',
    'Misschien iets voor deze week: een paar avonden rond dezelfde tijd naar bed gaan.' => 'Maybe something for this week: going to bed at about the same time on a few evenings.',
    'Misschien iets voor deze week: een extra wandeling of fietstocht. Ook een korte telt mee.' => 'Maybe something for this week: an extra walk or bike ride. A short one counts too.',
    'Misschien iets voor deze week: een moment voor oefeningen die je spieren versterken, zoals oefeningen met je eigen gewicht.' => 'Maybe something for this week: a moment for exercises that strengthen your muscles, such as bodyweight exercises.',
    'Misschien iets voor deze week: op een paar dagen een korte wandeling extra.' => 'Maybe something for this week: a short extra walk on a few days.',
    'Misschien iets voor deze week: eens kijken uit welke producten het verzadigd vet in je log komt.' => 'Maybe something for this week: having a look at which foods the saturated fat in your log comes from.',
    'Misschien iets voor deze week: eens volkoren kiezen, of peulvruchten, groente of noten aan een maaltijd toevoegen.' => 'Maybe something for this week: choosing whole grain now and then, or adding legumes, vegetables or nuts to a meal.',
    'Misschien iets voor deze week: eens kijken in welke maaltijden je eiwit eet.' => 'Maybe something for this week: having a look at which meals you eat protein in.',
    'Misschien iets voor deze week: eens kijken hoeveel zout er in je vaste producten zit.' => 'Maybe something for this week: having a look at how much salt is in the foods you usually buy.',
];
