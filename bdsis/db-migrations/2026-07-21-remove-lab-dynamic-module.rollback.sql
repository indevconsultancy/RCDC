-- ---------------------------------------------------------------------------
-- Rollback for 2026-07-21-remove-lab-dynamic-module.sql
--
-- Recreates the six "Lab Results (Dynamic)" tables and restores the rows that
-- existed at the moment of removal (captured by mysqldump).
--
-- This restores DATA only. The module's eight PHP files and its sidebar menu
-- entries were deleted in the same commit and must be restored from git, e.g.
--   git revert <commit>
-- Restoring these tables without the code simply leaves them unused.
-- ---------------------------------------------------------------------------

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: bdsis-up
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `bdsis_lab_dynamic_result_table`
--

DROP TABLE IF EXISTS `bdsis_lab_dynamic_result_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bdsis_lab_dynamic_result_table` (
  `result_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` varchar(30) NOT NULL,
  `test_id` int(11) NOT NULL,
  `parameter_id` int(11) NOT NULL,
  `type_id` int(11) NOT NULL,
  `result_value` text DEFAULT NULL,
  `entered_by` varchar(50) DEFAULT NULL,
  `entered_on` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`result_id`),
  UNIQUE KEY `uniq_result` (`subject_id`,`test_id`,`type_id`),
  KEY `subject_id` (`subject_id`),
  KEY `test_id` (`test_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bdsis_lab_dynamic_result_table`
--

LOCK TABLES `bdsis_lab_dynamic_result_table` WRITE;
/*!40000 ALTER TABLE `bdsis_lab_dynamic_result_table` DISABLE KEYS */;
INSERT INTO `bdsis_lab_dynamic_result_table` VALUES (1,'BAJ-17-001',1,1,1,'Salmonella','administrator','2026-07-21 16:17:47'),(2,'THI-25-121',1,1,1,'Shigella','demo','2026-07-03 11:33:23'),(3,'THI-25-121',1,1,2,'hgjkl','demo','2026-07-03 11:33:23'),(5,'BAJ-17-001',1,1,2,'fdkah','administrator','2026-07-21 16:17:47');
/*!40000 ALTER TABLE `bdsis_lab_dynamic_result_table` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bdsis_test_assign_table`
--

DROP TABLE IF EXISTS `bdsis_test_assign_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bdsis_test_assign_table` (
  `assign_id` int(11) NOT NULL AUTO_INCREMENT,
  `test_id` int(11) NOT NULL,
  `parameter_id` int(11) NOT NULL,
  `status` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`assign_id`),
  KEY `test_id` (`test_id`),
  KEY `parameter_id` (`parameter_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bdsis_test_assign_table`
--

LOCK TABLES `bdsis_test_assign_table` WRITE;
/*!40000 ALTER TABLE `bdsis_test_assign_table` DISABLE KEYS */;
INSERT INTO `bdsis_test_assign_table` VALUES (1,1,1,1);
/*!40000 ALTER TABLE `bdsis_test_assign_table` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bdsis_test_parameter_table`
--

DROP TABLE IF EXISTS `bdsis_test_parameter_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bdsis_test_parameter_table` (
  `parameter_id` int(11) NOT NULL AUTO_INCREMENT,
  `parameter_name` varchar(150) NOT NULL,
  `status` tinyint(4) DEFAULT 1,
  `created_by` varchar(50) DEFAULT NULL,
  `created_on` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`parameter_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bdsis_test_parameter_table`
--

LOCK TABLES `bdsis_test_parameter_table` WRITE;
/*!40000 ALTER TABLE `bdsis_test_parameter_table` DISABLE KEYS */;
INSERT INTO `bdsis_test_parameter_table` VALUES (1,'Organism Isolated',1,'tester','2026-06-17 11:31:56'),(2,'Testing',1,'demo','2026-06-17 11:46:02');
/*!40000 ALTER TABLE `bdsis_test_parameter_table` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bdsis_parameter_option_table`
--

DROP TABLE IF EXISTS `bdsis_parameter_option_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bdsis_parameter_option_table` (
  `option_id` int(11) NOT NULL AUTO_INCREMENT,
  `type_id` int(11) NOT NULL,
  `option_value` varchar(150) NOT NULL,
  PRIMARY KEY (`option_id`),
  KEY `type_id` (`type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bdsis_parameter_option_table`
--

LOCK TABLES `bdsis_parameter_option_table` WRITE;
/*!40000 ALTER TABLE `bdsis_parameter_option_table` DISABLE KEYS */;
INSERT INTO `bdsis_parameter_option_table` VALUES (1,1,'Shigella'),(2,1,'Salmonella'),(3,1,'No growth'),(4,3,'Yes'),(5,4,'No');
/*!40000 ALTER TABLE `bdsis_parameter_option_table` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bdsis_parameter_type_table`
--

DROP TABLE IF EXISTS `bdsis_parameter_type_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bdsis_parameter_type_table` (
  `type_id` int(11) NOT NULL AUTO_INCREMENT,
  `parameter_id` int(11) NOT NULL,
  `input_type` varchar(20) NOT NULL,
  PRIMARY KEY (`type_id`),
  KEY `parameter_id` (`parameter_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bdsis_parameter_type_table`
--

LOCK TABLES `bdsis_parameter_type_table` WRITE;
/*!40000 ALTER TABLE `bdsis_parameter_type_table` DISABLE KEYS */;
INSERT INTO `bdsis_parameter_type_table` VALUES (1,1,'Dropdown'),(2,1,'Text'),(3,2,'Radio'),(4,2,'Radio');
/*!40000 ALTER TABLE `bdsis_parameter_type_table` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bdsis_test_table`
--

DROP TABLE IF EXISTS `bdsis_test_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bdsis_test_table` (
  `test_id` int(11) NOT NULL AUTO_INCREMENT,
  `test_name` varchar(150) NOT NULL,
  `disease` varchar(150) NOT NULL,
  `icd_code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` tinyint(4) DEFAULT 1,
  `created_by` varchar(50) DEFAULT NULL,
  `created_on` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`test_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bdsis_test_table`
--

LOCK TABLES `bdsis_test_table` WRITE;
/*!40000 ALTER TABLE `bdsis_test_table` DISABLE KEYS */;
INSERT INTO `bdsis_test_table` VALUES (1,'Stool Culture & Sensitivity','Diarrhea','1A40','Bacterial culture',1,'tester','2026-06-17 11:31:56');
/*!40000 ALTER TABLE `bdsis_test_table` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-21 16:50:30
