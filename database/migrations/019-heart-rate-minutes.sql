-- ============================================================================
--  019 — heart rate through the day, minute by minute
-- ----------------------------------------------------------------------------
--  The Training page draws heart rate over a whole day (00:00 to 23:59),
--  over the last week day by day, and over a training session; and its
--  history over 7 dagen to 1 jaar as each day's average (docs/TRAINING.md).
--  Health Connect sends heart rate as records of samples. Until now one
--  average per record was kept, for the sleep card's "Hartslag in slaap"
--  (health_metrics, sleeping_hr); that stays exactly as it was.
--
--  heart_rate_minutes   one row per person, minute and app: the mean of the
--                       samples that app recorded in that minute, and how
--                       many there were. On the clock the samples were
--                       recorded on, as every time in Ownify is. Written
--                       again, unchanged, whenever the same record is synced
--                       again; removed with the account.
--
--  Nothing that is calculated changes: the scores, the points and the sleep
--  card read what they always read.
--
--  Until this is imported the Training page's heart-rate chart is empty, and
--  a sync stores what it always stored. After it, the next sync of an app
--  that sends the day's heart rate (Ownify for Android from 13.0) fills in
--  the last seven days.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `heart_rate_minutes` (
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `minute_at`   DATETIME NOT NULL COMMENT 'The minute, on the clock the samples were recorded on',
    `data_origin` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'The app that recorded it (package name), or source:<id>',
    `bpm`         DECIMAL(5,1) UNSIGNED NOT NULL COMMENT 'Mean of the samples in this minute',
    `samples`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`user_id`, `minute_at`, `data_origin`),
    CONSTRAINT `fk_hrm_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
