-- =========================================
-- PDTS DATABASE SCHEMA (CLEAN VERSION)
-- =========================================

CREATE DATABASE IF NOT EXISTS pdts_db;
USE pdts_db;

-- =========================================
-- PROFILE (USERS)
-- =========================================
CREATE TABLE profile (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  phone VARCHAR(20) NOT NULL,
  student_id VARCHAR(20) UNIQUE,
  block VARCHAR(5) NOT NULL,
  room_number VARCHAR(20) NOT NULL,
  password VARCHAR(255) NOT NULL,
  gender ENUM('male','female') DEFAULT NULL,
  role ENUM('student','penyelia','technician','admin','ketua_penyelia','boss_ups') NOT NULL,
  specialty VARCHAR(50),
  assigned_block VARCHAR(10),
  verified TINYINT(1) NOT NULL DEFAULT 0,
  verification_token VARCHAR(255),
  reset_token VARCHAR(255),
  reset_token_expires DATETIME,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at TIMESTAMP NULL,
  age INT,
  age_set_year INT,
  age_locked TINYINT(1) NOT NULL DEFAULT 0,
  phone_updated_at DATETIME,
  avatar VARCHAR(255),
  warnings_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_banned TINYINT(1) NOT NULL DEFAULT 0,
  banned_at DATETIME,
  banned_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
);

-- =========================================
-- COMPLAINTS (TICKETS)
-- =========================================
CREATE TABLE complaints (
  id INT NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(100) NOT NULL,
  subcategory VARCHAR(100),
  complaint TEXT NOT NULL,
  status ENUM('Pending','In Progress','Completed','Rejected') NOT NULL,
  admin_remark TEXT,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  assigned_to INT,
  rejected_by INT,
  rejection_reason TEXT,
  proof_attachment VARCHAR(255),
  proof_note TEXT,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at TIMESTAMP NULL,
  admin_remarks_json LONGTEXT,
  remark_pending TEXT,
  remark_in_progress TEXT,
  remark_completed TEXT,
  remark_rejected TEXT,
  PRIMARY KEY (id),
  INDEX (student_id)
);

-- =========================================
-- COMPLAINT ATTACHMENTS
-- =========================================
CREATE TABLE complaint_attachments (
  id INT NOT NULL AUTO_INCREMENT,
  complaint_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_size BIGINT DEFAULT 0,
  mime_type VARCHAR(100),
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX (complaint_id),
  CONSTRAINT fk_complaint_attachment
    FOREIGN KEY (complaint_id)
    REFERENCES complaints(id)
    ON DELETE CASCADE
);

-- =========================================
-- PASSWORD RESETS
-- =========================================
CREATE TABLE password_resets (
  id INT NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  token VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (id)
);

-- =========================================
-- STUDENT WARNINGS
-- =========================================
CREATE TABLE student_warnings (
  id INT NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  complaint_id INT NOT NULL,
  reason TEXT,
  created_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_warning_ticket (complaint_id),
  INDEX (student_id),
  INDEX (complaint_id)
);

-- =========================================
-- VALID STUDENT REFERENCE TABLE
-- =========================================
CREATE TABLE valid_student (
  id INT NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  gender VARCHAR(10),
  block VARCHAR(5),
  room_number VARCHAR(20),
  PRIMARY KEY (id)
);

-- =========================================
-- TRIGGER (SYSTEM AUTOMATION LOGIC)
-- =========================================
DELIMITER $$

CREATE TRIGGER trg_complaints_after_update
AFTER UPDATE ON complaints
FOR EACH ROW
BEGIN
  IF NEW.is_deleted = 0 AND NEW.status = 'Rejected' AND OLD.status <> 'Rejected' THEN
    INSERT IGNORE INTO student_warnings (student_id, complaint_id, reason)
    VALUES (NEW.student_id, NEW.id, 'Auto-warning: status set to Rejected');
  END IF;

  IF (OLD.status = 'Rejected' AND NEW.status <> 'Rejected') OR NEW.is_deleted = 1 THEN
    DELETE FROM student_warnings WHERE complaint_id = NEW.id;
  END IF;
END$$

DELIMITER ;
