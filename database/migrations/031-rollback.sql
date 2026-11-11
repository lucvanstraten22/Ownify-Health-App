-- ============================================================================
--  031 ROLLBACK — more to count in a challenge
-- ----------------------------------------------------------------------------
--  The normal way back is NOT this file: deploy the previous code and keep
--  the schema. Older code reads the column as text and never meets a new key
--  it did not make.
--
--  This file puts the fixed list of five back. A challenge counting anything
--  else is removed first, with its members (they cannot be kept in the old
--  column). Take a backup first.
-- ============================================================================

DELETE FROM `challenges`
 WHERE `metric` NOT IN ('workouts','steps','active_minutes','distance','sleep_nights');

ALTER TABLE `challenges`
    MODIFY COLUMN `metric` ENUM('workouts','steps','active_minutes','distance','sleep_nights') NOT NULL;
