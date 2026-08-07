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
  `ward_id` int(11) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `ward_id`, `city`, `phone`, `is_active`, `created_at`) VALUES
(1, 'First Citizen', 'mail.citizen@gmail.com', '$2y$10$3qmeDWBdQLKEpvombYJ4UudZXLjuTKO8ZrD2wdWIQV2So/pA.3cjO', 'citizen', NULL, 'Chennai', '+91987654321', 1, '2026-08-06 17:07:39');

--
-- Demo civic data for the database-backed City Pulse heatmap.
-- Each canonical issue has child rows in issue_reports; the API groups by
-- geohash and counts those reports to calculate cluster density.
--

INSERT INTO `issues` (`id`, `user_id`, `title`, `description`, `category`, `severity`, `status`, `lat`, `lng`, `geohash`, `address`, `ward_id`, `upvote_count`, `is_verified`, `ai_confidence`, `is_manipulated`, `priority_score`, `parent_issue_id`, `created_at`, `updated_at`, `resolved_at`) VALUES
(1, 1, 'Road damage near Anna Salai', 'Multiple citizens have reported a deep pothole and uneven surface near the Teynampet stretch.', 'pothole', 5, 'in_progress', 13.06040000, 80.24960000, 'tf3461e', 'Anna Salai, Teynampet', NULL, 51, 1, 0.961, 0, 98.0000, NULL, '2026-08-07 08:30:00', '2026-08-07 10:30:00', NULL),
(2, 1, 'Overflowing collection point', 'Waste has been accumulating around the collection point on Ranganathan Street.', 'garbage', 4, 'pending', 13.04180000, 80.23410000, 'tf341y0', 'Ranganathan Street, T. Nagar', NULL, 36, 1, 0.934, 0, 88.0000, NULL, '2026-08-07 09:10:00', '2026-08-07 09:10:00', NULL),
(3, 1, 'Waterlogging after rainfall', 'Residents are reporting standing water that is slowing traffic on the Velachery Main Road.', 'waterlogging', 5, 'pending', 12.98150000, 80.21810000, 'tf31c7j', 'Velachery Main Road', NULL, 42, 1, 0.973, 0, 95.0000, NULL, '2026-08-06 18:45:00', '2026-08-06 18:45:00', NULL),
(4, 1, 'Uneven road surface near Adyar', 'The road surface near LB Road has broken patches and is difficult for two-wheelers after dark.', 'road_damage', 4, 'acknowledged', 13.00680000, 80.25720000, 'tf31frc', 'LB Road, Adyar', NULL, 24, 1, 0.918, 0, 76.0000, NULL, '2026-08-06 15:20:00', '2026-08-06 16:00:00', NULL),
(5, 1, 'Streetlights out on Kutchery Road', 'Several streetlights are not working along the Mylapore stretch.', 'streetlight', 3, 'in_progress', 13.03380000, 80.26760000, 'tf344s9', 'Kutchery Road, Mylapore', NULL, 17, 1, 0.902, 0, 61.0000, NULL, '2026-08-06 11:05:00', '2026-08-06 12:15:00', NULL),
(6, 1, 'Open drain beside the bus stop', 'An uncovered drain beside the bus stop is creating a safety hazard for pedestrians.', 'open_drain', 4, 'pending', 13.07320000, 80.26090000, 'tf3467u', 'Poonamallee High Road, Egmore', NULL, 16, 1, 0.945, 0, 71.0000, NULL, '2026-08-05 16:00:00', '2026-08-05 16:00:00', NULL),
(7, 1, 'Deep pothole near Guindy station', 'A pothole has opened near the station entrance and is forcing vehicles into the next lane.', 'pothole', 4, 'in_progress', 13.01080000, 80.21310000, 'tf34129', 'GST Road, Guindy', NULL, 13, 1, 0.927, 0, 60.0000, NULL, '2026-08-05 12:25:00', '2026-08-05 13:10:00', NULL),
(8, 1, 'Water collects at the junction', 'Standing water is returning at the East Coast Road junction after every heavy shower.', 'waterlogging', 3, 'acknowledged', 12.98330000, 80.25980000, 'tf31f7e', 'East Coast Road, Thiruvanmiyur', NULL, 11, 1, 0.891, 0, 58.0000, NULL, '2026-08-04 17:40:00', '2026-08-04 18:05:00', NULL),
(9, 1, 'Footpath partially blocked', 'A temporary structure is narrowing the pedestrian path along the fourth main road.', 'encroachment', 3, 'pending', 13.00030000, 80.26800000, 'tf31fw9', '4th Main Road, Besant Nagar', NULL, 8, 1, 0.876, 0, 44.0000, NULL, '2026-08-04 10:15:00', '2026-08-04 10:15:00', NULL),
(10, 1, 'Drain cover needs replacement', 'A damaged drain cover near College Road is loose and difficult to see at night.', 'open_drain', 3, 'pending', 13.05690000, 80.24250000, 'tf343by', 'College Road, Nungambakkam', NULL, 9, 1, 0.913, 0, 50.0000, NULL, '2026-08-03 14:20:00', '2026-08-03 14:20:00', NULL),
(11, 1, 'Missed waste pickup', 'The scheduled waste pickup was missed and bags have been left beside the Royapettah road.', 'garbage', 2, 'resolved', 13.05260000, 80.26390000, 'tf3462n', 'Royapettah High Road', NULL, 7, 1, 0.899, 0, 31.0000, NULL, '2026-08-03 09:30:00', '2026-08-04 11:00:00', '2026-08-04 11:00:00'),
(12, 1, 'Broken shoulder on the service road', 'The broken road shoulder is narrowing the service lane beside the industrial estate.', 'road_damage', 4, 'pending', 13.11430000, 80.15480000, 'tf2fxgq', 'Ambattur Industrial Estate', NULL, 10, 1, 0.905, 0, 64.0000, NULL, '2026-08-02 13:10:00', '2026-08-02 13:10:00', NULL),
(13, 1, 'Construction waste on the verge', 'Construction waste has been left on the road edge along the OMR service road.', 'garbage', 3, 'in_progress', 12.90100000, 80.22790000, 'tf313ss', 'OMR Service Road, Sholinganallur', NULL, 6, 1, 0.862, 0, 40.0000, NULL, '2026-08-02 08:55:00', '2026-08-02 09:35:00', NULL),
(14, 1, 'Graffiti on the compound wall', 'Graffiti was reported on the compound wall beside New Avadi Road and has since been cleaned.', 'graffiti', 2, 'resolved', 13.08370000, 80.24140000, 'tf343vy', 'New Avadi Road, Kilpauk', NULL, 4, 1, 0.844, 0, 18.0000, NULL, '2026-08-01 16:05:00', '2026-08-02 10:30:00', '2026-08-02 10:30:00'),
(15, 1, 'Fallen branch cleared from lane', 'A fallen branch was blocking part of the lane near Madhavaram High Road.', 'fallen_tree', 3, 'resolved', 13.11670000, 80.24510000, 'tf34d5b', 'Madhavaram High Road, Perambur', NULL, 3, 1, 0.881, 0, 20.0000, NULL, '2026-07-31 11:30:00', '2026-08-01 09:15:00', '2026-08-01 09:15:00'),
(16, 1, 'Streetlight repaired near Saidapet', 'A faulty streetlight was reported and repaired near Jones Road.', 'streetlight', 2, 'resolved', 13.02130000, 80.22340000, 'tf341d2', 'Jones Road, Saidapet', NULL, 5, 1, 0.895, 0, 15.0000, NULL, '2026-07-30 18:15:00', '2026-07-31 08:30:00', '2026-07-31 08:30:00');

