-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: vet_system
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
-- Table structure for table `billing`
--

DROP TABLE IF EXISTS `billing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(50) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `client_name` varchar(150) DEFAULT NULL,
  `client_phone` varchar(50) DEFAULT NULL,
  `pet_name` varchar(100) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `payment_status` varchar(20) DEFAULT 'Pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `downpayment_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `downpayment_status` varchar(20) NOT NULL DEFAULT 'Unpaid',
  `downpayment_paid_at` datetime DEFAULT NULL,
  `is_boarding` tinyint(1) NOT NULL DEFAULT 0,
  `service_category` varchar(100) DEFAULT 'Clinic',
  `boarding_res_id` int(11) DEFAULT NULL,
  `items_json` longtext DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `discount_type` varchar(50) DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `change_amount` decimal(10,2) DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'Cash',
  `reference_no` varchar(100) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `idx_boarding_res_id` (`boarding_res_id`),
  KEY `idx_boarding_res` (`boarding_res_id`)
) ENGINE=InnoDB AUTO_INCREMENT=514 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing`
--

LOCK TABLES `billing` WRITE;
/*!40000 ALTER TABLE `billing` DISABLE KEYS */;
INSERT INTO `billing` VALUES (1,'INV-00001',3,NULL,NULL,NULL,NULL,500.00,'Paid','2026-03-21 21:45:03',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,500.00,0.00,'Cash',NULL,'2026-03-21 21:45:03',NULL),(4,'INV-00004',4,NULL,NULL,NULL,NULL,1.63,'Pending','2026-03-27 16:20:57',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(6,'INV-00006',12,NULL,NULL,NULL,NULL,100.00,'Paid','2026-03-30 14:53:34',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,100.00,0.00,'Cash',NULL,'2026-03-30 14:53:34',NULL),(7,'INV-00007',24,NULL,NULL,NULL,NULL,2599.00,'Paid','2026-03-30 14:55:34',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,2599.00,0.00,'Cash',NULL,'2026-03-30 14:55:34',NULL),(409,'INV-00409',NULL,6,'tim justin ferrer','09551738035','max',800.00,'Paid','2026-04-28 17:30:40',240.00,'Paid','2026-09-25 14:05:01',1,'Clinic',1000000,'[{\"name\":\"Pet Boarding — Cage 03 (Apr 17 → Apr 18, 2026)\",\"qty\":1,\"price\":800}]',0.00,NULL,800.00,0.00,'PayMongo',NULL,'2026-10-09 06:46:02',NULL),(410,'INV-00410',NULL,6,'tim justin ferrer','09551738035','max',800.00,'Paid','2026-04-28 17:30:40',800.00,'Paid','2026-07-14 12:36:52',1,'Clinic',1000006,'[{\"name\":\"Pet Boarding — Cage 04 (Apr 16 → Apr 17, 2026)\",\"qty\":1,\"price\":800}]',0.00,NULL,800.00,0.00,'Cash',NULL,'2026-04-28 17:30:40',NULL),(411,'INV-00411',NULL,NULL,NULL,NULL,NULL,8800.00,'Unpaid','2026-04-28 17:30:40',2640.00,'Unpaid',NULL,1,'Clinic',1000009,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(412,'INV-00412',NULL,NULL,NULL,NULL,NULL,1600.00,'Unpaid','2026-04-28 17:30:40',480.00,'Paid','2026-07-14 12:36:49',1,'Clinic',1000010,NULL,0.00,NULL,480.00,0.00,'Cash',NULL,'2026-07-14 12:36:49',NULL),(413,'INV-00413',NULL,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-04-28 17:30:40',240.00,'Unpaid',NULL,1,'Clinic',1000011,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(414,'INV-00414',NULL,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-04-28 17:30:40',240.00,'Unpaid',NULL,1,'Clinic',1000012,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(415,'INV-00415',NULL,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-04-28 17:30:40',240.00,'Paid','2026-04-28 12:59:49',1,'Clinic',1000014,NULL,0.00,NULL,240.00,0.00,'Cash',NULL,'2026-04-28 12:59:49',NULL),(416,'INV-00416',NULL,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:30:40',240.00,'Unpaid',NULL,1,'Clinic',1000015,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(417,'INV-00417',NULL,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:30:40',240.00,'Unpaid',NULL,1,'Clinic',1000016,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(418,'INV-00418',NULL,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:30:40',240.00,'Unpaid',NULL,1,'Clinic',1000017,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(419,'INV-00419',NULL,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:30:40',240.00,'Unpaid',NULL,1,'Clinic',1000023,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(420,'INV-00420',NULL,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:30:40',240.00,'Unpaid',NULL,1,'Clinic',1000024,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(421,'INV-00421',NULL,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:30:40',240.00,'Unpaid',NULL,1,'Clinic',1000025,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(422,'INV-00422',NULL,NULL,NULL,NULL,NULL,800.00,'Paid','2026-04-28 17:30:40',800.00,'Paid','2026-04-28 11:34:19',1,'Clinic',1000026,NULL,0.00,NULL,800.00,0.00,'Cash',NULL,'2026-04-28 17:30:40',NULL),(423,'INV-00423',NULL,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-04-28 17:30:40',240.00,'Paid','2026-04-28 11:32:40',1,'Clinic',1000027,NULL,0.00,NULL,240.00,0.00,'Cash',NULL,'2026-04-28 11:32:40',NULL),(424,'INV-00424',NULL,NULL,NULL,NULL,NULL,800.00,'Paid','2026-04-28 17:30:40',800.00,'Paid','2026-04-28 11:35:19',1,'Clinic',1000028,NULL,0.00,NULL,800.00,0.00,'Cash',NULL,'2026-04-28 17:30:40',NULL),(440,'INV-00440',1000000,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: duplicate invoice for the same boarding stay'),(441,'INV-00441',1000006,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: duplicate invoice for the same boarding stay'),(442,'INV-00442',1000009,NULL,NULL,NULL,NULL,8800.00,'Unpaid','2026-04-28 17:33:01',2640.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(443,'INV-00443',1000010,NULL,NULL,NULL,NULL,1600.00,'Paid','2026-04-28 17:33:01',1600.00,'Paid','2026-04-28 11:35:09',1,'Clinic',NULL,NULL,0.00,NULL,1600.00,0.00,'Cash',NULL,'2026-04-28 17:33:01',NULL),(444,'INV-00444',1000011,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(445,'INV-00445',1000012,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(446,'INV-00446',1000014,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(447,'INV-00447',1000015,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(448,'INV-00448',1000016,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(449,'INV-00449',1000017,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(450,'INV-00450',1000023,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(451,'INV-00451',1000024,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(452,'INV-00452',1000025,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(453,'INV-00453',1000026,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(454,'INV-00454',1000027,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(455,'INV-00455',1000028,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:33:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(456,'INV-00456',1000029,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-04-28 17:34:38',240.00,'Paid','2026-04-28 11:34:38',1,'Clinic',NULL,NULL,0.00,NULL,240.00,0.00,'Cash',NULL,'2026-04-28 11:34:38',NULL),(457,'INV-00457',NULL,NULL,NULL,NULL,NULL,800.00,'Void','2026-04-28 17:34:44',0.00,'Unpaid',NULL,1,'Clinic',1000029,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(458,'INV-00458',NULL,NULL,NULL,NULL,NULL,1366400.00,'Void','2026-04-28 18:41:52',409920.00,'Unpaid',NULL,1,'Clinic',1000030,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: its boarding stay no longer exists'),(459,'INV-00459',1000031,NULL,NULL,NULL,NULL,2400.00,'Unpaid','2026-04-28 19:56:53',720.00,'Paid','2026-04-28 13:56:53',1,'Clinic',NULL,NULL,0.00,NULL,720.00,0.00,'Cash',NULL,'2026-04-28 13:56:53',NULL),(460,'INV-00460',NULL,NULL,NULL,NULL,NULL,2400.00,'Paid','2026-04-28 19:58:14',2400.00,'Paid','2026-07-13 13:11:30',1,'Clinic',1000032,NULL,0.00,NULL,2400.00,0.00,'Cash',NULL,'2026-04-28 19:58:14',NULL),(461,'INV-00461',NULL,NULL,NULL,NULL,NULL,2400.00,'Paid','2026-04-28 19:58:14',2400.00,'Paid','2026-07-14 12:36:45',1,'Clinic',1000031,NULL,0.00,NULL,2400.00,0.00,'Cash',NULL,'2026-04-28 19:58:14',NULL),(462,'INV-00462',1000033,NULL,NULL,NULL,NULL,2400.00,'Unpaid','2026-07-21 15:48:19',720.00,'Paid','2026-07-21 09:48:19',1,'Clinic',NULL,NULL,0.00,NULL,720.00,0.00,'Cash',NULL,'2026-07-21 09:48:19',NULL),(463,'INV-00463',NULL,NULL,NULL,NULL,NULL,2400.00,'Unpaid','2026-07-21 15:48:24',720.00,'Paid','2026-07-22 17:14:21',1,'Clinic',1000033,NULL,0.00,NULL,720.00,0.00,'Cash',NULL,'2026-07-22 17:14:21',NULL),(464,'INV-00464',25,NULL,NULL,NULL,NULL,500.00,'Paid','2026-09-24 12:49:18',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,500.00,0.00,'Cash',NULL,'2026-09-24 12:49:18',NULL),(465,'INV-00465',41,NULL,NULL,NULL,NULL,500.00,'Unpaid','2026-09-24 12:49:18',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(466,'INV-00466',43,NULL,NULL,NULL,NULL,950.00,'Paid','2026-09-24 12:49:18',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,950.00,0.00,'Cash',NULL,'2026-09-24 12:49:18',NULL),(467,'INV-00467',44,NULL,NULL,NULL,NULL,1500.00,'Paid','2026-09-24 12:49:18',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,1500.00,0.00,'Cash',NULL,'2026-09-24 12:49:18',NULL),(468,'INV-00468',54,NULL,NULL,NULL,NULL,500.00,'Unpaid','2026-09-24 12:49:18',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(469,'INV-00469',55,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-09-24 12:49:18',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(470,'INV-00470',56,NULL,NULL,NULL,NULL,500.00,'Paid','2026-09-24 12:49:18',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,500.00,0.00,'Cash',NULL,'2026-09-24 12:49:18',NULL),(471,'INV-00471',61,NULL,NULL,NULL,NULL,500.00,'Unpaid','2026-09-24 12:49:18',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(472,'INV-00472',62,NULL,NULL,NULL,NULL,500.00,'Paid','2026-09-24 12:49:18',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,500.00,0.00,'Cash',NULL,'2026-09-24 12:49:18',NULL),(473,'INV-00473',NULL,NULL,NULL,NULL,NULL,800.00,'Paid','2026-09-24 13:07:12',240.00,'Paid','2026-09-24 07:07:12',1,'Clinic',1000034,NULL,0.00,NULL,800.00,0.00,'Cash',NULL,'2026-09-24 13:07:12',NULL),(474,'INV-00474',NULL,NULL,NULL,NULL,NULL,800.00,'Paid','2026-09-24 21:32:15',240.00,'Paid','2026-09-24 15:32:14',1,'Clinic',1000035,NULL,0.00,NULL,800.00,0.00,'Cash',NULL,'2026-09-24 21:32:15',NULL),(475,'INV-2026-00475',NULL,NULL,NULL,NULL,NULL,800.00,'Paid','2026-09-30 11:18:59',240.00,'Paid','2026-09-30 05:18:59',1,'Clinic',1000036,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(476,'INV-2026-00476',64,NULL,NULL,NULL,NULL,500.00,'Unpaid','2026-09-30 20:36:25',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(477,'INV-2026-00477',63,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-09-30 21:00:06',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,NULL,NULL,NULL,'Corrected 2026-10-10: was marked Paid automatically with no payment received'),(478,'INV-2026-00478',NULL,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-09-30 21:00:06',240.00,'Unpaid',NULL,1,'Clinic',1000037,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(479,'INV-20260930-6121',64,6,'tim justin ferrer',NULL,'max',511.00,'Unpaid','2026-10-01 02:19:52',0.00,'Unpaid',NULL,0,'Clinic',NULL,'[{\"name\":\"Consultation\",\"qty\":1,\"price\":500},{\"name\":\"Afoxolaner\",\"qty\":1,\"price\":11}]',0.00,NULL,0.00,0.00,NULL,NULL,NULL,'Corrected 2026-10-10: was marked Paid automatically with no payment received'),(480,'INV-2026-00480',66,NULL,NULL,NULL,NULL,500.00,'Unpaid','2026-10-01 15:44:08',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,NULL,NULL,NULL,'Corrected 2026-10-10: was marked Paid automatically with no payment received'),(481,'INV-2026-00481',NULL,4,'Dr. Admin Staff','0','max',800.00,'Paid','2026-10-01 17:32:27',240.00,'Paid',NULL,1,'Boarding',1000038,'[{\"name\":\"Pet Boarding (1 night)\",\"type\":\"boarding\",\"qty\":1,\"price\":800,\"subtotal\":800}]',0.00,NULL,800.00,0.00,'Cash','','2026-10-01 11:32:33',' | Paid ₱800.00 via Cash on Oct 01, 2026 11:32 AM'),(482,'INV-2026-00482',68,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-10-05 13:47:22',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(484,'INV-2026-00484',71,NULL,NULL,NULL,NULL,1500.00,'Unpaid','2026-10-07 17:37:37',0.00,'Unpaid',NULL,0,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(485,'INV-2026-00485',NULL,NULL,NULL,NULL,NULL,800.00,'Unpaid','2026-10-08 13:46:45',240.00,'Paid','2026-10-08 07:46:45',1,'Clinic',1000044,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(486,'INV-2026-00486',70,6,'tim justin ferrer','09551738035','dagapromax',500.00,'Paid','2026-10-08 13:49:58',500.00,'Paid',NULL,0,'Clinic',NULL,'[{\"name\":\"Consultation\",\"qty\":1,\"price\":500}]',0.00,NULL,500.00,0.00,'Reservation Fee',NULL,'2026-10-08 14:18:54',NULL),(488,'INV-2026-00488',73,6,'tim justin ferrer','09551738035','dagapromax',500.00,'Paid','2026-10-08 14:19:09',0.00,'Unpaid',NULL,0,'Clinic',NULL,'[{\"name\":\"Consultation\",\"qty\":1,\"price\":500}]',0.00,NULL,500.00,500.00,'Cash',NULL,'2026-10-08 14:23:14',NULL),(495,'INV-2026-00495',NULL,6,'tim justin ferrer','09551738035','dagapromax',800.00,'Paid','2026-10-09 12:22:46',240.00,'Paid','2026-10-09 06:22:46',1,'Clinic',1000060,'[{\"name\":\"Pet Boarding — Cage 01 (Oct 10 → Oct 11, 2026)\",\"qty\":1,\"price\":800}]',0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(500,'INV-2026-00500',1000034,NULL,NULL,NULL,NULL,800.00,'Void','2026-10-09 19:29:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: duplicate invoice for the same boarding stay'),(501,'INV-2026-00501',1000035,NULL,NULL,NULL,NULL,800.00,'Void','2026-10-09 19:29:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: duplicate invoice for the same boarding stay'),(502,'INV-2026-00502',1000036,NULL,NULL,NULL,NULL,800.00,'Void','2026-10-09 19:29:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: duplicate invoice for the same boarding stay'),(503,'INV-2026-00503',1000060,NULL,NULL,NULL,NULL,800.00,'Void','2026-10-09 19:29:01',240.00,'Unpaid',NULL,1,'Clinic',NULL,NULL,0.00,NULL,0.00,0.00,'Cash',NULL,NULL,'Voided 2026-10-10: duplicate invoice for the same boarding stay'),(508,'INV-2026-00508',NULL,6,'tim justin ferrer','09551738035','dagapromax',800.00,'Paid','2026-10-09 20:05:13',240.00,'Paid','2026-10-09 20:05:13',1,'Clinic',1000072,'[{\"name\":\"Pet Boarding — Cage 01 (Oct 10 → Oct 11, 2026)\",\"qty\":1,\"price\":800}]',0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(509,'INV-2026-00509',98,6,'tim justin ferrer','09551738035','dagapromax',500.00,'Unpaid','2026-10-09 20:21:48',0.00,'Unpaid',NULL,0,'Clinic',NULL,'[{\"name\":\"Consultation\",\"qty\":1,\"price\":500}]',0.00,NULL,0.00,0.00,'Cash',NULL,NULL,NULL),(513,'INV-2026-00513',118,6,'tim justin ferrer','09551738035','Mocha',500.00,'Paid','2026-10-11 11:52:46',150.00,'Paid',NULL,0,'Clinic',NULL,'[{\"name\":\"5-in-1 Vaccine\",\"qty\":1,\"price\":500}]',0.00,NULL,500.00,0.00,'Cash','','2026-10-11 11:53:14',NULL);
/*!40000 ALTER TABLE `billing` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=``*/ /*!50003 TRIGGER trg_al_bill_ai AFTER INSERT ON billing FOR EACH ROW
            INSERT INTO activity_log (module, action, record_id, details, actor) VALUES ('Billing', 'Invoice issued', NEW.id,
                  CONCAT(IFNULL(NEW.invoice_no, CONCAT('Bill #', NEW.id)), ' · ', IFNULL(NEW.client_name, ''), IF(NEW.pet_name IS NULL OR NEW.pet_name = '', '', CONCAT(' (', NEW.pet_name, ')')), ' · P', NEW.total_amount), IFNULL(@pv_actor, 'System')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=``*/ /*!50003 TRIGGER trg_al_bill_au AFTER UPDATE ON billing FOR EACH ROW
            BEGIN
                DECLARE ref VARCHAR(200) DEFAULT CONCAT(IFNULL(NEW.invoice_no, CONCAT('Bill #', NEW.id)), ' · ', IFNULL(NEW.client_name, ''));
                IF NOT (OLD.payment_status <=> NEW.payment_status) THEN
                    IF NEW.payment_status = 'Paid' THEN
                        INSERT INTO activity_log (module, action, record_id, details, actor) VALUES ('Billing', 'Payment received', NEW.id, CONCAT(ref, ' · P', NEW.total_amount, IFNULL(CONCAT(' via ', NEW.payment_method), '')), IFNULL(@pv_actor, 'System'));
                    ELSE
                        INSERT INTO activity_log (module, action, record_id, details, actor) VALUES ('Billing', 'Payment status changed', NEW.id, CONCAT(ref, ': ', IFNULL(OLD.payment_status, '?'), ' -> ', IFNULL(NEW.payment_status, '?')), IFNULL(@pv_actor, 'System'));
                    END IF;
                END IF;
                IF NOT (OLD.downpayment_status <=> NEW.downpayment_status) AND NEW.downpayment_status = 'Paid' THEN
                    INSERT INTO activity_log (module, action, record_id, details, actor) VALUES ('Billing', 'Downpayment received', NEW.id, CONCAT(ref, ' · P', NEW.downpayment_amount), IFNULL(@pv_actor, 'System'));
                END IF;
                IF NOT (OLD.total_amount <=> NEW.total_amount) THEN
                    INSERT INTO activity_log (module, action, record_id, details, actor) VALUES ('Billing', 'Invoice updated', NEW.id, CONCAT(ref, ': P', OLD.total_amount, ' -> P', NEW.total_amount), IFNULL(@pv_actor, 'System'));
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-11 14:53:23
