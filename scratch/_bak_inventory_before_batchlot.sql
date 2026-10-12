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
-- Table structure for table `inventory`
--

DROP TABLE IF EXISTS `inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `quantity` int(11) DEFAULT 0,
  `price_per_unit` decimal(10,2) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `lot_number` varchar(100) DEFAULT NULL,
  `unit_of_measure` varchar(50) DEFAULT 'piece',
  `reorder_level` int(11) DEFAULT 10,
  `supplier` varchar(150) DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory`
--

LOCK TABLES `inventory` WRITE;
/*!40000 ALTER TABLE `inventory` DISABLE KEYS */;
INSERT INTO `inventory` VALUES (1,'vaccine','Vaccines',100,10.00,'2027-02-02',NULL,NULL,'piece',10,NULL,6.00,'2026-09-24 15:25:47'),(5,'Dog Rabies vaccine','Vaccines',300,400.00,'2028-02-06',NULL,NULL,'piece',10,NULL,240.00,'2026-09-24 15:25:47'),(6,'Dog DHPP/DAPP vaccine','Vaccines',300,850.00,'2028-03-07',NULL,NULL,'piece',10,NULL,510.00,'2026-09-24 15:25:47'),(7,'Dog Bordetella vaccine','Vaccines',300,750.00,'2028-01-08',NULL,NULL,'piece',10,NULL,450.00,'2026-09-24 15:25:47'),(8,'Dog Leptospirosis vaccine','Vaccines',300,600.00,'2027-10-09',NULL,NULL,'piece',10,NULL,360.00,'2026-09-24 15:25:47'),(9,'Dog Canine Influenza vaccine','Vaccines',300,1200.00,'2027-11-10',NULL,NULL,'piece',10,NULL,720.00,'2026-09-24 15:25:47'),(10,'Dog Lyme disease vaccine','Vaccines',300,1100.00,'2027-12-11',NULL,NULL,'piece',10,NULL,660.00,'2026-09-24 15:25:47'),(11,'Cat Rabies vaccine','Vaccines',300,450.00,'2028-01-12',NULL,NULL,'piece',10,NULL,270.00,'2026-09-24 15:25:47'),(12,'FVRCP vaccine','Vaccines',300,900.00,'2028-01-13',NULL,NULL,'piece',10,NULL,540.00,'2026-09-24 15:25:47'),(13,'FeLV vaccine','Vaccines',300,1200.00,'2027-11-14',NULL,NULL,'piece',10,NULL,720.00,'2026-09-24 15:25:47'),(14,'Chlamydia vaccine','Vaccines',300,650.00,'2027-12-15',NULL,NULL,'piece',10,NULL,390.00,'2026-09-24 15:25:47'),(15,'Pyrantel pamoate','Dewormers',300,35.00,'2030-01-16',NULL,NULL,'piece',10,NULL,19.00,'2026-09-24 15:25:47'),(16,'Fenbendazole','Dewormers',300,30.00,'2029-10-17',NULL,NULL,'piece',10,NULL,17.00,'2026-09-24 15:25:47'),(17,'Praziquantel','Dewormers',300,60.00,'2029-11-18',NULL,NULL,'piece',10,NULL,33.00,'2026-09-24 15:25:47'),(18,'Albendazole','Dewormers',300,25.00,'2029-12-19',NULL,NULL,'piece',10,NULL,14.00,'2026-09-24 15:25:47'),(19,'Febantel','Dewormers',300,45.00,'2030-01-20',NULL,NULL,'piece',10,NULL,25.00,'2026-09-24 15:25:47'),(20,'Milbemycin oxime','Dewormers',300,350.00,'2028-10-21',NULL,NULL,'piece',10,NULL,193.00,'2026-09-24 15:25:47'),(21,'Moxidectin','Dewormers',300,600.00,'2028-11-22',NULL,NULL,'piece',10,NULL,330.00,'2026-09-24 15:25:47'),(22,'Emodepside','Dewormers',300,650.00,'2028-12-23',NULL,NULL,'piece',10,NULL,358.00,'2026-09-24 15:25:47'),(23,'Ivermectin','Dewormers',300,120.00,'2029-01-24',NULL,NULL,'piece',10,NULL,66.00,'2026-09-24 15:25:47'),(24,'Doramectin','Dewormers',300,350.00,'2028-10-25',NULL,NULL,'piece',10,NULL,193.00,'2026-09-24 15:25:47'),(25,'Eprinomectin','Dewormers',300,550.00,'2028-11-26',NULL,NULL,'piece',10,NULL,303.00,'2026-09-24 15:25:47'),(26,'Fipronil','Flea/Tick Control',300,450.00,'2029-12-27',NULL,NULL,'piece',10,NULL,248.00,'2026-09-24 15:25:47'),(27,'Fluralaner','Flea/Tick Control',300,1800.00,'2029-01-01',NULL,NULL,'piece',10,NULL,990.00,'2026-09-24 15:25:47'),(28,'Afoxolaner','Flea/Tick Control',299,650.00,'2028-10-02',NULL,NULL,'piece',10,NULL,358.00,'2026-09-24 15:25:47'),(29,'Sarolaner','Flea/Tick Control',300,750.00,'2028-11-03',NULL,NULL,'piece',10,NULL,413.00,'2026-09-24 15:25:47'),(30,'Lotilaner','Flea/Tick Control',300,1300.00,'2028-12-04',NULL,NULL,'piece',10,NULL,715.00,'2026-09-24 15:25:47'),(31,'Selamectin','Flea/Tick Control',300,600.00,'2030-01-05',NULL,NULL,'piece',10,NULL,330.00,'2026-09-24 15:25:47'),(32,'Imidacloprid','Flea/Tick Control',300,550.00,'2029-10-06',NULL,NULL,'piece',10,NULL,303.00,'2026-09-24 15:25:47'),(33,'Permethrin','Flea/Tick Control',300,300.00,'2029-11-07',NULL,NULL,'piece',10,NULL,165.00,'2026-09-24 15:25:47'),(34,'Amitraz','Flea/Tick Control',300,350.00,'2028-12-08',NULL,NULL,'piece',10,NULL,193.00,'2026-09-24 15:25:47'),(35,'Lime sulfur dip','Flea/Tick Control',300,650.00,'2029-01-09',NULL,NULL,'piece',10,NULL,358.00,'2026-09-24 15:25:47'),(36,'Amoxicillin','Antibiotics',301,15.00,'2029-04-10',NULL,NULL,'piece',10,NULL,8.00,'2026-09-24 15:25:47'),(37,'Amoxicillin-Clavulanate','Antibiotics',300,35.00,'2028-11-11',NULL,NULL,'piece',10,NULL,19.00,'2026-09-24 15:25:47'),(38,'Cephalexin','Antibiotics',300,20.00,'2029-06-12',NULL,NULL,'piece',10,NULL,11.00,'2026-09-24 15:25:47'),(39,'Cefovecin','Antibiotics',300,1800.00,'2029-01-13',NULL,NULL,'piece',10,NULL,990.00,'2026-09-24 15:25:47'),(40,'Doxycycline','Antibiotics',300,20.00,'2028-10-14',NULL,NULL,'piece',10,NULL,11.00,'2026-09-24 15:25:47'),(41,'Enrofloxacin','Antibiotics',300,35.00,'2029-05-15',NULL,NULL,'piece',10,NULL,19.00,'2026-09-24 15:25:47'),(42,'Marbofloxacin','Antibiotics',300,60.00,'2029-06-16',NULL,NULL,'piece',10,NULL,33.00,'2026-09-24 15:25:47'),(43,'Clindamycin','Antibiotics',300,30.00,'2029-07-17',NULL,NULL,'piece',10,NULL,17.00,'2026-09-24 15:25:47'),(44,'Metronidazole','Antibiotics',300,12.00,'2029-04-18',NULL,NULL,'piece',10,NULL,7.00,'2026-09-24 15:25:47'),(45,'Trimethoprim-Sulfamethoxazole','Antibiotics',300,15.00,'2029-05-19',NULL,NULL,'piece',10,NULL,8.00,'2026-09-24 15:25:47'),(46,'Gentamicin','Antibiotics',300,150.00,'2028-12-20',NULL,NULL,'piece',10,NULL,83.00,'2026-09-24 15:25:47'),(47,'Carprofen','Pain Relief',300,45.00,'2029-07-21',NULL,NULL,'piece',10,NULL,25.00,'2026-09-24 15:25:47'),(48,'Meloxicam','Pain Relief',300,30.00,'2029-04-22',NULL,NULL,'piece',10,NULL,17.00,'2026-09-24 15:25:47'),(49,'Robenacoxib','Pain Relief',300,85.00,'2028-11-23',NULL,NULL,'piece',10,NULL,47.00,'2026-09-24 15:25:47'),(50,'Firocoxib','Pain Relief',300,80.00,'2029-06-24',NULL,NULL,'piece',10,NULL,44.00,'2026-09-24 15:25:47'),(51,'Grapiprant','Pain Relief',300,90.00,'2029-01-25',NULL,NULL,'piece',10,NULL,50.00,'2026-09-24 15:25:47'),(52,'Butorphanol','Pain Relief',300,550.00,'2028-10-26',NULL,NULL,'piece',10,NULL,303.00,'2026-09-24 15:25:47'),(53,'Buprenorphine','Pain Relief',300,450.00,'2028-11-27',NULL,NULL,'piece',10,NULL,248.00,'2026-09-24 15:25:47'),(54,'Tramadol','Pain Relief',300,20.00,'2029-06-01',NULL,NULL,'piece',10,NULL,11.00,'2026-09-24 15:25:47'),(55,'Dexamethasone','Steroids',300,120.00,'2029-01-02',NULL,NULL,'piece',10,NULL,66.00,'2026-09-24 15:25:47'),(56,'Prednisolone','Steroids',300,15.00,'2029-04-03',NULL,NULL,'piece',10,NULL,8.00,'2026-09-24 15:25:47'),(57,'Prednisone','Steroids',300,10.00,'2029-05-04',NULL,NULL,'piece',10,NULL,6.00,'2026-09-24 15:25:47'),(58,'Hydrocortisone','Steroids',300,180.00,'2028-12-05',NULL,NULL,'piece',10,NULL,99.00,'2026-09-24 15:25:47'),(59,'Triamcinolone','Steroids',300,250.00,'2029-01-06',NULL,NULL,'piece',10,NULL,138.00,'2026-09-24 15:25:47'),(60,'Diphenhydramine','Allergy Medications',300,120.00,'2028-10-07',NULL,NULL,'piece',10,NULL,66.00,'2026-09-24 15:25:47'),(61,'Chlorpheniramine','Allergy Medications',300,15.00,'2028-11-08',NULL,NULL,'piece',10,NULL,8.00,'2026-09-24 15:25:47'),(62,'Cetirizine','Allergy Medications',300,100.00,'2026-10-21','','','piece',10,'',80.00,'2026-09-24 15:25:47'),(63,'Apoquel','Allergy Medications',300,100.00,'2026-10-23','ABA123','AD132','piece',10,'',90.00,'2026-09-24 15:25:47'),(64,'Lokivetmab injectable','Allergy Medications',300,3500.00,'2028-04-11',NULL,NULL,'piece',10,NULL,1925.00,'2026-09-24 15:25:47'),(65,'Epinephrine','Emergency Drugs',300,90.00,'2028-05-12',NULL,NULL,'piece',10,NULL,50.00,'2026-09-24 15:25:47'),(66,'Atropine','Emergency Drugs',300,80.00,'2028-12-13',NULL,NULL,'piece',10,NULL,44.00,'2026-09-24 15:25:47'),(67,'Diazepam','Emergency Drugs',300,180.00,'2029-01-14',NULL,NULL,'piece',10,NULL,99.00,'2026-09-24 15:25:47'),(68,'Midazolam','Emergency Drugs',300,250.00,'2028-10-15',NULL,NULL,'piece',10,NULL,138.00,'2026-09-24 15:25:47'),(69,'Lidocaine','Emergency Drugs',300,150.00,'2028-11-16',NULL,NULL,'piece',10,NULL,83.00,'2026-09-24 15:25:47'),(70,'Dextrose 50%','Emergency Drugs',300,120.00,'2028-12-17',NULL,NULL,'piece',10,NULL,66.00,'2026-09-24 15:25:47'),(71,'Calcium gluconate','Emergency Drugs',300,150.00,'2029-01-18',NULL,NULL,'piece',10,NULL,83.00,'2026-09-24 15:25:47'),(72,'Naloxone','Emergency Drugs',300,450.00,'2028-10-19',NULL,NULL,'piece',10,NULL,248.00,'2026-09-24 15:25:47'),(73,'Yohimbine','Emergency Drugs',300,900.00,'2028-11-20',NULL,NULL,'piece',10,NULL,495.00,'2026-09-24 15:25:47'),(74,'Atipamezole','Emergency Drugs',300,1200.00,'2028-12-21',NULL,NULL,'piece',10,NULL,660.00,'2026-09-24 15:25:47'),(75,'Activated charcoal','Emergency Drugs',300,250.00,'2030-01-22',NULL,NULL,'piece',10,NULL,138.00,'2026-09-24 15:25:47'),(76,'Ketamine','Anesthetics',300,650.00,'2028-10-23',NULL,NULL,'piece',10,NULL,358.00,'2026-09-24 15:25:47'),(77,'Propofol','Anesthetics',300,450.00,'2028-05-24',NULL,NULL,'piece',10,NULL,248.00,'2026-09-24 15:25:47'),(78,'Alfaxalone','Anesthetics',300,1800.00,'2028-06-25',NULL,NULL,'piece',10,NULL,990.00,'2026-09-24 15:25:47'),(79,'Isoflurane','Anesthetics',300,3500.00,'2030-01-26',NULL,NULL,'piece',10,NULL,1925.00,'2026-09-24 15:25:47'),(80,'Sevoflurane','Anesthetics',300,6500.00,'2029-10-27',NULL,NULL,'piece',10,NULL,3575.00,'2026-09-24 15:25:47'),(81,'Xylazine','Anesthetics',300,550.00,'2028-11-01',NULL,NULL,'piece',10,NULL,303.00,'2026-09-24 15:25:47'),(82,'Dexmedetomidine','Anesthetics',300,1500.00,'2028-12-02',NULL,NULL,'piece',10,NULL,825.00,'2026-09-24 15:25:47'),(83,'Medetomidine','Anesthetics',300,1200.00,'2029-01-03',NULL,NULL,'piece',10,NULL,660.00,'2026-09-24 15:25:47'),(84,'Metoclopramide','Gastrointestinal Medicines',300,80.00,'2028-10-04',NULL,NULL,'piece',10,NULL,44.00,'2026-09-24 15:25:47'),(85,'Maropitant','Gastrointestinal Medicines',300,450.00,'2028-11-05',NULL,NULL,'piece',10,NULL,248.00,'2026-09-24 15:25:47'),(86,'Omeprazole','Gastrointestinal Medicines',300,25.00,'2028-12-06',NULL,NULL,'piece',10,NULL,14.00,'2026-09-24 15:25:47'),(87,'Famotidine','Gastrointestinal Medicines',300,15.00,'2029-01-07',NULL,NULL,'piece',10,NULL,8.00,'2026-09-24 15:25:47'),(88,'Sucralfate','Gastrointestinal Medicines',300,20.00,'2028-10-08',NULL,NULL,'piece',10,NULL,11.00,'2026-09-24 15:25:47'),(89,'Kaolin-Pectin','Gastrointestinal Medicines',300,250.00,'2028-11-09',NULL,NULL,'piece',10,NULL,138.00,'2026-09-24 15:25:47'),(90,'Probiotics','Gastrointestinal Medicines',300,50.00,'2028-06-10',NULL,NULL,'piece',10,NULL,28.00,'2026-09-24 15:25:47'),(91,'Psyllium fiber','Gastrointestinal Medicines',300,350.00,'2029-01-11',NULL,NULL,'piece',10,NULL,193.00,'2026-09-24 15:25:47'),(92,'Artificial tears','Eye Medications',300,280.00,'2028-10-12',NULL,NULL,'piece',10,NULL,154.00,'2026-09-24 15:25:47'),(93,'Tobramycin eye drops','Eye Medications',300,320.00,'2028-05-13',NULL,NULL,'piece',10,NULL,176.00,'2026-09-24 15:25:47'),(94,'Chloramphenicol eye drops','Eye Medications',300,180.00,'2028-06-14',NULL,NULL,'piece',10,NULL,99.00,'2026-09-24 15:25:47'),(95,'Ciprofloxacin eye drops','Eye Medications',300,220.00,'2028-07-15',NULL,NULL,'piece',10,NULL,121.00,'2026-09-24 15:25:47'),(96,'Atropine ophthalmic','Eye Medications',300,350.00,'2028-04-16',NULL,NULL,'piece',10,NULL,193.00,'2026-09-24 15:25:47'),(97,'Fluorescein stain strips','Eye Medications',300,40.00,'2029-11-17',NULL,NULL,'piece',10,NULL,22.00,'2026-09-24 15:25:47'),(98,'Ear cleaning solution','Ear Medications',300,450.00,'2028-12-18',NULL,NULL,'piece',10,NULL,248.00,'2026-09-24 15:25:47'),(99,'Gentamicin ear drops','Ear Medications',300,350.00,'2029-01-19',NULL,NULL,'piece',10,NULL,193.00,'2026-09-24 15:25:47'),(100,'Miconazole ear preparations','Ear Medications',300,420.00,'2028-10-20',NULL,NULL,'piece',10,NULL,231.00,'2026-09-24 15:25:47'),(101,'Otic antibiotic/steroid combinations','Ear Medications',300,650.00,'2028-11-21',NULL,NULL,'piece',10,NULL,358.00,'2026-09-24 15:25:47'),(102,'Ear mite treatment','Ear Medications',300,380.00,'2028-12-22',NULL,NULL,'piece',10,NULL,209.00,'2026-09-24 15:25:47'),(103,'Chlorhexidine shampoo','Skin Medications',300,480.00,'2029-01-23',NULL,NULL,'piece',10,NULL,264.00,'2026-09-24 15:25:47'),(104,'Benzoyl peroxide shampoo','Skin Medications',300,550.00,'2028-10-24',NULL,NULL,'piece',10,NULL,303.00,'2026-09-24 15:25:47'),(105,'Miconazole shampoo','Skin Medications',300,500.00,'2028-11-25',NULL,NULL,'piece',10,NULL,275.00,'2026-09-24 15:25:47'),(106,'Ketoconazole shampoo','Skin Medications',300,520.00,'2028-12-26',NULL,NULL,'piece',10,NULL,286.00,'2026-09-24 15:25:47'),(107,'Silver sulfadiazine cream','Skin Medications',300,220.00,'2029-01-27',NULL,NULL,'piece',10,NULL,121.00,'2026-09-24 15:25:47'),(108,'Mupirocin ointment','Skin Medications',300,250.00,'2028-10-01',NULL,NULL,'piece',10,NULL,138.00,'2026-09-24 15:25:47'),(109,'Triple antibiotic ointment','Skin Medications',300,150.00,'2028-11-02',NULL,NULL,'piece',10,NULL,83.00,'2026-09-24 15:25:47'),(110,'Lactated Ringer\'s Solution','Fluids',300,150.00,'2028-12-03',NULL,NULL,'piece',10,NULL,83.00,'2026-09-24 15:25:47'),(111,'Normal Saline (0.9%)','Fluids',300,140.00,'2029-01-04',NULL,NULL,'piece',10,NULL,77.00,'2026-09-24 15:25:47'),(112,'Dextrose solutions','Fluids',300,160.00,'2028-10-05',NULL,NULL,'piece',10,NULL,88.00,'2026-09-24 15:25:47'),(113,'Plasma-Lyte','Fluids',300,380.00,'2028-11-06',NULL,NULL,'piece',10,NULL,209.00,'2026-09-24 15:25:47'),(114,'Sterile Water','Fluids',300,90.00,'2029-12-07',NULL,NULL,'piece',10,NULL,50.00,'2026-09-24 15:25:47'),(115,'Vitamin B Complex','Vitamins & Supplements',300,120.00,'2029-01-08',NULL,NULL,'piece',10,NULL,66.00,'2026-09-24 15:25:47'),(116,'Vitamin C','Vitamins & Supplements',300,10.00,'2028-10-09',NULL,NULL,'piece',10,NULL,6.00,'2026-09-24 15:25:47'),(117,'Iron supplements','Vitamins & Supplements',300,20.00,'2028-11-10',NULL,NULL,'piece',10,NULL,11.00,'2026-09-24 15:25:47'),(118,'Calcium supplements','Vitamins & Supplements',300,25.00,'2028-12-11',NULL,NULL,'piece',10,NULL,14.00,'2026-09-24 15:25:47'),(119,'Joint supplements','Vitamins & Supplements',300,45.00,'2029-01-12',NULL,NULL,'piece',10,NULL,25.00,'2026-09-24 15:25:47'),(120,'Omega-3 supplements','Vitamins & Supplements',300,25.00,'2028-04-13',NULL,NULL,'piece',10,NULL,14.00,'2026-09-24 15:25:47'),(121,'Liver support supplements','Vitamins & Supplements',300,60.00,'2028-11-14',NULL,NULL,'piece',10,NULL,33.00,'2026-09-24 15:25:47'),(122,'Probiotics (supplement)','Vitamins & Supplements',300,50.00,'2028-06-15',NULL,NULL,'piece',10,NULL,28.00,'2026-09-24 15:25:47'),(123,'Oxytocin','Reproductive Medicines',300,120.00,'2028-07-16',NULL,NULL,'piece',10,NULL,66.00,'2026-09-24 15:25:47'),(124,'Cabergoline','Reproductive Medicines',300,250.00,'2028-10-17',NULL,NULL,'piece',10,NULL,138.00,'2026-09-24 15:25:47'),(125,'Cloprostenol','Reproductive Medicines',300,600.00,'2028-11-18',NULL,NULL,'piece',10,NULL,330.00,'2026-09-24 15:25:47'),(126,'Calcium borogluconate','Reproductive Medicines',300,180.00,'2028-12-19',NULL,NULL,'piece',10,NULL,99.00,'2026-09-24 15:25:47'),(127,'Pregnancy test kits','Diagnostic Supplies',300,1100.00,'2028-07-20',NULL,NULL,'piece',10,NULL,715.00,'2026-09-24 15:25:47'),(128,'Blood glucose test strips','Diagnostic Supplies',300,35.00,'2028-04-21',NULL,NULL,'piece',10,NULL,23.00,'2026-09-24 15:25:47'),(129,'Urine dipsticks','Diagnostic Supplies',300,45.00,'2028-11-22',NULL,NULL,'piece',10,NULL,29.00,'2026-09-24 15:25:47'),(130,'Fecal flotation solution','Diagnostic Supplies',300,40.00,'2028-12-23',NULL,NULL,'piece',10,NULL,26.00,'2026-09-24 15:25:47'),(131,'Heartworm test kits','Diagnostic Supplies',300,1200.00,'2028-07-24',NULL,NULL,'piece',10,NULL,780.00,'2026-09-24 15:25:47'),(132,'FeLV/FIV rapid test kits','Diagnostic Supplies',300,1500.00,'2028-04-25',NULL,NULL,'piece',10,NULL,975.00,'2026-09-24 15:25:47'),(133,'Parvovirus rapid test kits','Diagnostic Supplies',300,950.00,'2028-05-26',NULL,NULL,'piece',10,NULL,618.00,'2026-09-24 15:25:47'),(134,'Povidone-iodine','Wound Care',300,180.00,'2029-12-27',NULL,NULL,'piece',10,NULL,117.00,'2026-09-24 15:25:47'),(135,'Chlorhexidine solution','Wound Care',300,280.00,'2029-01-01',NULL,NULL,'piece',10,NULL,182.00,'2026-09-24 15:25:47'),(136,'Hydrogen peroxide','Wound Care',300,90.00,'2028-10-02',NULL,NULL,'piece',10,NULL,59.00,'2026-09-24 15:25:47'),(137,'Sterile saline','Wound Care',300,90.00,'2028-11-03',NULL,NULL,'piece',10,NULL,59.00,'2026-09-24 15:25:47'),(138,'Alcohol','Wound Care',300,120.00,'2029-12-04',NULL,NULL,'piece',10,NULL,78.00,'2026-09-24 15:25:47'),(139,'Gauze','Wound Care',300,8.00,'2031-01-05',NULL,NULL,'piece',10,NULL,5.00,'2026-09-24 15:25:47'),(140,'Cotton','Wound Care',300,80.00,'2030-10-06',NULL,NULL,'piece',10,NULL,52.00,'2026-09-24 15:25:47'),(141,'Bandages','Wound Care',300,45.00,'2030-11-07',NULL,NULL,'piece',10,NULL,29.00,'2026-09-24 15:25:47'),(142,'Elastic wraps','Wound Care',300,120.00,'2030-12-08',NULL,NULL,'piece',10,NULL,78.00,'2026-09-24 15:25:47'),(143,'Surgical tape','Wound Care',300,65.00,'2031-01-09',NULL,NULL,'piece',10,NULL,42.00,'2026-09-24 15:25:47'),(144,'Hemostatic powder','Wound Care',300,450.00,'2029-10-10',NULL,NULL,'piece',10,NULL,293.00,'2026-09-24 15:25:47'),(145,'1 mL syringes','Consumables',300,10.00,'2031-11-11',NULL,NULL,'piece',10,NULL,7.00,'2026-09-24 15:25:47'),(146,'3 mL syringes','Consumables',300,12.00,'2031-12-12',NULL,NULL,'piece',10,NULL,8.00,'2026-09-24 15:25:47'),(147,'5 mL syringes','Consumables',300,15.00,'2032-01-13',NULL,NULL,'piece',10,NULL,10.00,'2026-09-24 15:25:47'),(148,'10 mL syringes','Consumables',300,18.00,'2031-10-14',NULL,NULL,'piece',10,NULL,12.00,'2026-09-24 15:25:47'),(149,'20 mL syringes','Consumables',300,25.00,'2031-11-15',NULL,NULL,'piece',10,NULL,16.00,'2026-09-24 15:25:47'),(150,'50-60 mL syringes','Consumables',100,45.00,'2031-12-16',NULL,NULL,'piece',10,NULL,29.00,'2026-09-24 15:25:47'),(151,'Needles 18G-27G','Consumables',100,6.00,'2032-01-17',NULL,NULL,'piece',10,NULL,4.00,'2026-09-24 15:25:47'),(152,'IV catheters','Consumables',300,80.00,'2030-10-18',NULL,NULL,'piece',10,NULL,52.00,'2026-09-24 15:25:47'),(153,'Butterfly needles','Consumables',300,35.00,'2031-11-19',NULL,NULL,'piece',10,NULL,23.00,'2026-09-24 15:25:47'),(154,'Blood collection tubes','Consumables',300,25.00,'2029-12-20',NULL,NULL,'piece',10,NULL,16.00,'2026-09-24 15:25:47'),(155,'IV giving sets','Consumables',300,95.00,'2031-01-21',NULL,NULL,'piece',10,NULL,62.00,'2026-09-24 15:25:47'),(156,'Gloves','Consumables',300,10.00,'2029-10-22',NULL,NULL,'piece',10,NULL,7.00,'2026-09-24 15:25:47'),(157,'Face masks','Consumables',300,5.00,'2029-11-23',NULL,NULL,'piece',10,NULL,3.00,'2026-09-24 15:25:47');
/*!40000 ALTER TABLE `inventory` ENABLE KEYS */;
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
/*!50003 CREATE*/ /*!50017 DEFINER=``*/ /*!50003 TRIGGER trg_inventory_mv_ai AFTER INSERT ON inventory FOR EACH ROW
            INSERT INTO inventory_movements (item_id, item_name, movement_type, qty_change, qty_after, reference, actor)
            VALUES (NEW.id, NEW.item_name, 'Added', NEW.quantity, NEW.quantity, NULLIF(IFNULL(@pv_inv_reason, ''), ''), IFNULL(@pv_actor, 'System')) */;;
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
/*!50003 CREATE*/ /*!50017 DEFINER=``*/ /*!50003 TRIGGER trg_inventory_mv_au AFTER UPDATE ON inventory FOR EACH ROW
            BEGIN
                IF NOT (OLD.quantity <=> NEW.quantity) THEN
                    INSERT INTO inventory_movements (item_id, item_name, movement_type, qty_change, qty_after, reference, actor)
                    VALUES (NEW.id, NEW.item_name,
                            IFNULL(NULLIF(@pv_inv_type, ''), IF(NEW.quantity > OLD.quantity, 'Restock', 'Adjustment')),
                            NEW.quantity - OLD.quantity, NEW.quantity, NULLIF(IFNULL(@pv_inv_reason, ''), ''), IFNULL(@pv_actor, 'System'));
                END IF;
                IF NOT (OLD.price_per_unit <=> NEW.price_per_unit) THEN
                    INSERT INTO inventory_movements (item_id, item_name, movement_type, qty_change, qty_after, reference, actor)
                    VALUES (NEW.id, NEW.item_name, 'Price change', 0, NEW.quantity,
                            CONCAT('P', OLD.price_per_unit, ' -> P', NEW.price_per_unit), IFNULL(@pv_actor, 'System'));
                END IF;
            END */;;
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
/*!50003 CREATE*/ /*!50017 DEFINER=``*/ /*!50003 TRIGGER trg_inventory_mv_ad AFTER DELETE ON inventory FOR EACH ROW
            INSERT INTO inventory_movements (item_id, item_name, movement_type, qty_change, qty_after, reference, actor)
            VALUES (OLD.id, OLD.item_name, 'Deleted', -OLD.quantity, 0, NULLIF(IFNULL(@pv_inv_reason, ''), ''), IFNULL(@pv_actor, 'System')) */;;
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

-- Dump completed on 2026-10-11 14:26:37
