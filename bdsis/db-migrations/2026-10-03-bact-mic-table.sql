-- =============================================================================
-- 2026-10-03 — Bacteriology MIC storage (Phase 1 bug-fix follow-up)
-- -----------------------------------------------------------------------------
-- Phase 1 of the "MIC Document" roll-out shipped the Method dropdown + MIC
-- panel yesterday (2026-10-02) and stored MIC readings in
-- bacteriology_ast_table.zone_diameter. That column is DECIMAL / FLOAT in the
-- Bacteriology schema (unlike rcdc_ecoli_pcr_ast_table where the same column
-- is VARCHAR(10)), so MIC strings with comparator prefixes ("<=0.5", "<=2/38")
-- cannot bind and the whole bacteriology save POST returned "Submission
-- Failed!!".
--
-- This migration creates a dedicated table that MIC rows now live in. The
-- Disk Diffusion flow is byte-identical to before -- it still writes
-- bacteriology_ast_table with ast_method_id='01'. The MIC flow now writes
-- bacteriology_mic_table keyed by brt2_id and antimicrobial_code.
--
-- Code tolerates the migration not being run (bdsis_bact_mic_table_exists()
-- probe), so re-deploying the PHP without first applying this SQL is safe:
-- the panel still renders and interprets client-side; save becomes a silent
-- no-op until the migration is applied.
--
-- Rollback:
--   DROP TABLE IF EXISTS bacteriology_mic_table;
-- =============================================================================

CREATE TABLE IF NOT EXISTS bacteriology_mic_table (
  id                 INT(11)      NOT NULL AUTO_INCREMENT,
  brt2_id            INT(11)      NOT NULL,
  antimicrobial_code VARCHAR(15)  NOT NULL,
  mic_value          VARCHAR(20)  NULL,
  ast_result         VARCHAR(30)  NULL,
  recorded_on        DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_brt2 (brt2_id),
  KEY idx_brt2_code (brt2_id, antimicrobial_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
