<?php
/**
 * Voeding's reference values — the ONE place they are written
 * (docs/NUTRITION.md).
 *
 * Every range, verdict and target the Voeding page shows is read from here:
 * the doughnut's rows, their bubbles (Binnen / Onder / Boven bereik), the
 * green zones on Energie and Water. Change a number here — or replace them
 * with Ownify's own research — and the website and the app follow on their
 * next read; nothing else needs to change.
 *
 * What a value is measured against:
 *
 *   share   a macronutrient's share of the energy from carbohydrates,
 *           protein and fat together (4, 4 and 9 kcal per gram), in %
 *   grams   the grams eaten that day
 *   target  the person's own target (includes/nutrition-targets.php):
 *           energy from Mifflin-St Jeor × activity, protein in g per kg
 *
 * A value with no basis for a verdict gets none ("Geen oordeel"): total
 * sugar, because the guideline is about added sugar, which Health Connect
 * does not tell apart.
 *
 * Sources of the defaults: Gezondheidsraad, Richtlijnen goede voeding 2015
 * and its background on macronutrients (energy shares, saturated fat,
 * fibre); Voedingscentrum (drinks a day). The energy range around the
 * person's own target is Ownify's assumption.
 */

declare(strict_types=1);

return [

    /* kcal per gram, for each macronutrient's share of the energy. */
    'kcal_per_gram' => ['carbs' => 4.0, 'protein' => 4.0, 'fat' => 9.0],

    /* kJ = kcal × this. kJ is always worked out, never measured. */
    'kj_per_kcal' => 4.184,

    'macros' => [
        'carbs'   => ['measure' => 'share', 'range' => [40, 70]],
        'protein' => ['measure' => 'target'],
        'fat'     => ['measure' => 'share', 'range' => [20, 40]],
    ],

    /* Details of a macronutrient — never macronutrients of their own. */
    'details' => [
        'fibre'         => ['of' => 'carbs', 'measure' => 'grams', 'range' => [30, 40]],
        'sugar'         => ['of' => 'carbs', 'measure' => 'none'],
        'saturated_fat' => ['of' => 'fat',   'measure' => 'share', 'range' => [0, 10]],
    ],

    /* Energie: the bereik around the person's own target, as shares of it. */
    'energy' => ['range' => [0.9, 1.1]],

    /* Water, in litres of drinks a day: the bereik, and the target in it. */
    'water' => ['range' => [1.5, 2.0], 'target' => 1.8],
];