-- 96 child reports make the density values visible through the real API.
INSERT INTO `issue_reports` (`id`, `issue_id`, `reporter_id`, `submitted_category`, `description`, `lat`, `lng`, `geohash`, `gps_accuracy`, `created_at`)
SELECT cluster.base_report_id + report_numbers.report_number - 1,
       cluster.issue_id,
       1,
       cluster.category,
       CONCAT('Seeded citizen report #', report_numbers.report_number, ' for ', cluster.title),
       cluster.lat,
       cluster.lng,
       cluster.geohash,
       8.00,
       DATE_SUB(cluster.created_at, INTERVAL report_numbers.report_number MINUTE)
  FROM (
    SELECT 1 AS issue_id, 1 AS base_report_id, 'pothole' AS category, 'Road damage near Anna Salai' AS title, 13.06040000 AS lat, 80.24960000 AS lng, 'tf3461e' AS geohash, 18 AS report_count, '2026-08-07 08:30:00' AS created_at
    UNION ALL SELECT 2, 19, 'garbage', 'Overflowing collection point', 13.04180000, 80.23410000, 'tf341y0', 12, '2026-08-07 09:10:00'
    UNION ALL SELECT 3, 31, 'waterlogging', 'Waterlogging after rainfall', 12.98150000, 80.21810000, 'tf31c7j', 10, '2026-08-06 18:45:00'
    UNION ALL SELECT 4, 41, 'road_damage', 'Uneven road surface near Adyar', 13.00680000, 80.25720000, 'tf31frc', 8, '2026-08-06 15:20:00'
    UNION ALL SELECT 5, 49, 'streetlight', 'Streetlights out on Kutchery Road', 13.03380000, 80.26760000, 'tf344s9', 7, '2026-08-06 11:05:00'
    UNION ALL SELECT 6, 56, 'open_drain', 'Open drain beside the bus stop', 13.07320000, 80.26090000, 'tf3467u', 6, '2026-08-05 16:00:00'
    UNION ALL SELECT 7, 62, 'pothole', 'Deep pothole near Guindy station', 13.01080000, 80.21310000, 'tf34129', 4, '2026-08-05 12:25:00'
    UNION ALL SELECT 8, 66, 'waterlogging', 'Water collects at the junction', 12.98330000, 80.25980000, 'tf31f7e', 6, '2026-08-04 17:40:00'
    UNION ALL SELECT 9, 72, 'encroachment', 'Footpath partially blocked', 13.00030000, 80.26800000, 'tf31fw9', 3, '2026-08-04 10:15:00'
    UNION ALL SELECT 10, 75, 'open_drain', 'Drain cover needs replacement', 13.05690000, 80.24250000, 'tf343by', 4, '2026-08-03 14:20:00'
    UNION ALL SELECT 11, 79, 'garbage', 'Missed waste pickup', 13.05260000, 80.26390000, 'tf3462n', 5, '2026-08-03 09:30:00'
    UNION ALL SELECT 12, 84, 'road_damage', 'Broken shoulder on the service road', 13.11430000, 80.15480000, 'tf2fxgq', 4, '2026-08-02 13:10:00'
    UNION ALL SELECT 13, 88, 'garbage', 'Construction waste on the verge', 12.90100000, 80.22790000, 'tf313ss', 3, '2026-08-02 08:55:00'
    UNION ALL SELECT 14, 91, 'graffiti', 'Graffiti on the compound wall', 13.08370000, 80.24140000, 'tf343vy', 2, '2026-08-01 16:05:00'
    UNION ALL SELECT 15, 93, 'fallen_tree', 'Fallen branch cleared from lane', 13.11670000, 80.24510000, 'tf34d5b', 2, '2026-07-31 11:30:00'
    UNION ALL SELECT 16, 95, 'streetlight', 'Streetlight repaired near Saidapet', 13.02130000, 80.22340000, 'tf341d2', 2, '2026-07-30 18:15:00'
  ) AS cluster
  INNER JOIN (
    SELECT 1 AS report_number UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18
  ) AS report_numbers ON report_numbers.report_number <= cluster.report_count;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assignments_issue_active` (`issue_id`,`completed_at`),
  ADD KEY `idx_assignments_worker_active` (`worker_id`,`completed_at`);

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
  ADD KEY `idx_severity` (`severity`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_priority` (`priority_score`);

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
