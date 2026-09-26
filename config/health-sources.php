<?php
/**
 * When two apps recorded the same moment — which one counts.
 *
 * ---------------------------------------------------------------------------
 * THE PROBLEM
 * ---------------------------------------------------------------------------
 * A phone and a watch both count the steps of the same walk, and both put
 * their record in Health Connect: 5.000 from the phone, 4.900 from the watch.
 * Added up that is 9.900 steps nobody walked. Health Connect's own total
 * counts every moment once; this is how JoLu does the same, for the metrics
 * listed under 'reconcile'. It is applied when a day's total is read
 * (health_metric_totals() in includes/health-totals.php), never by deleting a
 * record: everything the phone sent stays stored as it arrived.
 *
 * ---------------------------------------------------------------------------
 * THE RULE
 * ---------------------------------------------------------------------------
 * A day is cut into moments. At every moment exactly one record counts — the
 * one from the app that ranks highest — and it counts for the part of its
 * value that falls in that moment: a record of 600 steps over 10 minutes
 * gives 60 for each minute. Where the highest app has nothing, the next one
 * fills in. A record that crosses midnight is split over the two days.
 *
 * Which app ranks highest, per day:
 *
 *   1. the apps listed under 'priority' below, in that order;
 *   2. then the app whose records cover the most of that day's time — the
 *      one that was there all day, rather than the one that caught a part;
 *   3. then the app with the higher total of its own for the day;
 *   4. then the package name, alphabetically, so the answer never depends on
 *      the order the rows happen to come back in.
 *
 * Two records from the same app that overlap (a phone and a watch writing
 * under one app) are ranked by the newest arrival, then the later start, the
 * later end, the larger value — the order Health Connect uses.
 *
 * A reading without a time span — typed in by hand, or imported before
 * migration 012 — cannot be compared with anything and counts as it is.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS NOT
 * ---------------------------------------------------------------------------
 * It is not a verdict on which device is more accurate. Nobody can know
 * from the numbers whether 5.000 or 4.900 is the true count; the rule only
 * makes sure the same moment is not counted twice, and always picks the
 * same way. Health Connect itself asks the user to rank their apps, but no
 * app can read that ranking — so, until JoLu asks the user too, the list
 * below is the place to make that choice.
 */

declare(strict_types=1);

return [

    /* The metrics whose day total counts every moment once — the activity
       totals Health Connect itself reconciles. Anything not listed is a
       plain sum per day, as before. */
    'reconcile' => [
        'steps',
        'distance',
        'active_energy',
        'total_energy',
        'floors',
    ],

    /* App package names, most trusted first. Empty: the rule above decides.
       For example:

         'priority' => [
             'com.fitbit.FitbitMobile',
             'com.google.android.apps.fitness',
         ],

       An app not in the list ranks below every app that is. */
    'priority' => [],

];
