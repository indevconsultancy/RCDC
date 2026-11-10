-- Records -> Case Management: the four sample personnel fields become free text
-- ----------------------------------------------------------------------------
-- "Sample Collected By", "Sample Shipped to RCDC By", "Sample Received By" and
-- "Sample Processed By" were dropdowns of BDSIS usernames. They are now typed
-- in by hand, so the stored value is no longer guaranteed to be a username.
--
-- Each of those four columns carries a FOREIGN KEY to
-- commondb.user_profile_table(username), which rejects any value that is not an
-- existing username -- verified against this database: a free-text UPDATE on
-- every one of them fails with errno 1452. Those four constraints are therefore
-- dropped. Nothing else changes: the columns keep their type, their nullability
-- and their indexes, and no data is touched, so existing records are unaffected
-- and continue to hold usernames.
--
-- The application resolves a stored value through bdsis_processed_by_label(),
-- which looks it up in user_profile_table and falls back to the raw text, so
-- historical rows still display the staff member's full name.
--
-- Safe to re-run: each DROP is guarded by IF EXISTS (MariaDB 10.4+).

ALTER TABLE `sample_information_table`
    DROP FOREIGN KEY IF EXISTS `sample_information_table_ibfk_2`,   -- collected_by
    DROP FOREIGN KEY IF EXISTS `sample_information_table_ibfk_3`,   -- shipment_by
    DROP FOREIGN KEY IF EXISTS `sample_information_table_ibfk_4`;   -- receive_by

ALTER TABLE `microscopy_result_table`
    DROP FOREIGN KEY IF EXISTS `microscopy_result_table_ibfk_2`;    -- processed_by

-- ----------------------------------------------------------------------------
-- ROLLBACK
--
-- Only valid while every stored value still resolves to a username. Once a
-- free-text name has been saved, re-adding a constraint fails until those rows
-- are corrected; this query lists them:
--
--   SELECT 'collected_by' col, subject_id, collected_by v FROM sample_information_table s
--    WHERE collected_by IS NOT NULL
--      AND NOT EXISTS (SELECT 1 FROM commondb.user_profile_table u WHERE u.username = s.collected_by)
--   UNION ALL SELECT 'shipment_by', subject_id, shipment_by FROM sample_information_table s
--    WHERE shipment_by IS NOT NULL
--      AND NOT EXISTS (SELECT 1 FROM commondb.user_profile_table u WHERE u.username = s.shipment_by)
--   UNION ALL SELECT 'receive_by', subject_id, receive_by FROM sample_information_table s
--    WHERE receive_by IS NOT NULL
--      AND NOT EXISTS (SELECT 1 FROM commondb.user_profile_table u WHERE u.username = s.receive_by)
--   UNION ALL SELECT 'processed_by', subject_id, processed_by FROM microscopy_result_table m
--    WHERE processed_by IS NOT NULL
--      AND NOT EXISTS (SELECT 1 FROM commondb.user_profile_table u WHERE u.username = m.processed_by);
--
-- ALTER TABLE `sample_information_table`
--     ADD CONSTRAINT `sample_information_table_ibfk_2` FOREIGN KEY (`collected_by`)
--         REFERENCES `commondb`.`user_profile_table` (`username`) ON UPDATE CASCADE,
--     ADD CONSTRAINT `sample_information_table_ibfk_3` FOREIGN KEY (`shipment_by`)
--         REFERENCES `commondb`.`user_profile_table` (`username`) ON UPDATE CASCADE,
--     ADD CONSTRAINT `sample_information_table_ibfk_4` FOREIGN KEY (`receive_by`)
--         REFERENCES `commondb`.`user_profile_table` (`username`) ON UPDATE CASCADE;
--
-- ALTER TABLE `microscopy_result_table`
--     ADD CONSTRAINT `microscopy_result_table_ibfk_2` FOREIGN KEY (`processed_by`)
--         REFERENCES `commondb`.`user_profile_table` (`username`) ON UPDATE CASCADE;
