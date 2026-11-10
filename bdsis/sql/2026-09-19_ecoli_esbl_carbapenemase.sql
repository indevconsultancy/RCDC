-- ---------------------------------------------------------------------------
-- E. coli AST: ESBL and carbapenemase confirmatory testing
-- ---------------------------------------------------------------------------
-- The ESBL / carbapenemase workflow already exists for bacteriology isolates
-- (bacteriology_resistance_table, bacteriology_esbl_table, keyed by
-- bacteriology_result_table2.record_id). It deliberately skips pathogens 025
-- and 026 -- LF and NLF E. coli -- because an E. coli isolate's AST is not
-- recorded there: it lives in the PCR pathotype panel, keyed by
-- rcdc_ecoli_pcr_result_table3.record_id.
--
-- An E. coli isolate whose CTX reads Resistant therefore had nowhere to record
-- an ESBL confirmation. These two tables give it one, keyed by the pathotype
-- record, with exactly the columns and types their bacteriology counterparts
-- use so the same rules, the same helpers and the same report rows serve both.
--
-- Nothing here changes an existing table, so the bacteriology workflow and all
-- stored data are untouched.
--
-- ON DELETE CASCADE mirrors the bacteriology tables and matters more here:
-- de-selecting a pathotype deletes its rcdc_ecoli_pcr_result_table3 row
-- (bdsis_save_ecoli_strains) and lab/delete.php removes them wholesale, both of
-- which would otherwise fail on a foreign key.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ecoli_resistance_table` (
  `prt3_record_id`       int(11)     NOT NULL,
  `esbl_triggered`       tinyint(1)  NOT NULL DEFAULT 0,
  `esbl_trigger_agents`  varchar(50) DEFAULT NULL,
  `esbl_result`          varchar(30) DEFAULT NULL,
  `cpe_triggered`        tinyint(1)  NOT NULL DEFAULT 0,
  `cpe_trigger_agents`   varchar(50) DEFAULT NULL,
  `cpe_agent`            varchar(10) DEFAULT NULL,
  `mcim_method`          varchar(30) DEFAULT NULL,
  `mcim_zone`            float       DEFAULT NULL,
  `mcim_colonies`        varchar(3)  DEFAULT NULL,
  `mcim_result`          varchar(20) DEFAULT NULL,
  `ecim_method`          varchar(30) DEFAULT NULL,
  `ecim_zone`            float       DEFAULT NULL,
  `ecim_result`          varchar(60) DEFAULT NULL,
  `carbapenemase_result` varchar(60) DEFAULT NULL,
  `recorded_by`          varchar(50) DEFAULT NULL,
  `recorded_on`          date        DEFAULT NULL,
  PRIMARY KEY (`prt3_record_id`),
  CONSTRAINT `ecoli_resistance_ibfk_1` FOREIGN KEY (`prt3_record_id`)
      REFERENCES `rcdc_ecoli_pcr_result_table3` (`record_id`)
      ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `ecoli_esbl_table` (
  `record_id`      int(11)     NOT NULL AUTO_INCREMENT,
  `prt3_record_id` int(11)     NOT NULL,
  `agent_code`     varchar(10) NOT NULL,
  `esbl_method`    varchar(20) NOT NULL,
  `zone_diameter`  float       DEFAULT NULL,
  PRIMARY KEY (`prt3_record_id`,`agent_code`),
  UNIQUE KEY `record_id` (`record_id`),
  KEY `agent_code` (`agent_code`),
  CONSTRAINT `ecoli_esbl_ibfk_1` FOREIGN KEY (`prt3_record_id`)
      REFERENCES `rcdc_ecoli_pcr_result_table3` (`record_id`)
      ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ecoli_esbl_ibfk_2` FOREIGN KEY (`agent_code`)
      REFERENCES `ms_esbl_agent_table` (`code`)
      ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
