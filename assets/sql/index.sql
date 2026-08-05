-- civicconnect.sql

CREATE TABLE users (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('citizen','admin','worker') DEFAULT 'citizen',
    ward_id       INT,
    city          VARCHAR(100),
    phone         VARCHAR(15),
    is_active     BOOLEAN DEFAULT TRUE,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE issues (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    user_id        INT NOT NULL REFERENCES users(id),
    title          VARCHAR(255),
    description    TEXT,
    category       ENUM('pothole','garbage','streetlight','waterlogging',
                        'road_damage','encroachment','graffiti','open_drain','other') NOT NULL,
    severity       TINYINT NOT NULL DEFAULT 1 CHECK (severity BETWEEN 1 AND 5),
    status         ENUM('pending','acknowledged','in_progress','resolved','rejected') DEFAULT 'pending',
    lat            DECIMAL(10, 8) NOT NULL,
    lng            DECIMAL(11, 8) NOT NULL,
    geohash        VARCHAR(12) NOT NULL,   -- for spatial proximity queries
    address        VARCHAR(500),
    ward_id        INT,
    upvote_count   INT DEFAULT 0,
    is_verified    BOOLEAN DEFAULT FALSE,
    ai_confidence  DECIMAL(4, 3),
    is_manipulated BOOLEAN DEFAULT FALSE,
    priority_score DECIMAL(10, 4) DEFAULT 0,  -- precomputed, updated by cron
    parent_issue_id INT DEFAULT NULL,          -- if this is a duplicate
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at    TIMESTAMP NULL,
    INDEX idx_geohash   (geohash),
    INDEX idx_status    (status),
    INDEX idx_category  (category),
    INDEX idx_severity  (severity),
    INDEX idx_created   (created_at),
    INDEX idx_priority  (priority_score DESC)
);

CREATE TABLE issue_images (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    issue_id       INT NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
    file_path      VARCHAR(500) NOT NULL,
    original_name  VARCHAR(255),
    file_size      INT,
    ai_raw_output  JSON,           -- full YOLO output stored for audit
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE upvotes (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    issue_id   INT NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
    user_id    INT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_upvote (issue_id, user_id)
);

CREATE TABLE assignments (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    issue_id     INT NOT NULL REFERENCES issues(id),
    worker_id    INT NOT NULL REFERENCES users(id),
    assigned_by  INT REFERENCES users(id),
    notes        TEXT,
    assigned_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL
);

CREATE TABLE status_history (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    issue_id     INT NOT NULL REFERENCES issues(id),
    changed_by   INT NOT NULL REFERENCES users(id),
    old_status   VARCHAR(50),
    new_status   VARCHAR(50),
    note         TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE fake_flags (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    issue_id   INT NOT NULL REFERENCES issues(id),
    flagged_by INT NOT NULL REFERENCES users(id),
    reason     TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_flag (issue_id, flagged_by)
);