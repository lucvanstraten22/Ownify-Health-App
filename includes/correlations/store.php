<?php
/**
 * Combined insights, kept (docs/CORRELATIONS.md): worked out with the day's
 * insights and stored, so both apps show the same numbers and nothing is
 * worked out on a plain page load.
 *
 *   the day's insights are worked out    the combined ones are worked out
 *   (first load, reassessment after a    again in the same request
 *   sync, a new engine version)
 *   a day without a run yet              the first load claims it (INSERT
 *   (migration 029 just imported)        IGNORE on user + date) and runs
 *   any other load                       reads the newest finished run —
 *                                        also while today's is being worked
 *                                        out, so nothing flickers
 *   a request that died                  after two minutes the next load
 *                                        takes the run over
 *
 * Until migration 029 is imported nothing here runs and the Overzicht keeps
 * its 30.1 card (corr_installed()).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/frame.php';
require_once __DIR__ . '/engine.php';

if (!function_exists('correlations_today')) {

    /** Whether migration 029 is in. */
    function corr_installed(): bool
    {
        static $installed = null;

        if ($installed !== null) {
            return $installed;
        }
        if (!db_available()) {
            return false;
        }

        return $installed = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name IN ('correlation_runs', 'user_correlations')"
        ) === 2;
    }

    /**
     * The newest stored run, worked out first when today needs it. Never
     * throws: a failure is logged and what is stored (or null) is returned.
     *
     * @param bool $insightsRan  this request just worked out the day's insights
     * @return ?array{date: string, from: string, to: string, tested: int, meta: array, pairs: array<string, array>}
     */
    function correlations_today(int $userId, ?DateTimeImmutable $now = null, bool $insightsRan = false): ?array
    {
        if (!corr_installed()) {
            return null;
        }
        $now ??= new DateTimeImmutable('now');

        try {
            $runId = corr_claim_run($userId, $now->format('Y-m-d'), $now, $insightsRan);
            if ($runId !== null) {
                corr_run($userId, $runId, $now);
            }
        } catch (Throwable $e) {
            error_log('[ownify] correlations: the run could not be worked out: ' . $e->getMessage());
        }

        try {
            return corr_stored($userId);
        } catch (Throwable $e) {
            error_log('[ownify] correlations: the stored run could not be read: ' . $e->getMessage());

            return null;
        }
    }

    /** Whether this request works today's run out; its id when it does. */
    function corr_claim_run(int $userId, string $date, DateTimeImmutable $now, bool $insightsRan): ?int
    {
        $version = (string) corr_config()['engine_version'];
        $stamp   = $now->format('Y-m-d H:i:s');
        $row     = db_one('SELECT id, state, started_at, engine_version FROM correlation_runs WHERE user_id = ? AND local_date = ?',
            [$userId, $date]);

        if ($row === null) {
            $claimed = db_run(
                "INSERT IGNORE INTO correlation_runs (user_id, local_date, state, started_at, engine_version)
                 VALUES (?, ?, 'running', ?, ?)",
                [$userId, $date, $stamp, $version]
            );
            if ($claimed === null || $claimed->rowCount() !== 1) {
                return null;
            }

            return (int) db_value('SELECT id FROM correlation_runs WHERE user_id = ? AND local_date = ?', [$userId, $date]);
        }

        if ($row['state'] === 'running') {
            /* Somebody is on it — unless they died two minutes ago. */
            if ($now->getTimestamp() - (int) strtotime((string) $row['started_at']) < 120) {
                return null;
            }
        } elseif (!$insightsRan && (string) $row['engine_version'] === $version) {
            return null;
        }

        $taken = db_run(
            "UPDATE correlation_runs SET state = 'running', started_at = ? WHERE id = ? AND started_at = ?",
            [$stamp, $row['id'], $row['started_at']]
        );

        return ($taken !== null && $taken->rowCount() === 1) ? (int) $row['id'] : null;
    }

    /** Works the run out and stores it, in one transaction. */
    function corr_run(int $userId, int $runId, DateTimeImmutable $now): void
    {
        $started = hrtime(true);
        $cfg     = corr_config();
        $frame   = corr_frame($userId, $now);
        $result  = corr_evaluate($frame, $cfg, $userId);
        $ms      = (int) round((hrtime(true) - $started) / 1e6);
        $stamp   = $now->format('Y-m-d H:i:s');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_run('DELETE FROM user_correlations WHERE run_id = ?', [$runId]);
            foreach ($result['pairs'] as $code => $r) {
                db_run(
                    'INSERT INTO user_correlations (run_id, user_id, pair_code, tier, days, rho, p_value, q_value, effect, detail_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $runId, $userId, $code, $r['tier'], $r['n'],
                        corr_round($r['rho'], 4), corr_round($r['p'], 6), corr_round($r['q'], 6), corr_round($r['effect'], 4),
                        json_encode(corr_detail($r), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    ]
                );
            }
            db_run(
                "UPDATE correlation_runs SET state = 'done', computed_at = ?, engine_version = ?, window_from = ?, window_to = ?,
                        tested = ?, duration_ms = ?, meta_json = ?
                  WHERE id = ?",
                [$stamp, $result['engine_version'], $result['from'], $result['to'], $result['tested'], $ms,
                 json_encode($frame['meta'] ?? new stdClass()), $runId]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        /* Five weeks are kept; older runs go, their rows with them. */
        $keep = $now->modify('-35 days')->format('Y-m-d');
        db_run('DELETE FROM correlation_runs WHERE user_id = ? AND local_date < ?', [$userId, $keep]);
    }

    function corr_round(?float $value, int $decimals): ?float
    {
        return $value === null || !is_finite($value) ? null : round($value, $decimals);
    }

    /** What is kept beside the columns: numbers only, never a record. */
    function corr_detail(array $r): array
    {
        $chart = $r['chart'] === null ? null : [
            'kind' => $r['chart']['kind'],
            'high' => corr_round($r['chart']['high'], 3),
            'low'  => corr_round($r['chart']['low'], 3),
        ];

        return [
            'available'   => (bool) $r['available'],
            'tested'      => (bool) $r['tested'],
            'n_high'      => (int) $r['n_high'],
            'n_low'       => (int) $r['n_low'],
            'n_eff'       => corr_round($r['n_eff'] ?? null, 1),
            'first'       => $r['first'] ?? null,
            'stable'      => $r['stable'],
            'lo'          => corr_round($r['lo'], 4),
            'hi'          => corr_round($r['hi'], 4),
            'threshold'   => corr_round($r['threshold'], 4),
            'chart'       => $chart,
            'need'        => $r['need'],
            'sign'        => (int) $r['sign'],
            'concordance' => $r['concordance'],
        ];
    }

    /**
     * The newest finished run, in the shape corr_evaluate() gives.
     *
     * @return ?array{date: string, from: string, to: string, tested: int, meta: array, pairs: array<string, array>}
     */
    function corr_stored(int $userId): ?array
    {
        $run = db_one(
            'SELECT id, local_date, window_from, window_to, tested, meta_json FROM correlation_runs
              WHERE user_id = ? AND computed_at IS NOT NULL
           ORDER BY local_date DESC LIMIT 1',
            [$userId]
        );
        if ($run === null) {
            return null;
        }

        $pairs = [];
        foreach (db_all('SELECT pair_code, tier, days, rho, p_value, q_value, effect, detail_json FROM user_correlations WHERE run_id = ?',
            [$run['id']]) as $row) {
            $detail = json_decode((string) $row['detail_json'], true);
            $pairs[(string) $row['pair_code']] = (is_array($detail) ? $detail : []) + [
                'tier'   => (string) $row['tier'],
                'n'      => (int) $row['days'],
                'rho'    => $row['rho'] === null ? null : (float) $row['rho'],
                'p'      => $row['p_value'] === null ? null : (float) $row['p_value'],
                'q'      => $row['q_value'] === null ? null : (float) $row['q_value'],
                'effect' => $row['effect'] === null ? null : (float) $row['effect'],
            ];
        }
        /* Only the pairs the config still has, in its order. */
        $ordered = [];
        foreach (array_keys((array) corr_config()['pairs']) as $code) {
            if (isset($pairs[$code])) {
                $ordered[$code] = $pairs[$code];
            }
        }
        $meta = json_decode((string) ($run['meta_json'] ?? ''), true);

        return [
            'date'   => (string) $run['local_date'],
            'from'   => (string) $run['window_from'],
            'to'     => (string) $run['window_to'],
            'tested' => (int) $run['tested'],
            'meta'   => is_array($meta) ? $meta : [],
            'pairs'  => $ordered,
        ];
    }
}
