-- Rotavirus genotyping: "UT" (untypeable) selectable in BOTH the G and P groups
-- ----------------------------------------------------------------------------
-- The G type / P type checkboxes are built from ms_rotavirus_genotype_table by
-- bdsis_rotavirus_gp_groups(), which until now decided the group from the label
-- itself: "G..." (without "P[") is a G type, "P..." is a P type. "UT" starts with
-- neither, so a row labelled UT would have been dropped from both lists, and one
-- row could not belong to two groups anyway.
--
-- So the group becomes explicit:
--
--   genotype_group  NEW, nullable. 'G' or 'P' when the row belongs to one of the
--                   group pickers; NULL for every existing row, which keeps being
--                   classified by the label exactly as before (backward
--                   compatible -- no existing row is touched or reclassified).
--
-- Two rows are then added, one per group, because UT in the G column and UT in
-- the P column are separate observations and are stored as separate genotype ids:
--
--   115  UT  G
--   116  UT  P
--
-- Ids 115/116 are free (the master ends at 114). Nothing is renamed, deleted or
-- renumbered, so every stored rcdc_elisa_genotype_table reference stays valid.
--
-- The legacy single "Genotype" dropdown keeps its current 14 options: it is built
-- from bdsis_rotavirus_single_genotypes(), which skips rows carrying a group.
--
-- Safe to re-run.

ALTER TABLE `ms_rotavirus_genotype_table`
    ADD COLUMN IF NOT EXISTS `genotype_group` char(1) DEFAULT NULL COMMENT 'G or P when selectable in that group picker; NULL = classified by label';

INSERT INTO `ms_rotavirus_genotype_table` (`genotype_id`, `genotype`, `status`, `genotype_group`)
VALUES ('115', 'UT', 1, 'G'), ('116', 'UT', 1, 'P')
ON DUPLICATE KEY UPDATE `genotype` = VALUES(`genotype`),
                        `status` = VALUES(`status`),
                        `genotype_group` = VALUES(`genotype_group`);

-- ----------------------------------------------------------------------------
-- ROLLBACK
--
-- Only remove the UT rows once nothing references them:
--   SELECT * FROM rcdc_elisa_genotype_table WHERE genotype_id IN ('115','116');
--
-- DELETE FROM `ms_rotavirus_genotype_table` WHERE `genotype_id` IN ('115','116');
-- ALTER TABLE `ms_rotavirus_genotype_table` DROP COLUMN `genotype_group`;
