-- ============================================================================
--  018 — a night's sleep stages, period by period
-- ----------------------------------------------------------------------------
--  The Slaap page draws the night as a timeline: Wakker, Rusteloosheid, REM,
--  Licht and Diep as rows, from the moment the person went to bed on the left
--  to the moment they got up on the right, each period of a stage a block on
--  its row (docs/SLEEP.md). Health Connect sends those periods with every
--  sleep session already; until now only their minutes per stage were kept
--  (sleep_sessions.light_minutes, deep_minutes, rem_minutes, awake_minutes).
--
--  sleep_stages   one row per period of a stage, as the device recorded it:
--                 its session, its stage in Health Connect's numbering, and
--                 when it began and ended (the session's own clock, as
--                 sleep_sessions.started_at). Replaced as a whole whenever
--                 the session is synced again; removed with its session.
--
--  Nothing that is calculated changes: the minutes per stage, the night, the
--  sleep score and the points are worked out exactly as before. This only
--  keeps the periods the minutes were added up from.
--
--  Until this is imported the Slaap page shows each night's bedtime and wake
--  time on the timeline without its stages, and a sync stores what it always
--  stored. After it, the next sync fills in the stages of the last seven
--  nights (the app sends the last seven days each time).
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `sleep_stages` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sleep_session_id` BIGINT UNSIGNED NOT NULL,
    `stage`            TINYINT UNSIGNED NOT NULL
                       COMMENT 'Health Connect: 1 awake, 2 sleeping, 3 out of bed, 4 light, 5 deep, 6 REM, 7 awake in bed',
    `started_at`       DATETIME NOT NULL,
    `ended_at`         DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_stage_session` (`sleep_session_id`, `started_at`),
    CONSTRAINT `fk_stage_session` FOREIGN KEY (`sleep_session_id`)
        REFERENCES `sleep_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
