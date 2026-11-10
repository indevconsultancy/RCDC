-- ---------------------------------------------------------------------------
-- Rollback for 2026-07-21-rotavirus-genotype.sql
--
-- Drops the genotype column from rcdc_elisa_result_table2 and removes the
-- ms_rotavirus_genotype_table master.
--
-- WARNING: this is DESTRUCTIVE of captured data. Any rotavirus genotype
-- recorded since the migration is lost -- the value lives only in this column
-- and is not duplicated anywhere else. Check what would be discarded first:
--
--   SELECT e.subject_id, g.genotype
--   FROM `bdsis-up`.rcdc_elisa_result_table2 e
--   JOIN `bdsis-up`.ms_rotavirus_genotype_table g ON g.genotype_id = e.genotype_id
--   WHERE e.genotype_id IS NOT NULL;
--
-- If that returns rows, export them before running this.
--
-- Preconditions:
--   * lab/lab.php, lab/iu-lab.php, reports/report-view.php and
--     reports/report-html-builder.php must be reverted FIRST -- they SELECT
--     genotype_id and query ms_rotavirus_genotype_table, and will fatal once
--     the column and table are gone.
--   * The DROP TABLE will fail while the foreign key still exists, so the
--     statements below must run in the order given.
-- ---------------------------------------------------------------------------

ALTER TABLE `rcdc_elisa_result_table2`
    DROP FOREIGN KEY `rcdc_elisa_result_table2_ibfk_5`;

ALTER TABLE `rcdc_elisa_result_table2`
    DROP COLUMN `genotype_id`;

DROP TABLE `ms_rotavirus_genotype_table`;
