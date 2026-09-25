-- ============================================================================
--  011 — who may send you a friend request
-- ----------------------------------------------------------------------------
--  Friendships and friend requests already have their table: `friendships`,
--  one row per pair (see schema.sql). A request is a row with status
--  `pending` and the sender in `requested_by`; accepting turns it into
--  `accepted`, declining closes it as `declined`, and removing a friend
--  deletes the row. `created_at` is when the request was sent and
--  `responded_at` when it was answered.
--
--  What was missing is the person's own say in it: "Vriendverzoeken
--  toestaan". Off, nobody can send them a new request. Friends they already
--  have, and requests already waiting for them, are not affected.
--
--  Until this is imported the app runs as before: everybody accepts
--  requests, and the switch says it cannot be saved yet.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Command line: mysql -u USER -p DATABASE < this file
--  Re-running it is safe.
-- ============================================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
        AND column_name = 'allow_friend_requests') > 0,
    'SELECT ''user_profiles.allow_friend_requests already there'' AS note',
    'ALTER TABLE `user_profiles`
        ADD COLUMN `allow_friend_requests` TINYINT(1) NOT NULL DEFAULT 1
            COMMENT ''Vriendverzoeken toestaan: 0 = nobody can send this account a new request''
            AFTER `locale`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
