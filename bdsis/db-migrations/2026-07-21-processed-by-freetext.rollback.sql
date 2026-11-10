-- ---------------------------------------------------------------------------
-- Rollback for 2026-07-21-processed-by-freetext.sql
--
-- WARNING: this will fail if any bacteriology_result_table2.processed_by value
-- entered after the migration is not a valid commondb.user_profile_table
-- username. Find offending rows first with:
--
--   SELECT DISTINCT b.processed_by
--   FROM `bdsis-up`.bacteriology_result_table2 b
--   LEFT JOIN commondb.user_profile_table u ON u.username = b.processed_by
--   WHERE b.processed_by IS NOT NULL AND u.username IS NULL;
-- ---------------------------------------------------------------------------

ALTER TABLE `bacteriology_result_table2`
    ADD CONSTRAINT `bacteriology_result_table2_ibfk_4`
    FOREIGN KEY (`processed_by`) REFERENCES `commondb`.`user_profile_table` (`username`)
    ON UPDATE CASCADE;
