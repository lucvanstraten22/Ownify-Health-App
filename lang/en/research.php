<?php
/* English: config/research.php — the approved research behind the insights */
declare(strict_types=1);

return [

    /* ---- kinds of source */
    'Officiële gezondheidsinformatie (samenvatting van consensusverklaringen)' => 'Official health information (a summary of consensus statements)',
    'Consensusverklaring van deskundigen, na literatuuronderzoek' => 'Expert consensus statement, after a literature review',
    'Officiële richtlijn, op basis van systematische reviews' => 'Official guideline, based on systematic reviews',
    'Meta-analyse van cohortonderzoek' => 'Meta-analysis of cohort studies',
    'Systematische review met meta-analyse' => 'Systematic review with meta-analysis',
    'Overzicht van systematische reviews' => 'Umbrella review of systematic reviews',
    'Validatiestudie in een slaaplaboratorium' => 'Validation study in a sleep laboratory',
    'Officiële voedingsnormen' => 'Official dietary reference values',
    'Standpunt van een beroepsvereniging' => 'Position stand of a professional society',
    'Officieel feitenblad' => 'Official fact sheet',

    /* ---- strengths */
    'Zeer betrouwbaar' => 'Very reliable',
    'Betrouwbaar' => 'Reliable',
    'Beperkt onderbouwd' => 'Limited evidence',
    'Een officiële richtlijn of norm, gebaseerd op systematisch beoordeeld onderzoek, die past bij wat Ownify meet.' => 'An official guideline or norm, based on systematically assessed research, that fits what Ownify measures.',
    'Een consensus van deskundigen of een meta-analyse van vooral observationeel onderzoek, of een sterke richtlijn die Ownify maar bij benadering kan toepassen.' => 'An expert consensus or a meta-analysis of mostly observational research, or a strong guideline that Ownify can only apply approximately.',
    'Eén studie, een standpunt van een vereniging, of een meting die maar deels past bij wat het onderzoek mat.' => 'One study, a society\'s position, or a measurement that only partly matches what the research measured.',
    'Dit label zegt hoe sterk onderzoek deze bewering onderbouwt. Het is geen kans en geen percentage.' => 'This label says how strongly research supports this statement. It is not a probability and not a percentage.',
    'Ownify past dit maar bij benadering toe op je eigen gegevens. Daarom staat het label hier een stap lager dan het onderzoek zelf.' => 'Ownify can only apply this approximately to your own data. That is why the label here is one step lower than the research itself.',
    'Je gegevens passen maar deels bij wat het onderzoek mat. Daarom staat het label hier twee stappen lager dan het onderzoek zelf.' => 'Your data only partly matches what the research measured. That is why the label here is two steps lower than the research itself.',

    /* ---- the detail view */
    'Uit onderzoek blijkt' => 'Research shows',
    'Wat het onderzoek vond' => 'What the research found',
    'Wat Ownify ermee zegt' => 'What Ownify says with it',
    'Bewijskracht' => 'Strength of evidence',
    'Voor wie' => 'Who it applies to',
    'Beperkingen' => 'Limitations',
    'Bron' => 'Source',
    'Soort onderzoek' => 'Type of research',
    'De publicatie is in het Nederlands.' => 'The publication is in Dutch.',
    'De publicatie is in het Engels.' => 'The publication is in English.',
    'Verschenen' => 'Published',
    'Gecontroleerd op %s' => 'Checked on %s',
    'Bekijk de publicatie' => 'View the publication',
    'Lees de pdf' => 'Read the PDF',
    'Open de pdf' => 'Open the PDF',
    'Download de pdf' => 'Download the PDF',
    'Een ongewijzigde kopie van de publicatie, gedeeld onder %s.' => 'An unmodified copy of the publication, shared under %s.',
    'De officiële pdf, rechtstreeks van de uitgever.' => 'The official PDF, straight from the publisher.',
    'Geen pdf om in Ownify te lezen: de publicatie opent bij de uitgever.' => 'No PDF to read in Ownify: the publication opens at the publisher.',
    'De pdf wordt geladen…' => 'Loading the PDF…',
    'De pdf kon niet worden geladen. Je kunt hem bij de uitgever openen.' => 'The PDF could not be loaded. You can open it at the publisher.',
    'Je bent offline. De publicatie opent zodra je weer verbinding hebt.' => 'You are offline. The publication opens as soon as you are connected again.',
    'Pagina %1$d van %2$d' => 'Page %1$d of %2$d',
    'Ownify gebruikt inmiddels een nieuwere versie van deze bewering.' => 'Ownify now uses a newer version of this statement.',

    /* ---- organisations, citations, licences */
    'American Academy of Sleep Medicine en Sleep Research Society' => 'American Academy of Sleep Medicine and Sleep Research Society',
    'Wereldgezondheidsorganisatie (WHO)' => 'World Health Organization (WHO)',
    'Gezondheidsraad' => 'Health Council of the Netherlands (Gezondheidsraad)',
    'CDC, Sleep, About Sleep (15 mei 2024)' => 'CDC, Sleep, About Sleep (May 15, 2024)',
    'WHO, Genève 2020, 104 pagina\'s' => 'WHO, Geneva 2020, 104 pages',
    'Gezondheidsraad, Den Haag 2017, publicatienr. 2017/08' => 'Health Council of the Netherlands, The Hague 2017, publication no. 2017/08',
    'Gezondheidsraad, Den Haag 2026, publicatienr. 2026/14 (gecorrigeerde pdf 7 september 2026)' => 'Health Council of the Netherlands, The Hague 2026, publication no. 2026/14 (corrected PDF September 7, 2026)',
    'Gezondheidsraad, Den Haag 2021, publicatienr. 2021/10' => 'Health Council of the Netherlands, The Hague 2021, publication no. 2021/10',
    'Gezondheidsraad, Den Haag 2015, publicatienr. 2015/24, ISBN 978-94-6281-089-1' => 'Health Council of the Netherlands, The Hague 2015, publication no. 2015/24, ISBN 978-94-6281-089-1',
    'WHO-feitenblad, bijgewerkt 7 oktober 2026' => 'WHO fact sheet, updated October 7, 2026',
    'Webpagina, geen pdf' => 'Web page, no PDF',
    'Gratis te lezen, alle rechten voorbehouden' => 'Free to read, all rights reserved',
    'Gratis te lezen, auteursrecht voorbehouden' => 'Free to read, copyright reserved',
    'Niet vrij beschikbaar (abonnement)' => 'Not freely available (subscription)',

    /* ---- what each source found */
    'Aanbevolen slaap per dag: 6–12 jaar 9–12 uur, 13–17 jaar 8–10 uur, 18–60 jaar 7 uur of meer, 61–64 jaar 7–9 uur en vanaf 65 jaar 7–8 uur.' => 'Recommended sleep per day: 6–12 years 9–12 hours, 13–17 years 8–10 hours, 18–60 years 7 or more hours, 61–64 years 7–9 hours and from 65 years 7–8 hours.',
    'Volwassenen horen regelmatig 7 uur of meer per nacht te slapen. Regelmatig korter dan 7 uur slapen hangt samen met onder meer gewichtstoename, diabetes, hoge bloeddruk, hart- en vaatziekten, depressie en een hoger sterfterisico. Meer dan 9 uur kan passend zijn voor jonge volwassenen, bij het inhalen van slaaptekort of bij ziekte; voor anderen is onzeker of dat een risico geeft.' => 'Adults should sleep 7 or more hours per night on a regular basis. Regularly sleeping less than 7 hours is associated with, among other things, weight gain, diabetes, high blood pressure, heart disease and stroke, depression and a higher risk of death. More than 9 hours may be appropriate for young adults, when catching up on lost sleep or when ill; for others it is uncertain whether it carries a risk.',
    'Een panel van deskundigen noemt als passende slaapduur: schoolkinderen 9–11 uur, tieners (14–17) 8–10 uur, jongvolwassenen en volwassenen (18–64) 7–9 uur en ouderen (65+) 7–8 uur. Wat daarbuiten valt kan passend zijn, maar grote afwijkingen zijn zeldzaam.' => 'An expert panel names as appropriate sleep: school-age children 9–11 hours, teenagers (14–17) 8–10 hours, young adults and adults (18–64) 7–9 hours and older adults (65+) 7–8 hours. Durations outside these ranges may be appropriate, but large deviations are rare.',
    'Kinderen van 6–12 jaar horen 9–12 uur per 24 uur te slapen en tieners van 13–18 jaar 8–10 uur. Regelmatig korter slapen hangt samen met problemen met aandacht, gedrag en leren, en met meer ongelukken, hoge bloeddruk, overgewicht, diabetes en depressie.' => 'Children aged 6–12 should sleep 9–12 hours per 24 hours and teenagers aged 13–18 8–10 hours. Regularly sleeping less is associated with problems with attention, behaviour and learning, and with more accidents, high blood pressure, obesity, diabetes and depression.',
    'Na een literatuuronderzoek concludeerde een panel van slaap- en ritmedeskundigen dat regelmatige tijden van inslapen en opstaan belangrijk zijn voor gezondheid, veiligheid en prestaties. Wie doordeweeks te weinig slaapt, kan baat hebben bij inhalen op vrije dagen.' => 'After a literature review, a panel of sleep and circadian experts concluded that regular times of falling asleep and waking up are important for health, safety and performance. People who sleep too little on work days may benefit from catching up on free days.',
    'In een slaaplaboratorium (34 gezonde jonge volwassenen, 3 nachten) herkenden zeven consumentenapparaten slaap goed, maar wakker zijn veel minder goed. Slaapfasen kwamen wisselend overeen en op onrustige nachten werkten de apparaten slechter.' => 'In a sleep laboratory (34 healthy young adults, 3 nights), seven consumer devices detected sleep well, but being awake much less well. Sleep stages agreed inconsistently, and the devices did worse on restless nights.',
    'Volwassenen (18–64): per week minstens 150–300 minuten matig of 75–150 minuten zwaar intensieve aerobe activiteit, plus spierversterkende activiteiten op 2 of meer dagen (sterke aanbeveling, matige zekerheid). Vanaf 65: hetzelfde, plus gevarieerde activiteit met nadruk op balans en kracht op 3 of meer dagen. 5–17 jaar: gemiddeld 60 minuten per dag. Beperk zitten; een beetje bewegen is beter dan niets.' => 'Adults (18–64): at least 150–300 minutes of moderate or 75–150 minutes of vigorous aerobic activity per week, plus muscle-strengthening activities on 2 or more days (strong recommendation, moderate certainty). From 65: the same, plus varied activity emphasising balance and strength on 3 or more days. Ages 5–17: an average of 60 minutes a day. Limit sitting; some activity is better than none.',
    'Bewegen is goed, meer bewegen is beter. Volwassenen en ouderen: minstens 150 minuten per week matig intensief bewegen, verspreid over diverse dagen, minstens twee keer per week spier- en botversterkende activiteiten (ouderen met balansoefeningen) en voorkom veel stilzitten. Kinderen van 4 tot 18 jaar: elke dag minstens een uur matig intensief bewegen en minstens drie keer per week spier- en botversterkende activiteiten.' => 'Moving is good, moving more is better. Adults and older adults: at least 150 minutes of moderate-intensity activity per week, spread over several days, muscle- and bone-strengthening activities at least twice a week (older adults with balance exercises), and avoid sitting still a lot. Children aged 4 to 18: at least an hour of moderate-intensity activity every day and muscle- and bone-strengthening activities at least three times a week.',
    'In 57 studies uit 35 cohorten hing 7.000 stappen per dag, vergeleken met 2.000, samen met 47% minder sterfte, 25% minder hart- en vaatziekten, 38% minder dementie en 22% minder depressieve klachten. Voor sterfte vlakte het verband af rond 5.000–7.000 stappen. De zekerheid van het bewijs was voor de meeste uitkomsten matig.' => 'In 57 studies from 35 cohorts, 7,000 steps a day, compared with 2,000, was associated with 47% lower mortality, 25% less cardiovascular disease, 38% less dementia and 22% fewer depressive symptoms. For mortality the association levelled off around 5,000–7,000 steps. The certainty of the evidence was moderate for most outcomes.',
    'In 15 cohorten (47.471 volwassenen) hing meer stappen per dag samen met een steeds lager sterfterisico, tot het afvlakte rond 6.000–8.000 stappen bij 60-plussers en rond 8.000–10.000 bij volwassenen onder de 60.' => 'In 15 cohorts (47,471 adults), more steps a day was associated with a progressively lower risk of death, levelling off around 6,000–8,000 steps for adults aged 60 and older and around 8,000–10,000 for adults under 60.',
    'Een overzicht van 24 systematische reviews (249 validatiestudies): hartslag wijkt gemiddeld ongeveer 3% af, stappen worden meestal iets ondergeschat (−9% tot 12%), energieverbruik kan flink afwijken (−21% tot +15%), zuurstofsaturatie tot 2 procentpunt, en slaaptijd wordt meestal overschat, vaak met meer dan 10%. Ongeveer 11% van de apparaten is voor minstens één meting gevalideerd.' => 'An overview of 24 systematic reviews (249 validation studies): heart rate is off by about 3% on average, steps are mostly slightly underestimated (−9% to 12%), energy expenditure can be far off (−21% to +15%), oxygen saturation by up to 2 percentage points, and sleep time is mostly overestimated, often by more than 10%. About 11% of devices have been validated for at least one measurement.',
    'Totaal vet: 20–40% van de energie (vanaf 4 jaar); de kwaliteit van het voedingspatroon telt meer dan de hoeveelheid vet. Verzadigd vet en vrije suikers: zo laag mogelijk, met 10% van de energie als tussenstap. Koolhydraten: geen percentage, wel vooral uit volkoren, groente, fruit, peulvruchten en zuivel. Vezels: 3,0–3,5 gram per megajoule, ofwel 12,6–14,6 gram per 1.000 kcal.' => 'Total fat: 20–40% of energy (from age 4); the quality of the diet matters more than the amount of fat. Saturated fat and free sugars: as low as possible, with 10% of energy as an interim step. Carbohydrates: no percentage, but mainly from whole grains, vegetables, fruit, legumes and dairy. Fiber: 3.0–3.5 grams per megajoule, or 12.6–14.6 grams per 1,000 kcal.',
    'Volwassenen hebben gemiddeld 0,66 gram eiwit per kilogram lichaamsgewicht per dag nodig; de aanbevolen hoeveelheid is 0,83 gram per kilogram per dag. Er is geen overtuigend bewijs dat alle ouderen meer nodig hebben; sommige groepen, zoals kwetsbare ouderen, mogelijk wel.' => 'Adults need on average 0.66 grams of protein per kilogram of body weight per day; the recommended intake is 0.83 grams per kilogram per day. There is no convincing evidence that all older adults need more; some groups, such as frail older people, may.',
    'Eet meer plantaardig en minder dierlijk: dagelijks minstens 200 gram groente en 200 gram fruit, minstens 90 gram volkoren, wekelijks peulvruchten, 15 gram ongezouten noten, enkele porties zuivel en wekelijks vis. Beperk rood en vooral bewerkt vlees, drink zo min mogelijk suikerhoudende dranken, geen of hooguit één glas alcohol per dag, en maximaal 6 gram zout per dag.' => 'Eat more plant-based and less animal-based: at least 200 grams of vegetables and 200 grams of fruit daily, at least 90 grams of whole grains, legumes weekly, 15 grams of unsalted nuts, some portions of dairy and fish weekly. Limit red and especially processed meat, drink as few sugary drinks as possible, no alcohol or at most one glass a day, and at most 6 grams of salt a day.',
    'Van de vier meest gebruikte formules voorspelde Mifflin-St Jeor het rustmetabolisme het vaakst binnen 10% van de gemeten waarde, bij mensen met en zonder obesitas. Voor individuen blijven er opvallende afwijkingen.' => 'Of the four most widely used equations, Mifflin-St Jeor most often predicted resting metabolic rate within 10% of the measured value, in people with and without obesity. Notable errors remain for individuals.',
    'Voor het opbouwen en behouden van spiermassa is 1,4–2,0 gram eiwit per kilogram lichaamsgewicht per dag voldoende voor de meeste mensen die sporten. Bij krachtsporters die afvallen kan meer nodig zijn om spiermassa te behouden.' => 'For building and maintaining muscle, 1.4–2.0 grams of protein per kilogram of body weight per day is sufficient for most people who exercise. Strength athletes who are losing weight may need more to keep their muscle mass.',
    'Bij volwassenen (18+) noemt de WHO een BMI van 25 of hoger overgewicht en van 30 of hoger obesitas. Voor 5–19 jaar gelden groeicurves. Ook buikomvang kan worden gebruikt.' => 'For adults (18+), the WHO defines overweight as a BMI of 25 or more and obesity as a BMI of 30 or more. For ages 5–19, growth references apply. Waist circumference can also be used.',

    /* ---- claims: what Ownify uses */
    'Per leeftijd een aanbevolen slaapduur: 7 uur of meer van 18 tot 60 jaar, 7–9 uur van 61 tot 64, 7–8 uur vanaf 65, en 8–10 uur voor tieners van 13 tot 17.' => 'A recommended sleep duration per age: 7 or more hours from 18 to 60, 7–9 hours from 61 to 64, 7–8 hours from 65, and 8–10 hours for teenagers aged 13 to 17.',
    'Volwassenen van 18 tot 60 jaar: regelmatig minstens 7 uur slaap per nacht. Korter slapen hangt samen met meer gezondheidsrisico\'s.' => 'Adults aged 18 to 60: regularly at least 7 hours of sleep per night. Sleeping less is associated with more health risks.',
    'Bij volwassenen past 7–9 uur slaap, bij 65-plussers 7–8 uur en bij tieners 8–10 uur.' => 'For adults 7–9 hours of sleep is appropriate, for people over 65 7–8 hours and for teenagers 8–10 hours.',
    'Kinderen van 6 tot 12 jaar: 9–12 uur slaap per 24 uur; tieners van 13 tot 18 jaar: 8–10 uur.' => 'Children aged 6 to 12: 9–12 hours of sleep per 24 hours; teenagers aged 13 to 18: 8–10 hours.',
    'Regelmatige tijden van inslapen en opstaan zijn belangrijk voor gezondheid, veiligheid en prestaties.' => 'Regular times of falling asleep and waking up are important for health, safety and performance.',
    'Wearables herkennen slaap goed, maar wakker liggen en slaapfasen zoals diep en REM veel minder betrouwbaar.' => 'Wearables detect sleep well, but lying awake and sleep stages such as deep and REM far less reliably.',
    'Per week 150–300 minuten matig of 75–150 minuten zwaar intensief bewegen, plus spierversterkende activiteiten op minstens 2 dagen.' => '150–300 minutes of moderate or 75–150 minutes of vigorous activity per week, plus muscle-strengthening activities on at least 2 days.',
    'Vanaf 65 jaar dezelfde hoeveelheid beweging, plus op minstens 3 dagen per week activiteiten voor balans en kracht.' => 'From 65, the same amount of activity, plus activities for balance and strength on at least 3 days a week.',
    'Een beetje bewegen is beter dan niets: zitten vervangen door bewegen van elke intensiteit, ook licht, levert gezondheidswinst op.' => 'Some activity is better than none: replacing sitting with activity of any intensity, light included, brings health benefits.',
    'Minstens 150 minuten per week matig intensief bewegen, verspreid over diverse dagen, en minstens twee keer per week spier- en botversterkende activiteiten.' => 'At least 150 minutes of moderate-intensity activity per week, spread over several days, and muscle- and bone-strengthening activities at least twice a week.',
    'Kinderen van 4 tot 18 jaar: elke dag minstens een uur matig intensief bewegen en minstens drie keer per week spier- en botversterkende activiteiten.' => 'Children aged 4 to 18: at least an hour of moderate-intensity activity every day and muscle- and bone-strengthening activities at least three times a week.',
    'Meer stappen per dag hangt samen met minder gezondheidsrisico\'s; rond 7.000 stappen per dag was dat verband al duidelijk.' => 'More steps a day is associated with fewer health risks; at around 7,000 steps a day that association was already clear.',
    'Het verband met een lager sterfterisico vlakt af rond 8.000–10.000 stappen per dag onder de 60 en rond 6.000–8.000 vanaf 60 jaar.' => 'The association with a lower risk of death levels off around 8,000–10,000 steps a day under 60 and around 6,000–8,000 from 60.',
    'Wearables meten hartslag en stappen redelijk nauwkeurig, maar energieverbruik en slaaptijd veel minder: calorieën kunnen ruim 20% afwijken en slaaptijd wordt meestal overschat.' => 'Wearables measure heart rate and steps fairly accurately, but energy expenditure and sleep time far less so: calories can be off by more than 20%, and sleep time is usually overestimated.',
    'Totaal vet: 20–40% van je energie. De kwaliteit van wat je eet telt meer dan de hoeveelheid vet.' => 'Total fat: 20–40% of your energy. The quality of what you eat matters more than the amount of fat.',
    'Verzadigd vet: zo laag mogelijk, met 10% van je energie als tussenstap.' => 'Saturated fat: as low as possible, with 10% of your energy as an interim step.',
    'Koolhydraten hebben geen percentagenorm: het gaat om de bron, vooral volkoren, groente, fruit, peulvruchten en zuivel.' => 'Carbohydrates have no percentage norm: what matters is the source, mainly whole grains, vegetables, fruit, legumes and dairy.',
    'Vrije suikers: zo laag mogelijk, met 10% van je energie als tussenstap.' => 'Free sugars: as low as possible, with 10% of your energy as an interim step.',
    'Vezels: 3,0–3,5 gram per megajoule, dat is 12,6–14,6 gram per 1.000 kcal, uit volkoren, groente, fruit, peulvruchten en noten.' => 'Fiber: 3.0–3.5 grams per megajoule, which is 12.6–14.6 grams per 1,000 kcal, from whole grains, vegetables, fruit, legumes and nuts.',
    'Volwassenen: aanbevolen 0,83 gram eiwit per kilogram lichaamsgewicht per dag.' => 'Adults: a recommended 0.83 grams of protein per kilogram of body weight per day.',
    'Voor mensen die sporten noemt een sportvoedingsvereniging 1,4–2,0 gram eiwit per kilogram per dag voldoende om spiermassa op te bouwen en te behouden.' => 'For people who exercise, a sports nutrition society calls 1.4–2.0 grams of protein per kilogram per day sufficient to build and keep muscle.',
    'Maximaal 6 gram zout per dag.' => 'At most 6 grams of salt a day.',
    'Gezond eten is meer plantaardig en minder dierlijk: veel groente, fruit, volkoren, peulvruchten en noten, en weinig rood en bewerkt vlees en suikerhoudende dranken.' => 'Healthy eating is more plant-based and less animal-based: plenty of vegetables, fruit, whole grains, legumes and nuts, and little red and processed meat and few sugary drinks.',
    'Je energiedoel is een schatting met de Mifflin-St Jeor-formule. Die zit bij de meeste mensen binnen 10% van het gemeten rustverbruik, maar kan per persoon meer afwijken.' => 'Your energy target is an estimate with the Mifflin-St Jeor equation. For most people it is within 10% of measured resting expenditure, but it can be further off for an individual.',
    'De WHO noemt bij volwassenen een BMI van 25 of hoger overgewicht en van 30 of hoger obesitas.' => 'For adults, the WHO calls a BMI of 25 or more overweight and of 30 or more obesity.',

    /* ---- populations */
    'Iedereen vanaf 6 jaar, zonder slaapstoornis.' => 'Everyone from age 6, without a sleep disorder.',
    'Gezonde volwassenen van 18 tot 60 jaar.' => 'Healthy adults aged 18 to 60.',
    'Gezonde mensen zonder slaapstoornis.' => 'Healthy people without a sleep disorder.',
    'Kinderen en tieners.' => 'Children and teenagers.',
    'Volwassenen en jongeren.' => 'Adults and young people.',
    'Gezonde jonge volwassenen, apparaten uit 2019.' => 'Healthy young adults, devices from 2019.',
    'Volwassenen van 18 tot 64 jaar, ook met een chronische aandoening.' => 'Adults aged 18 to 64, including those with a chronic condition.',
    'Volwassenen vanaf 65 jaar.' => 'Adults aged 65 and older.',
    'Iedereen vanaf 5 jaar.' => 'Everyone from age 5.',
    'Volwassenen en ouderen (ouderen ook balansoefeningen).' => 'Adults and older adults (older adults also balance exercises).',
    'Kinderen en jongeren van 4 tot 18 jaar.' => 'Children and young people aged 4 to 18.',
    'Volwassenen.' => 'Adults.',
    'Consumentenwearables, vooral bij gezonde volwassenen.' => 'Consumer wearables, mostly in healthy adults.',
    'Iedereen vanaf 4 jaar.' => 'Everyone from age 4.',
    'Iedereen.' => 'Everyone.',
    'Iedereen vanaf 2 jaar.' => 'Everyone from age 2.',
    'Volwassenen, ook ouderen.' => 'Adults, older adults included.',
    'Gezonde mensen die sporten.' => 'Healthy people who exercise.',
    'De algemene bevolking.' => 'The general population.',
    'Gezonde volwassenen, met en zonder obesitas.' => 'Healthy adults, with and without obesity.',
    'Volwassenen vanaf 18 jaar.' => 'Adults aged 18 and older.',

    /* ---- limitations */
    'Een samenvatting van twee panels met iets andere leeftijdsgrenzen.' => 'A summary of two panels with slightly different age limits.',
    'Iedereen heeft een eigen slaapbehoefte.' => 'Everyone has their own sleep need.',
    'Een wearable schat slaaptijd vaak te hoog in.' => 'A wearable often overestimates sleep time.',
    'Gebaseerd op verbanden, niet op bewezen oorzaken.' => 'Based on associations, not on proven causes.',
    'Gaat alleen over duur, niet over kwaliteit of tijdstip.' => 'About duration only, not quality or timing.',
    'Niet voor mensen met een slaapstoornis.' => 'Not for people with a sleep disorder.',
    'Een consensus van deskundigen; de bereiken zijn geen diagnose.' => 'An expert consensus; the ranges are not a diagnosis.',
    'Daarbuiten slapen kan passend zijn.' => 'Sleeping outside them can be appropriate.',
    'Vooral observationeel onderzoek.' => 'Mostly observational research.',
    'Er is geen aanbevolen aantal minuten speling.' => 'There is no recommended number of minutes of variation.',
    'Wie doordeweeks te weinig slaapt, kan baat hebben bij inhalen op vrije dagen.' => 'People who sleep too little on work days may benefit from catching up on free days.',
    'Eén kleine studie.' => 'One small study.',
    'Oudere apparaten dan die van nu.' => 'Older devices than today\'s.',
    'Alle beweging telt mee, ook wandelen, fietsen en klussen.' => 'All activity counts, walking, cycling and chores included.',
    'Ownify ziet alleen de trainingen die je vastlegt.' => 'Ownify only sees the workouts you record.',
    'Een algemene aanbeveling, geen voorspelling voor één persoon.' => 'A general recommendation, not a prediction for one person.',
    'Alle beweging telt mee, ook wandelen en fietsen.' => 'All activity counts, walking and cycling included.',
    'Observationeel onderzoek: een verband, geen bewezen oorzaak.' => 'Observational research: an association, not a proven cause.',
    'Voor de meeste uitkomsten maar enkele studies.' => 'Only a few studies for most outcomes.',
    'Stappentellers tellen meestal iets te weinig.' => 'Step counters usually count slightly too few.',
    'Gaat alleen over sterfte.' => 'About mortality only.',
    'Studies gebruiken verschillende methoden.' => 'Studies use different methods.',
    'Apparaten veranderen sneller dan het onderzoek.' => 'Devices change faster than the research.',
    'Ownify ziet wat je logt, niet alles wat je eet.' => 'Ownify sees what you log, not everything you eat.',
    'Ownify ziet niet uit welke producten je koolhydraten komen.' => 'Ownify cannot see which foods your carbohydrates come from.',
    'Je apps loggen alle suikers samen, ook die in fruit en melk; vrije suikers zijn daaruit niet af te lezen.' => 'Your apps log all sugars together, including those in fruit and milk; free sugars cannot be read from that.',
    'Je eiwitdoel in Ownify hangt af van je activiteit en kan hoger liggen.' => 'Your protein target in Ownify depends on your activity and can be higher.',
    'Het standpunt van een vereniging, geen officiële norm.' => 'A society\'s position, not an official norm.',
    'Voor de algemene bevolking geldt 0,83 gram per kilogram.' => 'For the general population, 0.83 grams per kilogram applies.',
    'Apps loggen zout dat je zelf toevoegt meestal niet.' => 'Apps usually do not log the salt you add yourself.',
    'Deels bijgewerkt: in 2025 kwam een nieuw advies over eiwitbronnen; groente, fruit en granen volgen.' => 'Partly updated: in 2025 new advice on protein sources appeared; vegetables, fruit and grains will follow.',
    'Ownify ziet niet welke producten je eet.' => 'Ownify cannot see which foods you eat.',
    'Gaat over rustverbruik; de factor voor je activiteit is een tweede schatting.' => 'About resting expenditure; the factor for your activity is a second estimate.',
    'Onderzoek uit 2005.' => 'Research from 2005.',
    'BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.' => 'BMI says nothing about your body composition, such as muscle mass.',
    'Een indeling voor groepen, geen oordeel over één persoon.' => 'A classification for groups, not a judgement about one person.',

    /* ==== 31.0: the sources and claims approved on 28 October 2026 ==== */

    /* ---- kinds of source */
    'Observationeel onderzoek' => 'Observational study',
    'Overzichtsartikel' => 'Review article',
    'Artikel over onderzoeksmethoden' => 'Article on research methods',
    'Officiële definities' => 'Official definitions',
    'Samengevoegde analyse van laboratoriumstudies' => 'Pooled analysis of laboratory studies',
    'Officiële voorlichting' => 'Official public information',
    'Validatiestudie tegen een ecg' => 'Validation study against an ECG',

    /* ---- citations, organisations, licences */
    'RIVM, VZinfo.nl, Bewegen – Verantwoording, definities (geraadpleegd 28 oktober 2026)' => 'RIVM, VZinfo.nl, Physical activity – methods and definitions (accessed October 28, 2026)',
    'Gezondheidsraad, Den Haag 2025, publicatienr. 2025/19' => 'Health Council of the Netherlands, The Hague 2025, publication no. 2025/19',
    'Voedingscentrum, encyclopedie: Vocht (geraadpleegd 28 oktober 2026)' => 'Netherlands Nutrition Centre, encyclopaedia: Fluid (accessed October 28, 2026)',
    'Voedingscentrum' => 'Netherlands Nutrition Centre (Voedingscentrum)',
    'Gratis te lezen in PMC, geen hergebruiklicentie' => 'Free to read in PMC, no reuse licence',
    'Gratis te lezen in PMC, licentie nog te bevestigen' => 'Free to read in PMC, licence still to be confirmed',
    'Licentie niet bevestigd' => 'Licence not confirmed',
    'CC BY volgens Europe PMC, nog te bevestigen' => 'CC BY according to Europe PMC, still to be confirmed',
    'Geen open licentie' => 'No open licence',

    /* ---- what the sources found */
    'In 66 studies had één keer bewegen een klein gunstig effect op totale slaaptijd, inslaaptijd, slaapefficiëntie en diepe slaap, en een matig effect op wakker liggen na het inslapen. Regelmatig bewegen had kleine gunstige effecten op slaaptijd en efficiëntie, klein tot matig op inslaaptijd en matig op slaapkwaliteit. De effecten hingen af van geslacht, leeftijd, fitheid, soort beweging, tijdstip, duur en volhouden, niet van de intensiteit.'
        => 'In 66 studies, a single bout of exercise had small benefits for total sleep time, time to fall asleep, sleep efficiency and deep sleep, and a moderate benefit for time awake after falling asleep. Regular exercise had small benefits for sleep time and efficiency, small to moderate for time to fall asleep and moderate for sleep quality. The effects depended on sex, age, fitness, type of exercise, time of day, duration and adherence, not on intensity.',
    'Sterk bewijs dat zowel één keer bewegen als regelmatig bewegen de slaap verbetert. Matig bewijs dat langere sessies meer helpen, dat het effect geldt voor volwassenen van alle leeftijden en beide geslachten, en dat bewegen de slaap verbetert bij volwassenen met slapeloosheidsklachten of obstructieve slaapapneu.'
        => 'Strong evidence that both a single bout and regular physical activity improve sleep. Moderate evidence that longer bouts help more, that the effect holds for adults of all ages and both sexes, and that activity improves sleep in adults with insomnia symptoms or obstructive sleep apnoea.',
    'In 33 studies bij volwassenen was er geen steun voor een verband in twee richtingen van dag tot dag. Slaapkwaliteit, slaapefficiëntie en wakker liggen in de nacht hingen samen met bewegen de dag erna, maar die verbanden waren klein en verschilden in richting en niveau (tussen mensen of binnen één persoon). Tussen mensen ging meer bewegen overdag samen met iets korter slapen de nacht erna (een klein effect).'
        => 'In 33 studies in adults there was no support for a two-way link from day to day. Sleep quality, sleep efficiency and time awake during the night were associated with physical activity the following day, but these associations were small and varied in direction and level (between people or within one person). Between people, more daytime activity went with slightly shorter sleep the following night (a small effect).',
    'In 23 studies maakte bewegen in de avond de slaap niet slechter, eerder het omgekeerde: de REM-slaap begon 7,7 minuten later, diepe slaap nam 1,3 procentpunt toe en de lichtste slaap 0,9 procentpunt af. Zware inspanning die een uur of minder voor bedtijd eindigde, kan het inslapen verlengen en de slaaptijd en efficiëntie verlagen.'
        => 'In 23 studies, evening exercise did not make sleep worse, rather the opposite: REM sleep started 7.7 minutes later, deep sleep rose by 1.3 percentage points and the lightest sleep fell by 0.9 points. Vigorous exercise ending an hour or less before bedtime may lengthen the time to fall asleep and lower sleep time and efficiency.',
    'In 15 studies (194 deelnemers) verminderde zware inspanning die 0,5 tot 4 uur voor bedtijd eindigde de REM-slaap licht (−2,34%); andere slaapmaten veranderden niet. Regelmatig zwaar trainen in de avond verstoorde de slaap niet, en inspanning 2 tot 4 uur voor bedtijd verstoorde de slaap van gezonde volwassenen van 18 tot 50 jaar niet.'
        => 'In 15 studies (194 participants), high-intensity exercise ending 0.5 to 4 hours before bedtime slightly reduced REM sleep (−2.34%); other sleep measures did not change. Regular high-intensity evening exercise did not disrupt sleep, and exercise 2 to 4 hours before bedtime did not disrupt the sleep of healthy adults aged 18 to 50.',
    'Bij 14.689 dragers van een wearable (ruim 4 miljoen nachten in een jaar) ging later en zwaarder trainen samen met later inslapen, korter en minder goed slapen, een hogere hartslag in de nacht en een lagere HRV. Training die minstens 4 uur voor het inslapen eindigde, ging niet samen met een andere slaap, hoe zwaar ook. Voorbeeld: maximale in plaats van lichte inspanning die 2 uur voor de gewone inslaaptijd eindigde, ging samen met 36 minuten later inslapen. De gewone inslaaptijd werd per persoon per maand bepaald, doordeweeks en in het weekend apart.'
        => 'In 14,689 wearable users (over 4 million nights in a year), later and harder exercise went with later sleep onset, shorter and poorer sleep, a higher heart rate at night and a lower HRV. Exercise ending at least 4 hours before sleep onset was not associated with different sleep, however hard. Example: maximal instead of light exercise ending 2 hours before the usual sleep onset went with falling asleep 36 minutes later. The usual sleep onset was set per person per month, weekdays and weekends apart.',
    'In 13 gerandomiseerde studies verbeterde regelmatige krachttraining alle aspecten van slaap, vooral de slaapkwaliteit; samen met duurtraining was het voordeel kleiner. Het effect van één krachttraining is weinig onderzocht en wisselend.'
        => 'In 13 randomised trials, regular resistance exercise improved all aspects of sleep, most of all sleep quality; combined with aerobic exercise the benefit was smaller. The effect of a single session is poorly studied and inconsistent.',
    'In 69 publicaties verminderde slaaptekort (6 uur of minder in 24 uur) de sportprestatie gemiddeld met 7,56% (95%-interval −11,9 tot −3,13), in elke soort inspanning. Het effect was er na een nacht zonder slaap en na eerder opstaan dan gewoonlijk.'
        => 'In 69 publications, sleep loss (6 hours or less in 24 hours) reduced exercise performance by 7.56% on average (95% interval −11.9 to −3.13), in every type of exercise. The effect was there after a night without sleep and after waking earlier than usual.',
    'Sporters slapen vaak korter dan 7 uur. Een nacht of meer zonder slaap vermindert de prestatie; het effect van 1 tot 3 nachten gedeeltelijk slaaptekort is onduidelijk. In de bredere bevolking maakt gewoonlijk korter dan 7 uur slapen vatbaarder voor luchtweginfecties. Eén advies van 7–9 uur voor iedereen is waarschijnlijk niet ideaal; de deskundigen raden een persoonlijke aanpak aan.'
        => 'Athletes often sleep less than 7 hours. A night or more without sleep reduces performance; the effect of 1 to 3 nights of partial sleep loss is unclear. In the wider population, habitually sleeping less than 7 hours makes people more susceptible to respiratory infections. A single 7–9 hour advice for everyone is unlikely to be ideal; the experts recommend an individual approach.',
    'Gedeeltelijk slaaptekort verhoogde de energie-inname met 385 kcal per dag (95%-interval 252 tot 517), zonder effect op het totale energieverbruik: per saldo meer energie binnen dan eruit.'
        => 'Partial sleep deprivation increased energy intake by 385 kcal a day (95% interval 252 to 517), with no effect on total energy expenditure: on balance more energy in than out.',
    'Gedeeltelijk slaaptekort tot 5,5 uur of minder per nacht verhoogde de energie-inname per dag (gestandaardiseerd verschil 0,37, 95%-interval 0,21 tot 0,52), en ook de inname van vet, eiwit en koolhydraten.'
        => 'Partial sleep restriction to 5.5 hours or less a night increased daily energy intake (standardised difference 0.37, 95% interval 0.21 to 0.52), as well as the intake of fat, protein and carbohydrate.',
    'In 24 studies verkortte cafeïne de totale slaap met 45 minuten en de slaapefficiëntie met 7%, en verlengde het inslapen met 9 minuten en het wakker liggen met 12 minuten. Om niet korter te slapen zou koffie (107 mg per 250 ml) minstens 8,8 uur voor bedtijd moeten worden gedronken, en een gangbaar pre-workoutsupplement (217,5 mg) minstens 13,2 uur ervoor.'
        => 'In 24 studies, caffeine reduced total sleep by 45 minutes and sleep efficiency by 7%, and lengthened the time to fall asleep by 9 minutes and time awake by 12 minutes. To avoid sleeping less, coffee (107 mg per 250 ml) should be taken at least 8.8 hours before bedtime, and a standard pre-workout supplement (217.5 mg) at least 13.2 hours before.',
    'Volledig herstel van het autonome zenuwstelsel van het hart na één duurtraining duurt tot 24 uur na lichte inspanning, 24 tot 48 uur na inspanning rond de drempel en minstens 48 uur na zware inspanning. Fittere mensen herstellen sneller, en het verschilt sterk per persoon.'
        => 'Full recovery of the heart\'s autonomic nervous system after one aerobic session takes up to 24 hours after light exercise, 24 to 48 hours after exercise around the threshold and at least 48 hours after hard exercise. Fitter people recover faster, and it differs a lot from person to person.',
    'Als training tot een betere prestatie leidde, steeg de HRV in rust licht (gestandaardiseerd verschil 0,58), net als het herstel van de hartslag na inspanning. Bij overbelasting veranderde de HRV in rust nauwelijks, dus HRV alleen kan goede en slechte aanpassing niet van elkaar onderscheiden.'
        => 'When training led to better performance, resting HRV rose slightly (standardised difference 0.58), as did heart rate recovery after exercise. With overreaching, resting HRV hardly changed, so HRV alone cannot tell good adaptation from bad.',
    'Bij topsporters zijn zowel een stijging als een daling van de HRV in verband gebracht met slechte aanpassing aan training. Volg HRV van dag tot dag met gemiddelden (lopende gemiddelden van logaritmisch omgerekende waarden), niet met losse metingen.'
        => 'In elite athletes, both a rise and a fall in HRV have been linked to poor adaptation to training. Follow HRV from day to day with averages (rolling averages of log-transformed values), not with single readings.',
    'De meeste tegenstrijdige uitkomsten komen door verschillen in methode en verkeerd lezen. Bij één persoon moet je een verandering in een hartslagmaat lezen tegen de meetfout en de kleinste verandering die ertoe doet, en in de context van de training.'
        => 'Most contradictory findings come from differences in method and from misreading. For one person, a change in a heart rate measure should be read against its measurement error and the smallest change that matters, and in the context of training.',
    'Bij 28.175 mensen (9 miljoen ochtendmetingen in 5 jaar) was de HRV (rMSSD) na zware training 3% lager en de hartslag 0,7% hoger; na lichte training was de HRV 1,6% hoger. Na veel alcohol: HRV 12% lager, hartslag 6% hoger. Bij ziekte: HRV 10% lager, hartslag 6% hoger. De HRV daalde met de leeftijd (r = −0,35). HRV is gevoelig maar niet specifiek: veel dingen verlagen haar.'
        => 'In 28,175 people (9 million morning readings over 5 years), HRV (rMSSD) was 3% lower and heart rate 0.7% higher after hard training; after light training HRV was 1.6% higher. After a lot of alcohol: HRV 12% lower, heart rate 6% higher. During illness: HRV 10% lower, heart rate 6% higher. HRV fell with age (r = −0.35). HRV is sensitive but not specific: many things lower it.',
    'Het moment van eten en de verhouding van de voedingsstoffen kunnen herstel en spieropbouw helpen na veel of zware training. Glycogeenvoorraden zijn het grootst bij 8 tot 12 gram koolhydraten per kilogram per dag; koolhydraten (0,8 g/kg per uur) samen met eiwit (0,2–0,4 g/kg per uur) helpen ze snel aan te vullen.'
        => 'The timing of eating and the ratio of nutrients may help recovery and muscle building after a lot of or hard training. Glycogen stores are largest at 8 to 12 grams of carbohydrate per kilogram a day; carbohydrate (0.8 g/kg per hour) together with protein (0.2–0.4 g/kg per hour) helps refill them quickly.',
    'Er is geen bewijs dat de verhouding tussen recente en langere trainingsbelasting (acute:chronic workload ratio) helpt om blessures te voorkomen; de verhouding is statistisch onnauwkeurig en voegt ruis en schijnverbanden toe.'
        => 'There is no evidence that the ratio between recent and longer-term training load (acute:chronic workload ratio) helps prevent injuries; the ratio is statistically inaccurate and adds noise and spurious associations.',
    'Een balans tussen belasting (training, wedstrijden en wat het leven verder vraagt) en herstel is essentieel. Hoe iemand reageert verschilt tussen mensen en bij één persoon van moment tot moment, dus herstel volg je het best per persoon.'
        => 'A balance between stress (training, competition and whatever else life demands) and recovery is essential. How someone responds differs between people and, for one person, from moment to moment, so recovery is best followed per person.',
    'Een wereldwijde commissie van 58 deskundigen: gebruik BMI alleen als ruwe maat voor gezondheidsrisico in groepen, voor onderzoek of screening, niet als maat voor de gezondheid van één persoon. Te veel lichaamsvet bevestig je met een directe meting van het vetpercentage of met minstens één andere lichaamsmaat (buikomvang, taille-heupverhouding of taille-lengteverhouding) naast BMI, of met twee zulke maten ongeacht de BMI; boven een BMI van 40 mag je het aannemen.'
        => 'A global commission of 58 experts: use BMI only as a rough measure of health risk in groups, for research or screening, not as a measure of one person\'s health. Confirm excess body fat with a direct measurement of body fat or with at least one other body measure (waist circumference, waist-to-hip or waist-to-height ratio) besides BMI, or with two such measures regardless of BMI; above a BMI of 40 it may be assumed.',
    'Bij 1.421 mensen met een slimme weegschaal schommelde het gewicht binnen een week met 0,35%: hoger na het weekend, lager doordeweeks. Rond kerst nam het gewicht gemiddeld 1,35% toe, en dat was in de maanden erna niet helemaal weer verdwenen.'
        => 'In 1,421 people with a smart scale, weight fluctuated by 0.35% within a week: higher after the weekend, lower on weekdays. Around Christmas weight rose by 1.35% on average, and it had not fully gone in the months after.',
    'Volwassenen: per week 150–300 minuten matig of 75–150 minuten zwaar intensief bewegen, of een gelijkwaardige mix; spierversterkende activiteiten voor alle leeftijden; kinderen en jongeren gemiddeld 60 minuten per dag; minder stilzitten; een beetje bewegen is beter dan niets.'
        => 'Adults: 150–300 minutes of moderate or 75–150 minutes of vigorous activity a week, or an equivalent mix; muscle-strengthening activities for all ages; children and adolescents an average of 60 minutes a day; less sitting; some activity is better than none.',
    'Herhaalt de Beweegrichtlijnen 2017 en geeft de intensiteiten: licht 1,6–2,9 MET, matig 3,0–5,9 MET, zwaar 6 MET of meer; zittend gedrag is 1,5 MET of minder, zittend of liggend en niet slapend.'
        => 'Restates the Dutch physical activity guidelines 2017 and gives the intensities: light 1.6–2.9 MET, moderate 3.0–5.9 MET, vigorous 6 MET or more; sedentary behaviour is 1.5 MET or less while sitting or lying and not sleeping.',
    'Werkt een deel van de Richtlijnen goede voeding 2015 bij, voor eiwitbronnen (peulvruchten, noten, zuivel, vis, vlees, eieren en vervangers): een meer plantaardig en minder dierlijk voedingspatroon, met hooguit ongeveer 200 gram rood vlees per week en ongeveer 250 gram (bereide) peulvruchten per week. Groente, fruit en granen volgen in een later advies; tot dan gelden daarvoor de richtlijnen van 2015.'
        => 'Updates part of the Dutch dietary guidelines 2015, for protein sources (legumes, nuts, dairy, fish, meat, eggs and alternatives): a more plant-based and less animal-based pattern, with at most about 200 grams of red meat a week and about 250 grams of (prepared) legumes a week. Vegetables, fruit and grains follow in a later advice; until then the 2015 guidelines apply to them.',
    'Vrouwen en meisjes vanaf 13 jaar: drink minstens 1,4 liter per dag; mannen en jongens vanaf 13 jaar: minstens 1,4 tot 1,8 liter. Alle dranken tellen mee. Bij flink sporten en zweten meer drinken (per uur sporten kun je 1 tot 2 liter verliezen). Op een heel warme dag minstens 2 liter, en niet meer dan 3 liter per dag.'
        => 'Women and girls from 13: drink at least 1.4 litres a day; men and boys from 13: at least 1.4 to 1.8 litres. All drinks count. Drink more with hard exercise and sweating (you can lose 1 to 2 litres per hour of exercise). On a very hot day at least 2 litres, and no more than 3 litres a day.',
    'In 46 cohortstudies ging elke 10 slagen per minuut hogere rusthartslag samen met een relatief risico van 1,09 op overlijden door alle oorzaken en 1,08 door hart- en vaatziekten. Boven 80 slagen per minuut was dat 1,45 en 1,33, vergeleken met de laagste groep.'
        => 'In 46 cohort studies, each 10 beats per minute higher resting heart rate went with a relative risk of 1.09 for death from all causes and 1.08 for death from cardiovascular disease. Above 80 beats per minute it was 1.45 and 1.33, compared with the lowest group.',
    'HRV hangt sterk af van hoe lang er gemeten wordt, van leeftijd en van geslacht; waarden over 24 uur, over enkele minuten en over minder dan een minuut zijn niet uitwisselbaar. Gepubliceerde normen moeten worden aangevuld met waarden uit de eigen groep.'
        => 'HRV depends strongly on how long it is measured, on age and on sex; values over 24 hours, over a few minutes and over less than a minute are not interchangeable. Published norms should be supplemented with values from one\'s own population.',
    'Bij 5 volwassenen (148 metingen tegen een ecg) maten alle zeven apparaten de gemiddelde rusthartslag nauwkeurig. Voor HRV (rMSSD) liep de nauwkeurigheid sterk uiteen: een gemiddelde afwijking van 4,1% bij het beste apparaat en 112% bij een camera-app.'
        => 'In 5 adults (148 readings against an ECG), all seven devices measured mean resting heart rate accurately. For HRV (rMSSD) accuracy varied widely: a mean error of 4.1% for the best device and 112% for a camera app.',
    'Een methode die, als je veel dingen tegelijk toetst, het verwachte aandeel valse vondsten onder de gerapporteerde vondsten onder een gekozen grens houdt.'
        => 'A method that, when many things are tested at once, keeps the expected share of false findings among the reported findings below a chosen limit.',
    'Verbanden in de zelf bijgehouden gegevens van één persoon kunnen wijzen op mogelijke oorzaak en gevolg, maar om ze zo te lezen moet je expliciet rekening houden met hoe dagen op elkaar lijken, met trends in de tijd, met doorwerking en met vertraagde effecten; een gerandomiseerde n-van-1-studie is de echte toets.'
        => 'Associations in one person\'s self-tracked data can point to possible cause and effect, but reading them that way needs explicit account of how days resemble each other, of trends over time, of carry-over and of delayed effects; a randomised n-of-1 trial is the real test.',
    'In 39 n-van-1-studies konden effecten binnen één persoon anders zijn dan die tussen mensen. Herhaald meten bij één persoon kan inzichten over gezondheidsgedrag persoonlijk maken, ook voor slapen, bewegen en eten.'
        => 'In 39 n-of-1 studies, effects within one person could differ from those between people. Repeated measurement in one person can make insights about health behaviour personal, including for sleep, activity and eating.',
    'In 351 studies (18.712 mensen) en een eigen laboratoriumonderzoek (514 mensen) was de maximale hartslag te voorspellen met 208 − 0,7 × leeftijd (r = −0,90 met de leeftijd); het laboratorium gaf vrijwel hetzelfde: 209 − 0,7 × leeftijd. Er was geen verschil tussen mannen en vrouwen of naar hoeveel iemand bewoog. De bekende 220 − leeftijd schat de maximale hartslag van ouderen te laag.'
        => 'In 351 studies (18,712 people) and the authors\' own laboratory study (514 people), maximal heart rate was predicted by 208 − 0.7 × age (r = −0.90 with age); the laboratory gave almost the same: 209 − 0.7 × age. It did not differ between men and women or with how active people were. The familiar 220 − age underestimates the maximal heart rate of older adults.',
    'Voor de meeste volwassenen: matige duurinspanning minstens 30 minuten op minstens 5 dagen (150 minuten of meer per week), of zware minstens 20 minuten op minstens 3 dagen (75 minuten of meer), of een combinatie; krachtoefeningen voor elke grote spiergroep op 2 tot 3 dagen; oefeningen voor balans, behendigheid en coördinatie op 2 tot 3 dagen. De indeling naar intensiteit legt matig bij 64–76% en zwaar bij 77–95% van de maximale hartslag.'
        => 'For most adults: moderate aerobic exercise for at least 30 minutes on at least 5 days (150 minutes or more a week), or vigorous for at least 20 minutes on at least 3 days (75 minutes or more), or a combination; resistance exercise for each major muscle group on 2 to 3 days; exercises for balance, agility and coordination on 2 to 3 days. Its intensity classification puts moderate at 64–76% and vigorous at 77–95% of maximal heart rate.',
    'In 65 studies (3.577 gezonde mensen van 5 tot 102 jaar) namen bij volwassenen de totale slaaptijd, de slaapefficiëntie, het aandeel diepe slaap, het aandeel REM-slaap en de tijd tot de eerste REM-slaap af met de leeftijd, terwijl de inslaaptijd, het aandeel lichte slaap en het wakker liggen in de nacht toenamen. Na je 60e bleef alleen de slaapefficiëntie verder dalen. Hoe sterk de veranderingen leken, hing sterk af van hoe zorgvuldig de deelnemers waren geselecteerd.'
        => 'In 65 studies (3,577 healthy people aged 5 to 102), total sleep time, sleep efficiency, the share of deep sleep, the share of REM sleep and the time to the first REM sleep decreased with age in adults, while the time to fall asleep, the share of light sleep and time awake during the night increased. After 60 only sleep efficiency kept decreasing. How strong the changes looked depended a lot on how carefully participants were selected.',
    'Ouderen hebben meer eiwit nodig dan jongere volwassenen. Boven de 65: minstens 1,0–1,2 gram eiwit per kilogram lichaamsgewicht per dag; 1,2 g/kg of meer voor wie sport of anderszins actief is; 1,2–1,5 g/kg voor de meeste mensen met een acute of chronische ziekte. Uitzondering: ernstige nierziekte zonder dialyse.'
        => 'Older adults need more protein than younger adults. Over 65: at least 1.0–1.2 grams of protein per kilogram of body weight a day; 1.2 g/kg or more for those who exercise or are otherwise active; 1.2–1.5 g/kg for most people with an acute or chronic illness. Exception: severe kidney disease without dialysis.',
    'Gezonde ouderen: minstens 1,0–1,2 gram eiwit per kilogram lichaamsgewicht per dag. Ouderen die ondervoed zijn of dat dreigen te worden door een acute of chronische ziekte: 1,2–1,5 g/kg, meer bij ernstige ziekte of letsel. Dagelijks bewegen of sporten (kracht en duur) voor alle ouderen, zo lang het kan.'
        => 'Healthy older adults: at least 1.0–1.2 grams of protein per kilogram of body weight a day. Older adults who are malnourished or at risk of it because of an acute or chronic illness: 1.2–1.5 g/kg, more with severe illness or injury. Daily physical activity or exercise (resistance and aerobic) for all older adults, for as long as possible.',
    'De spiereiwitopbouw na één maaltijd vlakte af bij ongeveer 0,24 gram eiwit per kilogram lichaamsgewicht bij jonge mannen (rond 22 jaar) en bij ongeveer 0,40 g/kg bij oudere mannen (rond 71 jaar); het verschil was net niet significant (p = 0,055). Per kilogram vetvrije massa was het 0,25 tegenover 0,60 g/kg (p < 0,01). Oudere mannen reageren minder op kleine porties eiwit en hebben per maaltijd meer nodig.'
        => 'Muscle protein synthesis after one meal levelled off at about 0.24 grams of protein per kilogram of body weight in young men (around 22) and at about 0.40 g/kg in older men (around 71); the difference was just short of significant (p = 0.055). Per kilogram of lean mass it was 0.25 against 0.60 g/kg (p < 0.01). Older men respond less to small protein doses and need more per meal.',
    'Bij 1.906 gezonde volwassenen (metingen van 5 minuten) veranderde de HRV in vrijwel al haar maten duidelijk met de leeftijd, over de decennia 25–34, 35–44, 45–54, 55–64 en 65–74. Verschillen tussen mannen en vrouwen verdwenen in de laatste twee decennia, en de leeftijdseffecten in het laatste.'
        => 'In 1,906 healthy adults (5-minute recordings), HRV changed clearly with age in almost all of its measures, across the decades 25–34, 35–44, 45–54, 55–64 and 65–74. Differences between men and women disappeared in the last two decades, and the age effects in the last one.',
    'In 36 studies die oudere met jongere volwassenen vergeleken, was de daling van de spierfunctie 24 en 72 uur na inspanning, en de piek ervan, bij ouderen niet anders dan bij jongeren. Spierpijn en creatinekinase waren bij jongeren juist hoger. Conclusie: een hogere leeftijd gaat niet samen met meer spierschade door inspanning.'
        => 'In 36 studies comparing older with younger adults, the drop in muscle function 24 and 72 hours after exercise, and its peak, did not differ between older and younger adults. Muscle soreness and creatine kinase were in fact higher in younger adults. Conclusion: advancing age is not associated with more exercise-induced muscle damage.',

    /* ---- claims: what Ownify says with them */
    'Per week 150–300 minuten matig of 75–150 minuten zwaar intensief bewegen, of een mix waarin een zware minuut telt als twee matige, plus spierversterkende activiteiten op minstens 2 dagen.'
        => '150–300 minutes of moderate or 75–150 minutes of vigorous activity a week, or a mix in which one vigorous minute counts as two moderate ones, plus muscle-strengthening activities on at least 2 days.',
    'Of een training zwaar was, leidt Ownify af uit je hartslag tegen de maximale hartslag voor je leeftijd.' => 'Whether a workout was hard, Ownify works out from your heart rate against the maximal heart rate for your age.',
    'Gezonde mensen van 18 tot 64 jaar die sporten.' => 'Healthy people aged 18 to 64 who exercise.',
    'Gezond eten is meer plantaardig en minder dierlijk. Het advies van 2025 over eiwitbronnen noemt per week ongeveer 250 gram peulvruchten en hooguit ongeveer 200 gram rood vlees; voor groente, fruit en granen gelden de richtlijnen van 2015.'
        => 'Healthy eating is more plant-based and less animal-based. The 2025 advice on protein sources names about 250 grams of legumes and at most about 200 grams of red meat a week; for vegetables, fruit and grains the 2015 guidelines apply.',
    'Groente, fruit en granen krijgen een nieuw advies; tot dan gelden de richtlijnen van 2015.' => 'Vegetables, fruit and grains will get a new advice; until then the 2015 guidelines apply.',
    'Bewegen, één keer of regelmatig, gaat gemiddeld samen met beter slapen; langere sessies helpen meer.' => 'Physical activity, once or regularly, goes with better sleep on average; longer sessions help more.',
    'Volwassenen van alle leeftijden, ook met slapeloosheidsklachten of slaapapneu.' => 'Adults of all ages, including those with insomnia symptoms or sleep apnoea.',
    'Gebaseerd op reviews van wisselende kwaliteit.' => 'Based on reviews of varying quality.',
    'Gemiddelden over groepen, geen voorspelling voor één persoon.' => 'Averages over groups, not a prediction for one person.',
    'Het beste tijdstip en de beste hoeveelheid zijn nog onzeker.' => 'The best timing and the best amount are still uncertain.',
    'Eén keer bewegen gaat gemiddeld samen met iets langer en efficiënter slapen, regelmatig bewegen ook met een betere slaapkwaliteit. De effecten zijn klein tot matig.'
        => 'A single bout of exercise goes with slightly longer and more efficient sleep on average, regular exercise also with better sleep quality. The effects are small to moderate.',
    'Gemengde groepen, ook mensen met slapeloosheid.' => 'Mixed groups, including people with insomnia.',
    'Studies tot 2013, vooral klein en in een laboratorium.' => 'Studies up to 2013, mostly small and in a laboratory.',
    'Van dag tot dag hangen slapen en bewegen maar weinig samen: de verbanden zijn klein en verschillen per persoon en per richting.'
        => 'From day to day, sleep and activity are only weakly linked: the associations are small and differ by person and by direction.',
    'Weinig studies keken binnen één persoon.' => 'Few studies looked within one person.',
    'Regelmatige krachttraining verbetert de slaap, vooral de slaapkwaliteit; over één losse training is weinig bekend.' => 'Regular strength training improves sleep, most of all sleep quality; little is known about a single session.',
    'Volwassenen in gerandomiseerde studies.' => 'Adults in randomised trials.',
    'Geen samengevoegde getallen.' => 'No pooled numbers.',
    'Gemengde groepen deelnemers.' => 'Mixed groups of participants.',
    'Later en zwaarder trainen ging samen met later inslapen, korter slapen, een hogere hartslag en een lagere HRV in de nacht; training die minstens 4 uur voor het inslapen eindigde, niet.'
        => 'Later and harder exercise went with falling asleep later, sleeping shorter, a higher heart rate and a lower HRV at night; exercise that ended at least 4 hours before sleep onset did not.',
    'Volwassen dragers van één merk wearable, vooral actieve mensen.' => 'Adult users of one wearable brand, mostly active people.',
    'Eén studie, met gebruikers van één merk.' => 'One study, with users of one brand.',
    'De wearable mat niet wanneer mensen probeerden te gaan slapen.' => 'The wearable did not record when people tried to go to sleep.',
    'Bewegen in de avond maakt de slaap gemiddeld niet slechter; alleen zware inspanning die een uur of minder voor bedtijd eindigt, kan het inslapen vertragen.'
        => 'Evening exercise does not make sleep worse on average; only vigorous exercise ending an hour or less before bedtime may delay falling asleep.',
    'Gezonde, vooral jonge volwassenen.' => 'Healthy, mostly young adults.',
    'Losse sessies in een laboratorium.' => 'Single sessions in a laboratory.',
    'De effecten van tijdstip en zwaarte waren niet stevig.' => 'The effects of timing and intensity were not robust.',
    'Zware training 2 tot 4 uur voor bedtijd verstoorde de slaap van gezonde volwassenen niet; dichter op bedtijd verminderde ze de REM-slaap licht.'
        => 'Hard training 2 to 4 hours before bedtime did not disrupt the sleep of healthy adults; closer to bedtime it slightly reduced REM sleep.',
    'Gezonde volwassenen van 18 tot 50 jaar die goed slapen.' => 'Healthy adults aged 18 to 50 who sleep well.',
    'Kleine studies.' => 'Small studies.',
    'Alleen goede slapers.' => 'Good sleepers only.',
    'Na zware training was de HRV de volgende ochtend gemiddeld ongeveer 3% lager, na lichte training ongeveer 1,6% hoger. Ook alcohol en ziekte verlagen de HRV, dus een lage HRV zegt niet waardoor.'
        => 'After hard training, HRV the next morning was about 3% lower on average, after light training about 1.6% higher. Alcohol and illness lower HRV too, so a low HRV does not say why.',
    'Volwassen gebruikers van een HRV-app.' => 'Adult users of an HRV app.',
    'Een meting van 1 minuut bij het wakker worden; Ownify gebruikt de waarden uit de nacht.' => 'A 1-minute reading on waking; Ownify uses the values from the night.',
    'Gezondheidsbewuste gebruikers van één app.' => 'Health-conscious users of one app.',
    'Na zware training was de hartslag in rust de volgende ochtend gemiddeld ongeveer 0,7% hoger. Ook alcohol en ziekte verhogen hem, dus een hogere hartslag zegt niet waardoor.'
        => 'After hard training, resting heart rate the next morning was about 0.7% higher on average. Alcohol and illness raise it too, so a higher heart rate does not say why.',
    'Het hart herstelt na lichte duurtraining binnen ongeveer 24 uur, na training rond de drempel in 24 tot 48 uur en na zware training in minstens 48 uur. Fittere mensen herstellen sneller.'
        => 'The heart recovers within about 24 hours after light aerobic training, in 24 to 48 hours after training around the threshold and in at least 48 hours after hard training. Fitter people recover faster.',
    'Volwassenen na duurtraining.' => 'Adults after aerobic training.',
    'Vooral duurtraining; over krachttraining is weinig bekend.' => 'Mostly aerobic training; little is known about strength training.',
    'Verschilt sterk per persoon.' => 'Differs a lot from person to person.',
    'Een hogere HRV in rust kan bij goede aanpassing aan training horen, maar bij overbelasting verandert de HRV nauwelijks: één HRV-waarde zegt niet of training goed of slecht uitpakt.'
        => 'A higher resting HRV can come with good adaptation to training, but with overreaching HRV hardly changes: one HRV value does not say whether training is going well or badly.',
    'Duursporters.' => 'Endurance athletes.',
    'Vooral getrainde duursporters.' => 'Mostly trained endurance athletes.',
    'Veel kleine studies.' => 'Many small studies.',
    'Volg HRV met gemiddelden over meerdere dagen, niet met losse metingen: zowel een stijging als een daling kan bij slechte aanpassing horen.'
        => 'Follow HRV with averages over several days, not with single readings: both a rise and a fall can come with poor adaptation.',
    'Topsporters in duursport.' => 'Elite endurance athletes.',
    'Een overzichtsartikel over topsporters.' => 'A review about elite athletes.',
    'Lees een verandering in je HRV of hartslag tegen de meetfout en de kleinste verandering die ertoe doet, en in de context van je training.'
        => 'Read a change in your HRV or heart rate against the measurement error and the smallest change that matters, and in the context of your training.',
    'Sporters.' => 'Athletes.',
    'Een overzichtsartikel met een mening.' => 'A review with an opinion.',
    'HRV hangt sterk af van hoe lang er gemeten wordt, van leeftijd en van geslacht, dus vergelijk je HRV het best met je eigen waarden.'
        => 'HRV depends strongly on how long it is measured, on age and on sex, so your HRV is best compared with your own values.',
    'Een verhalend overzichtsartikel.' => 'A narrative review.',
    'Wearables meten de rusthartslag nauwkeurig, maar hoe goed ze HRV meten verschilt sterk per apparaat.' => 'Wearables measure resting heart rate accurately, but how well they measure HRV differs a lot between devices.',
    'Volwassenen, met apparaten van 2020.' => 'Adults, with devices from 2020.',
    'Een heel kleine studie.' => 'A very small study.',
    'In bevolkingsonderzoek ging elke 10 slagen per minuut hogere rusthartslag samen met 9% meer risico op overlijden en 8% meer op overlijden door hart- en vaatziekten; boven 80 slagen per minuut was dat 45% en 33% meer dan in de laagste groep. Ownify volgt je rusthartslag alleen tegen je eigen basis.'
        => 'In population studies, each 10 beats per minute higher resting heart rate went with 9% more risk of death and 8% more of death from cardiovascular disease; above 80 beats per minute it was 45% and 33% more than in the lowest group. Ownify follows your resting heart rate only against your own baseline.',
    'De algemene volwassen bevolking.' => 'The general adult population.',
    'Een risico voor groepen, geen voorspelling voor één persoon.' => 'A risk for groups, not a prediction for one person.',
    'De studies verschilden sterk, en er is kans op publicatiebias.' => 'The studies differed a lot, and publication bias is likely.',
    'Na korter slapen aten mensen in studies gemiddeld 385 kcal per dag meer, zonder meer te verbruiken.' => 'After shorter sleep, people in studies ate 385 kcal a day more on average, without using more.',
    'Volwassenen in korte laboratoriumstudies.' => 'Adults in short laboratory studies.',
    'Korte studies, met vrij toegang tot eten.' => 'Short studies, with free access to food.',
    'Kleine groepen.' => 'Small groups.',
    'Slapen tot 5,5 uur of minder per nacht verhoogde in studies de energie-inname, en ook de inname van vet, eiwit en koolhydraten.'
        => 'Sleeping 5.5 hours or less a night increased energy intake in studies, and also the intake of fat, protein and carbohydrate.',
    'Volwassenen in interventiestudies.' => 'Adults in intervention studies.',
    'Korte interventies.' => 'Short interventions.',
    'Weinig studies naar langer slapen.' => 'Few studies of sleeping longer.',
    'Na een nacht van 6 uur of minder was de sportprestatie gemiddeld ongeveer 7,6% lager, bij elke soort inspanning.' => 'After a night of 6 hours or less, exercise performance was about 7.6% lower on average, in every type of exercise.',
    'Volwassenen, vaak sporters.' => 'Adults, often athletes.',
    'Gaat over prestatie, niet over of iemand traint.' => 'About performance, not about whether someone trains.',
    'Vooral korte laboratoriumstudies.' => 'Mostly short laboratory studies.',
    'Hoeveel slaap iemand nodig heeft verschilt; deskundigen raden sporters een persoonlijke aanpak aan in plaats van één advies van 7–9 uur.'
        => 'How much sleep someone needs differs; experts recommend athletes an individual approach instead of a single 7–9 hour advice.',
    'Gaat over topsporters.' => 'About elite athletes.',
    'Een verhalend overzicht met een consensus.' => 'A narrative review with a consensus.',
    'Cafeïne verkortte de slaap in studies gemiddeld met 45 minuten. Om dat te voorkomen zou koffie minstens 8,8 uur voor bedtijd gedronken moeten worden.'
        => 'In studies caffeine shortened sleep by 45 minutes on average. To avoid that, coffee should be drunk at least 8.8 hours before bedtime.',
    'Volwassenen in laboratoriumstudies.' => 'Adults in laboratory studies.',
    'Laboratoriumstudies met vaste doses.' => 'Laboratory studies with set doses.',
    'Ownify ziet alleen cafeïne die je logt.' => 'Ownify only sees the caffeine you log.',
    'Vanaf 13 jaar: drink minstens 1,4 liter per dag (mannen en jongens 1,4 tot 1,8 liter), en meer als je flink sport en zweet.'
        => 'From 13: drink at least 1.4 litres a day (men and boys 1.4 to 1.8 litres), and more when you exercise hard and sweat.',
    'Iedereen vanaf 13 jaar.' => 'Everyone from 13.',
    'Een algemeen advies.' => 'A general advice.',
    'Ownify ziet alleen wat je logt.' => 'Ownify only sees what you log.',
    'Na veel of zware training kunnen het moment van eten en de verhouding tussen koolhydraten en eiwit het herstel helpen.'
        => 'After a lot of or hard training, the timing of eating and the ratio of carbohydrate to protein may help recovery.',
    'Getrainde mensen.' => 'Trained people.',
    'Het standpunt van een vereniging, gericht op getrainde mensen.' => 'A society\'s position, aimed at trained people.',
    'Herstel na belasting verschilt tussen mensen en bij één persoon van moment tot moment; je volgt het daarom het best bij jezelf.'
        => 'Recovery after stress differs between people and, for one person, from moment to moment; that is why it is best followed in yourself.',
    'Een consensus over begrippen, met weinig getallen.' => 'A consensus on concepts, with few numbers.',
    'BMI is een maat voor groepen, niet voor de gezondheid van één persoon. Te veel lichaamsvet bevestig je met een andere maat, zoals je buikomvang.'
        => 'BMI is a measure for groups, not for one person\'s health. Excess body fat is confirmed with another measure, such as your waist circumference.',
    'Een klinisch kader; Ownify meet geen buikomvang.' => 'A clinical framework; Ownify does not measure waist circumference.',
    'Je maximale hartslag is ongeveer 208 − 0,7 × je leeftijd. Ownify gebruikt je eigen hoogst gemeten hartslag als die hoger is.'
        => 'Your maximal heart rate is about 208 − 0.7 × your age. Ownify uses your own highest recorded heart rate when that is higher.',
    'Gezonde volwassenen, mannen en vrouwen.' => 'Healthy adults, men and women.',
    'Een formule voor groepen: de echte maximale hartslag van één persoon kan ervan afwijken.' => 'A formula for groups: one person\'s real maximal heart rate can differ from it.',
    'Matig intensief is vanaf ongeveer 64% van je maximale hartslag, zwaar vanaf 77%.' => 'Moderate intensity starts at about 64% of your maximal heart rate, vigorous at 77%.',
    'Gezonde volwassenen van alle leeftijden.' => 'Healthy adults of all ages.',
    'Het standpunt van een vereniging.' => 'A society\'s position.',
    'De grenzen zijn een afspraak, geen gemeten omslagpunt.' => 'The limits are a convention, not a measured turning point.',
    'Met de jaren nemen slaapefficiëntie en diepe slaap af; na je 60e daalt vooral de efficiëntie nog. Een lagere waarde dan op je 25e is op latere leeftijd gewoon.'
        => 'Over the years sleep efficiency and deep sleep decrease; after 60 mostly efficiency keeps falling. A lower value than at 25 is normal at a later age.',
    'Gezonde mensen van 5 tot 102 jaar.' => 'Healthy people aged 5 to 102.',
    'Onderzoek uit 1960–2003, in een slaaplaboratorium.' => 'Research from 1960–2003, in a sleep laboratory.',
    'Weinig deelnemers van middelbare leeftijd.' => 'Few middle-aged participants.',
    'HRV is gemiddeld lager naarmate je ouder bent, en verschillen tussen mannen en vrouwen verdwijnen rond je 55e. Daarom vergelijkt Ownify je HRV alleen met je eigen waarden.'
        => 'HRV is lower on average the older you are, and differences between men and women disappear around 55. That is why Ownify compares your HRV only with your own values.',
    'Gezonde volwassenen van 25 tot 74 jaar.' => 'Healthy adults aged 25 to 74.',
    'Korte metingen overdag, geen waarden uit de nacht.' => 'Short daytime recordings, not values from the night.',
    'Eén Duitse groep.' => 'One German cohort.',
    'Oudere volwassenen kregen na inspanning niet meer spierschade dan jongere: hun spierfunctie na 24 en 72 uur was hetzelfde. Ownify gebruikt daarom bij elke leeftijd dezelfde hersteltijden.'
        => 'Older adults did not get more muscle damage after exercise than younger ones: their muscle function after 24 and 72 hours was the same. That is why Ownify uses the same recovery times at every age.',
    'Oudere en jongere volwassenen.' => 'Older and younger adults.',
    'Inspanning in een laboratorium.' => 'Exercise in a laboratory.',
    'Gaat niet over het herstel van de hartslag.' => 'Not about heart rate recovery.',
    'Vanaf 65 jaar: minstens 1,0–1,2 gram eiwit per kilogram per dag, en 1,2 of meer als je actief bent. De Nederlandse norm blijft 0,83 gram per kilogram.'
        => 'From 65: at least 1.0–1.2 grams of protein per kilogram a day, and 1.2 or more if you are active. The Dutch norm stays 0.83 grams per kilogram.',
    'Ouderen vanaf 65 jaar, zonder ernstige nierziekte.' => 'Adults from 65, without severe kidney disease.',
    'Advies van deskundigen, op basis van het bewijs van 2013.' => 'Expert advice, based on the evidence of 2013.',
    'Niet bij ernstige nierziekte zonder dialyse.' => 'Not with severe kidney disease without dialysis.',
    'Een tweede groep deskundigen: gezonde ouderen minstens 1,0–1,2 gram eiwit per kilogram per dag, en elke dag bewegen of sporten zolang het kan.'
        => 'A second expert group: healthy older adults at least 1.0–1.2 grams of protein per kilogram a day, and daily activity or exercise for as long as possible.',
    'Ouderen.' => 'Older adults.',
    'Advies van deskundigen, zonder precieze leeftijdsgrens voor ouderen.' => 'Expert advice, without an exact age limit for older adults.',
    'Per maaltijd vlakte de spieropbouw af bij ongeveer 0,24 gram eiwit per kilogram bij jonge mannen en bij ongeveer 0,40 gram per kilogram bij oudere mannen.'
        => 'Per meal, muscle building levelled off at about 0.24 grams of protein per kilogram in young men and at about 0.40 grams per kilogram in older men.',
    'Gezonde mannen van rond de 22 en rond de 71 jaar.' => 'Healthy men of around 22 and around 71.',
    'Alleen mannen, en niets over 40 tot 64 jaar.' => 'Men only, and nothing about ages 40 to 64.',
    'Porties eiwit in een laboratorium.' => 'Protein doses in a laboratory.',
];
