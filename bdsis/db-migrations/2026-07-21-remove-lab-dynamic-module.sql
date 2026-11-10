-- ---------------------------------------------------------------------------
-- Remove the "Lab Results (Dynamic)" module.
--
-- The module let an admin define arbitrary tests and parameters and then record
-- results against them, in parallel to the fixed Bacteriology/PCR/ELISA forms.
-- It is being retired.
--
-- Scope verified before writing this migration:
--   * these six tables are referenced by exactly eight PHP files, all of which
--     belong to the module and are deleted in the same change:
--       lab/dynamic-result.php, lab/iu-dynamic-result.php,
--       settings/define-test.php, settings/iu-define-test.php,
--       settings/test-parameters.php, settings/iu-test-parameters.php,
--       settings/assign-parameters.php, settings/iu-assign-parameters.php
--   * no foreign key anywhere in the schema references any of them, so no
--     other table's integrity depends on them
--   * row counts at time of removal: lab_dynamic_result 4, test 1,
--     test_parameter 2, test_assign 1, parameter_option 5, parameter_type 4
--
-- Rollback: 2026-07-21-remove-lab-dynamic-module.rollback.sql restores both the
-- schema and the data captured above. The PHP files must be restored separately
-- from git (they are deleted in the same commit as this migration).
-- ---------------------------------------------------------------------------

DROP TABLE IF EXISTS `bdsis_lab_dynamic_result_table`;
DROP TABLE IF EXISTS `bdsis_test_assign_table`;
DROP TABLE IF EXISTS `bdsis_test_parameter_table`;
DROP TABLE IF EXISTS `bdsis_parameter_option_table`;
DROP TABLE IF EXISTS `bdsis_parameter_type_table`;
DROP TABLE IF EXISTS `bdsis_test_table`;
