-- Apply this migration to an existing CivicConnect database. Fresh installs
-- can use assets/sql/index.sql instead.
ALTER TABLE users MODIFY role ENUM('citizen', 'authority', 'worker', 'admin') NOT NULL DEFAULT 'citizen';
ALTER TABLE issues MODIFY category ENUM('pothole','garbage','streetlight','waterlogging','road_damage','encroachment','graffiti','open_drain','fallen_tree','unknown','other') NOT NULL;
ALTER TABLE issue_images
  ADD COLUMN IF NOT EXISTS report_id INT NULL AFTER issue_id,
  ADD COLUMN IF NOT EXISTS mime_type VARCHAR(80) NULL AFTER original_name,
  ADD COLUMN IF NOT EXISTS sha256 CHAR(64) NULL AFTER file_size,
  ADD INDEX IF NOT EXISTS idx_issue_images_report (report_id),
  ADD INDEX IF NOT EXISTS idx_issue_images_hash (sha256);

CREATE TABLE IF NOT EXISTS issue_reports (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL,
  reporter_id INT NOT NULL,
  submitted_category VARCHAR(50) NULL,
  description TEXT NULL,
  lat DECIMAL(10,8) NOT NULL,
  lng DECIMAL(11,8) NOT NULL,
  geohash VARCHAR(12) NOT NULL,
  gps_accuracy DECIMAL(10,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_issue_reports_issue (issue_id), INDEX idx_issue_reports_reporter (reporter_id),
  INDEX idx_issue_reports_geohash (geohash), INDEX idx_issue_reports_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS issue_ai_analyses (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  issue_id INT NOT NULL, report_id INT NULL, image_id INT NULL,
  category VARCHAR(50) NOT NULL,
  severity TINYINT NOT NULL DEFAULT 1,
  confidence DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  is_manipulated TINYINT(1) NOT NULL DEFAULT 0,
  model_version VARCHAR(100) NULL,
  raw_output JSON NULL,
  analyzed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_issue (issue_id), INDEX idx_ai_report (report_id), INDEX idx_ai_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  selector CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  user_agent_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY unique_remember_selector (selector), INDEX idx_remember_user (user_id), INDEX idx_remember_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS auth_login_attempts (
  identity_hash CHAR(64) NOT NULL PRIMARY KEY,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  INDEX idx_login_blocked_until (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
