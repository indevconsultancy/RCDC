-- =============================================================================
-- 2026-10-05 — Carba NP persistence columns on bacteriology_resistance_table.
-- -----------------------------------------------------------------------------
-- Adds the two tube-colour columns the Carba NP Decision Engine stores.
-- The computed interpretation (Positive, carbapenemase producer / Negative,
-- no carbapenemase detected / Invalid) is written into the existing mcim_result
-- column; `carbapenemase_result` carries the final-displayed result as it does
-- today for mCIM / PCR / E-test, so reports and exports don't need a new
-- vocabulary.
--
-- Idempotent (ALGORITHM=INPLACE on compatible MySQL). Safe to apply on a
-- running schema; the PHP save/load path probes column existence via
-- bdsis_carba_np_columns_exist() and skips the new columns gracefully until
-- this migration is applied -- so re-deploying the PHP ahead of this SQL
-- won't break any save.
--
-- Rollback:
--   ALTER TABLE bacteriology_resistance_table
--     DROP COLUMN carba_np_tube_a,
--     DROP COLUMN carba_np_tube_b;
-- =============================================================================

ALTER TABLE bacteriology_resistance_table
    ADD COLUMN carba_np_tube_a VARCHAR(60) NULL AFTER mcim_colonies,
    ADD COLUMN carba_np_tube_b VARCHAR(60) NULL AFTER carba_np_tube_a;
