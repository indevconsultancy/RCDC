-- =============================================================================
-- 2026-10-16 — AmpC (FOX, FEP) and Carba NP Persistence Migration
-- -----------------------------------------------------------------------------
-- Ensures all required columns and tables for AmpC β-lactamase Phenotypic Test
-- (FOX 30 µg, FEP 30 µg, AmpC phenotype result) and Carba NP Test (Tube A,
-- Tube B, System Result, Interpretation) exist across:
--   1. bacteriology_ampc_table
--   2. bacteriology_resistance_table
--   3. ecoli_resistance_table
--   4. rcdc_bacteriology_result_table
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bacteriology_ampc_table` (
  `id`            INT(11)       NOT NULL AUTO_INCREMENT,
  `brt2_id`       INT(11)       NOT NULL,
  `agent_code`    VARCHAR(15)   NOT NULL,
  `ampc_method`   VARCHAR(60)   NULL,
  `zone_diameter` VARCHAR(20)   NULL,
  `agent_result`  VARCHAR(50)   NULL,
  PRIMARY KEY (`id`),
  KEY `idx_brt2` (`brt2_id`),
  KEY `idx_brt2_agent` (`brt2_id`, `agent_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for generic/audit bacteriology results if queried
CREATE TABLE IF NOT EXISTS `rcdc_bacteriology_result_table` (
  `record_id` INT(11) NOT NULL AUTO_INCREMENT,
  `subject_id` VARCHAR(15) DEFAULT NULL,
  `ampc_fox_method` VARCHAR(20) DEFAULT NULL,
  `ampc_fox_val` VARCHAR(20) DEFAULT NULL,
  `ampc_fox_value` VARCHAR(20) DEFAULT NULL,
  `ampc_fox_int` VARCHAR(20) DEFAULT NULL,
  `ampc_fep_method` VARCHAR(20) DEFAULT NULL,
  `ampc_fep_val` VARCHAR(20) DEFAULT NULL,
  `ampc_fep_value` VARCHAR(20) DEFAULT NULL,
  `ampc_fep_int` VARCHAR(20) DEFAULT NULL,
  `ampc_phenotype_result` VARCHAR(100) DEFAULT NULL,
  `carba_tube_a` VARCHAR(50) DEFAULT NULL,
  `carba_tube_b` VARCHAR(50) DEFAULT NULL,
  `carba_system_result` VARCHAR(50) DEFAULT NULL,
  `carba_interpretation` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`record_id`),
  KEY `idx_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- bacteriology_resistance_table columns
ALTER TABLE `bacteriology_resistance_table`
  ADD COLUMN IF NOT EXISTS `ampc_triggered`        TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `ampc_trigger_agents`   VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `ampc_result`           VARCHAR(50)  NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fox_method`       VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fox_val`          VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fox_value`        VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fox_int`          VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fep_method`       VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fep_val`          VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fep_value`        VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fep_int`          VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_phenotype_result` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_np_tube_a`       VARCHAR(60)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_np_tube_b`       VARCHAR(60)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_np_result`       VARCHAR(32)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_np_interpretation` VARCHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_tube_a`          VARCHAR(50)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_tube_b`          VARCHAR(50)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_system_result`   VARCHAR(50)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_interpretation`  VARCHAR(100) DEFAULT NULL;

-- ecoli_resistance_table columns
ALTER TABLE `ecoli_resistance_table`
  ADD COLUMN IF NOT EXISTS `ampc_triggered`        TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `ampc_trigger_agents`   VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `ampc_result`           VARCHAR(50)  NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fox_method`       VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fox_val`          VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fox_value`        VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fox_int`          VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fep_method`       VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fep_val`          VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fep_value`        VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_fep_int`          VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ampc_phenotype_result` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_np_tube_a`       VARCHAR(60)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_np_tube_b`       VARCHAR(60)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_np_result`       VARCHAR(32)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_np_interpretation` VARCHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_tube_a`          VARCHAR(50)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_tube_b`          VARCHAR(50)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_system_result`   VARCHAR(50)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carba_interpretation`  VARCHAR(100) DEFAULT NULL;
