-- Store the auto-computed Carba NP outputs on bacteriology_resistance_table
-- so edit mode and reports read persisted data rather than re-deriving at
-- display time. carba_np_result mirrors bdsis_carba_np_result (Positive /
-- Negative / Invalid). carba_np_interpretation mirrors bdsis_carba_np_interpret
-- (Carbapenemase producer / No carbapenemase detected / Invalid).
--
-- Idempotent: paired with bdsis_carba_np_result_columns_ensure() in
-- includes/lab-helpers.php, which applies the same ALTER on first page load
-- when the DB account has ALTER TABLE. If you prefer, apply this SQL manually
-- via phpMyAdmin -- the save path then skips the auto-ensure branch.
--
-- Prerequisite: db-migrations/2026-10-05-carba-np-columns.sql has already
-- added carba_np_tube_a / carba_np_tube_b. The AFTER clauses anchor to
-- carba_np_tube_b, so running this before that migration will fail.

ALTER TABLE bacteriology_resistance_table
  ADD COLUMN carba_np_result        VARCHAR(32) NULL AFTER carba_np_tube_b,
  ADD COLUMN carba_np_interpretation VARCHAR(64) NULL AFTER carba_np_result;
