-- Rollback for 2026-09-24_epis_pending.sql.
-- Drops only the queue table this migration created. No BDSIS record that was
-- already imported is affected: those live in subject_information_table and are
-- ordinary cases once saved.
DROP TABLE IF EXISTS `bdsis_epis_case`;
