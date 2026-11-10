-- 2026-10-07-cid-no.sql
-- Adds the optional alphanumeric CID No. column to subject_information_table.
-- Positioned immediately after subject_id so the row layout mirrors the UI
-- (CID No. renders directly after Subject ID in the case entry form and
-- directly after NAME in the Group Report tables).
--
-- The PHP code paths (case.php, iu-case.php, reports/report-group.php,
-- reports/report-group-pdf.php) all guard reads/writes behind
-- bdsis_subject_cid_no_column_exists(), so the application ships safely
-- ahead of this ALTER being applied, and starts persisting / displaying
-- CID No. the moment the column exists.
--
-- Apply via phpMyAdmin against the `rcdc_bdsis` database.

ALTER TABLE subject_information_table
  ADD COLUMN cid_no VARCHAR(32) NULL AFTER subject_id;
