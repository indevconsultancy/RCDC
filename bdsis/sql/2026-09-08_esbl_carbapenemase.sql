-- ---------------------------------------------------------------------------
-- Conditional ESBL / Carbapenemase (mCIM, eCIM) testing -- Bacteriology AST.
-- Database: bdsis-up.  Idempotent: safe to run more than once.
-- ---------------------------------------------------------------------------

-- 1. Routine disk-diffusion agents that drive the confirmatory tests.
--    Breakpoints: CLSI M100 Enterobacterales, disk diffusion (mm).
--    Interpreted by the existing rule in lab/iu-lab.php:
--      zone <= resistance -> R ; intermediate1..intermediate2 -> I ; zone >= susceptible -> S
--    CRO (Ceftriaxone) already exists.  (code, ast_method_id) is the primary key.
INSERT IGNORE INTO ms_ast_table (code, ast_method_id, antimicrobial_agent, content, susceptible, intermediate1, intermediate2, resistance, status) VALUES
  ('CAZ', '01', 'Ceftazidime', '30 &#181;g', 21, 18, 20, 17, '1'),
  ('CTX', '01', 'Cefotaxime',  '30 &#181;g', 26, 23, 25, 22, '1'),
  ('CPD', '01', 'Cefpodoxime', '10 &#181;g', 21, 18, 20, 17, '1'),
  ('IMI', '01', 'Imipenem',    '10 &#181;g', 23, 20, 22, 19, '1'),
  ('EPT', '01', 'Ertapenem',   '10 &#181;g', 22, 19, 21, 18, '1'),
  ('MER', '01', 'Meropenem',   '10 &#181;g', 23, 20, 22, 19, '1');

-- 2. ESBL confirmatory agents (combination disk).  A clavulanate disc is
--    compared with its plain partner (pair_code): an increase of >= rule_mm
--    confirms an ESBL producer.
CREATE TABLE IF NOT EXISTS ms_esbl_agent_table (
  code                varchar(10)  NOT NULL,
  short_label         varchar(10)  NOT NULL,
  antimicrobial_agent varchar(100) NOT NULL,
  content             varchar(20)  DEFAULT NULL,
  pair_code           varchar(10)  DEFAULT NULL,
  rule_mm             float        DEFAULT NULL,
  sort_order          int(11)      NOT NULL DEFAULT 0,
  status              varchar(5)   NOT NULL DEFAULT '1',
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT IGNORE INTO ms_esbl_agent_table (code, short_label, antimicrobial_agent, content, pair_code, rule_mm, sort_order, status) VALUES
  ('CTX',   'CTX',    'Cefotaxime',                   '30 &#181;g',    NULL,  NULL, 1, '1'),
  ('CTXCV', 'CTX/CV', 'Cefotaxime/Clavulanic acid',   '30/10 &#181;g', 'CTX', 5,    2, '1'),
  ('CAZ',   'CAZ',    'Ceftazidime',                  '30 &#181;g',    NULL,  NULL, 3, '1'),
  ('CAZCV', 'CAZ/CV', 'Ceftazidime/Clavulanic acid',  '30/10 &#181;g', 'CAZ', 5,    4, '1');

-- 3. Per-isolate ESBL disc readings (one row per agent entered).
--    ON DELETE CASCADE: removing the pathogen row (lab/iu-lab.php step 1,
--    lab/delete.php) removes these with it, so those paths need no change.
CREATE TABLE IF NOT EXISTS bacteriology_esbl_table (
  record_id     int(11)     NOT NULL AUTO_INCREMENT,
  brt2_id       int(11)     NOT NULL,
  agent_code    varchar(10) NOT NULL,
  esbl_method   varchar(20) NOT NULL,
  zone_diameter float       DEFAULT NULL,
  PRIMARY KEY (brt2_id, agent_code),
  UNIQUE KEY record_id (record_id),
  KEY agent_code (agent_code),
  CONSTRAINT bacteriology_esbl_ibfk_1 FOREIGN KEY (brt2_id)    REFERENCES bacteriology_result_table2 (record_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT bacteriology_esbl_ibfk_2 FOREIGN KEY (agent_code) REFERENCES ms_esbl_agent_table (code) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- 4. Per-isolate summary: what triggered each test and the FINAL interpretations
--    (stored, not re-derived, so every report prints what was signed off).
CREATE TABLE IF NOT EXISTS bacteriology_resistance_table (
  brt2_id              int(11)     NOT NULL,
  esbl_triggered       tinyint(1)  NOT NULL DEFAULT 0,
  esbl_trigger_agents  varchar(50) DEFAULT NULL,
  esbl_result          varchar(20) DEFAULT NULL,
  cpe_triggered        tinyint(1)  NOT NULL DEFAULT 0,
  cpe_trigger_agents   varchar(50) DEFAULT NULL,
  mcim_method          varchar(30) DEFAULT NULL,
  mcim_zone            float       DEFAULT NULL,
  mcim_colonies        varchar(3)  DEFAULT NULL,
  mcim_result          varchar(15) DEFAULT NULL,
  ecim_method          varchar(30) DEFAULT NULL,
  ecim_zone            float       DEFAULT NULL,
  ecim_result          varchar(25) DEFAULT NULL,
  carbapenemase_result varchar(15) DEFAULT NULL,
  recorded_by          varchar(50) DEFAULT NULL,
  recorded_on          date        DEFAULT NULL,
  PRIMARY KEY (brt2_id),
  CONSTRAINT bacteriology_resistance_ibfk_1 FOREIGN KEY (brt2_id) REFERENCES bacteriology_result_table2 (record_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
