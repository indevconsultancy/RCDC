-- ---------------------------------------------------------------------------
-- Bacteriology Result: "Processed by" changed from a staff dropdown to free text.
--
-- bacteriology_result_table2.processed_by carried a foreign key to
-- commondb.user_profile_table(username). A free-text field cannot satisfy that
-- constraint, so it is dropped. The column keeps its varchar(50) type and every
-- existing value is left untouched -- legacy rows still hold usernames and are
-- resolved for display by bdsis_processed_by_label() in includes/lab-helpers.php.
--
-- recorded_by keeps its foreign key: it is still written from $_SESSION["users"]
-- and remains a genuine username.
--
-- Rollback: 2026-07-21-processed-by-freetext.rollback.sql
-- ---------------------------------------------------------------------------

ALTER TABLE `bacteriology_result_table2`
    DROP FOREIGN KEY `bacteriology_result_table2_ibfk_4`;
