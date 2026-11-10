-- Rotavirus genotyping: withdraw "UT" from the G type / P type pickers
-- ----------------------------------------------------------------------------
-- The approved G/P lists are:
--   G type  G1, G2, G3, G4, G8, G9, G10, G12
--   P type  P[4], P[6], P[8], P[9], P[10], P[11]
-- so the two UT rows added by 2026-09-18_rotavirus_ut_genotype.sql are withdrawn.
--
-- They are DEACTIVATED (status 0), not deleted -- the same way the earlier
-- retired entries (G1P[8], Mixed, Untypeable, ...) are held. That keeps every
-- stored rcdc_elisa_genotype_table row valid and its foreign key intact: the
-- pickers and the save whitelist are both built from ACTIVE genotypes, so UT can
-- no longer be chosen or saved, while nothing already recorded is destroyed.
--
-- NOTE: at the time of writing, subject THI-26-1001 holds UT in both groups.
-- Those two rows remain in the database, but they will not appear on the form,
-- and the next time that record's ELISA section is saved they will be dropped
-- along with any other unticked option. Reactivate below if they must be kept.
--
-- Safe to re-run.

UPDATE `ms_rotavirus_genotype_table` SET `status` = 0 WHERE `genotype_id` IN ('115', '116');

-- ----------------------------------------------------------------------------
-- ROLLBACK (puts UT back in both pickers):
--
-- UPDATE `ms_rotavirus_genotype_table` SET `status` = 1 WHERE `genotype_id` IN ('115', '116');
