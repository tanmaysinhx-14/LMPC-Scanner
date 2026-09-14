-- Legal Metrology Scanner database for XAMPP MySQL / MySQL Workbench.
-- Import this file in MySQL Workbench or phpMyAdmin.

CREATE DATABASE IF NOT EXISTS `lmpc_scanner`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lmpc_scanner`;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `Users` (
  `user_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` VARCHAR(16) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` VARCHAR(40) NOT NULL DEFAULT '',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Scans` (
  `scan_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspector_id` BIGINT UNSIGNED NOT NULL,
  `image_path` LONGTEXT NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  `timestamp` VARCHAR(40) NOT NULL,
  `product_name` VARCHAR(500) NOT NULL DEFAULT '',
  `evidence_notes` TEXT NOT NULL,
  `evidence_hashes` LONGTEXT NOT NULL,
  `reviewer_id` BIGINT UNSIGNED NULL,
  `reviewed_at` VARCHAR(40) NULL,
  `review_reason` TEXT NOT NULL,
  `rule_config` LONGTEXT NOT NULL DEFAULT '{}',
  PRIMARY KEY (`scan_id`),
  KEY `idx_scans_status` (`status`),
  KEY `idx_scans_timestamp` (`timestamp`),
  CONSTRAINT `fk_scans_inspector` FOREIGN KEY (`inspector_id`) REFERENCES `Users` (`user_id`),
  CONSTRAINT `fk_scans_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `Users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ScanResults` (
  `result_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scan_id` BIGINT UNSIGNED NOT NULL,
  `rule_class` VARCHAR(80) NOT NULL,
  `extracted_text` TEXT NOT NULL,
  `is_compliant` TINYINT(1) NOT NULL,
  `penalty_amount` BIGINT NOT NULL DEFAULT 0,
  `reason` TEXT NOT NULL,
  `parsed_data` LONGTEXT NOT NULL,
  PRIMARY KEY (`result_id`),
  KEY `idx_scan_results_scan_id` (`scan_id`),
  CONSTRAINT `fk_scan_results_scan` FOREIGN KEY (`scan_id`) REFERENCES `Scans` (`scan_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AuditEvents` (
  `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` BIGINT UNSIGNED NULL,
  `scan_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(80) NOT NULL,
  `details` TEXT NOT NULL,
  `timestamp` VARCHAR(40) NOT NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_audit_events_timestamp` (`timestamp`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `Users` (`user_id`),
  CONSTRAINT `fk_audit_scan` FOREIGN KEY (`scan_id`) REFERENCES `Scans` (`scan_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `SessionTokens` (
  `token_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `selector` VARCHAR(64) NOT NULL,
  `validator_hash` CHAR(64) NOT NULL,
  `created_at` VARCHAR(40) NOT NULL,
  `expires_at` VARCHAR(40) NOT NULL,
  `last_used_at` VARCHAR(40) NULL,
  `revoked_at` VARCHAR(40) NULL,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_session_selector` (`selector`),
  KEY `idx_session_tokens_user` (`user_id`),
  KEY `idx_session_tokens_expiry` (`expires_at`),
  CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `Users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RuleConfigs` (
  `config_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_name` VARCHAR(120) NOT NULL DEFAULT '',
  `config_version` VARCHAR(32) NOT NULL DEFAULT '',
  `payload` LONGTEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` VARCHAR(40) NOT NULL,
  PRIMARY KEY (`config_id`),
  KEY `idx_rule_configs_active` (`is_active`),
  CONSTRAINT `fk_rule_config_user` FOREIGN KEY (`created_by`) REFERENCES `Users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
