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
 *   GEMINI_TIMEOUT            timeout          seconds for one question
 *   GEMINI_CONNECT_TIMEOUT    connect_timeout  seconds to reach Google
 *   AI_RETRIES                retries          retries of one request, at most
 *   AI_RETRY_BACKOFF_MS       retry_backoff_ms the first wait before a retry
 *   AI_SWITCHED_OFF           switched_off     1/0: OwnifyAI off or on
 *   AI_DAILY_MESSAGE_LIMIT    daily_limit      questions per person per day
 *   AI_GLOBAL_DAILY_LIMIT     global_daily_limit   Gemini requests per day,
 *                                                  everybody together
 *   AI_MINUTE_LIMIT           minute_limit     Gemini requests per minute,
 *                                              everybody together
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

    /**
     * OwnifyAI switched off (50.5, the owner's decision): Gemini cannot be made
     * to answer on the free tier (docs/AI.md). Nothing is sent to Gemini;
     * the sheet keeps its conversations, shows its field disabled and,
     * always above it, errors.off from config/dashboard.php. Set to false
     * to switch it on again.
     */
    'switched_off' => true,

    /** A model on the free tier. Changing it is a line here, or GEMINI_MODEL. */
    'model' => 'gemini-3.8-flash',

    /** The Gemini API. Only ever changed to point a test at a stand-in. */
    'api_base' => 'https://generativelanguage.googleapis.com',

    /**
     * Seconds one question may take, from the moment it reaches the server:
     * every Gemini request, the look-up between them and every retry
     * together. It must stay under the web server's own limit. On Hestia,
     * nginx hands the request to Apache, and Apache gives PHP-FPM 30 s
     * (`Timeout 30` in /etc/apache2/apache2.conf, Hestia's default) before
     * it answers the app 504 by itself — after which PHP goes on asking
     * Gemini for nobody, and what it answers is stored but never seen. Up to
     * 50.2 this was 45 s for each request (two rounds: 90), so an answer
     * slower than 30 s always failed. Raise it only together with Apache's
     * Timeout, and keep it under the Android app's 90 s.
     */
    'timeout' => 25,

    /** Seconds to set up the connection to Google (DNS, TCP, TLS) — it takes well under one. */
    'connect_timeout' => 5,

    /**
     * How often one request may be asked again when asking again can help:
     * Google overloaded for a moment (HTTP 500, 502, 503 "high demand", 504),
     * or the request never reached Google (no name, no connection, no TLS —
     * nothing was counted there). Never after a 429 (quota) or after a request
     * that reached Google and got no answer in time (Google may have counted
     * it), never past `timeout`, and only with a third of it left. Each retry
     * takes a place in the minute and counts against the day like any other
     * request. The waits: retry_backoff_ms, then 3 x that, plus a little at
     * random, or longer when Google says Retry-After.
     */
    'retries'          => 2,
    'retry_backoff_ms' => 1000,

    /**
     * How much Gemini may write, thinking included. Answers are asked to be
     * short; this is the ceiling, not the aim. Gemini 3 counts its thinking
     * against it, and a model that thinks up to the ceiling sends back no
     * answer at all (finishReason MAX_TOKENS) — so it stays well above what
     * thinking takes. The model allows 64k; a higher ceiling costs nothing
     * by itself, only the tokens actually written count.
     */
    'max_output_tokens' => 8192,

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
     * question can take two when the assistant looks something up, more
     * with a retry. Google's free tier allows gemini-3.8-flash 20 requests
     * a day for the whole project (its own 429 said so, October 2026:
     * "free-tier limit of 20 requests"), so Ownify stops itself there
     * instead of letting Google refuse. Raise it only when AI Studio shows
     * a higher limit for the project.
     */
    'global_daily_limit' => 20,

    /**
     * Requests Ownify makes to Gemini per minute, everybody together — one
     * shared count for all accounts (includes/ai/pace.php). Google's free
     * tier allows gemini-3.8-flash 5 requests per minute for the whole
     * project; past it Google refuses (HTTP 429) and everyone has to wait.
     * Ownify keeps under it itself: a question that would go over waits,
     * and the sheet counts down to when there is room again. At most 8.
     */
    'minute_limit' => 5,

    /**
     * How many Gemini requests one question may take at most: an answer
     * straight away, or one look-up and then the answer. Every one of them
     * counts against minute_limit, so fewer rounds leave room for more people.
     */
    'max_rounds' => 2,

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
    'GEMINI_CONNECT_TIMEOUT' => ['connect_timeout', 'int'],
    'AI_RETRIES'             => ['retries', 'int'],
    'AI_RETRY_BACKOFF_MS'    => ['retry_backoff_ms', 'int'],
    'AI_DAILY_MESSAGE_LIMIT' => ['daily_limit', 'int'],
    'AI_GLOBAL_DAILY_LIMIT'  => ['global_daily_limit', 'int'],
    'AI_MINUTE_LIMIT'        => ['minute_limit', 'int'],
    'AI_THINKING_LEVEL'      => ['thinking_level', 'string'],
    'AI_DIAGNOSTIC_LOG'      => ['diagnostic_log', 'string'],
    'AI_SWITCHED_OFF'        => ['switched_off', 'bool'],
] as $variable => [$key, $type]) {
    $value = getenv($variable);
    if (!is_string($value) || $value === '') {
        $value = isset($_SERVER[$variable]) && is_string($_SERVER[$variable]) ? $_SERVER[$variable] : '';
    }
    if ($value !== '') {
        $settings[$key] = match ($type) {
            'int'   => (int) $value,
            'bool'  => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            default => $value,
        };
    }
}

unset($settings['api_key']);   // read only where it is used, see includes/ai/config.php

return $settings;
