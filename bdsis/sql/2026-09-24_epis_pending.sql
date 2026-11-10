-- ---------------------------------------------------------------------------
-- ePIS pending / verification queue
-- ---------------------------------------------------------------------------
-- One new table. Nothing existing is altered, and no ePIS row is ever written
-- into subject_information_table by the sync: a fetched record lands here as
-- 'New' and only becomes a BDSIS subject when somebody accepts it and saves the
-- case through the ordinary Records wizard. Rejected and unverified rows stay
-- here for audit and never appear in the main Record listing.
--
-- Identity: ePIS supplies no encounter id and the same UHID recurs in the feed
-- with different onset dates, so a row is identified by a SHA-1 of the fields
-- that make an encounter unique. The unique key plus INSERT IGNORE makes a
-- double-click, a concurrent sync or a repeated pull harmless.
--
-- Install:  mysql -u root -P 3307 bdsis-up < sql/2026-09-24_epis_pending.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `bdsis_epis_case` (
  `id`                    int(11)      NOT NULL AUTO_INCREMENT,
  `record_hash`           char(40)     NOT NULL,

  -- ---- as received from ePIS ----
  `epis_uid`              varchar(60)  DEFAULT NULL,   -- feed id when present
  `uhid`                  varchar(30)  DEFAULT NULL,
  `patient_name`          varchar(100) DEFAULT NULL,
  `birth_date`            date         DEFAULT NULL,
  `gender`                varchar(10)  DEFAULT NULL,
  `mobile_no`             varchar(20)  DEFAULT NULL,   -- text: leading zeros survive
  `permanent_dzongkhag`   varchar(60)  DEFAULT NULL,
  `present_dzongkhag`     varchar(60)  DEFAULT NULL,
  `dzongkhag_code`        varchar(2)   DEFAULT NULL,   -- resolved against commondb
  `provisional_diagnosis` text         DEFAULT NULL,
  `notifiable_diseases`   text         DEFAULT NULL,
  `matched_codes`         varchar(120) DEFAULT NULL,   -- which ICD-11 codes hit
  `symptoms_onset_date`   date         DEFAULT NULL,
  `admission_date`        date         DEFAULT NULL,
  `other_chief_complaint` text         DEFAULT NULL,
  `is_hospitalized`       varchar(10)  DEFAULT NULL,
  `hospital_name`         varchar(100) DEFAULT NULL,
  `outcome`               varchar(60)  DEFAULT NULL,
  `outcome_date`          date         DEFAULT NULL,
  `laboratory_results`    text         DEFAULT NULL,
  `raw_payload`           longtext     DEFAULT NULL,   -- untouched original, for audit

  -- ---- verification workflow ----
  `status`                varchar(20)  NOT NULL DEFAULT 'New',  -- New|Accepted|Rejected|Imported
  `decided_by`            varchar(50)  DEFAULT NULL,
  `decided_on`            datetime     DEFAULT NULL,
  `decision_reason`       varchar(255) DEFAULT NULL,

  -- ---- the promotion to a real BDSIS record ----
  `linked_subject_id`     varchar(15)  DEFAULT NULL,   -- subject_information_table.subject_id
  `imported_on`           datetime     DEFAULT NULL,
  `imported_by`           varchar(50)  DEFAULT NULL,

  -- ---- sync audit ----
  `synced_on`             datetime     DEFAULT NULL,
  `last_seen_on`          datetime     DEFAULT NULL,
  `synced_by`             varchar(50)  DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_record_hash` (`record_hash`),
  UNIQUE KEY `uniq_linked_subject` (`linked_subject_id`),  -- one ePIS row per subject
  KEY `idx_status` (`status`),
  KEY `idx_uhid` (`uhid`),
  KEY `idx_dzongkhag` (`dzongkhag_code`),
  KEY `idx_onset` (`symptoms_onset_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
