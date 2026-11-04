<?php
/**
 * Who a claim may be shown to (docs/RESEARCH.md, "Voor wie": 38.0).
 *
 * Research is only shown to a person who belongs to the population it holds
 * for. The claim's own age bounds (config/research.php `age_min`/`age_max`)
 * always apply; this file adds the other criteria a source actually sets —
 * only those, never a description of who happened to be studied:
 *
 *   ages      the ages the source studied, where narrower than the claim's
 *             own bounds: a list of [min, max] (null: open)
 *   sex       the source studied one sex only: the sexes it holds for
 *   training  the training status the finding is about (`levels` below)
 *   health    a health characteristic the source restricts its conclusion
 *             to — in its scope ("for healthy individuals not suffering from
 *             a sleep disorder"), as an exclusion ("not with severe kidney
 *             disease") or as the inclusion criterion its title names ("in
 *             healthy participants")
 *
 * What Ownify knows, and nothing more (research_person()):
 *   age       from the birth date
 *   sex       from Geslacht: man or vrouw; anything else is unknown
 *   training  from Activiteitsniveau, as the person set it
 *   health    nothing: Ownify records no health conditions
 *
 * A criterion Ownify cannot establish for a person is not met: the claim
 * is not shown until it can be. Nothing is guessed — not a sex from a name,
 * not a training status from workouts, not health from the data.
 *
 * Not person criteria: the population of a study about a measurement or a
 * method (a wearable's accuracy, how HRV is measured, the statistics) — who
 * wore the device is a limitation of the finding, not a requirement for the
 * person reading it. Those claims have no entry here.
 *
 * Each entry says what in its source sets it. This file is not part of the reviewed registry (no content hash): it is how
 * Ownify applies it, and changing it needs no new claim version.
 */

declare(strict_types=1);

return [

    /* Activiteitsniveau (user_profiles.activity_level) that establishes a
       training status. "Topsporter" is not something Ownify asks, so `elite`
       is never met. */
    'levels' => [
        'active'  => ['active', 'athlete'],   // "mensen die sporten": Actief or Sporter
        'athlete' => ['athlete'],             // "sporters", "getrainde mensen": Sporter
        'elite'   => [],                      // "topsporters": not established by Ownify
    ],

    /* The health characteristics a claim can require. Ownify records none
       of them, so a claim that needs one is not shown to anybody yet. */
    'health' => ['healthy', 'no_sleep_disorder', 'no_severe_kidney_disease'],

    'claims' => [

        /* ------------------------------------------------------- sleep */

        /* NSF (SLP-03): "guidelines for healthy individuals and those not
           suffering from a sleep disorder". From 65, CDC (SLP-01, by age
           only) says the same 7–8 hours (config/insights.php). */
        'sleep.duration.nsf' => ['health' => ['no_sleep_disorder']],

        /* Ohayon (AGE-03): normative values "in healthy individuals". */
        'sleep.architecture.age' => ['health' => ['healthy']],

        /* Walsh (XSP-03): a consensus about elite athletes. */
        'sleep.athletes' => ['training' => 'elite'],

        /* ---------------------------------------------- training, heart */

        /* Tanaka (AGE-01): HRmax of healthy adults (351 studies, 514 in the lab). */
        'activity.intensity.age' => ['health' => ['healthy']],

        /* ACSM (AGE-02): "…in apparently healthy adults". */
        'activity.intensity.bands' => ['health' => ['healthy']],

        /* Voss (AGE-07): 1.906 healthy subjects, by decade from 25 to 74. */
        'hrv.age' => ['ages' => [[25, 74]], 'health' => ['healthy']],

        /* Bellenger (XTH-02): trained, mostly endurance athletes. */
        'hrv.monitoring' => ['training' => 'athlete'],

        /* Plews (XTH-03): elite endurance athletes. */
        'hrv.averaging' => ['training' => 'elite'],

        /* Buchheit (XTH-04): athletes. */
        'hrv.change' => ['training' => 'athlete'],

        /* Kellmann (TRN-02): recovery in sport — athletes. */
        'recovery.individual' => ['training' => 'athlete'],

        /* Stutz (XSA-04): "…in Healthy Participants". */
        'cross.evening_exercise.review' => ['health' => ['healthy']],

        /* Frimpong (XSA-05): healthy adults of 18–50 who sleep well. */
        'cross.evening_hie.sleep' => ['health' => ['healthy', 'no_sleep_disorder']],

        /* --------------------------------------------------- nutrition */

        /* PROT-AGE (AGE-04): not with severe kidney disease without
           dialysis. Then the Dutch norm (NUT-02) is shown instead. */
        'nutrition.protein.older' => ['health' => ['no_severe_kidney_disease']],

        /* ESPEN (AGE-05): the claim gives the amount for healthy older people. */
        'nutrition.protein.older.espen' => ['health' => ['healthy']],

        /* Moore (AGE-06): healthy men of about 22 and about 71 — nothing
           about women, nothing about 40 to 64. */
        'nutrition.protein.meal.age' => ['ages' => [[18, 39], [65, null]], 'sex' => ['male'], 'health' => ['healthy']],

        /* ISSN (NUT-05): "healthy, exercising individuals". */
        'nutrition.protein.exercise' => ['training' => 'active', 'health' => ['healthy']],

        /* ISSN (XNT-01): trained people after a lot of or hard training. */
        'nutrition.timing' => ['training' => 'athlete'],

        /* Frankenfield (NUT-04): "…in healthy nonobese and obese adults". */
        'nutrition.energy_estimate' => ['health' => ['healthy']],
    ],
];
