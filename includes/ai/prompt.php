<?php
/**
 * Ownify AI: the system instruction for one question.
 *
 *   the assistant's parts     config/ai-prompt.php — who it is, how it treats
 *                             data, safety, scope, style; the same every time
 *   earlier in the chat       the older questions of a long conversation, in
 *                             brief (the recent messages travel as messages)
 *   OWNIFY DATA               the person's data for this question, fetched
 *                             just now (includes/ai/context.php)
 *
 * The tools describe themselves (includes/ai/tools.php).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/goals.php';

if (!function_exists('ai_system_instruction')) {

    /**
     * @param array<string,mixed> $context  ai_context()
     * @param string[]            $digest   older user questions, oldest first
     */
    function ai_system_instruction(array $context, array $digest = []): string
    {
        $parts = (array) require dirname(__DIR__, 2) . '/config/ai-prompt.php';

        /* The board's own limit (config/goals.php), not a number written here. */
        $text = strtr(implode("\n\n", array_map('trim', array_values($parts))), ['{goal_limit}' => (string) GOAL_MAX_ACTIVE]);

        if ($digest !== []) {
            $text .= "\n\nEARLIER IN THIS CONVERSATION — older questions from the user, not repeated below:\n- "
                . implode("\n- ", $digest);
        }

        $text .= "\n\nOWNIFY DATA — the user's own data, fetched " . ($context['now'] ?? date('Y-m-d H:i'))
            . ". JSON; a missing value was not recorded:\n"
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $text;
    }
}
