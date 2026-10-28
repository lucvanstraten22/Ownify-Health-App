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
 */

declare(strict_types=1);

require_once __DIR__ . '/research.php';
require_once __DIR__ . '/insights/facts.php';
require_once __DIR__ . '/insights/rules.php';
require_once __DIR__ . '/insights/notify.php';
require_once __DIR__ . '/insights/lifecycle.php';
require_once __DIR__ . '/insights/render.php';
