-- ESBL / Carbapenemase conditional testing -- gap fixes
-- ----------------------------------------------------------------------------
-- Builds on 2026-09-08_esbl_carbapenemase.sql and
-- 2026-09-08_esbl_carbapenemase_pdf_alignment.sql. Additive only: no table is
-- dropped, no row is deleted, and every existing value stays valid.
--
-- 1. bacteriology_resistance_table.cpe_agent (NEW, nullable)
--    The carbapenem disc the confirmatory test was set up with -- the
--    "Antibiotic" field of the Carbapenems Test (IMI / EPT / MER, the
--    ms_ast_table codes). Validated against that list on save.
--
-- 2. bacteriology_resistance_table.carbapenemase_result  varchar(30) -> varchar(60)
--    The final result for a Positive mCIM is now the eCIM outcome itself
--    ("Metallo-β-lactamase (MBL) detected", 34 characters), which does not fit
--    varchar(30). The server runs without strict mode, so the value would be
--    truncated silently rather than rejected -- hence widening, not trimming.
--    Widening never alters an existing value.
--
-- 3. ms_esbl_agent_table.antimicrobial_agent -- naming aligned to the master
--    The ESBL table spelled the clavulanate discs "Cefotaxime/Clavulanic acid"
--    and "Ceftazidime/Clavulanic acid", while ms_ast_table (the antibiotic
--    master) and the requirement use "Cefotaxime-clavulanate" and
--    "Ceftazidime-clavulanate". Codes, disc content (30/10 µg), pairing and the
--    >= 5 mm rule are unchanged; only the printed name follows the master.
--
-- No master rows are inserted: CAZCV and CTXCV already exist in ms_ast_table
-- (status 'ESBL', 30/10 µg) and in ms_esbl_agent_table (rule_mm = 5).
--
-- Safe to re-run.

ALTER TABLE `bacteriology_resistance_table`
    ADD COLUMN IF NOT EXISTS `cpe_agent` varchar(10) DEFAULT NULL AFTER `cpe_trigger_agents`,
    MODIFY `carbapenemase_result` varchar(60) DEFAULT NULL;

UPDATE `ms_esbl_agent_table` SET `antimicrobial_agent` = 'Cefotaxime-clavulanate'  WHERE `code` = 'CTXCV';
UPDATE `ms_esbl_agent_table` SET `antimicrobial_agent` = 'Ceftazidime-clavulanate' WHERE `code` = 'CAZCV';

-- ----------------------------------------------------------------------------
-- ROLLBACK
--
-- carbapenemase_result can only be narrowed back once no stored value exceeds
-- 30 characters:
--   SELECT brt2_id, carbapenemase_result FROM bacteriology_resistance_table
--    WHERE CHAR_LENGTH(carbapenemase_result) > 30;
--
-- ALTER TABLE `bacteriology_resistance_table`
--     DROP COLUMN `cpe_agent`,
--     MODIFY `carbapenemase_result` varchar(30) DEFAULT NULL;
-- UPDATE `ms_esbl_agent_table` SET `antimicrobial_agent` = 'Cefotaxime/Clavulanic acid'  WHERE `code` = 'CTXCV';
-- UPDATE `ms_esbl_agent_table` SET `antimicrobial_agent` = 'Ceftazidime/Clavulanic acid' WHERE `code` = 'CAZCV';
