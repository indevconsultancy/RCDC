-- ---------------------------------------------------------------------------
-- Rollback for 2026-07-21-enable-bumthang-facility.sql
--
-- Removes Wangdicholing Hospital (Bumthang) from the Health Facility dropdown.
--
-- WARNING: do NOT run this if any case has already been recorded against this
-- facility -- the facility would disappear from the dropdown while its cases
-- remain, leaving those records pointing at a facility users can no longer see
-- or select. Check first:
--
--   SELECT COUNT(*) FROM `bdsis-up`.subject_information_table WHERE center_id='001';
--   SELECT COUNT(*) FROM `bdsis-up`.subject_information_table WHERE subject_id LIKE 'BUM%';
-- ---------------------------------------------------------------------------

UPDATE `commondb`.`ms_health_center_table`
   SET `dsis` = 0
 WHERE `center_id` = '001'
   AND `center_code` = 'BUM'
   AND `dzongkhag_code` = '01';
