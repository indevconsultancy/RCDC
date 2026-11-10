-- =============================================================================
-- 2026-10-17 — Carbapenemase Screening Data Sync & Persistence Migration
-- -----------------------------------------------------------------------------
-- Ensures all required columns for Module A Carbapenemase Screening
-- (EPT, IMI, MER zone/interpretation, screening result, final carbapenemase result)
-- exist across:
--   1. bacteriology_resistance_table
--   2. ecoli_resistance_table
-- =============================================================================

ALTER TABLE `bacteriology_resistance_table`
  ADD COLUMN IF NOT EXISTS `ept_zone_mm` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ept_interpretation` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `imi_zone_mm` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `imi_interpretation` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `mer_zone_mm` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `mer_interpretation` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carbapenemase_screening_result` VARCHAR(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `final_carbapenemase_result` VARCHAR(60) DEFAULT NULL;

ALTER TABLE `ecoli_resistance_table`
  ADD COLUMN IF NOT EXISTS `ept_zone_mm` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ept_interpretation` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `imi_zone_mm` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `imi_interpretation` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `mer_zone_mm` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `mer_interpretation` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `carbapenemase_screening_result` VARCHAR(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `final_carbapenemase_result` VARCHAR(60) DEFAULT NULL;
