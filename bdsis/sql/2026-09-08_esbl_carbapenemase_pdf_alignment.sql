-- ---------------------------------------------------------------------------
-- ESBL / Carbapenemase: align with "BDSIS New Change" requirements.
--   * Antibiotic master file gains the two combination discs the document lists.
--   * Result columns widened for the document's full interpretation wording.
-- Database: bdsis-up.  Idempotent.  Both result tables were empty when this ran,
-- so no value migration is needed.
-- ---------------------------------------------------------------------------

-- 1. Antibiotic master file: the two clavulanate combination discs, 30/10 ug.
--    status='ESBL' deliberately, NOT '1': the routine disk-diffusion panel is
--    built from `WHERE ast_method_id='01' AND status='1'`, so these appear in
--    the master file (as the document asks) without being added as required
--    zone fields to every isolate's routine panel. The >= 5 mm combination-disk
--    rule is not a susceptible/resistant breakpoint, so those columns stay NULL;
--    the rule lives in ms_esbl_agent_table.rule_mm, which drives the form.
INSERT IGNORE INTO ms_ast_table (code, ast_method_id, antimicrobial_agent, content, susceptible, intermediate1, intermediate2, resistance, status) VALUES
  ('CAZCV', '01', 'Ceftazidime-clavulanate', '30/10 &#181;g', NULL, NULL, NULL, NULL, 'ESBL'),
  ('CTXCV', '01', 'Cefotaxime-clavulanate',  '30/10 &#181;g', NULL, NULL, NULL, NULL, 'ESBL');

-- 2. Room for the document's full interpretation wording:
--      "Metallo-beta-lactamase (MBL) detected"  (37 chars)
--      "Carbapenemase Indeterminate"            (27 chars)
--      "Non-ESBL producer"                      (17 chars)
ALTER TABLE bacteriology_resistance_table
  MODIFY COLUMN esbl_result          varchar(30) DEFAULT NULL,
  MODIFY COLUMN mcim_result          varchar(20) DEFAULT NULL,
  MODIFY COLUMN ecim_result          varchar(60) DEFAULT NULL,
  MODIFY COLUMN carbapenemase_result varchar(30) DEFAULT NULL;
