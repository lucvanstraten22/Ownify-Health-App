-- ============================================================================
--  009 — no Apple sign-in
-- ----------------------------------------------------------------------------
--  Apple sign-in was never built and is not going to be. This takes 'apple'
--  out of the list of ways to sign in, so the database says what the app does:
--  e-mail and password, or Google.
--
--  Nothing was ever stored under 'apple'. If a row somehow were, this stops
--  with an error instead of changing it.
--
--  Optional: the app works the same with or without it.
-- ----------------------------------------------------------------------------
--  NO `USE` STATEMENT, ON PURPOSE
--  phpMyAdmin: select the database in the sidebar FIRST, then Import.
--  Re-running it is safe.
-- ============================================================================

ALTER TABLE `user_auth_identities`
    MODIFY COLUMN `provider` ENUM('email','google') NOT NULL;
