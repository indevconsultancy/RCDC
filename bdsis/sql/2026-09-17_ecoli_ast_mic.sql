-- E. coli AST -- Minimum Inhibitory Concentration (MIC) result entries
-- ----------------------------------------------------------------------------
-- MIC entries are stored in the EXISTING E. coli AST table, as rows with
-- ast_method_id = '02' (the value the form's "MIC" option already posts), so
-- everything keyed off that table keeps working unchanged for them: the saved
-- method is re-selected on reopen, Processed by / on (rcdc_ecoli_pcr_ast_table1,
-- whose FK points at this table) is kept, the section-exists checks, the Delete
-- action, the Group Report and the Excel export.
--
-- Column use for an MIC row:
--   antimicrobial_code  the antibiotic, from the same master list the Disk
--                       Diffusion panel uses (ms_ast_table, method 01, active)
--   zone_diameter       the MIC value as entered, e.g. "<=0.5", ">64"
--                       (varchar(10) -- already text, no change needed)
--   ast_result          the interpretation: Susceptible / Intermediate /
--                       Resistant (the words Disk Diffusion rows already use,
--                       so every report reads both alike), or SDD / N/A
--   entry_uuid          NEW -- the entry's stable id (UUID v4), kept across
--                       saves; NULL for every Disk Diffusion row
--
-- Additive only: one nullable column and its index. No existing row changes.
-- Safe to re-run.

ALTER TABLE `rcdc_ecoli_pcr_ast_table`
    ADD COLUMN IF NOT EXISTS `entry_uuid` char(36) DEFAULT NULL AFTER `ast_result`,
    ADD UNIQUE INDEX IF NOT EXISTS `entry_uuid` (`entry_uuid`);

-- ----------------------------------------------------------------------------
-- ROLLBACK (removes MIC entry ids only; MIC rows themselves stay as method 02):
--
-- ALTER TABLE `rcdc_ecoli_pcr_ast_table`
--     DROP INDEX `entry_uuid`,
--     DROP COLUMN `entry_uuid`;
