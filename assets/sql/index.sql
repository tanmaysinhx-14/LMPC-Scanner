-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 06, 2026 at 08:37 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `civicconnect`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fake_flags`
--

CREATE TABLE `fake_flags` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `flagged_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issues`
--

CREATE TABLE `issues` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `category` enum('pothole','garbage','streetlight','waterlogging','road_damage','encroachment','graffiti','open_drain','fallen_tree','unknown','other') NOT NULL,
  `severity` tinyint(4) NOT NULL DEFAULT 1 CHECK (`severity` between 1 and 5),
  `status` enum('pending','acknowledged','in_progress','resolved','rejected') DEFAULT 'pending',
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `geohash` varchar(12) NOT NULL,
  `address` varchar(500) DEFAULT NULL,
  `ward_id` int(11) DEFAULT NULL,
  `upvote_count` int(11) DEFAULT 0,
  `is_verified` tinyint(1) DEFAULT 0,
  `ai_confidence` decimal(4,3) DEFAULT NULL,
  `is_manipulated` tinyint(1) DEFAULT 0,
  `priority_score` decimal(10,4) DEFAULT 0.0000,
  `parent_issue_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issue_images`
--

CREATE TABLE `issue_images` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(80) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `ai_raw_output` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ai_raw_output`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `status_history`
--

CREATE TABLE `status_history` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `upvotes`
--

CREATE TABLE `upvotes` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('citizen','authority','worker','admin') DEFAULT 'citizen',
  `ward_id` int(11) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `issue_reports` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `submitted_category` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `geohash` varchar(12) NOT NULL,
  `gps_accuracy` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `issue_ai_analyses` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `image_id` int(11) DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `severity` tinyint(4) NOT NULL DEFAULT 1 CHECK (`severity` between 1 and 5),
  `confidence` decimal(5,4) NOT NULL DEFAULT 0.0000,
  `is_manipulated` tinyint(1) NOT NULL DEFAULT 0,
  `model_version` varchar(100) DEFAULT NULL,
  `raw_output` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_output`)),
  `analyzed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `remember_tokens` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `selector` char(36) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `auth_login_attempts` (
  `identity_hash` char(64) NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `ward_id`, `city`, `phone`, `is_active`, `created_at`) VALUES
(1, 'First Citizen', 'mail.citizen@gmail.com', '$2y$10$3qmeDWBdQLKEpvombYJ4UudZXLjuTKO8ZrD2wdWIQV2So/pA.3cjO', 'citizen', NULL, 'Chennai', '+91987654321', 1, '2026-08-06 17:07:39');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fake_flags`
--
ALTER TABLE `fake_flags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_flag` (`issue_id`,`flagged_by`);

--
-- Indexes for table `issues`
--
ALTER TABLE `issues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_geohash` (`geohash`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_severity` (`severity`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_priority` (`priority_score`);

--
-- Indexes for table `issue_images`
--
ALTER TABLE `issue_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_issue_images_issue` (`issue_id`),
  ADD KEY `idx_issue_images_report` (`report_id`),
  ADD KEY `idx_issue_images_hash` (`sha256`);

ALTER TABLE `issue_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_issue_reports_issue` (`issue_id`),
  ADD KEY `idx_issue_reports_reporter` (`reporter_id`),
  ADD KEY `idx_issue_reports_geohash` (`geohash`),
  ADD KEY `idx_issue_reports_created` (`created_at`);

ALTER TABLE `issue_ai_analyses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ai_issue` (`issue_id`),
  ADD KEY `idx_ai_report` (`report_id`),
  ADD KEY `idx_ai_category` (`category`);

ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_remember_selector` (`selector`),
  ADD KEY `idx_remember_user` (`user_id`),
  ADD KEY `idx_remember_expiry` (`expires_at`);

ALTER TABLE `auth_login_attempts`
  ADD PRIMARY KEY (`identity_hash`),
  ADD KEY `idx_login_blocked_until` (`blocked_until`);

--
-- Indexes for table `status_history`
--
ALTER TABLE `status_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `upvotes`
--
ALTER TABLE `upvotes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_upvote` (`issue_id`,`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fake_flags`
--
ALTER TABLE `fake_flags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `issues`
--
ALTER TABLE `issues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `issue_images`
--
ALTER TABLE `issue_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `issue_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `issue_ai_analyses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `remember_tokens`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `status_history`
--
ALTER TABLE `status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `upvotes`
--
ALTER TABLE `upvotes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
