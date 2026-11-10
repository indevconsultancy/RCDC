-- ---------------------------------------------------------------------------
-- Case Investigation Form for Rotavirus surveillance: fields on the paper form
-- that the case wizard did not capture. Every column is NULLable and optional;
-- nothing existing changes. Idempotent (MariaDB ADD COLUMN IF NOT EXISTS).
-- Database: bdsis-up
-- ---------------------------------------------------------------------------

-- Demography (Step 1)
ALTER TABLE subject_information_table
  ADD COLUMN IF NOT EXISTS reg_number        varchar(30) DEFAULT NULL COMMENT 'Reg. Number (hospital registration)',
  ADD COLUMN IF NOT EXISTS dob               date        DEFAULT NULL COMMENT 'Date of birth',
  ADD COLUMN IF NOT EXISTS residence_type    varchar(5)  DEFAULT NULL COMMENT 'Rural | Urban',
  ADD COLUMN IF NOT EXISTS guardian_name     varchar(60) DEFAULT NULL COMMENT 'Parent (Father/Mother)/Guardian name',
  ADD COLUMN IF NOT EXISTS notified_by       varchar(60) DEFAULT NULL COMMENT 'Notified/Investigated by',
  ADD COLUMN IF NOT EXISTS notification_date date        DEFAULT NULL COMMENT 'Date of notification';

-- Hospitalization, clinical syndrome, treatment, immunization history, outcome (Step 1)
ALTER TABLE case_clinical_information_table
  ADD COLUMN IF NOT EXISTS admission_date        date        DEFAULT NULL COMMENT 'Date of admission, if applicable',
  ADD COLUMN IF NOT EXISTS hospital_name         varchar(100) DEFAULT NULL COMMENT 'Name/Address of hospital (Sentinel Site)',
  ADD COLUMN IF NOT EXISTS discharge_death_date  date        DEFAULT NULL COMMENT 'Date of discharge or death, if applicable',
  ADD COLUMN IF NOT EXISTS max_diarrhea_episodes int(11)     DEFAULT NULL COMMENT 'Max diarrhoeal episodes in 24H at peak',
  ADD COLUMN IF NOT EXISTS dehydration_degree    varchar(2)  DEFAULT NULL COMMENT '0 None,1 Some,2 Severe,3 Shock,99 Unknown',
  ADD COLUMN IF NOT EXISTS sym_watery_loose      varchar(3)  DEFAULT NULL COMMENT 'Watery/Loose diarrhea Yes|No',
  ADD COLUMN IF NOT EXISTS sym_bloody            varchar(3)  DEFAULT NULL COMMENT 'Bloody diarrhea Yes|No',
  ADD COLUMN IF NOT EXISTS sym_fever             varchar(3)  DEFAULT NULL COMMENT 'Fever Yes|No',
  ADD COLUMN IF NOT EXISTS sym_abdominal_pain    varchar(3)  DEFAULT NULL COMMENT 'Abdominal pain Yes|No',
  ADD COLUMN IF NOT EXISTS diarrhea_acute        varchar(3)  DEFAULT NULL COMMENT 'Acute diarrhea (<14 days) Yes|No',
  ADD COLUMN IF NOT EXISTS diarrhea_persistent   varchar(3)  DEFAULT NULL COMMENT 'Persistent diarrhea (14-28 days) Yes|No',
  ADD COLUMN IF NOT EXISTS diarrhea_chronic      varchar(3)  DEFAULT NULL COMMENT 'Chronic diarrhea (>28 days) Yes|No',
  ADD COLUMN IF NOT EXISTS vomiting              varchar(3)  DEFAULT NULL COMMENT 'Vomiting present? Yes|No',
  ADD COLUMN IF NOT EXISTS max_vomiting_episodes int(11)     DEFAULT NULL COMMENT 'Max vomiting episodes in 24-hour period at peak',
  ADD COLUMN IF NOT EXISTS rehydration           varchar(10) DEFAULT NULL COMMENT 'Rehydration done: 1=ORS, 2=IV fluids (comma-joined)',
  ADD COLUMN IF NOT EXISTS rota_vaccine          varchar(1)  DEFAULT NULL COMMENT 'Rota vaccine received: 1=Yes, 2=No',
  ADD COLUMN IF NOT EXISTS vaccine_type          varchar(10) DEFAULT NULL COMMENT '1=Rotarix, 2=Rotavac, 3=Rotasil, Others',
  ADD COLUMN IF NOT EXISTS vaccine_last_date     date        DEFAULT NULL COMMENT 'Date of vaccine last received',
  ADD COLUMN IF NOT EXISTS vaccine_info_source   varchar(1)  DEFAULT NULL COMMENT '1=Card, 2=HF records, 3=Recall',
  ADD COLUMN IF NOT EXISTS outcome               varchar(10) DEFAULT NULL COMMENT 'Alive | Death | Referred | 99 (unknown)';

-- Laboratory result, Stool R/E (Step 3)
ALTER TABLE microscopy_result_table
  ADD COLUMN IF NOT EXISTS quantity_adequate varchar(3)  DEFAULT NULL COMMENT 'Quantity adequate? Yes|No',
  ADD COLUMN IF NOT EXISTS consistency       varchar(15) DEFAULT NULL COMMENT 'Watery/Loose | Formed',
  ADD COLUMN IF NOT EXISTS aliquot_mcb       int(11)     DEFAULT NULL COMMENT 'Sample aliquot in mCB media? 1=Yes, 0=No';
