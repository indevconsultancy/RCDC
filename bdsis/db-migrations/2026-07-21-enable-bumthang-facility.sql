-- ---------------------------------------------------------------------------
-- Record Management > New Case: add Bumthang to the Health Facility dropdown.
--
-- The Health Facility dropdown is not a hardcoded list -- it is driven by
-- commondb.ms_health_center_table filtered on dsis=1 (see load-facility.php and
-- the $bdsis_Site / center queries in records/case.php and lab/lab.php). Every
-- one of the 12 currently-enabled facilities is a hospital (center_type 1 or 2)
-- with status=1 and a unique 3-letter center_code that is reused as the
-- subject-ID prefix (PUN-18-001, SAM-20-015, BAJ-18-0035, ...).
--
-- Bumthang dzongkhag (code 01) already has exactly one hospital in the master:
-- Wangdicholing Hospital, center_id 001, center_code 'BUM', center_type 2,
-- status 1. So enabling Bumthang is a master-data flag flip, NOT a new row --
-- no schema change, no new reference data, nothing else touched.
--
-- Verified before applying:
--   * no subject_id currently uses the 'BUM' prefix (0 rows), so the new
--     facility cannot collide with existing case numbering
--   * 'BUM' is held by this center only (1 row), so the prefix is unambiguous
--
-- Rollback: 2026-07-21-enable-bumthang-facility.rollback.sql
-- ---------------------------------------------------------------------------

UPDATE `commondb`.`ms_health_center_table`
   SET `dsis` = 1
 WHERE `center_id` = '001'
   AND `center_code` = 'BUM'
   AND `dzongkhag_code` = '01';
