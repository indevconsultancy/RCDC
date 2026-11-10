-- ---------------------------------------------------------------------------
-- Rollback: 2026-07-23-lab-record-status.sql
--
-- Safe to run. The table is additive and starts empty; nothing else in the
-- schema references it. Dropping it returns every lab record to the implicit
-- Draft state, which is how BDSIS behaved before the migration.
--
-- NOTE: this discards verification history (who verified what, and when). Take
-- a dump of lab_record_status_table first if that history matters.
-- ---------------------------------------------------------------------------

DROP TABLE IF EXISTS `lab_record_status_table`;
