-- ---------------------------------------------------------------------------
-- BDSIS New Field Request - Rotavirus Genotyping (ELISA section only)
--
-- Adds multi-select G type / P type capture alongside the existing single
-- "Genotype" field. Purely additive:
--   * rcdc_elisa_result_table2.genotype_id is NOT touched, so existing values,
--     its foreign key and the report that prints it all keep working.
--   * The G/P values themselves already exist in ms_rotavirus_genotype_table as
--     active rows (101-108 = G1..G12, 109-114 = P[4]..P[11]), so no master data
--     is added here.
--
-- Keyed on subject_id, NOT on the ELISA record_id: lab/iu-lab.php deletes and
-- re-inserts all six virus rows on every ELISA save, so a record_id key would
-- be cascade-deleted on each save.
--
-- Database: bdsis-up.  Idempotent.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rcdc_elisa_genotype_table (
  subject_id  varchar(15) NOT NULL,
  genotype_id varchar(3)  NOT NULL,
  recorded_by varchar(50) DEFAULT NULL,
  recorded_on date        DEFAULT NULL,
  PRIMARY KEY (subject_id, genotype_id),
  KEY genotype_id (genotype_id),
  CONSTRAINT rcdc_elisa_genotype_ibfk_1 FOREIGN KEY (subject_id)  REFERENCES subject_information_table (subject_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT rcdc_elisa_genotype_ibfk_2 FOREIGN KEY (genotype_id) REFERENCES ms_rotavirus_genotype_table (genotype_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
