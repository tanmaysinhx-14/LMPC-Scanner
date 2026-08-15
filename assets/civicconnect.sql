-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 07, 2026 at 01:41 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+05:30";


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
  `completed_at` timestamp NULL DEFAULT NULL,
  `citizen_verified_at` timestamp NULL DEFAULT NULL,
  `citizen_reopen_reason` text DEFAULT NULL,
  `after_image_path` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `work_requests`
--

CREATE TABLE `work_requests` (
  `id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `issue_id` int(11) DEFAULT NULL,
  `message` varchar(1000) NOT NULL,
  `status` enum('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `review_note` varchar(1000) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `auth_login_attempts`
--

CREATE TABLE `auth_login_attempts` (
  `identity_hash` char(64) NOT NULL,
  `attempts` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL
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
  `department` varchar(50) NOT NULL DEFAULT 'municipal',
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
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `recurrence_of` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issue_ai_analyses`
--

CREATE TABLE `issue_ai_analyses` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `image_id` int(11) DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `severity` tinyint(4) NOT NULL DEFAULT 1,
  `confidence` decimal(5,4) NOT NULL DEFAULT 0.0000,
  `is_manipulated` tinyint(1) NOT NULL DEFAULT 0,
  `model_version` varchar(100) DEFAULT NULL,
  `raw_output` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_output`)),
  `analyzed_at` timestamp NOT NULL DEFAULT current_timestamp()
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
-- Table structure for table `issue_reports`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `mobile_access_tokens`
--

CREATE TABLE `mobile_access_tokens` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `device_name` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mobile_refresh_tokens`
--

CREATE TABLE `mobile_refresh_tokens` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `family_id` char(32) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `device_id` varchar(120) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `rotated_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `replaced_by_id` bigint(20) DEFAULT NULL
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
  `role` enum('citizen','worker','admin') NOT NULL DEFAULT 'citizen',
  `department` varchar(50) DEFAULT NULL,
  `ward_id` int(11) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

-- Six demo accounts per role for multi-device demonstrations.
-- Citizens use Citizen@123, workers use Worker@123, and administrators use Admin@123.
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `department`, `ward_id`, `city`, `phone`, `is_active`, `created_at`) VALUES
(1, 'Citizen One', 'citizen.one@civicconnect.test', '$2y$12$DYH2fS2TXrHSQkLVD/ceOOnbmRwb/eSleeme/eqPW4jcXBsgglNUe', 'citizen', NULL, 1, 'Chennai', '+91987654321', 1, '2026-08-14 09:00:00'),
(2, 'Citizen Two', 'citizen.two@civicconnect.test', '$2y$12$DYH2fS2TXrHSQkLVD/ceOOnbmRwb/eSleeme/eqPW4jcXBsgglNUe', 'citizen', NULL, 1, 'Chennai', '+91987654322', 1, '2026-08-14 09:01:00'),
(3, 'Citizen Three', 'citizen.three@civicconnect.test', '$2y$12$DYH2fS2TXrHSQkLVD/ceOOnbmRwb/eSleeme/eqPW4jcXBsgglNUe', 'citizen', NULL, 2, 'Chennai', '+91987654323', 1, '2026-08-14 09:02:00'),
(4, 'Citizen Four', 'citizen.four@civicconnect.test', '$2y$12$DYH2fS2TXrHSQkLVD/ceOOnbmRwb/eSleeme/eqPW4jcXBsgglNUe', 'citizen', NULL, 2, 'Chennai', '+91987654324', 1, '2026-08-14 09:03:00'),
(5, 'Citizen Five', 'citizen.five@civicconnect.test', '$2y$12$DYH2fS2TXrHSQkLVD/ceOOnbmRwb/eSleeme/eqPW4jcXBsgglNUe', 'citizen', NULL, 3, 'Chennai', '+91987654325', 1, '2026-08-14 09:04:00'),
(6, 'Citizen Six', 'citizen.six@civicconnect.test', '$2y$12$DYH2fS2TXrHSQkLVD/ceOOnbmRwb/eSleeme/eqPW4jcXBsgglNUe', 'citizen', NULL, 3, 'Chennai', '+91987654326', 1, '2026-08-14 09:05:00'),
(7, 'Worker One', 'worker.one@civicconnect.test', '$2y$12$SrdPb1.zIhkfDN1Nu/ymD..NvZeRpeezhCLSkKx3tHJh2aLsH.RC.', 'worker', 'public_works', 1, 'Chennai', '+91987654421', 1, '2026-08-14 09:10:00'),
(8, 'Worker Two', 'worker.two@civicconnect.test', '$2y$12$SrdPb1.zIhkfDN1Nu/ymD..NvZeRpeezhCLSkKx3tHJh2aLsH.RC.', 'worker', 'public_works', 1, 'Chennai', '+91987654422', 1, '2026-08-14 09:11:00'),
(9, 'Worker Three', 'worker.three@civicconnect.test', '$2y$12$SrdPb1.zIhkfDN1Nu/ymD..NvZeRpeezhCLSkKx3tHJh2aLsH.RC.', 'worker', 'sanitation', 2, 'Chennai', '+91987654423', 1, '2026-08-14 09:12:00'),
(10, 'Worker Four', 'worker.four@civicconnect.test', '$2y$12$SrdPb1.zIhkfDN1Nu/ymD..NvZeRpeezhCLSkKx3tHJh2aLsH.RC.', 'worker', 'drainage', 2, 'Chennai', '+91987654424', 1, '2026-08-14 09:13:00'),
(11, 'Worker Five', 'worker.five@civicconnect.test', '$2y$12$SrdPb1.zIhkfDN1Nu/ymD..NvZeRpeezhCLSkKx3tHJh2aLsH.RC.', 'worker', 'electricity', 3, 'Chennai', '+91987654425', 1, '2026-08-14 09:14:00'),
(12, 'Worker Six', 'worker.six@civicconnect.test', '$2y$12$SrdPb1.zIhkfDN1Nu/ymD..NvZeRpeezhCLSkKx3tHJh2aLsH.RC.', 'worker', 'municipal', 3, 'Chennai', '+91987654426', 1, '2026-08-14 09:15:00'),
(13, 'Admin One', 'admin.one@civicconnect.test', '$2y$12$nyO98D6FesSRzj6Dz5RiSesT0MbEzjFrtQgYlcfZSWU7vLIdmGUPC', 'admin', NULL, NULL, 'Chennai', '+91987654521', 1, '2026-08-14 09:20:00'),
(14, 'Admin Two', 'admin.two@civicconnect.test', '$2y$12$nyO98D6FesSRzj6Dz5RiSesT0MbEzjFrtQgYlcfZSWU7vLIdmGUPC', 'admin', NULL, NULL, 'Chennai', '+91987654522', 1, '2026-08-14 09:21:00'),
(15, 'Admin Three', 'admin.three@civicconnect.test', '$2y$12$nyO98D6FesSRzj6Dz5RiSesT0MbEzjFrtQgYlcfZSWU7vLIdmGUPC', 'admin', NULL, NULL, 'Chennai', '+91987654523', 1, '2026-08-14 09:22:00'),
(16, 'Admin Four', 'admin.four@civicconnect.test', '$2y$12$nyO98D6FesSRzj6Dz5RiSesT0MbEzjFrtQgYlcfZSWU7vLIdmGUPC', 'admin', NULL, NULL, 'Chennai', '+91987654524', 1, '2026-08-14 09:23:00'),
(17, 'Admin Five', 'admin.five@civicconnect.test', '$2y$12$nyO98D6FesSRzj6Dz5RiSesT0MbEzjFrtQgYlcfZSWU7vLIdmGUPC', 'admin', NULL, NULL, 'Chennai', '+91987654525', 1, '2026-08-14 09:24:00'),
(18, 'Admin Six', 'admin.six@civicconnect.test', '$2y$12$nyO98D6FesSRzj6Dz5RiSesT0MbEzjFrtQgYlcfZSWU7vLIdmGUPC', 'admin', NULL, NULL, 'Chennai', '+91987654526', 1, '2026-08-14 09:25:00');

--
-- Six compact civic records make multi-device changes easy to observe.
-- Issue 1 has two same-category reports at the same location. Issue 2 is a
-- different road problem nearby, while the remaining issues are city signals.
--

INSERT INTO `issues` (`id`, `user_id`, `title`, `description`, `category`, `severity`, `status`, `lat`, `lng`, `geohash`, `address`, `ward_id`, `upvote_count`, `is_verified`, `ai_confidence`, `is_manipulated`, `priority_score`, `parent_issue_id`, `created_at`, `updated_at`, `resolved_at`) VALUES
(1, 1, 'Pothole cluster near Anna Salai', 'A deep pothole and uneven surface have been reported twice near the Teynampet stretch.', 'pothole', 5, 'pending', 13.06040000, 80.24960000, 'tf3461e', 'Anna Salai, Teynampet', 1, 0, 1, 0.961, 0, 74.0000, NULL, '2026-08-14 08:30:00', '2026-08-14 08:30:00', NULL),
(2, 2, 'Road damage beside Anna Salai', 'A separate broken road patch is close to the pothole cluster and may need one coordinated inspection.', 'road_damage', 4, 'pending', 13.06100000, 80.25010000, 'tf3461s', 'Anna Salai, Teynampet', 1, 0, 1, 0.934, 0, 53.0000, NULL, '2026-08-14 09:10:00', '2026-08-14 09:10:00', NULL),
(3, 3, 'Overflowing collection point', 'Waste has been accumulating around the collection point on Ranganathan Street.', 'garbage', 3, 'acknowledged', 13.04180000, 80.23410000, 'tf341y0', 'Ranganathan Street, T. Nagar', 2, 0, 1, 0.934, 0, 40.0000, NULL, '2026-08-14 10:00:00', '2026-08-14 10:00:00', NULL),
(4, 4, 'Waterlogging after rainfall', 'Standing water is slowing traffic on the Velachery Main Road.', 'waterlogging', 5, 'pending', 12.98150000, 80.21810000, 'tf31c7j', 'Velachery Main Road', 2, 0, 1, 0.973, 0, 60.0000, NULL, '2026-08-14 10:30:00', '2026-08-14 10:30:00', NULL),
(5, 5, 'Streetlights out on Kutchery Road', 'Several streetlights are not working along the Mylapore stretch.', 'streetlight', 3, 'in_progress', 13.03380000, 80.26760000, 'tf344s9', 'Kutchery Road, Mylapore', 3, 0, 1, 0.902, 0, 40.0000, NULL, '2026-08-14 11:05:00', '2026-08-14 11:05:00', NULL),
(6, 6, 'Deep pothole near Guindy station', 'A pothole has opened near the station entrance and is forcing vehicles into the next lane.', 'pothole', 4, 'pending', 13.01080000, 80.21310000, 'tf34129', 'GST Road, Guindy', 3, 0, 1, 0.927, 0, 40.0000, NULL, '2026-08-14 11:35:00', '2026-08-14 11:35:00', NULL);

-- Seven report events: one extra same-location pothole report and one report
-- for each of the other five issues. Additional real actions can be observed
-- without a large fixture set.
INSERT INTO `issue_reports` (`id`, `issue_id`, `reporter_id`, `submitted_category`, `description`, `lat`, `lng`, `geohash`, `gps_accuracy`, `created_at`)
(1, 1, 'pothole', 'Deep pothole reported at the same location.', 13.06040000, 80.24960000, 'tf3461e', 8.00, '2026-08-14 08:30:00'),
(2, 2, 'pothole', 'A second citizen saw the same pothole.', 13.06045000, 80.24965000, 'tf3461e', 9.00, '2026-08-14 08:45:00'),
(3, 3, 'road_damage', 'Broken road patch close to Anna Salai.', 13.06100000, 80.25010000, 'tf3461s', 12.00, '2026-08-14 09:10:00'),
(4, 4, 'garbage', 'Overflowing collection point needs attention.', 13.04180000, 80.23410000, 'tf341y0', 10.00, '2026-08-14 10:00:00'),
(5, 5, 'waterlogging', 'Standing water is affecting the road.', 12.98150000, 80.21810000, 'tf31c7j', 11.00, '2026-08-14 10:30:00'),
(6, 6, 'streetlight', 'Several lights are out after sunset.', 13.03380000, 80.26760000, 'tf344s9', 14.00, '2026-08-14 11:05:00'),
(7, 6, 'pothole', 'The pothole remains a concern for commuters.', 13.01080000, 80.21310000, 'tf34129', 15.00, '2026-08-14 11:35:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assignments_issue_active` (`issue_id`,`completed_at`),
  ADD KEY `idx_assignments_worker_active` (`worker_id`,`completed_at`),
  ADD KEY `idx_assignments_citizen_verified` (`citizen_verified_at`);

--
-- Indexes for table `work_requests`
--
ALTER TABLE `work_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_work_requests_worker_status` (`worker_id`,`status`),
  ADD KEY `idx_work_requests_issue_status` (`issue_id`,`status`),
  ADD KEY `idx_work_requests_reviewed_by` (`reviewed_by`);

--
-- Indexes for table `auth_login_attempts`
--
ALTER TABLE `auth_login_attempts`
  ADD PRIMARY KEY (`identity_hash`),
  ADD KEY `idx_login_blocked_until` (`blocked_until`);

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
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_severity` (`severity`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_priority` (`priority_score`),
  ADD KEY `idx_recurrence` (`is_recurring`,`recurrence_of`);

--
-- Indexes for table `issue_ai_analyses`
--
ALTER TABLE `issue_ai_analyses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ai_issue` (`issue_id`),
  ADD KEY `idx_ai_report` (`report_id`),
  ADD KEY `idx_ai_category` (`category`);

--
-- Indexes for table `issue_images`
--
ALTER TABLE `issue_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_issue_images_report` (`report_id`),
  ADD KEY `idx_issue_images_hash` (`sha256`);

--
-- Indexes for table `issue_reports`
--
ALTER TABLE `issue_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_issue_reports_issue` (`issue_id`),
  ADD KEY `idx_issue_reports_reporter` (`reporter_id`),
  ADD KEY `idx_issue_reports_geohash` (`geohash`),
  ADD KEY `idx_issue_reports_created` (`created_at`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_remember_selector` (`selector`),
  ADD KEY `idx_remember_user` (`user_id`),
  ADD KEY `idx_remember_expiry` (`expires_at`);

--
-- Indexes for table `mobile_access_tokens`
--
ALTER TABLE `mobile_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_mobile_token_hash` (`token_hash`),
  ADD KEY `idx_mobile_token_user` (`user_id`),
  ADD KEY `idx_mobile_token_expiry` (`expires_at`);

--
-- Indexes for table `mobile_refresh_tokens`
--
ALTER TABLE `mobile_refresh_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_mobile_refresh_hash` (`token_hash`),
  ADD KEY `idx_mobile_refresh_user` (`user_id`),
  ADD KEY `idx_mobile_refresh_family` (`family_id`),
  ADD KEY `idx_mobile_refresh_expiry` (`expires_at`);

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
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_department_role` (`department`,`role`,`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `work_requests`
--
ALTER TABLE `work_requests`
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
-- AUTO_INCREMENT for table `issue_ai_analyses`
--
ALTER TABLE `issue_ai_analyses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `issue_images`
--
ALTER TABLE `issue_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `issue_reports`
--
ALTER TABLE `issue_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `mobile_access_tokens`
--
ALTER TABLE `mobile_access_tokens`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mobile_refresh_tokens`
--
ALTER TABLE `mobile_refresh_tokens`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
