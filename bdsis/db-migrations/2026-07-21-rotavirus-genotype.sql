-- ---------------------------------------------------------------------------
-- Lab Results > ELISA: capture the Rotavirus genotype.
--
-- Subjects who are Rotavirus-positive on ELISA *and* have E.coli isolated in
-- the Bacteriology Result section (ms_enteric_bacterial_pathogen_table
-- pathogen_id '025' LF E.coli / '026' NLF E.coli) are genotyped for rotavirus.
-- There is no age restriction -- this applies to patients of all ages. 219
-- existing subjects already satisfy both conditions, so the field has to be
-- usable on historical records, not just new ones.
--
-- Two additive changes:
--
--   1. ms_rotavirus_genotype_table -- a new master in the same shape as the
--      other ms_* masters (short varchar primary key + display name), seeded
--      with the standard WHO rotavirus surveillance genotypes plus the three
--      catch-all values the lab needs (Mixed / Untypeable / Not Determined).
--      It carries a `status` flag so a genotype can be retired from the
--      dropdown later without deleting rows that reference it.
--
--   2. rcdc_elisa_result_table2.genotype_id -- NULL-able, defaulting to NULL,
--      with a foreign key to the new master using the same ON UPDATE CASCADE
--      style as the four constraints already on that table.
--
-- The genotype logically belongs to the Rotavirus row (virus_id='05') of a
-- subject; every other virus row stores NULL. That is enforced in the
-- application (lab/iu-lab.php), not by a constraint, because the ELISA handler
-- deletes and re-inserts all six virus rows on every save.
--
-- NULL-able is load-bearing: all 27,598 existing rows keep their current
-- values and none is rewritten by this migration. It is purely additive -- no
-- UPDATE, no DELETE, no type change to any existing column.
--
-- Verify after applying:
--   SELECT COUNT(*) FROM `bdsis-up`.rcdc_elisa_result_table2;              -- 27598
--   SELECT COUNT(*) FROM `bdsis-up`.rcdc_elisa_result_table2
--    WHERE genotype_id IS NOT NULL;                                        -- 0
--
-- Rollback: 2026-07-21-rotavirus-genotype.rollback.sql
-- ---------------------------------------------------------------------------

CREATE TABLE `ms_rotavirus_genotype_table` (
  `genotype_id` varchar(3) NOT NULL,
  `genotype` varchar(50) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`genotype_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci ROW_FORMAT=COMPACT;

INSERT INTO `ms_rotavirus_genotype_table` (`genotype_id`, `genotype`, `status`) VALUES
  ('001', 'G1P[8]',         1),
  ('002', 'G2P[4]',         1),
  ('003', 'G3P[8]',         1),
  ('004', 'G4P[8]',         1),
  ('005', 'G9P[8]',         1),
  ('006', 'G12P[6]',        1),
  ('007', 'G12P[8]',        1),
  ('008', 'G2P[6]',         1),
  ('009', 'G3P[6]',         1),
  ('010', 'G9P[4]',         1),
  ('011', 'Mixed',          1),
  ('012', 'Untypeable',     1),
  ('013', 'Not Determined', 1);

ALTER TABLE `rcdc_elisa_result_table2`
    ADD COLUMN `genotype_id` varchar(3) DEFAULT NULL AFTER `virus_id`,
    ADD KEY `genotype_id` (`genotype_id`) USING BTREE,
    ADD CONSTRAINT `rcdc_elisa_result_table2_ibfk_5`
        FOREIGN KEY (`genotype_id`) REFERENCES `ms_rotavirus_genotype_table` (`genotype_id`)
        ON UPDATE CASCADE;
