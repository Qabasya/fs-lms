/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.6.27-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: wordpress
-- ------------------------------------------------------
-- Server version	10.6.27-MariaDB-ubu2204

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
-- Table structure for table `wp_fs_lms_persons`
--

DROP TABLE IF EXISTS `wp_fs_lms_persons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_fs_lms_persons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `wp_user_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `doc_type` varchar(30) DEFAULT NULL,
  `full_name_enc` longblob DEFAULT NULL,
  `doc_number_enc` longblob DEFAULT NULL,
  `inn_enc` longblob DEFAULT NULL,
  `address_enc` longblob DEFAULT NULL,
  `phone_enc` longblob DEFAULT NULL,
  `doc_number_hash` varchar(64) DEFAULT NULL,
  `inn_hash` varchar(64) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `wp_user_id` (`wp_user_id`),
  KEY `doc_number_hash` (`doc_number_hash`),
  KEY `inn_hash` (`inn_hash`),
  KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_fs_lms_persons`
--

LOCK TABLES `wp_fs_lms_persons` WRITE;
/*!40000 ALTER TABLE `wp_fs_lms_persons` DISABLE KEYS */;
INSERT INTO `wp_fs_lms_persons` VALUES (1,3,'max@fs.ru','1998-12-12','pass','Ïm5%\Z¡‘íô-Üª i-†;ÃÎ+Ê†%u9œê¯CU¡š\na¥ñVK/€ÿñ„QdËB­³UZ	O.WÙ1”çYÔÀâş¯))MÇÒ1h ;¶­','Ğ@šÁDò! «	í¥øcEºmƒ³ºk^“©…å^êXğãG\r»i\'ÂË¡ÜËÇ”™ñú_','?_3¹°ÃÔ™eYÙ¹{\Z\\gGt$¨78³JÙm=\r)Ït³›œ™”¼{ôrß%G¨oŸ™',NULL,'ò³\'şæòZ†;S¼GxàÍ¸˜‚ªBpäzåj8Ì®k\Z	‡È=}<NA0ç6s','6de0fe402a0f2b57745b269b3a30603dbdf2f232b55eecf8cc671338f0f8fba2','602d5e8bddc723069763ab4a929171869d2f37b69e197d8ca899ab12967039e8',NULL,'2026-06-03 18:33:54','2026-06-03 20:09:49'),(2,4,'yos@gmail.com','1980-08-08','pass','\0k6Í»à\'1½åš3\Zæ£~#ÉÀë‡€óv¿Â‡›r!ƒu²1Í?Èa‚V\n’ôzNæ³»£Şı®b2Ü½ÅÚ˜ó%\0\n@›iÁûT|Åñçgsûˆ','Ÿîdåd~dCzÙıÃÇİQ,uÓÍöFcl!©ÄE€³6‚`ıÍ¡‡ınä{Kq”9t­’','ª×¼´@÷q\rœïJiè‰¯/]ªÅü\'1+.NƒT•	å_%Í4J»7ñ´¶','µ3ù/,XëCjˆ‹M=­&÷—éß½œSN;­\ZÅDa}Şu3£õ~Ã‡Td‘zÈ£ùg.o¼×´hƒå¨\\','è$÷‰úWl³É ìlbRÆŠ×@ş‰tTK~7³˜ÉâøÂl)ò„D­LÈ«\n ?l','b6c0f5468af4853440c33e0dd3080354e587bc44244154530795f47d8e1b54c8','adb838c130ce325be5877121ff349324777a907c1b2f0dda03b677c68d63d162',NULL,'2026-06-03 18:33:54','2026-06-03 18:33:54');
/*!40000 ALTER TABLE `wp_fs_lms_persons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wp_fs_lms_applications`
--

DROP TABLE IF EXISTS `wp_fs_lms_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_fs_lms_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_person_id` bigint(20) unsigned DEFAULT NULL,
  `parent_person_id` bigint(20) unsigned DEFAULT NULL,
  `period_key` varchar(50) NOT NULL,
  `status` varchar(50) NOT NULL,
  `join_code_hash` varchar(64) DEFAULT NULL,
  `join_code_enc` blob DEFAULT NULL,
  `join_code_expires_at` datetime DEFAULT NULL,
  `student_email_hash` varchar(64) DEFAULT NULL,
  `student_data_enc` longblob DEFAULT NULL,
  `parent_data_enc` longblob DEFAULT NULL,
  `converted_to_enrollment_id` bigint(20) unsigned DEFAULT NULL,
  `parent_submitted_ip` varchar(45) DEFAULT NULL,
  `parent_submitted_ua` varchar(500) DEFAULT NULL,
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_person_id` (`student_person_id`),
  KEY `parent_person_id` (`parent_person_id`),
  KEY `status` (`status`),
  KEY `join_code_hash` (`join_code_hash`),
  KEY `student_email_hash` (`student_email_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_fs_lms_applications`
--

LOCK TABLES `wp_fs_lms_applications` WRITE;
/*!40000 ALTER TABLE `wp_fs_lms_applications` DISABLE KEYS */;
INSERT INTO `wp_fs_lms_applications` VALUES (2,NULL,NULL,'','pending_parent','0a99aa0c0a158c08bc7baca623f7272129bbd189756df70af5f2afecdc32d5f4','´:NÑ’m>ïÍ6Oj>*…áèºÚ2x¢Uş0úğŸ²¾Há­â˜°Š¡“yO#ïûHÃ^Sl¶','2026-06-18 22:31:24','aba979114200def35a5ecb255fe20202fde3825eb936ebb3b74eae9666f563b5','H•9Ã|iş ÀĞÂïùÁ6\nµôùæù;º`Ï_ÙC`±¤m6„{	Ö‡›>‚8ÌOP§–³áªšS&Ú#ÚÛ%rîsb´œ¿:@°®DûtŞœĞP+BRJXsNÅH>Ço?.“o…úàæ²ÕË[öémíPãsD~ö‹T™±—\nôkZÇH\'|=tëû´´•\n÷ºŞĞíŸ…ú«¦²­n™É4Şã×7’ÛXyõƒj«©„\r«j\ZtHz!e4Ñã²=×ê\ZEo¾!…;¬192g¨L¯øc¾ş÷gÒA|Ş@—ÍÄNæBJY—êzÛ”ºÙ¾KÆ]øO–Õmd_Ä>g¬%ìÈ¯»ÁÍSŠØòç±ªüŠğ×ßù%×å<œ´wø!£Š©\rÙ‡ÇH©ë½GõoÔIo@>ú\Zñ°µøÍÜ^\Zkdş7í¶œ¯îşÁHV\'=‘ÖÑ°)Ü7·Pêg£ìícR½|´Ÿ.¼)üÑ¶·/¶<ˆ¦\rÂùŞ|·Ó„C¶JZ,^–ËjäCàiï]ò-k;½H¸/M¢P¾†X€¹v|23™\"o\0$æôşŠÜ¹Ÿxª\nşÕB_#¹·Vó3éÊşy9°-`8u­:[«œÍa`·w\nPwğt`ê\0ù:Ã™µı`o!¤öoâßèÂÂŒ¾#]à­\"›ê«(¡;\rÌn×D{zYÅ\\ö£7°5™0ş{)\0©\'HiÛi¾Úpz‘7p?Ÿ­Ø·õ¼Ú©Â®3Ü·³î„|Ÿ[·Iv	ÏZ–Cv}Õ¨×\'‹O˜W',NULL,NULL,'172.18.0.1',NULL,NULL,'2026-06-04 22:31:24','2026-06-04 22:31:24');
/*!40000 ALTER TABLE `wp_fs_lms_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wp_fs_lms_consents`
--

DROP TABLE IF EXISTS `wp_fs_lms_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_fs_lms_consents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint(20) unsigned DEFAULT NULL,
  `person_id` bigint(20) unsigned DEFAULT NULL,
  `subject_role` varchar(20) NOT NULL,
  `consent_type` varchar(50) NOT NULL,
  `version` varchar(20) NOT NULL,
  `document_hash` varchar(64) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `accepted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `valid_until` datetime DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `withdrawn_reason` text DEFAULT NULL,
  `signed_for_person_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `application_id` (`application_id`),
  KEY `person_id` (`person_id`),
  KEY `consent_type` (`consent_type`),
  KEY `signed_for_person_id` (`signed_for_person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_fs_lms_consents`
--

LOCK TABLES `wp_fs_lms_consents` WRITE;
/*!40000 ALTER TABLE `wp_fs_lms_consents` DISABLE KEYS */;
/*!40000 ALTER TABLE `wp_fs_lms_consents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wp_fs_lms_audit_log`
--

DROP TABLE IF EXISTS `wp_fs_lms_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_fs_lms_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `actor_ip` varchar(45) NOT NULL,
  `actor_ua` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `action` (`action`),
  KEY `target_combined` (`target_type`,`target_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_fs_lms_audit_log`
--

LOCK TABLES `wp_fs_lms_audit_log` WRITE;
/*!40000 ALTER TABLE `wp_fs_lms_audit_log` DISABLE KEYS */;
INSERT INTO `wp_fs_lms_audit_log` VALUES (1,NULL,NULL,'consent_signed','consent',0,'{\"consent_type\":\"pd_processing\",\"version\":\"0fad87fa0be10c70e256e40e54c0c4bce1a8968bfb402d817778d9950239057e\",\"application_id\":1,\"subject_role\":\"self\"}','172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:28:47'),(2,NULL,NULL,'create_application','application',1,'{\"email_hash\":\"2eaa68ba1adb3e78100045ee18a13df2f463667af4060566e553af5f35fa35c8\"}','172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:28:47'),(3,NULL,NULL,'view_join_link','application',1,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:29:14'),(4,NULL,NULL,'consent_signed','consent',0,'{\"consent_type\":\"pd_child_processing\",\"version\":\"0fad87fa0be10c70e256e40e54c0c4bce1a8968bfb402d817778d9950239057e\",\"application_id\":1,\"subject_role\":\"guardian\",\"for_person_id\":0}','172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:30:20'),(5,NULL,NULL,'submit_parent_data','application',1,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:30:20'),(6,1,'administrator','start_enrollment','application',1,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:33:24'),(7,1,'administrator','start_enrollment','application',1,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:33:29'),(8,1,'administrator','create_relationship','relationship',1,'{\"guardian_person_id\":2,\"student_person_id\":1,\"relation_type\":\"father\",\"is_primary\":true}','172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:33:54'),(9,1,'administrator','enroll_student','enrollment',1,'{\"application_id\":1,\"subject_key\":\"math\"}','172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:33:54'),(10,1,'administrator','password_set','user',3,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:33:54'),(11,1,'administrator','password_set','user',4,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 18:33:54'),(12,1,'administrator','update_person','person',1,'{\"changed_fields\":[\"full_name\",\"phone\",\"email\",\"birth_date\"]}','172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 20:05:43'),(13,1,'administrator','password_set','user',3,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 20:05:43'),(14,1,'administrator','update_person','person',1,'{\"changed_fields\":[\"full_name\",\"doc_number\",\"inn\",\"phone\",\"email\",\"birth_date\"]}','172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 20:09:49'),(15,1,'administrator','password_set','user',3,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-03 20:09:49'),(16,NULL,NULL,'consent_signed','consent',0,'{\"consent_type\":\"pd_processing\",\"version\":\"0fad87fa0be10c70e256e40e54c0c4bce1a8968bfb402d817778d9950239057e\",\"application_id\":2,\"subject_role\":\"self\"}','172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-04 22:31:24'),(17,NULL,NULL,'create_application','application',2,'{\"email_hash\":\"aba979114200def35a5ecb255fe20202fde3825eb936ebb3b74eae9666f563b5\"}','172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-04 22:31:24'),(18,NULL,NULL,'view_join_link','application',2,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36','2026-06-04 22:32:08');
/*!40000 ALTER TABLE `wp_fs_lms_audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wp_fs_lms_pii_access_log`
--

DROP TABLE IF EXISTS `wp_fs_lms_pii_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_fs_lms_pii_access_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `person_id` bigint(20) unsigned NOT NULL,
  `fields_accessed` text NOT NULL,
  `access_reason` varchar(255) NOT NULL,
  `actor_ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `person_id` (`person_id`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_fs_lms_pii_access_log`
--

LOCK TABLES `wp_fs_lms_pii_access_log` WRITE;
/*!40000 ALTER TABLE `wp_fs_lms_pii_access_log` DISABLE KEYS */;
INSERT INTO `wp_fs_lms_pii_access_log` VALUES (1,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 19:06:32'),(2,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 19:06:32'),(3,1,'administrator',2,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 19:06:54'),(4,1,'administrator',2,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 19:06:54'),(5,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 19:13:31'),(6,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 19:13:31'),(7,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 19:25:33'),(8,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 19:25:33'),(9,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 19:30:55'),(10,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 19:30:55'),(11,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 19:36:30'),(12,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 19:36:30'),(13,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 19:41:30'),(14,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 19:41:30'),(15,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 19:47:37'),(16,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 19:47:46'),(17,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 19:47:46'),(18,1,'administrator',2,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 19:48:03'),(19,1,'administrator',2,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 19:48:11'),(20,1,'administrator',2,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 19:48:11'),(21,1,'administrator',2,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 19:48:30'),(22,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 19:52:59'),(23,1,'administrator',2,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 19:57:42'),(24,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 20:02:36'),(25,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 20:04:57'),(26,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 20:05:38'),(27,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 20:05:48'),(28,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 20:09:36'),(29,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 20:09:41'),(30,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_edit','172.18.0.1','2026-06-03 20:09:41'),(31,1,'administrator',2,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 20:19:47'),(32,1,'administrator',2,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 20:19:56'),(33,1,'administrator',2,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-03 20:19:56'),(34,1,'administrator',2,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 20:28:44'),(35,1,'administrator',2,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 20:28:47'),(36,1,'administrator',2,'doc_number,inn,address,phone','admin_userlist_edit','172.18.0.1','2026-06-03 20:28:47'),(37,1,'administrator',2,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 20:30:08'),(38,1,'administrator',2,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-03 20:30:10'),(39,1,'administrator',2,'doc_number,inn,address,phone','admin_userlist_edit','172.18.0.1','2026-06-03 20:30:10'),(40,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_edit','172.18.0.1','2026-06-03 20:30:10'),(41,1,'administrator',2,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-03 20:32:58'),(42,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 20:32:18'),(43,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 20:45:15'),(44,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 21:04:53'),(45,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 21:33:52'),(46,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 21:44:40'),(47,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-04 21:45:22'),(48,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_edit','172.18.0.1','2026-06-04 21:45:22'),(49,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 21:48:01'),(50,1,'administrator',1,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-04 21:48:16'),(51,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_edit','172.18.0.1','2026-06-04 21:48:16'),(52,1,'administrator',2,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 21:48:34'),(53,1,'administrator',2,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 21:52:55'),(54,1,'administrator',2,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-04 21:53:14'),(55,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-04 21:53:14'),(56,1,'administrator',2,'doc_number,inn,address,phone','admin_userlist_reveal','172.18.0.1','2026-06-04 21:53:14'),(57,1,'administrator',2,'login,password','admin_reveal_credentials','172.18.0.1','2026-06-04 21:53:18'),(58,1,'administrator',1,'doc_number,inn,address,phone','admin_userlist_edit','172.18.0.1','2026-06-04 21:53:18'),(59,1,'administrator',2,'doc_number,inn,address,phone','admin_userlist_edit','172.18.0.1','2026-06-04 21:53:18'),(60,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 22:35:17'),(61,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 22:36:53'),(62,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 22:44:03'),(63,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 22:46:06'),(64,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 22:47:09'),(65,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 22:48:22'),(66,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 22:49:39'),(67,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 22:50:12'),(68,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-04 22:51:26'),(69,1,'administrator',1,'doc_number,inn,address,phone','admin_masked_view','172.18.0.1','2026-06-05 21:28:31');
/*!40000 ALTER TABLE `wp_fs_lms_pii_access_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wp_fs_lms_enrollments`
--

DROP TABLE IF EXISTS `wp_fs_lms_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_fs_lms_enrollments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `student_person_id` bigint(20) unsigned NOT NULL,
  `source_application_id` bigint(20) unsigned DEFAULT NULL,
  `group_id` varchar(100) DEFAULT NULL,
  `subject_key` varchar(50) NOT NULL,
  `period_key` varchar(50) NOT NULL,
  `status` varchar(50) NOT NULL,
  `enrolled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `terminated_at` datetime DEFAULT NULL,
  `terminated_reason` text DEFAULT NULL,
  `terminated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `snapshot_enc` longblob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_subject_period` (`student_person_id`,`subject_key`,`period_key`),
  KEY `source_application_id` (`source_application_id`),
  KEY `group_id` (`group_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_fs_lms_enrollments`
--

LOCK TABLES `wp_fs_lms_enrollments` WRITE;
/*!40000 ALTER TABLE `wp_fs_lms_enrollments` DISABLE KEYS */;
INSERT INTO `wp_fs_lms_enrollments` VALUES (1,1,1,'matematika-1_20_20','math','20_20','active','2026-06-03 00:00:00',NULL,NULL,NULL,'‹¯Å³Ÿş\'\0åôĞ œˆ£é,±dÕOBºøQ¢®÷+I’GµË`I2êìÅÍ@{Ô±Øš¤1*\rLi|d$ ™PáV3İ¤2‡Pª¢sL9ŠÉ=6ôÖ÷[	4VÆüF„Æ=¢6ØÄ©K{Ã—h:ïÉD2e\rt¶iuÑıòM`â–7i\rŒ™òrB%ˆi|,ıˆZ\0êËP7e{‰waËZ¯`™<vnÈˆñ›J‰ï>9\\\0bngy eÃ¶ÉÌFæ{ƒ<©%p°¦s¼äÑÔVIl¨ì><VPEÌıô<Gå\n´şö‰PúCWZBh…õ‰=–¶õEi\Z°İë‚´+Ğa2åíÌ0øèŒçY§ùÜS|gOÜïèÏAÃ}§•êçh„Ú®¢7–é°ámÂèó!7,Àó‹w,éß1]ÚJ¥Ø\n«_ôİ­Şê¸ØÈLïÖsÿŠ3wB<•zŠİ+˜{:Ûéâµ\n~Gû(\n±deéÕvM™™5WßVÁ¹=£¨.Ú…t`æ}GØ”¢Š“éôß¨ŒÆj‰â‹!6§°^Š»:^¬LFG;\râT™„\0·°p¿sĞÇW<ŞTX´–Á-âªJ8*]İ:\"»\r‚®bè˜ÿ4S)55dø$Ó©¸†kréş.ûuŒÑïL´:Ày=f ÁG`ÿ+ÆøàèJõ4cØú\"sé\ZË·\0—œ¯\rÁÕS¯—¨c1Ğ×‹·ıx´£»ìã?O3H˜¯î •’‰Šqcy`3²¿êt7W˜ª®U0eL¼İÅB8Äoüâ\n\\üX	_Y%ÓI&ÁÍ›~\\!±èÄ¡‹Ÿb#§æƒG’ƒ—íÖò{ŞÏ!Ëö=C^•°ˆ]šÉFìpö×Ú•Óèšdı±à`n8ë1ÆdÄ\ZSqÊQD©ÚßH>ƒ§³Ú>.²S•¡cC÷ßŸã\0Â¢ÛY½¢œ“²E	o0kbã¿k+]5ÂÇZÁš4Âaj7…\Z‚‚ò!xeïÎ‡WW×”[Î¦³„ÔÂÏ­0¼)Â’#Œt=ª|hj`¡ešÛa‡¼LëÀŠ\n<™]³eâ¯ğ(V“›j„€µ×?©ö;Iq4(°tîgS*˜yé†&¸ršèYÔ NÕ—äNß¶ì+¢O€ìh×)ÖAªn\rÛ+íS²÷1“¾Ë€goµœy„<¥\Z­½Õ²AÀàEo1ÉFè05ZùyÆ CëødïÈïÈ\n¼®C¯²ƒº`âCÑ´c(£«ğEÿ|Ãx\'¦¼Ğñc\rF£1~]Gs‡½$gä¬ØÙè=JlX1äDW±8J	\0Á\\B¢¥1>Ù{@pÒ\0Áiı½¹-eN¦{nT %ß‹‚]*²´Ô5¦W;7ıÌ`?•b„nám{{(sñıƒ4qG¶¥\rÓ«|†\Z­¥{Î96Üø¦—|av×ß…&5÷½:L¼(qÚÏ·y“ß9gşédÿÖGÀ³„Ê#Y\'J(m$µ³İ¡)N(\Z¤ü³İËüöJpêÏ~uÒs ZíÑo;İ].ö¿«w€Ş#èÇóñdĞ¶¹KGOpôJêèÔ‚nb´ÌlÅ=Æ²mÙ1›øE‹ƒwp	$ÂóØ…ÕÆÕÕd6ÏÖ?H™½şPo;Ş7§è)›• –0p|¦(¸¤—0\'^Ò›\0¤›ÿòşÂP6sqº2˜G^LWÑ–áì\"ÆÔ~ª¥ĞÔ•¨MÛğIl_‘a®’\\~%BqqÅ\Zîù-û³Ú~3ªË«Ÿ\r~\"','2026-06-03 18:33:54','2026-06-03 20:09:49');
/*!40000 ALTER TABLE `wp_fs_lms_enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wp_fs_lms_expelled_archive`
--

DROP TABLE IF EXISTS `wp_fs_lms_expelled_archive`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_fs_lms_expelled_archive` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_id` bigint(20) unsigned DEFAULT NULL,
  `student_person_id` bigint(20) unsigned DEFAULT NULL,
  `parent_person_id` bigint(20) unsigned DEFAULT NULL,
  `data_enc` longblob NOT NULL,
  `expelled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expelled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `restored_at` datetime DEFAULT NULL,
  `restored_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `enrollment_id` (`enrollment_id`),
  KEY `student_person_id` (`student_person_id`),
  KEY `expelled_at` (`expelled_at`),
  KEY `restored_at` (`restored_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_fs_lms_expelled_archive`
--

LOCK TABLES `wp_fs_lms_expelled_archive` WRITE;
/*!40000 ALTER TABLE `wp_fs_lms_expelled_archive` DISABLE KEYS */;
/*!40000 ALTER TABLE `wp_fs_lms_expelled_archive` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wp_fs_lms_relationships`
--

DROP TABLE IF EXISTS `wp_fs_lms_relationships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_fs_lms_relationships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guardian_person_id` bigint(20) unsigned NOT NULL,
  `student_person_id` bigint(20) unsigned NOT NULL,
  `relation_type` varchar(50) NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `guardian_student_from` (`guardian_person_id`,`student_person_id`,`valid_from`),
  KEY `student_person_id` (`student_person_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_fs_lms_relationships`
--

LOCK TABLES `wp_fs_lms_relationships` WRITE;
/*!40000 ALTER TABLE `wp_fs_lms_relationships` DISABLE KEYS */;
INSERT INTO `wp_fs_lms_relationships` VALUES (1,2,1,'father','2026-06-03',NULL,'2026-06-03 18:33:54');
/*!40000 ALTER TABLE `wp_fs_lms_relationships` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-03 19:02:04
