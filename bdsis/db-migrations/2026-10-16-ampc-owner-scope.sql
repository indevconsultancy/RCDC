-- =============================================================================
-- 2026-10-16 — Separate the two id spaces in bacteriology_ampc_table
-- -----------------------------------------------------------------------------
-- The per-agent AmpC rows are keyed by brt2_id alone. Since the E. coli AST
-- panel's MIC method began rendering the same confirmatory cascade as an
-- Enteric Pathogen row, that column carries two unrelated id spaces:
--
--   owner='bact'   brt2_id = bacteriology_result_table2.record_id
--   owner='ecoli'  brt2_id = rcdc_ecoli_pcr_result_table3.record_id
--
-- They collide freely. Before this column, saving AmpC for pathotype record 42
-- deleted and replaced the agent rows of bacteriology isolate 42 — a different
-- subject's result.
--
-- The default 'bact' leaves every row already stored meaning exactly what it
-- meant, so the bacteriology flow is untouched and no backfill is needed.
--
-- includes/lab-ampc.php applies the same ALTER itself on first use
-- (bdsis_ampc_owner_column_ensure), so running this by hand is a no-op there.
-- Rollback: db-migrations/2026-10-16-ampc-owner-scope.rollback.sql
-- =============================================================================

ALTER TABLE `bacteriology_ampc_table`
  ADD COLUMN IF NOT EXISTS `owner` VARCHAR(10) NOT NULL DEFAULT 'bact' AFTER `brt2_id`,
  ADD KEY IF NOT EXISTS `idx_owner_brt2` (`owner`, `brt2_id`);
