-- Rollback for 2026-09-19_ecoli_esbl_carbapenemase.sql.
-- Drops only the two tables that migration created; nothing else is touched.
DROP TABLE IF EXISTS `ecoli_esbl_table`;
DROP TABLE IF EXISTS `ecoli_resistance_table`;
