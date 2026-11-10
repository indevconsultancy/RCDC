-- Seed ms_ast_table with Method 02 (MIC) CLSI Breakpoints
-- ----------------------------------------------------------------------------
-- Aligns the E. coli AST MIC workflow with the Bacteriology MIC workflow:
-- provides ms_ast_table rows with ast_method_id = '02' for all 14 CLSI
-- breakpoint antibiotics. This satisfies the foreign key constraint on
-- rcdc_ecoli_pcr_ast_table (REFERENCES ms_ast_table(code)) and allows
-- master data views and reporting to resolve MIC entries cleanly.

INSERT INTO `ms_ast_table` (`code`, `ast_method_id`, `antimicrobial_agent`, `content`, `susceptible`, `intermediate1`, `intermediate2`, `resistance`, `status`) VALUES
('AMP', '02', 'Ampicillin',                    '', 8.00,  8.00, 32.00, 32.00, '1'),
('CZO', '02', 'Cefazolin',                     '', 2.00,  2.00,  8.00,  8.00, '1'),
('CRO', '02', 'Ceftriaxone',                   '', 1.00,  1.00,  4.00,  4.00, '1'),
('CTX', '02', 'Cefotaxime',                    '', 1.00,  1.00,  4.00,  4.00, '1'),
('CAZ', '02', 'Ceftazidime',                   '', 4.00,  4.00, 16.00, 16.00, '1'),
('GEN', '02', 'Gentamicin',                    '', 4.00,  4.00, 16.00, 16.00, '1'),
('TCY', '02', 'Tetracycline',                  '', 4.00,  4.00, 16.00, 16.00, '1'),
('CIP', '02', 'Ciprofloxacin',                 '', 0.25,  0.25,  1.00,  1.00, '1'),
('CHL', '02', 'Chloramphenicol',               '', 8.00,  8.00, 32.00, 32.00, '1'),
('NAL', '02', 'Nalidixic acid',                '', 8.00,  8.00, 32.00, 32.00, '1'),
('SXT', '02', 'Trimethoprim-sulfamethoxazole', '', 2.00,  2.00,  4.00,  4.00, '1'),
('ETP', '02', 'Ertapenem',                     '', 0.50,  0.50,  2.00,  2.00, '1'),
('IPM', '02', 'Imipenem',                      '', 1.00,  1.00,  4.00,  4.00, '1'),
('MEM', '02', 'Meropenem',                     '', 1.00,  1.00,  4.00,  4.00, '1')
ON DUPLICATE KEY UPDATE
  `antimicrobial_agent` = VALUES(`antimicrobial_agent`),
  `susceptible`         = VALUES(`susceptible`),
  `intermediate1`       = VALUES(`intermediate1`),
  `intermediate2`       = VALUES(`intermediate2`),
  `resistance`          = VALUES(`resistance`),
  `status`              = '1';
