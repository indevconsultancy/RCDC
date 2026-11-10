-- =============================================================================
-- Rollback of db-migrations/2026-10-16-ampc-owner-scope.sql
-- -----------------------------------------------------------------------------
-- DESTRUCTIVE where E. coli AmpC results have been saved: dropping `owner`
-- merges the two id spaces back into one, so an E. coli pathotype's agent rows
-- become indistinguishable from the bacteriology isolate of the same id. Clear
-- the E. coli rows first if that matters:
--
--   DELETE FROM `bacteriology_ampc_table` WHERE `owner` = 'ecoli';
-- =============================================================================

ALTER TABLE `bacteriology_ampc_table`
  DROP KEY `idx_owner_brt2`,
  DROP COLUMN `owner`;
