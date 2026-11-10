-- ---------------------------------------------------------------------------
-- Recover cases that were written into the wrong database.
--
-- The app connects to `bdsis-up` (config/db-config.php), but records/iu-case.php
-- hardcoded the schema on its INSERTs -- "INSERT INTO bdsis.subject_information_table"
-- -- so every NEW case was written into the stale `bdsis` database while every
-- read, and every UPDATE, used `bdsis-up`. The user saw "Submitted Successfully"
-- and was then told "Case not available", because the row genuinely was not in
-- the database the page reads from.
--
-- `bdsis-up` is authoritative: it is the connected schema, every report and the
-- dashboard read from it, and it carries the newer columns (e.g. the rotavirus
-- genotype_id added on 2026-07-21) that `bdsis` does not have.
--
-- This moves the stranded cases across. Verified before writing:
--   * 5 subjects exist in `bdsis` and not in `bdsis-up`
--   * they have 5 matching case_clinical_information_table rows
--   * they have NO sample_information_table or microscopy_result_table rows --
--     the users could never reach steps 2-4, which is the bug being fixed
--
-- INSERT IGNORE and the NOT EXISTS guard make this safe to re-run.
--
-- Rollback: 2026-07-22-recover-cases-written-to-wrong-schema.rollback.sql
-- ---------------------------------------------------------------------------

-- Parent rows first: case_clinical_information_table has a FK to subject_information_table.
INSERT IGNORE INTO `bdsis-up`.`subject_information_table`
SELECT a.* FROM `bdsis`.`subject_information_table` a
 WHERE NOT EXISTS (
     SELECT 1 FROM `bdsis-up`.`subject_information_table` b
      WHERE b.subject_id = a.subject_id
 );

INSERT IGNORE INTO `bdsis-up`.`case_clinical_information_table`
SELECT c.* FROM `bdsis`.`case_clinical_information_table` c
 WHERE EXISTS (
     SELECT 1 FROM `bdsis-up`.`subject_information_table` s
      WHERE s.subject_id = c.subject_id
 )
   AND NOT EXISTS (
     SELECT 1 FROM `bdsis-up`.`case_clinical_information_table` d
      WHERE d.subject_id = c.subject_id
 );
