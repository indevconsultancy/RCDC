-- =============================================================================
-- 2026-10-01 — AmpC β-Lactamase Test (Bacteriology)
-- -----------------------------------------------------------------------------
-- Adds the AmpC phenotype confirmatory panel storage to the Bacteriology
-- resistance workflow. Mirrors the existing bacteriology_resistance_table +
-- bacteriology_esbl_table split so AmpC can live alongside ESBL and CPE
-- without disturbing either.
--
-- Trigger: the AmpC panel auto-opens inside a pathogen row's AST block when
-- CTX, CRO AND CAZ are ALL Resistant on the routine Disk Diffusion panel.
-- Save path: lab/iu-lab.php -> includes/lab-ampc.php (bdsis_ampc_save).
-- Load path: includes/lab-ampc.php -> bdsis_bact_load_resistance merges it in.
--
-- Code tolerates the migration not being run yet (bdsis_ampc_table_exists()
-- probe), so deploying the PHP before the SQL is safe -- the panel renders
-- and computes interpretations client-side but save becomes a no-op until
-- this migration runs.
--
-- Rollback:
--   DROP TABLE IF EXISTS bacteriology_ampc_table;
--   ALTER TABLE bacteriology_resistance_table
--     DROP COLUMN ampc_triggered,
--     DROP COLUMN ampc_trigger_agents,
--     DROP COLUMN ampc_result;
-- =============================================================================

ALTER TABLE bacteriology_resistance_table
  ADD COLUMN ampc_triggered       TINYINT(1)   NOT NULL DEFAULT 0  AFTER carbapenemase_result,
  ADD COLUMN ampc_trigger_agents  VARCHAR(100) NULL                AFTER ampc_triggered,
  ADD COLUMN ampc_result          VARCHAR(50)  NULL                AFTER ampc_trigger_agents;

CREATE TABLE IF NOT EXISTS bacteriology_ampc_table (
  id            INT(11)       NOT NULL AUTO_INCREMENT,
  brt2_id       INT(11)       NOT NULL,
  agent_code    VARCHAR(15)   NOT NULL,
  ampc_method   VARCHAR(60)   NULL,
  zone_diameter DECIMAL(6,2)  NULL,
  agent_result  VARCHAR(50)   NULL,
  PRIMARY KEY (id),
  KEY idx_brt2 (brt2_id),
  KEY idx_brt2_agent (brt2_id, agent_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
