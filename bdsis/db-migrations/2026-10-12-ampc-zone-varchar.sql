-- Widen bacteriology_ampc_table.zone_diameter from DECIMAL(6,2) to
-- VARCHAR(20) so clinical MIC notation with comparator prefixes
-- ('<=0.5', '>16', '>=2', '<0.25') persists verbatim alongside plain
-- numeric values. Existing decimal rows convert to their string form
-- (10.50 -> "10.50"); the interpretation path in lab-ampc.php extracts
-- the first numeric token from either representation so S/R math is
-- unchanged.
--
-- Idempotent in two senses: paired with bdsis_ampc_zone_varchar_ensure()
-- in includes/lab-ampc.php which runs the same ALTER on first save when
-- the DB account has ALTER TABLE privilege, and the MODIFY COLUMN is a
-- no-op when the column is already VARCHAR.
--
-- Rollback (data-lossy for comparator strings):
--   ALTER TABLE bacteriology_ampc_table
--     MODIFY COLUMN zone_diameter DECIMAL(6,2) NULL;

ALTER TABLE bacteriology_ampc_table
  MODIFY COLUMN zone_diameter VARCHAR(20) NULL;
