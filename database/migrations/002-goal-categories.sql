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
--
--      mysql -u root -p jolu < database/migrations/002-goal-categories.sql
-- ============================================================================

USE `jolu`;

ALTER TABLE `goals`
    MODIFY COLUMN `category` ENUM(
        -- what the interface offers
        'health','weight','strength','activity','nutrition','habit','performance','other',
        -- what the column held before, kept so old rows remain readable
        'sleep','training','body','general'
    ) NOT NULL DEFAULT 'other';
