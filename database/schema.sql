-- KALASAG: Barangay Disaster and Emergency Response System
-- Barangay 172, Zone 17, Malibay, Pasay City
-- clean schema from our final ERD (16 tables), no fake data
-- run: sudo mysql < database/schema.sql
-- then make the first admin: php setup/create_admin.php

DROP DATABASE IF EXISTS kalasag_db;
CREATE DATABASE kalasag_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kalasag_db;

-- use PH time so NOW() in the seed data matches what php shows
SET time_zone = '+08:00';

-- ---------- accounts ----------

CREATE TABLE users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('admin','resident','responder') NOT NULL DEFAULT 'resident',
    full_name      VARCHAR(120) NOT NULL,
    contact_no     VARCHAR(20),
    email          VARCHAR(120) NULL UNIQUE,      -- optional, only used for forgot password
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- forgot password: we only save the HASH of the reset link token, never the token itself
    reset_token_hash  CHAR(64) NULL,
    reset_expires_at  DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE admins (
    admin_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL UNIQUE,
    position     VARCHAR(80),
    designation  VARCHAR(80),
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE households (
    household_id         INT AUTO_INCREMENT PRIMARY KEY,
    address              VARCHAR(255) NOT NULL,
    purok                VARCHAR(50),
    latitude             DECIMAL(10,7),
    longitude            DECIMAL(10,7),
    has_elderly          TINYINT(1) NOT NULL DEFAULT 0,
    has_pwd              TINYINT(1) NOT NULL DEFAULT 0,
    has_pregnant         TINYINT(1) NOT NULL DEFAULT 0,
    has_bedridden        TINYINT(1) NOT NULL DEFAULT 0,
    vulnerability_score  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE residents (
    resident_id   INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL UNIQUE,
    household_id  INT NULL,
    first_name    VARCHAR(60) NOT NULL,
    last_name     VARCHAR(60) NOT NULL,
    birthdate     DATE,
    contact_no    VARCHAR(20),
    is_verified   TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id)      REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (household_id) REFERENCES households(household_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE responders (
    responder_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL UNIQUE,
    team_name     VARCHAR(80) NOT NULL,
    contact_no    VARCHAR(20),
    availability  ENUM('available','deployed','off_duty') NOT NULL DEFAULT 'available',
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- incident stuff ----------

CREATE TABLE incident_types (
    type_id      INT AUTO_INCREMENT PRIMARY KEY,
    type_name    VARCHAR(50) NOT NULL UNIQUE,
    icon_name    VARCHAR(50),
    description  VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE responder_capabilities (
    capability_id  INT AUTO_INCREMENT PRIMARY KEY,
    responder_id   INT NOT NULL,
    type_id        INT NOT NULL,
    UNIQUE KEY uq_resp_type (responder_id, type_id),
    FOREIGN KEY (responder_id) REFERENCES responders(responder_id) ON DELETE CASCADE,
    FOREIGN KEY (type_id)      REFERENCES incident_types(type_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- resident_id and reported_by are nullable bc guest reports have no account
CREATE TABLE incidents (
    incident_id    INT AUTO_INCREMENT PRIMARY KEY,
    resident_id    INT NULL,
    reported_by    INT NULL,
    type_id        INT NOT NULL,
    household_id   INT NULL,
    description    TEXT NOT NULL,
    latitude       DECIMAL(10,7) NOT NULL,
    longitude      DECIMAL(10,7) NOT NULL,
    gps_accuracy   FLOAT,
    landmark       VARCHAR(150),
    status         ENUM('pending','acknowledged','dispatched','resolved','cancelled') NOT NULL DEFAULT 'pending',
    is_priority    TINYINT(1) NOT NULL DEFAULT 0,
    date_reported  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_resolved  DATETIME NULL,
    FOREIGN KEY (resident_id)  REFERENCES residents(resident_id) ON DELETE SET NULL,
    FOREIGN KEY (reported_by)  REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (type_id)      REFERENCES incident_types(type_id),
    FOREIGN KEY (household_id) REFERENCES households(household_id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_date (date_reported)
) ENGINE=InnoDB;

CREATE TABLE guest_reports (
    guest_report_id  INT AUTO_INCREMENT PRIMARY KEY,
    incident_id      INT NOT NULL UNIQUE,
    tracking_code    VARCHAR(20) NOT NULL UNIQUE,
    guest_name       VARCHAR(120),
    guest_contact    VARCHAR(20),
    FOREIGN KEY (incident_id) REFERENCES incidents(incident_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE incident_urgency (
    urgency_id           INT AUTO_INCREMENT PRIMARY KEY,
    incident_id          INT NOT NULL UNIQUE,
    urgency              ENUM('critical','moderate','low') NOT NULL,
    urgency_confidence   FLOAT,
    urgency_overridden   TINYINT(1) NOT NULL DEFAULT 0,
    classified_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(incident_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE incident_photos (
    photo_id     INT AUTO_INCREMENT PRIMARY KEY,
    incident_id  INT NOT NULL,
    file_path    VARCHAR(255) NOT NULL,
    uploaded_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(incident_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE incident_status_log (
    log_id       INT AUTO_INCREMENT PRIMARY KEY,
    incident_id  INT NOT NULL,
    updated_by   INT NULL,
    old_status   ENUM('pending','acknowledged','dispatched','resolved','cancelled') NULL,
    new_status   ENUM('pending','acknowledged','dispatched','resolved','cancelled') NOT NULL,
    remarks      TEXT,
    changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(incident_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by)  REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE incident_responses (
    response_id    INT AUTO_INCREMENT PRIMARY KEY,
    incident_id    INT NOT NULL,
    responder_id   INT NOT NULL,
    assigned_by    INT NULL,
    dispatched_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    arrived_at     DATETIME NULL,
    cleared_at     DATETIME NULL,
    action_taken   TEXT,
    FOREIGN KEY (incident_id)  REFERENCES incidents(incident_id) ON DELETE CASCADE,
    FOREIGN KEY (responder_id) REFERENCES responders(responder_id),
    FOREIGN KEY (assigned_by)  REFERENCES admins(admin_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- sender_id nullable so guests can still message using their tracking code
CREATE TABLE incident_messages (
    message_id   INT AUTO_INCREMENT PRIMARY KEY,
    incident_id  INT NOT NULL,
    sender_id    INT NULL,
    sender_type  ENUM('resident','admin','responder','guest') NOT NULL,
    message      TEXT NOT NULL,
    sent_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(incident_id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)   REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- info pages ----------

CREATE TABLE announcements (
    announcement_id  INT AUTO_INCREMENT PRIMARY KEY,
    posted_by        INT NULL,
    title            VARCHAR(150) NOT NULL,
    body             TEXT NOT NULL,
    category         ENUM('advisory','weather','evacuation','general') NOT NULL DEFAULT 'general',
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    date_posted      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE evacuation_centers (
    center_id     INT AUTO_INCREMENT PRIMARY KEY,
    updated_by    INT NULL,
    center_name   VARCHAR(120) NOT NULL,
    address       VARCHAR(255),
    latitude      DECIMAL(10,7),
    longitude     DECIMAL(10,7),
    capacity      INT NOT NULL DEFAULT 0,
    status        ENUM('open','full','closed') NOT NULL DEFAULT 'closed',
    last_updated  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================================
-- incident types (the only starting data, the report form needs these)
-- the admin can add more later
-- =========================================================

INSERT INTO incident_types (type_name, icon_name, description) VALUES
('Flood',              'bi-water',          'Flooding in streets or homes'),
('Fire',               'bi-fire',           'Fire in a house, building, or vehicle'),
('Vehicular Accident', 'bi-car-front-fill', 'Road accident involving vehicles or pedestrians'),
('Fallen Tree',        'bi-tree-fill',      'Fallen tree or branch blocking the road or damaging property'),
('Medical Emergency',  'bi-heart-pulse-fill','Someone needs urgent medical help');
