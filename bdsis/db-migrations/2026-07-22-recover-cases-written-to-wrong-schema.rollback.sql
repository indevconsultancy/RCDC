-- ---------------------------------------------------------------------------
-- Rollback for 2026-07-22-recover-cases-written-to-wrong-schema.sql
--
-- Removes ONLY the five cases recovered from the stale `bdsis` database. They
-- are identified explicitly by subject_id rather than by re-deriving the set,
-- so this cannot delete anything that was already in `bdsis-up`.
--
-- WARNING: do not run this once staff have added sample shipment, microscopy or
-- lab results against these cases -- those child rows would block the delete
-- (or, worse, be orphaned). Check first:
--   SELECT subject_id FROM `bdsis-up`.sample_information_table WHERE subject_id IN (...);
-- ---------------------------------------------------------------------------

DELETE FROM `bdsis-up`.`case_clinical_information_table`
 WHERE subject_id IN ('MON-26-123','PAR-26-001','PHU-26-123','TON-26-111','MON-26-019');

DELETE FROM `bdsis-up`.`subject_information_table`
 WHERE subject_id IN ('MON-26-123','PAR-26-001','PHU-26-123','TON-26-111','MON-26-019');
