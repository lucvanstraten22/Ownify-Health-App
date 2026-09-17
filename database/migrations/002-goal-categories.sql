-- ============================================================================
--  002 — goal categories match the product's vocabulary
-- ----------------------------------------------------------------------------
--  The column was created with five broad domains while the interface offers
--  eight categories, each with its own icon and accent (config/goals.php).
--  Storing eight values in a five-value enum loses the distinction, so the
--  column is widened rather than the categories being folded together.
--
--  The five original values are kept alongside the new ones so existing rows
--  stay valid and nothing has to be rewritten; 'general' remains the default.
-- ============================================================================
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
-- ----------------------------------------------------------------------------
--  The database is whichever one you have selected. On shared hosting the
--  name is not ours to choose — Hestia prefixes it with the account, so it is
--  `luc_healthapp` there and something else on the next server. Naming one
--  here would make this file work in exactly one place.
--
--  phpMyAdmin:  select the database in the sidebar FIRST, then Import.
--  Command line: name it as an argument, e.g.
--      mysql -u USER -p DATABASE < database/migrations/002-goal-categories.sql
-- ----------------------------------------------------------------------------


ALTER TABLE `goals`
    MODIFY COLUMN `category` ENUM(
        -- what the interface offers
        'health','weight','strength','activity','nutrition','habit','performance','other',
        -- what the column held before, kept so old rows remain readable
        'sleep','training','body','general'
    ) NOT NULL DEFAULT 'other';
