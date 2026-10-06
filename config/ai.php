<?php
/**
 * Ownify AI — the assistant in the sheet you swipe up — and the Gemini API
 * behind it.
 *
 * ---------------------------------------------------------------------------
 * NOTHING SECRET GOES IN THIS FILE
 * ---------------------------------------------------------------------------
 * This file is in the repository and in every deploy. The Gemini API key is
 * read from, in this order:
 *
 *   1. the process environment          GEMINI_API_KEY
 *   2. the server environment           $_SERVER['GEMINI_API_KEY']
 *   3. config/ai.local.php              ['api_key' => '...']   (git-ignored)
 *
 * and never leaves the server: no page, no API answer and no log line holds
 * it. Without one the assistant says it is not available, and nothing else
 * changes. docs/AI.md says where to get a key and how to check it.
 *
 * Everything below can be overridden the same way — the environment first,
 * then config/ai.local.php — so the model or the limits change without a
 * code change:
 *
 *   GEMINI_MODEL              model            which Gemini model answers
 *   AI_DAILY_MESSAGE_LIMIT    daily_limit      questions per person per day
 *   AI_GLOBAL_DAILY_LIMIT     global_daily_limit   Gemini requests per day,
 *                                                  everybody together
 *
 * ---------------------------------------------------------------------------
 * FREE OF CHARGE, ON PURPOSE
 * ---------------------------------------------------------------------------
 * Ownify uses the Gemini API's free tier and nothing that costs money: no
 * paid model, no Google Search grounding, no second provider to fall back
 * on. When Google says the free quota is used up (HTTP 429), the assistant
 * says so and stops asking until the quota is back; it never switches to
 * anything else. Google's limits are per Google Cloud project — one shared
 * pool for every Ownify user — which is why Ownify also counts every request
 * it makes itself (global_daily_limit) and gives each person a small daily
 * share (daily_limit).
 *
 * On the free tier Google may use what is sent to improve its products, and
 * people at Google may read it. The consent screen says exactly that. If
 * Ownify ever moves to terms that say otherwise, change the wording in
 * config/dashboard.php ('ai' → 'consent') and `consent_version` below:
 * everybody who said yes to the old wording is asked again.
 */

declare(strict_types=1);

$settings = [

    /** A model on the free tier. Changing it is a line here, or GEMINI_MODEL. */
    'model' => 'gemini-3.8-flash',

    /** The Gemini API. Only ever changed to point a test at a stand-in. */
    'api_base' => 'https://generativelanguage.googleapis.com',

    /** Seconds to wait for Gemini's answer before saying it took too long. */
    'timeout' => 45,

    /**
     * How much Gemini may write, thinking included. Answers are asked to be
     * short; this is the ceiling, not the aim.
     */
    'max_output_tokens' => 2048,

    /**
     * How hard the model thinks before it answers: 'low' is fastest and uses
     * the fewest tokens, which matters on a shared free quota. Empty leaves
     * it to the model. (A model that does not take this setting is asked
     * again without it.)
     */
    'thinking_level' => 'low',

    /* ------------------------------------------------------------ limits */

    /** Questions one person can ask per day (the server counts them, not the app). */
    'daily_limit' => 10,

    /**
     * Requests Ownify makes to Gemini per day, everybody together — one
     * question can take two or three when the assistant looks something up.
     * Kept under what Google's free tier allows (see your limits in AI
     * Studio), so Ownify stops itself before Google has to.
     */
    'global_daily_limit' => 250,

    /** How many Gemini requests one question may take at most (look-ups included). */
    'max_rounds' => 3,

    /** The longest question someone can send, in characters. */
    'max_message_chars' => 2000,

    /* ------------------------------------------------------ conversation */

    /** The most recent messages sent along, so "that" still means something. */
    'history_messages' => 12,

    /** Earlier questions from the same conversation, listed as a reminder. */
    'history_digest' => 8,

    /** Conversations kept per person; the oldest go when there are more. */
    'max_conversations' => 50,

    /* ------------------------------------------------------------ consent */

    /**
     * The wording people said yes to. Change it when the consent text in
     * config/dashboard.php changes in substance — another tier, another
     * provider, other terms — and everybody is asked again.
     *
     * 2026-10: the text now says what goes along with every question (the
     * profile, the scores, the goals, two weeks in brief, the conversation)
     * instead of "only what fits the question", so everybody is asked again.
     */
    'consent_version' => '2026-10-gemini-free',

    /* --------------------------------------------------------- diagnostic */

    /**
     * Temporary, and off: true writes one line per Gemini request to
     * ownify-ai-diagnostic.log in PHP's upload_tmp_dir (on Hestia
     * /home/<user>/tmp, which the File Manager shows), or give a full path.
     * Only status, Gemini's error, the model and the outline of what was
     * sent and received — never the key, a word of the text, or a
     * signature. Never inside the web root. See includes/ai/diagnostic.php.
     */
    'diagnostic_log' => false,
];

$local = __DIR__ . '/ai.local.php';
if (is_file($local)) {
    $settings = array_replace($settings, (array) require $local);
}

/* The environment wins, so a host that injects settings rather than storing
   them in a file works without one. The key itself is read by
   includes/ai/config.php and never returned from here. */
foreach ([
    'GEMINI_MODEL'           => ['model', 'string'],
    'GEMINI_API_BASE'        => ['api_base', 'string'],
    'GEMINI_TIMEOUT'         => ['timeout', 'int'],
    'AI_DAILY_MESSAGE_LIMIT' => ['daily_limit', 'int'],
    'AI_GLOBAL_DAILY_LIMIT'  => ['global_daily_limit', 'int'],
    'AI_THINKING_LEVEL'      => ['thinking_level', 'string'],
    'AI_DIAGNOSTIC_LOG'      => ['diagnostic_log', 'string'],
] as $variable => [$key, $type]) {
    $value = getenv($variable);
    if (!is_string($value) || $value === '') {
        $value = isset($_SERVER[$variable]) && is_string($_SERVER[$variable]) ? $_SERVER[$variable] : '';
    }
    if ($value !== '') {
        $settings[$key] = $type === 'int' ? (int) $value : $value;
    }
}

unset($settings['api_key']);   // read only where it is used, see includes/ai/config.php

return $settings;
