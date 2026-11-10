-- ---------------------------------------------------------------------------
-- Rollback for 2026-09-28-enable-district-hospitals.sql
--
-- WARNING: do NOT run this if any case has already been recorded against any
-- of the eight facilities. Disabling (dsis = 0) a centre with existing cases
-- leaves those records pointing at a facility users can no longer see or
-- select in the Health Facility dropdown. Check first with:
--
--   SELECT h.center_id, h.center_code, h.center_name, d.dzongkhag_name,
--          (SELECT COUNT(*) FROM `rcdc_bdsis`.subject_information_table s
--                            WHERE s.center_id = h.center_id) AS bdsis_case_cnt
--     FROM `commondb`.ms_health_center_table h
--     JOIN `commondb`.ms_dzongkhag_table    d ON d.dzongkhag_code = h.dzongkhag_code
--    WHERE d.dzongkhag_name IN
--          ('Bumthang','Gasa','Dagana','Chhukha',
--           'Haa','Pemagatsel','Trashiyangtse','Zhemgang')
--      AND h.dsis = 1;
--
-- Any bdsis_case_cnt > 0 must be reassigned or archived before the rollback
-- runs. If in doubt, do not roll back.
--
-- This rollback ALSO refuses to touch a centre whose Bumthang enable was
-- separately shipped (2026-07-21-enable-bumthang-facility.sql): it filters
-- out center_id='001' AND center_code='BUM' so Bumthang's earlier migration
-- keeps its own rollback path.
-- ---------------------------------------------------------------------------

UPDATE `commondb`.ms_health_center_table h
   JOIN `commondb`.ms_dzongkhag_table    d ON d.dzongkhag_code = h.dzongkhag_code
    SET h.dsis = 0
  WHERE d.dzongkhag_name IN
        ('Bumthang','Gasa','Dagana','Chhukha',
         'Haa','Pemagatsel','Trashiyangtse','Zhemgang')
    AND h.status = 1
    AND h.center_code <> ''
    AND h.center_type IN (1, 2)
    AND h.dsis = 1
    AND NOT (h.center_id = '001' AND h.center_code = 'BUM');
