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
 *   per_kcal grams per 1.000 kcal eaten that day: the range in grams is the
 *           day's own energy times this (fibre)
 *   none     no norm to measure against: shown, never judged
 *
 * A value with no basis for a verdict gets none ("Geen oordeel"): total
 * sugar, because the guideline is about free sugars, which Health Connect
 * does not tell apart; and carbohydrates, which have no percentage norm.
 *
 * Sources (29.0, the owner's decision of 27 October 2026: the 2026 norms):
 * Gezondheidsraad, Voedingsnormen voor vetten, vetzuren, verteerbare
 * koolhydraten en voedingsvezels, publicatienr. 2026/14 (NUT-01 in
 * config/research.php) — total fat 20–40 % of the energy; saturated fat as
 * low as possible, 10 % as an interim step; carbohydrates no percentage;
 * fibre 3,0–3,5 g per MJ, 12,6–14,6 g per 1.000 kcal. Water: Voedingscentrum
 * (drinks a day), not a reviewed research source. The energy range around the
 * person's own target is Ownify's assumption.
 */

declare(strict_types=1);

return [

    /* kcal per gram, for each macronutrient's share of the energy. */
    'kcal_per_gram' => ['carbs' => 4.0, 'protein' => 4.0, 'fat' => 9.0],

    /* kJ = kcal × this. kJ is always worked out, never measured. */
    'kj_per_kcal' => 4.184,

    'macros' => [
        /* 2026: no percentage norm for carbohydrates — their source matters
           (whole grain, vegetables, fruit, legumes, dairy), which a log does
           not show. Their share is shown, never judged. */
        'carbs'   => ['measure' => 'none'],
        'protein' => ['measure' => 'target'],
        'fat'     => ['measure' => 'share', 'range' => [20, 40]],
    ],

    /* Details of a macronutrient — never macronutrients of their own. */
    'details' => [
        /* 2026: 3,0–3,5 g per MJ (12,6–14,6 g per 1.000 kcal) instead of a
           fixed 30–40 g: the range follows the day's own energy. */
        'fibre'         => ['of' => 'carbs', 'measure' => 'per_kcal', 'range' => [12.6, 14.6]],
        'sugar'         => ['of' => 'carbs', 'measure' => 'none'],
        /* 2026: as low as possible; 10 % of the energy is the interim step. */
        'saturated_fat' => ['of' => 'fat',   'measure' => 'share', 'range' => [0, 10]],
    ],

    /* Energie: the bereik around the person's own target, as shares of it. */
    'energy' => ['range' => [0.9, 1.1]],

    /* Water, in litres of drinks a day: the bereik, and the target in it. */
    'water' => ['range' => [1.5, 2.0], 'target' => 1.8],
];
