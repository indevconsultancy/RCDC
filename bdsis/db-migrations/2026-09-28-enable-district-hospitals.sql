-- ---------------------------------------------------------------------------
-- Record Management > New Case: enable the district hospitals for the eight
-- dzongkhags below in the Health Facility dropdown.
--
--   Bumthang, Gasa, Dagana, Chhukha, Haa, Pemagatsel, Trashiyangtse, Zhemgang
--
-- Same convention as 2026-07-21-enable-bumthang-facility.sql: the Health
-- Facility dropdown is not hardcoded, it is driven by
-- commondb.ms_health_center_table filtered on dsis = 1 (see records/case.php
-- and includes/rbac.php::bdsis_case_centres). Every row this migration
-- touches already exists in the master with status = 1 -- this is a FLAG FLIP,
-- not a schema change and not a new-row insert.
--
-- The WHERE clause is defensive:
--   * dzongkhag_name             -- restricts to the eight target districts
--   * status = 1                 -- only active master rows
--   * center_code <> ''          -- non-empty subject-ID prefix (dropdown rule)
--   * center_type IN (1, 2)      -- 1/2 = hospital (per bumthang commentary)
--   * dsis = 0                   -- IDEMPOTENT: only rows not already enabled
--
-- The last predicate makes the migration safe to run twice: an already-enabled
-- facility is a no-op, the affected-rows count on a re-run is 0.
--
-- Verified BEFORE applying (run the diagnostic sibling file first):
--   2026-09-28-check-health-facilities.sql
--
-- Verified WHILE applying, per dzongkhag, that:
--   * no subject_id currently uses each hospital's center_code prefix that
--     collides with existing case numbering (a fresh BDSIS install already
--     runs this check via bdsis_next_subject_id / retry-on-PK-conflict)
--   * center_code is unique to that centre (avoids ambiguous prefixes)
--
-- If ANY hospital's center_type is neither 1 nor 2 in your master, the row
-- will be left out on purpose -- re-run the diagnostic to inspect, then relax
-- the center_type predicate below if a BHU or similar is intended.
--
-- Rollback: 2026-09-28-enable-district-hospitals.rollback.sql
-- ---------------------------------------------------------------------------

UPDATE `commondb`.ms_health_center_table h
   JOIN `commondb`.ms_dzongkhag_table    d ON d.dzongkhag_code = h.dzongkhag_code
    SET h.dsis = 1
  WHERE d.dzongkhag_name IN
        ('Bumthang','Gasa','Dagana','Chhukha',
         'Haa','Pemagatsel','Trashiyangtse','Zhemgang')
    AND h.status = 1
    AND h.center_code <> ''
    AND h.center_type IN (1, 2)
    AND h.dsis = 0;

-- ---------------------------------------------------------------------------
-- What just happened? Re-run the diagnostic to confirm every target hospital
-- now shows dsis = 1:
--   SOURCE 2026-09-28-check-health-facilities.sql;
-- ---------------------------------------------------------------------------
