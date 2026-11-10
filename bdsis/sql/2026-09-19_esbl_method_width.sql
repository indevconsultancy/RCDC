-- ---------------------------------------------------------------------------
-- ESBL confirmatory method: room for the value the form actually offers
-- ---------------------------------------------------------------------------
-- bacteriology_esbl_table.esbl_method and ecoli_esbl_table.esbl_method are
-- varchar(20). One of the three methods the form offers -- "Combination Disk
-- Test" -- is 21 characters, and sql_mode is not strict, so every save of that
-- method has been silently truncated to "Combination Disk Tes".
--
-- The consequences are not cosmetic. On reopening such a record the Method
-- select finds no option matching the stored text and falls back to its
-- placeholder, so the reading appears unrecorded; and because the save
-- validates the posted method against the same option list, pressing Update on
-- that record is refused with "choose the method for ...". The record becomes
-- impossible to re-save without re-entering a value that was never wrong.
--
-- Widening the column is additive: no value is lost, nothing else changes, and
-- the rows already truncated are repaired to the value they were meant to hold.
-- 30 leaves room for the longest method in bdsis_esbl_methods() plus headroom.
-- ---------------------------------------------------------------------------

ALTER TABLE `bacteriology_esbl_table` MODIFY `esbl_method` varchar(30) NOT NULL;
ALTER TABLE `ecoli_esbl_table`        MODIFY `esbl_method` varchar(30) NOT NULL;

UPDATE `bacteriology_esbl_table` SET `esbl_method` = 'Combination Disk Test'
 WHERE `esbl_method` = 'Combination Disk Tes';
UPDATE `ecoli_esbl_table`        SET `esbl_method` = 'Combination Disk Test'
 WHERE `esbl_method` = 'Combination Disk Tes';
