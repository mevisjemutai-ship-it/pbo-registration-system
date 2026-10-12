-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: pbo_portal
-- ------------------------------------------------------
-- Server version	8.0.46-0ubuntu0.24.04.4

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `application_addresses`
--

DROP TABLE IF EXISTS `application_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `county` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sub_county` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `physical_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alternative_phone_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_address` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_id` (`application_id`),
  CONSTRAINT `fk_address_application` FOREIGN KEY (`application_id`) REFERENCES `pbo_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application_addresses`
--

LOCK TABLES `application_addresses` WRITE;
/*!40000 ALTER TABLE `application_addresses` DISABLE KEYS */;
INSERT INTO `application_addresses` VALUES (1,1,'nk','jkmi','tyhjxmxs','67890','7890','ictsupport@pbora.go.ke','6789','6789'),(2,2,'zxcvbn','eldoret','34567','456789','67890','ictsupport@pbora.go.ke','6789','6789'),(7,3,'Nairobi City County','Starehe Sub-County','Co-operative Bank House, 15th Floor','+254 (0) 111053310','(020) 2213938','ictsupport@pbora.go.ke','P.O. Box 44617 – 00100','00100'),(9,4,'uasingishu','eldoret','34567','456789','09876','ictsupport@pbora.go.ke','dfgyhujnm','yui8jknmx'),(10,5,'Nairobi City County','','bncm78','+254 (0) 111053310','(020) 2213938','ictsupport@pbora.go.ke','dfgyhujnm',''),(20,7,'Nairobi City County','Westlands','Safaricom House, L.R. No. 209 / 15029','+254 722 002100','+254 722 002200','investorrelations@safaricom.co.ke','P.O. Box 66827 - 00800','00800'),(23,8,'uasingishu','','Safaricom House, L.R. No. 209 / 15029','+254 722 002100','7890','ictsupport@pbora.go.ke','5678',''),(32,9,'uasingishu','','','',NULL,'','','');
/*!40000 ALTER TABLE `application_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `application_audit_log`
--

DROP TABLE IF EXISTS `application_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_audit_application` (`application_id`),
  KEY `fk_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_application` FOREIGN KEY (`application_id`) REFERENCES `pbo_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application_audit_log`
--

