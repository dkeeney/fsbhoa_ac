-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 25, 2025 at 01:07 PM
-- Server version: 8.0.42-0ubuntu0.24.04.2
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fsbhoa_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `ac_access_log`
--

CREATE TABLE `ac_access_log` (
  `log_id` int NOT NULL,
  `event_timestamp` datetime(3) NOT NULL,
  `controller_identifier` varchar(50) NOT NULL COMMENT 'Identifier from uhppoted-rest, e.g., uhppoted_device_id or IP',
  `door_number` tinyint NOT NULL,
  `rfid_id` varchar(8) DEFAULT NULL,
  `cardholder_id` int DEFAULT NULL,
  `event_type_code` int NOT NULL COMMENT 'Numeric code for the event type from uhppoted-rest',
  `event_description` varchar(255) NOT NULL,
  `access_granted` tinyint(1) DEFAULT NULL COMMENT 'TRUE for granted, FALSE for denied, NULL if not applicable',
  `raw_event_details` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_amenities`
--

CREATE TABLE `ac_amenities` (
  `id` int NOT NULL COMMENT 'Primary key for the amenity',
  `name` varchar(100) NOT NULL COMMENT 'The display name of the amenity (e.g., Billiards, Library)',
  `image_url` varchar(255) DEFAULT NULL COMMENT 'URL to an image for the amenity',
  `display_order` int NOT NULL DEFAULT '0' COMMENT 'An integer to control the sort order of buttons on the kiosk UI',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether the amenity is active and should be displayed (1=Active, 0=Inactive)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_cardholders`
--

CREATE TABLE `ac_cardholders` (
  `id` int NOT NULL,
  `rfid_id` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `property_id` int DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `phone_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'Mobile',
  `photo` longblob,
  `card_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'inactive',
  `notes` text,
  `card_issue_date` date DEFAULT NULL,
  `card_expiry_date` date NOT NULL DEFAULT '2099-12-31',
  `resident_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'Resident Owner',
  `origin` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'Indicates if the record was from a csv import or added manually',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_controllers`
--

CREATE TABLE `ac_controllers` (
  `controller_record_id` int NOT NULL,
  `uhppoted_device_id` bigint NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `door_count` tinyint(1) NOT NULL DEFAULT '4' COMMENT 'Number of doors supported by this controller model (e.g., 1, 2, or 4)',
  `is_static_ip` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 = DHCP, 1 = Static',
  `friendly_name` varchar(100) NOT NULL,
  `notes` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_deleted_cardholders`
--

CREATE TABLE `ac_deleted_cardholders` (
  `id` int NOT NULL COMMENT 'The Primary Key from the original ac_cardholders table.',
  `rfid_id` varchar(8) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `property_id` int DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `phone_type` varchar(10) DEFAULT 'Mobile',
  `photo` longblob,
  `card_status` varchar(20) NOT NULL DEFAULT 'inactive',
  `notes` text,
  `card_issue_date` date DEFAULT NULL,
  `card_expiry_date` date NOT NULL DEFAULT '2099-12-31',
  `resident_type` varchar(50) DEFAULT 'Resident Owner',
  `origin` varchar(20) NOT NULL DEFAULT 'manual',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_doors`
--

CREATE TABLE `ac_doors` (
  `door_record_id` int NOT NULL,
  `controller_record_id` int NOT NULL,
  `door_number_on_controller` tinyint NOT NULL COMMENT 'Typically 1-4, representing the door output on the controller board.',
  `friendly_name` varchar(100) NOT NULL,
  `notes` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `map_x` int DEFAULT '0',
  `map_y` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_print_log`
--

CREATE TABLE `ac_print_log` (
  `log_id` int NOT NULL,
  `system_job_id` varchar(50) NOT NULL COMMENT 'Unique ID generated by our Java app for this print request',
  `printer_job_id` varchar(50) DEFAULT NULL COMMENT 'Job ID from the Zebra SDK',
  `cardholder_id` int DEFAULT NULL,
  `rfid_id` varchar(8) DEFAULT NULL,
  `print_request_data` json DEFAULT NULL COMMENT 'Original JSON payload sent from PHP, for retries or auditing',
  `sdk_image_name` varchar(255) DEFAULT NULL COMMENT 'Name of the image file saved via SDK for this job, if any',
  `status` varchar(30) NOT NULL COMMENT 'e.g., submitted, printing, completed_ok, failed_error, cancelled_by_user',
  `status_message` text COMMENT 'Error messages or detailed status from SDK/printer',
  `submitted_by_user` varchar(100) DEFAULT NULL COMMENT 'WordPress username who initiated the print',
  `submitted_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_property`
--

CREATE TABLE `ac_property` (
  `property_id` int NOT NULL,
  `house_number` varchar(20) NOT NULL COMMENT 'e.g., 123, 456A',
  `street_name` varchar(180) NOT NULL COMMENT 'e.g., Main St, Oak Ave',
  `street_address` varchar(200) NOT NULL,
  `notes` text,
  `origin` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'Indicates if the record was from a csv import or added manually'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ac_task_list`
--

CREATE TABLE `ac_task_list` (
  `id` int NOT NULL,
  `controller_id` int DEFAULT NULL,
  `door_number` tinyint DEFAULT NULL COMMENT '1-4, or NULL for all doors on the targeted controller(s)',
  `task_type` tinyint NOT NULL COMMENT 'Numeric ID for the uhppoted task type',
  `start_time` time NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date NOT NULL,
  `on_mon` tinyint(1) NOT NULL DEFAULT '0',
  `on_tue` tinyint(1) NOT NULL DEFAULT '0',
  `on_wed` tinyint(1) NOT NULL DEFAULT '0',
  `on_thu` tinyint(1) NOT NULL DEFAULT '0',
  `on_fri` tinyint(1) NOT NULL DEFAULT '0',
  `on_sat` tinyint(1) NOT NULL DEFAULT '0',
  `on_sun` tinyint(1) NOT NULL DEFAULT '0',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ac_access_log`
--
ALTER TABLE `ac_access_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_event_timestamp` (`event_timestamp`),
  ADD KEY `idx_controller_identifier` (`controller_identifier`),
  ADD KEY `idx_rfid_id_access_log` (`rfid_id`),
  ADD KEY `idx_cardholder_id_access_log` (`cardholder_id`),
  ADD KEY `idx_event_type_code` (`event_type_code`),
  ADD KEY `idx_access_granted` (`access_granted`);

--
-- Indexes for table `ac_amenities`
--
ALTER TABLE `ac_amenities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_name_unique` (`name`);

--
-- Indexes for table `ac_cardholders`
--
ALTER TABLE `ac_cardholders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_rfid_id_unique` (`rfid_id`),
  ADD KEY `idx_last_name` (`last_name`),
  ADD KEY `idx_first_name` (`first_name`),
  ADD KEY `idx_property_id` (`property_id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_phone_type` (`phone_type`),
  ADD KEY `idx_card_status` (`card_status`),
  ADD KEY `idx_resident_type` (`resident_type`);

--
-- Indexes for table `ac_controllers`
--
ALTER TABLE `ac_controllers`
  ADD PRIMARY KEY (`controller_record_id`),
  ADD UNIQUE KEY `idx_uhppoted_device_id_unique` (`uhppoted_device_id`),
  ADD UNIQUE KEY `idx_friendly_name_unique` (`friendly_name`);

--
-- Indexes for table `ac_deleted_cardholders`
--
ALTER TABLE `ac_deleted_cardholders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ac_doors`
--
ALTER TABLE `ac_doors`
  ADD PRIMARY KEY (`door_record_id`),
  ADD UNIQUE KEY `idx_friendly_name_unique` (`friendly_name`),
  ADD UNIQUE KEY `idx_controller_door_unique` (`controller_record_id`,`door_number_on_controller`),
  ADD KEY `idx_fk_controller_record_id` (`controller_record_id`);

--
-- Indexes for table `ac_print_log`
--
ALTER TABLE `ac_print_log`
  ADD PRIMARY KEY (`log_id`),
  ADD UNIQUE KEY `idx_system_job_id_unique` (`system_job_id`),
  ADD KEY `idx_printer_job_id_print_log` (`printer_job_id`),
  ADD KEY `idx_cardholder_id_print_log` (`cardholder_id`),
  ADD KEY `idx_rfid_id_print_log` (`rfid_id`),
  ADD KEY `idx_status_print_log` (`status`),
  ADD KEY `idx_submitted_by_user_print_log` (`submitted_by_user`);

--
-- Indexes for table `ac_property`
--
ALTER TABLE `ac_property`
  ADD PRIMARY KEY (`property_id`),
  ADD UNIQUE KEY `idx_street_address_unique` (`street_address`),
  ADD KEY `idx_street_name_house_number` (`street_name`,`house_number`);

--
-- Indexes for table `ac_task_list`
--
ALTER TABLE `ac_task_list`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_controller_id_task_list` (`controller_id`),
  ADD KEY `idx_enabled_task_list` (`enabled`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ac_access_log`
--
ALTER TABLE `ac_access_log`
  MODIFY `log_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_amenities`
--
ALTER TABLE `ac_amenities`
  MODIFY `id` int NOT NULL AUTO_INCREMENT COMMENT 'Primary key for the amenity';

--
-- AUTO_INCREMENT for table `ac_cardholders`
--
ALTER TABLE `ac_cardholders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_controllers`
--
ALTER TABLE `ac_controllers`
  MODIFY `controller_record_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_doors`
--
ALTER TABLE `ac_doors`
  MODIFY `door_record_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_print_log`
--
ALTER TABLE `ac_print_log`
  MODIFY `log_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_property`
--
ALTER TABLE `ac_property`
  MODIFY `property_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ac_task_list`
--
ALTER TABLE `ac_task_list`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ac_access_log`
--
ALTER TABLE `ac_access_log`
  ADD CONSTRAINT `fk_ac_access_log_cardholder` FOREIGN KEY (`cardholder_id`) REFERENCES `ac_cardholders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `ac_cardholders`
--
ALTER TABLE `ac_cardholders`
  ADD CONSTRAINT `fk_ac_cardholders_property` FOREIGN KEY (`property_id`) REFERENCES `ac_property` (`property_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `ac_doors`
--
ALTER TABLE `ac_doors`
  ADD CONSTRAINT `fk_ac_doors_controller` FOREIGN KEY (`controller_record_id`) REFERENCES `ac_controllers` (`controller_record_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ac_print_log`
--
ALTER TABLE `ac_print_log`
  ADD CONSTRAINT `fk_ac_print_log_cardholder` FOREIGN KEY (`cardholder_id`) REFERENCES `ac_cardholders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `ac_task_list`
--
ALTER TABLE `ac_task_list`
  ADD CONSTRAINT `fk_ac_task_list_controller` FOREIGN KEY (`controller_id`) REFERENCES `ac_controllers` (`controller_record_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


