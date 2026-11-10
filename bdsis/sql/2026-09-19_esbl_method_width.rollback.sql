-- Rollback for 2026-09-19_esbl_method_width.sql.
-- Re-truncates the repaired rows first so the narrower column can accept them.
UPDATE `bacteriology_esbl_table` SET `esbl_method` = 'Combination Disk Tes'
 WHERE `esbl_method` = 'Combination Disk Test';
UPDATE `ecoli_esbl_table`        SET `esbl_method` = 'Combination Disk Tes'
 WHERE `esbl_method` = 'Combination Disk Test';
ALTER TABLE `bacteriology_esbl_table` MODIFY `esbl_method` varchar(20) NOT NULL;
ALTER TABLE `ecoli_esbl_table`        MODIFY `esbl_method` varchar(20) NOT NULL;