LOCK TABLES `application_audit_log` WRITE;
/*!40000 ALTER TABLE `application_audit_log` DISABLE KEYS */;
INSERT INTO `application_audit_log` VALUES (1,1,1,'APPLICATION_CREATED','Organization details completed and application draft created with reference PBO.REG.2026.0001.','2026-09-30 09:02:21'),(2,1,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-09-30 09:02:33'),(3,1,1,'BENEFICIARIES_SAVED','1 Section of the Public beneficiary group(s) saved.','2026-09-30 09:03:37'),(4,1,1,'FINANCIAL_YEAR_SAVED','Financial year recorded as May to April.','2026-09-30 09:03:51'),(5,1,1,'OFFICIALS_SAVED','5 official/director record(s) saved.','2026-09-30 09:05:45'),(6,1,1,'APPLICATION_SUBMITTED','PBO application reviewed and submitted.','2026-09-30 09:06:40'),(7,2,1,'APPLICATION_CREATED','Organization details completed and application draft created with reference PBO.REG.2026.0002.','2026-09-30 09:13:17'),(8,2,1,'OBJECTIVES_SAVED','6 public benefit purpose(s) saved.','2026-09-30 09:14:53'),(9,2,1,'BENEFICIARIES_SAVED','3 Section of the Public beneficiary group(s) saved.','2026-09-30 09:15:04'),(10,2,1,'FINANCIAL_YEAR_SAVED','Financial year recorded as January to December.','2026-09-30 09:16:45'),(11,2,1,'OFFICIALS_SAVED','5 official/director record(s) saved.','2026-09-30 09:18:51'),(12,2,1,'APPLICATION_SUBMITTED','PBO application reviewed and submitted.','2026-09-30 09:19:11'),(13,3,1,'APPLICATION_CREATED','Organization details completed and application draft created with reference PBO.REG.2026.0003.','2026-09-30 12:59:47'),(14,3,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-09-30 12:59:57'),(15,3,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-09-30 13:08:04'),(16,3,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-09-30 13:14:46'),(17,3,1,'FINANCIAL_YEAR_SAVED','Financial year recorded as March to February.','2026-09-30 13:14:51'),(18,4,1,'APPLICATION_CREATED','Organization details completed and application draft created with reference PBO.REG.2026.0004.','2026-09-30 13:31:35'),(19,5,1,'APPLICATION_CREATED','Organization details completed and application draft created with reference PBO.REG.2026.0005.','2026-10-01 05:41:14'),(20,5,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 05:44:37'),(21,5,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 06:30:51'),(28,4,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 07:27:48'),(29,4,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 07:38:51'),(30,4,1,'OBJECTIVES_SAVED','3 public benefit purpose(s) saved.','2026-10-01 07:38:59'),(31,4,1,'OBJECTIVES_SAVED','3 public benefit purpose(s) saved.','2026-10-01 07:39:02'),(32,4,1,'BENEFICIARIES_SAVED','1 Section of the Public beneficiary group(s) saved.','2026-10-01 07:58:27'),(33,4,1,'BENEFICIARIES_SAVED','1 Section of the Public beneficiary group(s) saved.','2026-10-01 07:59:19'),(34,4,1,'FINANCIAL_YEAR_SAVED','Financial year recorded as August – July.','2026-10-01 08:15:24'),(35,4,1,'FINANCIAL_YEAR_SAVED','Financial year recorded as August – July.','2026-10-01 08:15:29'),(36,4,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 08:34:01'),(37,4,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 08:34:15'),(38,4,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 08:42:34'),(39,4,1,'APPLICATION_SUBMITTED','PBO application reviewed and submitted.','2026-10-01 08:51:19'),(40,3,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 11:24:01'),(41,3,1,'APPLICATION_SUBMITTED','PBO application reviewed and submitted.','2026-10-01 11:24:20'),(42,5,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 11:25:28'),(43,5,1,'OBJECTIVES_SAVED','3 public benefit purpose(s) saved.','2026-10-01 11:25:36'),(44,5,1,'BENEFICIARIES_SAVED','2 Section of the Public beneficiary group(s) saved.','2026-10-01 11:25:51'),(45,5,1,'FINANCIAL_YEAR_SAVED','Financial year recorded as October – September.','2026-10-01 11:26:00'),(46,5,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 11:27:52'),(47,5,1,'APPLICATION_SUBMITTED','PBO application reviewed and submitted.','2026-10-01 11:28:11'),(48,7,1,'APPLICATION_CREATED','Organization details completed and application draft created with reference PBO.REG.2026.0007.','2026-10-01 12:25:28'),(49,7,1,'OBJECTIVES_SAVED','3 public benefit purpose(s) saved.','2026-10-01 12:25:50'),(50,7,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 12:25:58'),(51,7,1,'FINANCIAL_YEAR_SAVED','Financial year recorded as October – September.','2026-10-01 12:26:05'),(52,7,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 12:27:23'),(53,7,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 12:33:00'),(54,7,1,'OBJECTIVES_SAVED','3 public benefit purpose(s) saved.','2026-10-01 12:33:03'),(55,7,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 12:33:06'),(56,7,1,'OBJECTIVES_SAVED','3 public benefit purpose(s) saved.','2026-10-01 12:33:10'),(57,7,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 12:33:12'),(58,7,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 12:33:12'),(59,7,1,'OBJECTIVES_SAVED','3 public benefit purpose(s) saved.','2026-10-01 12:33:15'),(60,7,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 12:33:16'),(61,7,1,'OBJECTIVES_SAVED','3 public benefit purpose(s) saved.','2026-10-01 12:33:18'),(62,7,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 12:33:20'),(63,7,1,'APPLICATION_SUBMITTED','PBO application reviewed and submitted.','2026-10-01 12:33:29'),(64,8,1,'APPLICATION_CREATED','Organization details completed and application draft created with reference PBO.REG.2026.0008.','2026-10-01 12:48:37'),(65,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:48:44'),(66,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:48:45'),(67,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:48:48'),(68,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:48:50'),(69,8,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 12:48:51'),(70,8,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 12:48:56'),(71,8,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 12:48:58'),(72,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:49:00'),(73,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:49:01'),(74,8,1,'FINANCIAL_YEAR_SAVED','Financial year section saved as an incomplete draft.','2026-10-01 12:49:04'),(75,8,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 12:49:05'),(76,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:49:08'),(77,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:49:10'),(78,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:49:11'),(79,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:49:13'),(80,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:49:14'),(81,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:49:16'),(82,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:49:17'),(83,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:49:18'),(84,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:49:19'),(85,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:49:21'),(86,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:49:22'),(87,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:49:23'),(88,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:49:26'),(89,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:54:52'),(90,8,1,'FINANCIAL_YEAR_SAVED','Financial year section saved as an incomplete draft.','2026-10-01 12:54:53'),(91,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:54:56'),(92,8,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 12:54:59'),(93,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:55:00'),(94,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:55:01'),(95,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:55:02'),(96,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:55:03'),(97,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:55:05'),(98,8,1,'BENEFICIARIES_SAVED','0 Section of the Public beneficiary group(s) saved.','2026-10-01 12:55:06'),(99,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:55:07'),(100,8,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 12:55:14'),(101,8,1,'FINANCIAL_YEAR_SAVED','Financial year section saved as an incomplete draft.','2026-10-01 12:55:16'),(102,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 12:55:17'),(103,8,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 13:17:02'),(104,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:17:03'),(105,8,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 13:17:05'),(106,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:17:11'),(107,8,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 13:17:13'),(108,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:17:17'),(109,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:17:19'),(110,8,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 13:17:55'),(111,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:17:56'),(112,8,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 13:17:57'),(113,8,1,'FINANCIAL_YEAR_SAVED','Financial year section saved as an incomplete draft.','2026-10-01 13:17:57'),(114,8,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 13:17:58'),(115,8,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 13:18:00'),(116,8,1,'FINANCIAL_YEAR_SAVED','Financial year section saved as an incomplete draft.','2026-10-01 13:18:01'),(117,8,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 13:18:03'),(118,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:18:05'),(119,8,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 13:18:07'),(120,8,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 13:18:08'),(121,8,1,'FINANCIAL_YEAR_SAVED','Financial year section saved as an incomplete draft.','2026-10-01 13:18:08'),(122,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:18:09'),(123,8,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 13:18:10'),(124,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:18:11'),(125,8,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 13:18:12'),(126,8,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 13:18:14'),(127,8,1,'FINANCIAL_YEAR_SAVED','Financial year section saved as an incomplete draft.','2026-10-01 13:18:14'),(128,8,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 13:18:15'),(129,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:44:18'),(130,8,1,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-01 13:44:19'),(131,8,1,'FINANCIAL_YEAR_SAVED','Financial year section saved as an incomplete draft.','2026-10-01 13:44:20'),(132,8,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-01 13:44:21'),(133,8,1,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-01 13:44:22'),(134,8,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-01 13:44:24'),(135,8,1,'FINANCIAL_YEAR_SAVED','Financial year recorded as November – October.','2026-10-02 05:35:13'),(136,8,1,'BENEFICIARIES_SAVED','1 Section of the Public beneficiary group(s) saved.','2026-10-02 05:35:34'),(137,8,1,'OFFICIALS_SAVED','Principal officials saved. No additional directors recorded.','2026-10-02 06:13:31'),(138,8,1,'ORGANIZATION_DETAILS_UPDATED','Organization particulars, address and applicable international registration details updated.','2026-10-02 06:13:46'),(139,9,4,'APPLICATION_CREATED','Organization details completed and application draft created with reference PBO.REG.2026.0009.','2026-10-02 07:09:42'),(140,9,4,'OBJECTIVES_SAVED','1 public benefit purpose(s) saved.','2026-10-02 07:09:45'),(141,9,4,'BENEFICIARIES_SAVED','Beneficiaries recorded as Public.','2026-10-02 07:09:48'),(142,7,1,'admin_edit_authorization','Administrator re-authenticated and was authorized to edit submitted PBO record.','2026-10-06 06:06:35'),(143,4,1,'admin_edit_authorization','Administrator re-authenticated and was authorized to edit submitted PBO record.','2026-10-06 06:07:59'),(144,4,1,'admin_edit_authorization','Administrator re-authenticated and was authorized to edit submitted PBO record.','2026-10-06 06:11:41'),(145,4,1,'admin_edit_authorization','Administrator re-authenticated and was authorized to edit submitted PBO record.','2026-10-06 06:35:01'),(146,4,1,'admin_edit_authorization','Administrator re-authenticated and was authorized to edit submitted PBO record.','2026-10-06 06:46:42'),(147,4,1,'submitted_record_edited','Administrator edited the submitted PBO record. Organization, address, international details where applicable, objectives, beneficiaries, financial year and officials were processed in one transaction. Directors are optional. Existing official signatures were preserved.','2026-10-06 06:47:03'),(148,4,1,'admin_edit_authorization','Administrator re-authenticated and was authorized to edit submitted PBO record.','2026-10-06 06:47:18'),(149,2,1,'admin_edit_authorization','Administrator re-authenticated and was authorized to edit submitted PBO record.','2026-10-06 07:06:10'),(150,7,1,'admin_edit_authorization','Administrator re-authenticated and was authorized to edit submitted PBO record.','2026-10-06 07:20:51');
/*!40000 ALTER TABLE `application_audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `application_beneficiaries`
--

DROP TABLE IF EXISTS `application_beneficiaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_beneficiaries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `beneficiary_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_application_beneficiary` (`application_id`,`beneficiary_code`),
  CONSTRAINT `fk_beneficiary_application` FOREIGN KEY (`application_id`) REFERENCES `pbo_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application_beneficiaries`
--

LOCK TABLES `application_beneficiaries` WRITE;
/*!40000 ALTER TABLE `application_beneficiaries` DISABLE KEYS */;
INSERT INTO `application_beneficiaries` VALUES (1,1,'THE_POOR',NULL),(2,2,'ELDERLY',NULL),(3,2,'REFUGEES',NULL),(4,2,'STREET_CHILDREN',NULL),(5,3,'PUBLIC',NULL),(8,5,'THE_POOR',NULL),(9,5,'PRISONERS_EX_CONVICTS',NULL),(14,7,'PUBLIC',NULL),(24,8,'DRUG_ALCOHOL_ADDICTS',NULL),(25,9,'PUBLIC',NULL),(26,4,'ELDERLY',NULL);
/*!40000 ALTER TABLE `application_beneficiaries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `application_international_details`
--

DROP TABLE IF EXISTS `application_international_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_international_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `parent_organization_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_of_origin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_physical_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_postal_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_postal_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_phone_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person_phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_id` (`application_id`),
  CONSTRAINT `fk_international_application` FOREIGN KEY (`application_id`) REFERENCES `pbo_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application_international_details`
--

LOCK TABLES `application_international_details` WRITE;
/*!40000 ALTER TABLE `application_international_details` DISABLE KEYS */;
INSERT INTO `application_international_details` VALUES (1,1,'hjuki','yhnj s','7890','tgyui89','okjn x 9','0y890','567890','ictsupport@pbora.go.ke','PBORA ICT Support Assistant','67890','ictsupport@pbora.go.ke'),(2,7,'Vodacom Group Limited','South Africa','67890','Vodacom Corporate Park, 05/08 Vodafone Boulevard','Private Bag X9904, Sandton, 2146, South Africa.','2146','07456875465','dpo@safaricom.co.ke','Lilian George','+27 11 653 5000','companysecretary@vodacom.co.za');
/*!40000 ALTER TABLE `application_international_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `application_objectives`
--

DROP TABLE IF EXISTS `application_objectives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_objectives` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `objective_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_application_objective` (`application_id`,`objective_code`),
  CONSTRAINT `fk_objective_application` FOREIGN KEY (`application_id`) REFERENCES `pbo_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application_objectives`
--

LOCK TABLES `application_objectives` WRITE;
/*!40000 ALTER TABLE `application_objectives` DISABLE KEYS */;
INSERT INTO `application_objectives` VALUES (1,1,'YOUTH',NULL),(2,2,'DISABILITY',NULL),(3,2,'GENDER',NULL),(4,2,'HOUSING_SETTLEMENT',NULL),(5,2,'INFORMAL_SECTOR',NULL),(6,2,'POPULATION_REPRODUCTIVE_HEALTH',NULL),(7,2,'SPORTS',NULL),(8,3,'SPORTS',NULL),(17,5,'HEALTH',NULL),(18,5,'INFORMAL_SECTOR',NULL),(19,5,'OLD_AGE',NULL),(32,7,'EDUCATION',NULL),(33,7,'GOVERNANCE',NULL),(34,7,'HUMAN_RIGHTS',NULL),(59,8,'CULTURE',NULL),(60,9,'ENERGY',NULL),(61,4,'PEACE_BUILDING',NULL),(62,4,'POPULATION_REPRODUCTIVE_HEALTH',NULL),(63,4,'SPORTS',NULL);
/*!40000 ALTER TABLE `application_objectives` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `application_officials`
--

DROP TABLE IF EXISTS `application_officials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_officials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `designation` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nationality` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_passport_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kra_pin` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_official_application` (`application_id`),
  CONSTRAINT `fk_official_application` FOREIGN KEY (`application_id`) REFERENCES `pbo_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application_officials`
--

LOCK TABLES `application_officials` WRITE;
/*!40000 ALTER TABLE `application_officials` DISABLE KEYS */;
INSERT INTO `application_officials` VALUES (1,1,'nuj','CHAIRPERSON','gyui','345678','4567','7890','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/1/signatures/chairperson-baba4a6b2b7e675fe71175e5.pdf','2026-09-30 09:05:45'),(2,1,'PBORA ICT Support Assistant','TREASURER','drftgyh','890-','66789','987','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/1/signatures/treasurer-c0a007d3eee000acb6dbabf2.pdf','2026-09-30 09:05:45'),(3,1,'PBORA ICT Support Assistant','SECRETARY','sdfghnmj','5678','34567','6789','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/1/signatures/secretary-57c754ef89331d893104816a.pdf','2026-09-30 09:05:45'),(4,1,'PBORA ICT Support Assistant','DIRECTOR','dfghn','67890','3456','56789','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/1/signatures/director-1-fa126190a5b2cc798f62a1f9.pdf','2026-09-30 09:05:45'),(5,1,'PBORA ICT Support Assistant','DIRECTOR','dfghjkl','567890','456789','677890','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/1/signatures/director-2-37847759ced6a95e85da73c5.pdf','2026-09-30 09:05:45'),(6,2,'iokujn','CHAIRPERSON','2345','56789','4567','7890','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/2/signatures/chairperson-9e5a30fca5e9ac658336af59.pdf','2026-09-30 09:18:51'),(7,2,'PBORA ICT Support Assistant','TREASURER','dfujk','890','66789','987','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/2/signatures/treasurer-bd11fcc560e4eb1c8990a18e.pdf','2026-09-30 09:18:51'),(8,2,'PBORA ICT Support Assistant','SECRETARY','sdfghnmj','890','34567','7890','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/2/signatures/secretary-7d96385cd553d1b655dfbbdc.pdf','2026-09-30 09:18:51'),(9,2,'dfghgjjk','DIRECTOR','dfghn','67890','4567','56789','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/2/signatures/director-1-7c32e4d4047fe2e2219af45d.pdf','2026-09-30 09:18:51'),(10,2,'PBORA ICT Support Assistant','DIRECTOR','dfghjkl','567890','456789','7890','ictsupport@pbora.go.ke','/pbo-portal/uploads/applications/2/signatures/director-2-c9431b3ef0a2996cb7d5f9fb.pdf','2026-09-30 09:18:51'),(20,3,'Dianee Maya','CHAIRPERSON','Kenyan','42285899','4567','+39 055 75921','ictsupport@pbora.go.ke',NULL,'2026-10-01 11:24:01'),(21,3,'PBORA ICT Support Assistant','TREASURER','Italian','32449955','6789','0742445859','ictsupport@pbora.go.ke',NULL,'2026-10-01 11:24:01'),(22,3,'Mike Harris','SECRETARY','Kenyan','7653456','7857','543454445','ictsupport@pbora.go.ke',NULL,'2026-10-01 11:24:01'),(23,5,'olkjm','CHAIRPERSON','Kenyan','4567832','4567','+39 055 75921','ictsupport@pbora.go.ke',NULL,'2026-10-01 11:27:52'),(24,5,'Kittony Laban','TREASURER','USA','3456778','6u7i8','09835473642','ictsupport@pbora.go.ke',NULL,'2026-10-01 11:27:52'),(25,5,'PBORA ICT Support Assistant','SECRETARY','sdfgh','89067898','34567','+2544689908','ictsupport@pbora.go.ke',NULL,'2026-10-01 11:27:52'),(26,7,'muijokuj','CHAIRPERSON','Kenyan','42285899','4567','+39 055 75921','ictsupport@pbora.go.ke',NULL,'2026-10-01 12:27:23'),(27,7,'redwig','TREASURER','Italian','3456786','768989','09835473642','ictsupport@pbora.go.ke',NULL,'2026-10-01 12:27:23'),(28,7,'PBORA ICT Support Assistant','SECRETARY','Kenyan','5645677','edxfcv','564756098','ictsupport@pbora.go.ke',NULL,'2026-10-01 12:27:23'),(29,4,'PBORA ICT Support Assistant','CHAIRPERSON','hijko,l','7890','4567','7890','ictsupport@pbora.go.ke',NULL,'2026-10-06 06:47:03'),(30,4,'PBORA ICT Support Assistant','TREASURER','edrftyguhj','34567','6789','67890','ictsupport@pbora.go.ke',NULL,'2026-10-06 06:47:03'),(31,4,'PBORA ICT Support Assistant','SECRETARY','sdfgh','5678','34567','67890','ictsupport@pbora.go.ke',NULL,'2026-10-06 06:47:03');
/*!40000 ALTER TABLE `application_officials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `application_operating_locations`
--

DROP TABLE IF EXISTS `application_operating_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_operating_locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `county` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sub_county` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `physical_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alternative_phone_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_address` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_application_operating_county` (`application_id`,`county`),
  KEY `idx_operating_locations_application` (`application_id`),
  CONSTRAINT `fk_operating_location_application` FOREIGN KEY (`application_id`) REFERENCES `pbo_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application_operating_locations`
--

LOCK TABLES `application_operating_locations` WRITE;
/*!40000 ALTER TABLE `application_operating_locations` DISABLE KEYS */;
/*!40000 ALTER TABLE `application_operating_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `application_reference_sequences`
--

DROP TABLE IF EXISTS `application_reference_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_reference_sequences` (
  `reference_year` smallint unsigned NOT NULL,
  `last_number` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`reference_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `application_reference_sequences`
--

LOCK TABLES `application_reference_sequences` WRITE;
/*!40000 ALTER TABLE `application_reference_sequences` DISABLE KEYS */;
INSERT INTO `application_reference_sequences` VALUES (2026,9);
/*!40000 ALTER TABLE `application_reference_sequences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `applications`
--

DROP TABLE IF EXISTS `applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `organization_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_type` enum('national','international','international_exempt') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','submitted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submitted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_number` (`application_number`),
  KEY `idx_applications_organization_name` (`organization_name`),
  KEY `idx_applications_status` (`status`),
  KEY `idx_applications_created_by` (`created_by`),
  KEY `idx_applications_created_at` (`created_at`),
  CONSTRAINT `fk_applications_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `applications`
--

LOCK TABLES `applications` WRITE;
/*!40000 ALTER TABLE `applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notices`
--

DROP TABLE IF EXISTS `notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_notices_created_by` (`created_by`),
  CONSTRAINT `fk_notices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notices`
--

LOCK TABLES `notices` WRITE;
/*!40000 ALTER TABLE `notices` DISABLE KEYS */;
/*!40000 ALTER TABLE `notices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pbo_applications`
--

DROP TABLE IF EXISTS `pbo_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pbo_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_reference` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pbo_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pbo_registration_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `registration_type` enum('national','international') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('draft','submitted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `current_step` tinyint unsigned NOT NULL DEFAULT '1',
  `financial_year_start_month` tinyint unsigned DEFAULT NULL,
  `financial_year_end_month` tinyint unsigned DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `updated_by` bigint unsigned NOT NULL,
  `submitted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submitted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_application_reference` (`application_reference`),
  KEY `fk_application_created_by` (`created_by`),
  KEY `fk_application_updated_by` (`updated_by`),
  KEY `fk_application_submitted_by` (`submitted_by`),
  KEY `idx_pbo_registry_status_date` (`status`,`registration_date`),
  KEY `idx_pbo_registry_status_type_date` (`status`,`registration_type`,`registration_date`),
  KEY `idx_pbo_registration_number` (`pbo_registration_number`),
  KEY `idx_pbo_name` (`pbo_name`),
  CONSTRAINT `fk_application_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_application_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_application_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pbo_applications`
--

LOCK TABLES `pbo_applications` WRITE;
/*!40000 ALTER TABLE `pbo_applications` DISABLE KEYS */;
INSERT INTO `pbo_applications` VALUES (1,'PBO.REG.2026.0001','Charity','2039',NULL,'international','submitted',6,5,4,1,1,1,'2026-09-30 09:02:21','2026-09-30 09:06:40','2026-09-30 09:06:40'),(2,'PBO.REG.2026.0002','Charity','2039',NULL,'national','submitted',6,1,12,1,1,1,'2026-09-30 09:13:17','2026-09-30 09:19:11','2026-09-30 09:19:11'),(3,'PBO.REG.2026.0003','Public benefta','2039','2022-03-28','national','submitted',6,3,2,1,1,1,'2026-09-30 12:59:47','2026-10-01 11:24:20','2026-10-01 11:24:20'),(4,'PBO.REG.2026.0004','Charity','209876','2026-09-30','national','submitted',6,8,7,1,1,1,'2026-09-30 13:31:35','2026-10-06 06:47:03','2026-10-01 08:51:19'),(5,'PBO.REG.2026.0005','Charity','078990','1995-05-02','national','submitted',6,10,9,1,1,1,'2026-10-01 05:41:14','2026-10-01 11:28:11','2026-10-01 11:28:11'),(7,'PBO.REG.2026.0007','Safaricom','20397','1999-07-16','international','submitted',6,10,9,1,1,1,'2026-10-01 12:25:28','2026-10-01 12:33:29','2026-10-01 12:33:29'),(8,'PBO.REG.2026.0008','ujfkmd','irkfd,ce','2021-02-01','national','draft',6,11,10,1,1,NULL,'2026-10-01 12:48:37','2026-10-02 06:13:46',NULL),(9,'PBO.REG.2026.0009','Safaricom','078990','2026-03-21','national','draft',4,NULL,NULL,4,4,NULL,'2026-10-02 07:09:42','2026-10-02 07:09:48',NULL);
/*!40000 ALTER TABLE `pbo_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','staff') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'mjemutai','Kiplagat Mevis Jemutai','$2y$10$VosYvQfxYV4f8jOjwhE6VuewvZEmcNaEJAVIMqbqG3k5AzwVNm.1u','admin',1,0,'2026-10-06 06:05:55','2026-09-28 09:40:24','2026-10-06 06:05:55'),(4,'jmulei','Jonathan mulei','$2y$10$B4/vh7YYHdKP94IsPhIFOOrz70OXCI25bgeThsnQG.RVSjgZTnbpm','staff',1,0,'2026-10-02 06:52:19','2026-10-02 06:51:58','2026-10-05 11:04:25'),(5,'vwanyonyi','Velma Wanyonyi','$2y$10$JPsVigzbonDoCIo/CXHY4.JrQI4GhxKLMaOp3i9KspcMDaTlNtAmq','staff',1,0,'2026-10-05 11:05:16','2026-10-05 11:04:59','2026-10-05 11:05:27');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'pbo_portal'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-06 12:04:54
