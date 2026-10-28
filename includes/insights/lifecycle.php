<?php
/**
 * The daily lifecycle of insights (docs/INSIGHTS.md): worked out once per
 * person per local day, stored, and shown from storage by both apps.
 *
 *   first load of the day   claims the day (INSERT IGNORE on insight_days),
 *                           works it out and stores one insight per slot and
 *                           at most one suggestion
 *   any other load          reads what is stored — also while another
 *                           request is still working it out
 *   after a save or sync    (insights_note_change(): data_changed_at) the
 *                           next load reassesses: the stored insights are
 *                           kept, updated, replaced or withdrawn as the new
 *                           facts say — never a second suggestion, never a
 *                           Gemini call
 *   a request that died     leaves 'running'; after two minutes the next
 *                           load takes the day over (compare-and-set)
 *
 * The day is Ownify's one clock, Europe/Amsterdam (includes/bootstrap.php):
 * the first load after midnight starts a new day.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/research.php';
require_once __DIR__ . '/facts.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/notify.php';

if (!function_exists('insights_today')) {

    /** Whether migration 028 is in: the registry, the insight tables and the consent columns. */
    function insights_installed(): bool
    {
        static $installed = null;

        if ($installed !== null) {
            return $installed;
        }
        if (!db_available() || !research_installed()) {
            return false;
        }

        $tables = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name IN ('user_insights', 'insight_days')"
        );
        $columns = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
                AND column_name IN ('ai_insights_consent', 'ai_insights_consent_version', 'ai_insights_consent_at')"
        );

        return $installed = ($tables === 2 && $columns === 3);
    }

    /**
     * Today's stored insights for this person, worked out first when the day
     * needs it. Never throws: a failure is logged and the stored (or no)
     * result is returned.
     *
     * @return array{date: string, slots: array<string, array>, suggestion: ?array, evaluated: ?string, mode: ?string}
     */
    function insights_today(int $userId, ?DateTimeImmutable $now = null): array
    {
        $now  ??= new DateTimeImmutable('now');
        $date   = $now->format('Y-m-d');
        $mode   = null;

        if (!insights_installed()) {
            return ['date' => $date, 'slots' => [], 'suggestion' => null, 'evaluated' => null, 'mode' => null];
        }

        try {
            $mode = insights_claim_day($userId, $date, $now);
            if ($mode !== null) {
                insights_evaluate($userId, $date, $mode, $now);
            }
        } catch (Throwable $e) {
            error_log('[ownify] insights: the day could not be worked out (' . $mode . '): ' . $e->getMessage());
        }

        return insights_stored($userId, $date) + ['mode' => $mode];
    }

    /**
     * Whether this request works the day out, and how: 'full' (the day's
     * first, or a new engine version, or a takeover of a request that died
     * before it finished), 'reassess' (data changed since), or null (read
     * what is stored).
     */
    function insights_claim_day(int $userId, string $date, DateTimeImmutable $now): ?string
    {
        $cfg     = insights_config();
        $life    = $cfg['lifecycle'];
        $version = (string) $cfg['engine_version'];
        $stamp   = $now->format('Y-m-d H:i:s');

        $day = db_one('SELECT state, started_at, evaluated_at, data_changed_at, evaluations, engine_version
                         FROM insight_days WHERE user_id = ? AND local_date = ?', [$userId, $date]);

        if ($day === null) {
            $claimed = db_run(
                "INSERT IGNORE INTO insight_days (user_id, local_date, state, started_at, evaluations, engine_version)
                 VALUES (?, ?, 'running', ?, 1, ?)",
                [$userId, $date, $stamp, $version]
            );

            return ($claimed !== null && $claimed->rowCount() === 1) ? 'full' : null;
        }

        $evaluations = (int) $day['evaluations'];
        $cap         = (int) $life['reassess_max_per_day'] + 1;

        if ($day['state'] === 'running') {
            $age = $now->getTimestamp() - (int) strtotime((string) $day['started_at']);
            if ($age < (int) $life['stale_running_seconds'] || $evaluations >= $cap) {
                return null;
            }
            /* A request that died: take the day over, unless somebody else just did. */
            $taken = db_run(
                "UPDATE insight_days SET started_at = ?, evaluations = evaluations + 1
                  WHERE user_id = ? AND local_date = ? AND state = 'running' AND started_at = ?",
                [$stamp, $userId, $date, $day['started_at']]
            );

            return ($taken !== null && $taken->rowCount() === 1) ? ($day['evaluated_at'] === null ? 'full' : 'reassess') : null;
        }

        /* A change in the same second as the evaluation counts as after
           it: the facts may have been read just before it landed. */
        $changed = $day['data_changed_at'] !== null
            && ($day['evaluated_at'] === null || strtotime((string) $day['data_changed_at']) >= strtotime((string) $day['evaluated_at']));
        $outdated = (string) $day['engine_version'] !== $version;
        $rested   = $day['evaluated_at'] === null
            || $now->getTimestamp() - (int) strtotime((string) $day['evaluated_at']) >= 60 * (int) $life['reassess_min_minutes'];

        if (!$outdated && !($changed && $rested && $evaluations < $cap)) {
            return null;
        }

        $taken = db_run(
            "UPDATE insight_days SET state = 'running', started_at = ?, evaluations = evaluations + 1
              WHERE user_id = ? AND local_date = ? AND state = 'done' AND engine_version = ?
                AND (evaluated_at <=> ?)",
            [$stamp, $userId, $date, $day['engine_version'], $day['evaluated_at']]
        );

        if ($taken === null || $taken->rowCount() !== 1) {
            return null;
        }

        return $outdated ? 'full' : 'reassess';
    }

    /**
     * Works the day out and stores it, then marks the day done. In 'full'
     * mode every slot gets its best candidate; in 'reassess' mode a stored
     * insight is kept while its rule still applies (its numbers updated) and
     * only replaced by a clearly more relevant one, or withdrawn when nothing
     * applies any more. The suggestion is never replaced by another one.
     */
    function insights_evaluate(int $userId, string $date, string $mode, DateTimeImmutable $now): void
    {
        $cfg     = insights_config();
        $facts   = insights_facts($userId, $now);
        $hash    = hash('sha256', research_json($facts));
        $stamp   = $now->format('Y-m-d H:i:s');

        $day = db_one('SELECT facts_hash FROM insight_days WHERE user_id = ? AND local_date = ?', [$userId, $date]);

        if ($mode === 'reassess' && $day !== null && $day['facts_hash'] === $hash) {
            /* Nothing that matters changed: a repeated sync, the same week. */
            insights_finish_day($userId, $date, $hash, $stamp);
            return;
        }

        $claims     = research_claims();
        $candidates = insights_candidates($facts, $claims, $cfg);
        $yesterday  = insights_yesterday($userId, $date);
        $best       = insights_choose($candidates, $yesterday, $cfg);
        $stored     = insights_rows($userId, $date);
        $until      = $date . ' 23:59:59';

        foreach ((array) $cfg['slots'] as $slot) {
            $new = $best[$slot] ?? null;
            $old = $stored[$slot] ?? null;

            if ($mode === 'reassess' && $old !== null && $old['withdrawn_at'] === null) {
                /* Keep the stored rule while it still applies. */
                $same = null;
                foreach ($candidates as $candidate) {
                    if ($candidate['slot'] === $slot && $candidate['rule'] === $old['rule_key']) {
                        $same = $candidate;
                        break;
                    }
                }
                if ($same !== null && ($new === null || $new['relevance'] <= $same['relevance'] || $new['rule'] === $same['rule'])) {
                    $same['score'] = insights_score($same, $yesterday, $cfg);
                    $new = $same;
                }
            }

            if ($new === null) {
                if ($old !== null && $old['withdrawn_at'] === null) {
                    db_run('UPDATE user_insights SET withdrawn_at = ? WHERE id = ?', [$stamp, $old['id']]);
                }
                continue;
            }

            insights_put($userId, $date, $slot, $new, $until, $old);
        }

        /* At most one suggestion a day: made once, never replaced. A later
           reassessment may only withdraw it — or make the first one, when
           the day had none yet. */
        $old = $stored['suggestion'] ?? null;
        if ($old === null) {
            $suggestion = insights_choose_suggestion($candidates, insights_recent_suggestions($userId, $date), $claims, $yesterday, $cfg);
            if ($suggestion !== null) {
                insights_put($userId, $date, 'suggestion', $suggestion, $until, null);
            }
        } elseif ($old['withdrawn_at'] === null) {
            $still = null;
            foreach ($candidates as $candidate) {
                if ($candidate['rule'] === $old['rule_key'] && $candidate['outcome'] === $old['outcome']) {
                    $still = $candidate;
                    break;
                }
            }
            if ($still === null) {
                db_run('UPDATE user_insights SET withdrawn_at = ? WHERE id = ?', [$stamp, $old['id']]);
            } elseif (research_json($still['facts']) !== (string) $old['facts_json']) {
                /* Same suggestion, newer numbers behind it. */
                db_run('UPDATE user_insights SET facts_json = ?, fingerprint = ?, phrased_body = NULL, phrased_locale = NULL, phrased_model = NULL WHERE id = ?',
                    [research_json($still['facts']), insights_fingerprint(['claim_id' => (int) $old['claim_id']] + $still), $old['id']]);
            }
        }

        insights_prune($userId, $date);
        insights_finish_day($userId, $date, $hash, $stamp);
    }

    /** Upserts one slot's insight. Gemini's wording is kept only while the text it reworded is unchanged. */
    function insights_put(int $userId, string $date, string $slot, array $candidate, string $until, ?array $old): void
    {
        $fingerprint = insights_fingerprint($candidate);
        $keep        = $old !== null && $old['fingerprint'] === $fingerprint && $old['withdrawn_at'] === null;

        db_run(
            'INSERT INTO user_insights
                (user_id, local_date, slot, rule_key, outcome, claim_id, strength, applicability,
                 facts_json, fingerprint, rank_score, valid_until, withdrawn_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                rule_key = VALUES(rule_key), outcome = VALUES(outcome), claim_id = VALUES(claim_id),
                strength = VALUES(strength), applicability = VALUES(applicability),
                facts_json = VALUES(facts_json), fingerprint = VALUES(fingerprint),
                rank_score = VALUES(rank_score), valid_until = VALUES(valid_until), withdrawn_at = NULL,
                phrased_body   = IF(?, phrased_body, NULL),
                phrased_locale = IF(?, phrased_locale, NULL),
                phrased_model  = IF(?, phrased_model, NULL)',
            [
                $userId, $date, $slot, $candidate['rule'], $candidate['outcome'], (int) $candidate['claim_id'],
                $candidate['strength'], $candidate['applicability'], research_json($candidate['facts']),
                $fingerprint, max(-32000, min(32000, (int) ($candidate['score'] ?? 0))), $until,
                $keep ? 1 : 0, $keep ? 1 : 0, $keep ? 1 : 0,
            ]
        );
    }

    function insights_finish_day(int $userId, string $date, string $hash, string $stamp): void
    {
        db_run(
            "UPDATE insight_days SET state = 'done', evaluated_at = ?, facts_hash = ?, engine_version = ?
              WHERE user_id = ? AND local_date = ?",
            [$stamp, $hash, (string) insights_config()['engine_version'], $userId, $date]
        );
    }

    /**
     * Today's rows, keyed by slot (withdrawn ones included, so a reassessment
     * can see them).
     *
     * @return array<string, array<string, mixed>>
     */
    function insights_rows(int $userId, string $date): array
    {
        $out = [];
        foreach (db_all(
            'SELECT id, slot, rule_key, outcome, claim_id, strength, applicability, facts_json, fingerprint,
                    rank_score, phrased_locale, phrased_body, phrased_model, created_at, updated_at, valid_until, withdrawn_at
               FROM user_insights WHERE user_id = ? AND local_date = ?',
            [$userId, $date]
        ) as $row) {
            $out[(string) $row['slot']] = $row;
        }

        return $out;
    }

    /** What each slot showed the day before: for the repeat penalty. */
    function insights_yesterday(int $userId, string $date): array
    {
        $before = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $out    = [];
        foreach (db_all(
            'SELECT slot, rule_key, outcome FROM user_insights
              WHERE user_id = ? AND local_date = ? AND withdrawn_at IS NULL',
            [$userId, $before]
        ) as $row) {
            $out[(string) $row['slot']] = ['rule' => (string) $row['rule_key'], 'outcome' => (string) $row['outcome']];
        }

        return $out;
    }

    /** "rule.outcome" of the suggestions of the last few days (withdrawn ones too: they were seen). */
    function insights_recent_suggestions(int $userId, string $date): array
    {
        $days = max(0, (int) insights_config()['lifecycle']['suggestion_cooldown_days']);
        if ($days === 0) {
            return [];
        }

        $from = (new DateTimeImmutable($date))->modify('-' . $days . ' days')->format('Y-m-d');

        return array_map(
            static fn ($row) => $row['rule_key'] . '.' . $row['outcome'],
            db_all("SELECT rule_key, outcome FROM user_insights
                     WHERE user_id = ? AND slot = 'suggestion' AND local_date >= ? AND local_date < ?",
                [$userId, $from, $date])
        );
    }

    /** Insights and days older than lifecycle.keep_days go. */
    function insights_prune(int $userId, string $date): void
    {
        $keep = max(7, (int) insights_config()['lifecycle']['keep_days']);
        $from = (new DateTimeImmutable($date))->modify('-' . $keep . ' days')->format('Y-m-d');

        db_run('DELETE FROM user_insights WHERE user_id = ? AND local_date < ?', [$userId, $from]);
        db_run('DELETE FROM insight_days WHERE user_id = ? AND local_date < ?', [$userId, $from]);
    }

    /**
     * Today's stored result, as both apps show it: the live insight per slot
     * and the suggestion (or that it was withdrawn).
     *
     * @return array{date: string, slots: array<string, array>, suggestion: ?array, evaluated: ?string}
     */
    function insights_stored(int $userId, string $date): array
    {
        $rows  = insights_rows($userId, $date);
        $slots = [];
        foreach ((array) insights_config()['slots'] as $slot) {
            if (isset($rows[$slot]) && $rows[$slot]['withdrawn_at'] === null) {
                $slots[$slot] = $rows[$slot];
            }
        }

        $evaluated = db_value('SELECT evaluated_at FROM insight_days WHERE user_id = ? AND local_date = ?', [$userId, $date]);

        return [
            'date'       => $date,
            'slots'      => $slots,
            'suggestion' => $rows['suggestion'] ?? null,
            'evaluated'  => $evaluated === null ? null : (string) $evaluated,
        ];
    }
}
