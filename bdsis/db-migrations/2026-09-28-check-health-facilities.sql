-- ---------------------------------------------------------------------------
-- Diagnostic (READ ONLY). Report the current state of every hospital that
-- belongs to the eight target dzongkhags, so the DBA can decide whether the
-- companion enable-migration needs to be run at all.
--
-- Target dzongkhags (client's list, mapped to master):
--   Bumthang, Gasa, Dagana, Chhukha, Haa, Pemagatsel, Trashiyangtse, Zhemgang
--
-- What we output per row:
--   dzongkhag_name   the district
--   center_id        primary key (referenced by every sibling app's cases)
--   center_code      subject-ID prefix in BDSIS (e.g. BUM, JDW, DAG, ...)
--   center_name      display label everywhere
--   center_type      1 or 2 = hospital (per bumthang migration commentary)
--   status           1 = active in the shared master (must be 1 to be usable)
--   dsis             1 = OFFERED in the BDSIS Health Facility dropdown today
--                    0 = present in the master but NOT offered by BDSIS
--   bdsis_case_cnt   how many BDSIS subjects already reference this centre
--                    (belt-and-braces context: never disable a centre in use)
--
-- ANY row with dsis = 0 is a candidate for enabling. Rows with dsis = 1 are
-- already in the dropdown -- no action needed.
--
-- No writes. Safe to run at any time.
-- ---------------------------------------------------------------------------

SELECT  d.dzongkhag_name,
        h.center_id,
        h.center_code,
        h.center_name,
        h.center_type,
        h.status,
        h.dsis,
        (SELECT COUNT(*) FROM `rcdc_bdsis`.subject_information_table s
                          WHERE s.center_id = h.center_id) AS bdsis_case_cnt
   FROM `commondb`.ms_health_center_table h
   JOIN `commondb`.ms_dzongkhag_table    d ON d.dzongkhag_code = h.dzongkhag_code
  WHERE d.dzongkhag_name IN
        ('Bumthang','Gasa','Dagana','Chhukha',
         'Haa','Pemagatsel','Trashiyangtse','Zhemgang')
    AND h.status = 1
    AND h.center_code <> ''
    AND h.center_type IN (1, 2)                    -- 1/2 = hospital
  ORDER BY d.dzongkhag_name, h.center_name;
