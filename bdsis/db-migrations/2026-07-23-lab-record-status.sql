-- ---------------------------------------------------------------------------
-- Lab record lifecycle: Draft -> In Progress -> Completed -> Verified -> Approved
--
-- BDSIS has never had a verification lifecycle. Before this migration there was
-- no status column, no verified_by/verified_on, and no "returned for correction"
-- anywhere in the schema -- the only columns whose names suggested otherwise are
-- unrelated (bacteriology_result_table2.ast_status is an AST flag,
-- sample_information_table.sample_status is sample receipt).
--
-- The lifecycle is therefore added as ONE new table keyed by subject_id, not as
-- columns spread across the five result tables. Reasons:
--
--   * A lab record spans bacteriology_result_table1/2, bacteriology_ast_table,
--     rcdc_ecoli_pcr_result_table1/2/3 and rcdc_elisa_result_table1/2. Status is
--     a property of the record as a whole, so it belongs in one row, not seven.
--   * The existing result tables are written by long INSERT/UPDATE statements in
--     lab/iu-lab.php. Adding columns to them would mean touching every one of
--     those statements, which is exactly the risk this change should avoid.
--   * A subject with no row here is Draft. So this table starts EMPTY and every
--     one of the 5,078 existing subjects keeps behaving exactly as it does now.
--     Nothing is backfilled and no existing row is modified.
--
-- The status vocabulary is the one in the specification. 'returned' is the
-- "returned for correction" state, which re-enables editing until the record is
-- verified again.
--
-- Rollback: 2026-07-23-lab-record-status.rollback.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `lab_record_status_table` (
  `subject_id`      VARCHAR(20)  NOT NULL,
  `status`          VARCHAR(12)  NOT NULL DEFAULT 'draft',
  `verified_by`     VARCHAR(50)      NULL DEFAULT NULL,
  `verified_on`     DATETIME         NULL DEFAULT NULL,
  `returned_by`     VARCHAR(50)      NULL DEFAULT NULL,
  `returned_on`     DATETIME         NULL DEFAULT NULL,
  `return_reason`   VARCHAR(500)     NULL DEFAULT NULL,
  `approved_by`     VARCHAR(50)      NULL DEFAULT NULL,
  `approved_on`     DATETIME         NULL DEFAULT NULL,
  `updated_by`      VARCHAR(50)      NULL DEFAULT NULL,
  `updated_on`      DATETIME         NULL DEFAULT NULL,
  PRIMARY KEY (`subject_id`),
  KEY `idx_lab_status` (`status`),
  CONSTRAINT `chk_lab_status`
    CHECK (`status` IN ('draft','in_progress','completed','verified','returned','approved'))
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- No FK to subject_information_table: subject_id there is VARCHAR(20) PRIMARY
-- KEY, but the two tables are written by different code paths and a hard FK
-- would make a lab status row block a case deletion that administrators are
-- still permitted to perform. Ownership is enforced in the application, which
-- is where every other cross-table rule in BDSIS already lives.
