<?php
/**
 * Insights from a person's own data and approved research (docs/INSIGHTS.md),
 * written by Ownify's own code and templates — no AI service is asked (30.1):
 *
 *   includes/research.php            the approved registry, read from the database
 *   includes/insights/facts.php      derived facts, counted the way everything else is
 *   includes/insights/rules.php      facts and claims → candidates (pure)
 *   includes/insights/lifecycle.php  once a day, stored, reassessed after changes
 *   includes/insights/render.php     what both apps show
 *   includes/insights/notify.php     the line a save or sync calls
 *
 * and the combined insights (31.0, docs/CORRELATIONS.md), worked out with
 * them:
 *
 *   includes/correlations/stats.php   the statistics, pure
 *   includes/correlations/frame.php   one person's day table
 *   includes/correlations/engine.php  the 17 pairs → labels and numbers (pure)
 *   includes/correlations/store.php   once a day, stored
 *   includes/correlations/render.php  what both apps show
 */

declare(strict_types=1);

require_once __DIR__ . '/research.php';
require_once __DIR__ . '/insights/facts.php';
require_once __DIR__ . '/insights/rules.php';
require_once __DIR__ . '/insights/notify.php';
require_once __DIR__ . '/insights/lifecycle.php';
require_once __DIR__ . '/insights/render.php';
/* Guarded: a deploy lands one file at a time, and a missing file must not
   take the page with it. */
if (is_file(__DIR__ . '/correlations/render.php') && is_file(dirname(__DIR__) . '/config/correlations.php')) {
    require_once __DIR__ . '/correlations/render.php';
}
