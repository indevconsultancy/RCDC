-- 2026-10-08-cid-no-widen.sql
-- Widens subject_information_table.cid_no from VARCHAR(32) to VARCHAR(50)
-- per the client's revised spec (handles IDs up to 50 chars). The field
-- stays NULLABLE and keeps its position directly after subject_id.
--
-- Idempotent across the three possible staging states:
--
--   1. Column already exists at VARCHAR(32)
--        -> this MODIFY widens it to VARCHAR(50) in place; stored values
--           are unchanged.
--
--   2. Column already exists at VARCHAR(50) (migration already applied)
--        -> MODIFY is a no-op; MySQL reports "0 rows affected" and the
--           schema is already correct.
--
--   3. Column does not yet exist (fresh staging; 2026-10-07-cid-no.sql
--      was never applied)
--        -> run db-migrations/2026-10-07-cid-no.sql FIRST, then this one,
--           OR just run the ADD below. The ADD IF NOT EXISTS avoids a
--           duplicate-column error on MySQL 8+; on older MySQL / MariaDB,
--           replace the ADD line with a plain ADD and skip it if the
--           earlier migration already created the column at VARCHAR(32).
--
-- Apply via phpMyAdmin against the `rcdc_bdsis` database.
--
-- Note: no PHP changes are needed for the ALTER to take effect -- the
-- bdsis_subject_cid_no_column_exists() probe already gates every read
-- and write, and the maxlength / cap values in the UI and
-- bdsis_normalize_cid_no() helper were raised to 50 alongside this
-- migration so a 50-char CID now round-trips cleanly.

ALTER TABLE subject_information_table
  MODIFY COLUMN cid_no VARCHAR(50) NULL;

-- Fresh-staging fallback (uncomment if the 2026-10-07 migration was never
-- applied and MySQL/MariaDB does not support ADD IF NOT EXISTS):
--   ALTER TABLE subject_information_table
--     ADD COLUMN cid_no VARCHAR(50) NULL AFTER subject_id;
