-- Schema export for Src_db
-- Generated on 2025-10-24 21:44:35 +08:00

CREATE DATABASE IF NOT EXISTS `src_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `src_db`;

-- Table: employees (unified Admin and Research Adviser)
CREATE TABLE IF NOT EXISTS `employees` (
  `employee_id` VARCHAR(32) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) NULL,
  `last_name` VARCHAR(100) NOT NULL,
  -- Compatibility aliases for systems expecting firstname/lastname field names
  `firstname` VARCHAR(100) AS (`first_name`) VIRTUAL,
  `lastname`  VARCHAR(100) AS (`last_name`) VIRTUAL,
  `email` VARCHAR(150) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  -- Prefer normalized enum, but allow mapping to legacy 'role' via views if needed
  `employee_type` ENUM('ADMIN','RESEARCH_ADVISER') NOT NULL,
  -- Legacy compatibility: expose role as alias of employee_type
  `role` VARCHAR(30) AS (`employee_type`) VIRTUAL,
  -- Convenience: computed full name for reporting
  `fullname` VARCHAR(255) AS (CONCAT_WS(' ', `first_name`, NULLIF(`middle_name`, ''), `last_name`)) VIRTUAL,
  `department` VARCHAR(50) NULL,
  `profile_pic` VARCHAR(255) NULL,
  `permissions` TEXT NULL,
  `phone` VARCHAR(30) NULL,
  `last_login_at` DATETIME NULL,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `uniq_employee_email` (`email`),
  INDEX `idx_employee_type` (`employee_type`),
  INDEX `idx_is_archived` (`is_archived`),
  INDEX `idx_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: students
CREATE TABLE IF NOT EXISTS `students` (
  `student_id` VARCHAR(32) NOT NULL,
  `firstname` VARCHAR(100) NOT NULL,
  `middlename` VARCHAR(100) NULL,
  `lastname` VARCHAR(100) NOT NULL,
  `suffix` VARCHAR(50) NULL,
  `email` VARCHAR(150) NOT NULL,
  `department` VARCHAR(50) NULL,
  `course_strand` VARCHAR(50) NULL,
  `password` VARCHAR(255) NOT NULL,
  `profile_pic` VARCHAR(255) NULL,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `research_file` VARCHAR(255) NULL,
  `reset_token` VARCHAR(255) NULL,
  `reset_token_expiry` DATETIME NULL,
  `last_password_change` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `uniq_student_email` (`email`),
  INDEX `idx_student_verified` (`is_verified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: strands (used by setup_strands)
CREATE TABLE IF NOT EXISTS `strands` (
  `strand_id` INT AUTO_INCREMENT PRIMARY KEY,
  `strand` VARCHAR(50) NOT NULL UNIQUE,
  `id` INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: departments
CREATE TABLE IF NOT EXISTS `departments` (
  `department_id` INT AUTO_INCREMENT PRIMARY KEY,
  `department_name` VARCHAR(100) NOT NULL UNIQUE,
  `code` VARCHAR(20) NULL UNIQUE,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `id` INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: courses
CREATE TABLE IF NOT EXISTS `courses` (
  `course_id` INT AUTO_INCREMENT PRIMARY KEY,
  `department_id` INT NULL,
  `strand_id` INT NULL,
  `course_name` VARCHAR(150) NOT NULL,
  `course_code` VARCHAR(50) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `id` INT NULL,
  UNIQUE KEY `uniq_course_per_dept` (`department_id`, `course_name`),
  UNIQUE KEY `uniq_course_per_strand` (`strand_id`, `course_name`),
  CONSTRAINT `fk_courses_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`department_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_courses_strand` FOREIGN KEY (`strand_id`) REFERENCES `strands`(`strand_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: cap_books (uploaded research documents)
CREATE TABLE IF NOT EXISTS `cap_books` (
  `book_id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `abstract` TEXT NULL,
  `keywords` TEXT NULL,
  `authors` TEXT NULL,
  `department` VARCHAR(50) NULL,
  `course_strand` VARCHAR(50) NULL,
  `image` VARCHAR(255) NULL,
  `document` VARCHAR(255) NULL,
  `views` INT NOT NULL DEFAULT 0,
  `submission_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `student_id` VARCHAR(32) NULL,
  `adviser_id` VARCHAR(32) NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 0,
  `year` VARCHAR(25) NULL,
  INDEX `idx_books_status` (`status`),
  INDEX `idx_books_year` (`year`),
  INDEX `idx_books_student` (`student_id`),
  INDEX `idx_books_title_lower` ((LOWER(TRIM(`title`)))),
  UNIQUE KEY `uniq_books_title_approved` ((LOWER(TRIM(`title`))), `status`) USING BTREE,
  CONSTRAINT `fk_books_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`student_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_books_adviser` FOREIGN KEY (`adviser_id`) REFERENCES `employees`(`employee_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: cap_bookmarks
CREATE TABLE IF NOT EXISTS `cap_bookmarks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` VARCHAR(32) NOT NULL,
  `book_id` INT NOT NULL,
  `bookmarked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_bookmark` (`student_id`, `book_id`),
  INDEX `idx_bm_student` (`student_id`),
  INDEX `idx_bm_book` (`book_id`),
  CONSTRAINT `fk_bookmarks_book` FOREIGN KEY (`book_id`) REFERENCES `cap_books`(`book_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bookmarks_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`student_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: activity_logs
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `actor_type` VARCHAR(20) NOT NULL,
  `actor_id` VARCHAR(64) NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` JSON NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backward-compatibility helpers (views and alias columns) during migration
-- 1) Provide paper_id alias for legacy cap_bookmarks usage
ALTER TABLE `cap_bookmarks`
  ADD COLUMN `paper_id` INT AS (`book_id`) VIRTUAL;

-- 2) Legacy admin table mapped to employees of type ADMIN
DROP VIEW IF EXISTS `admin`;
CREATE VIEW `admin` AS
SELECT
  e.`employee_id` AS `id`,
  CONCAT_WS(' ', e.`first_name`, NULLIF(e.`middle_name`, ''), e.`last_name`) AS `fullname`,
  e.`email` AS `email`,
  e.`password` AS `password`,
  e.`created_at` AS `created_at`
FROM `employees` e
WHERE e.`employee_type` = 'ADMIN';

-- 3) Legacy sub_admins mapped to employees of type RESEARCH_ADVISER
DROP VIEW IF EXISTS `sub_admins`;
CREATE VIEW `sub_admins` AS
SELECT
  e.`employee_id` AS `id`,
  CONCAT_WS(' ', e.`first_name`, NULLIF(e.`middle_name`, ''), e.`last_name`) AS `fullname`,
  e.`email` AS `email`,
  e.`password` AS `password`,
  e.`permissions` AS `permissions`,
  e.`department` AS `department`,
  e.`department` AS `strand`,
  e.`profile_pic` AS `profile_pic`,
  e.`created_at` AS `created_at`,
  e.`is_archived` AS `is_archived`
FROM `employees` e
WHERE e.`employee_type` = 'RESEARCH_ADVISER';

-- 4) Legacy research_submission mapped to cap_books
DROP VIEW IF EXISTS `research_submission`;
CREATE VIEW `research_submission` AS
SELECT
  b.`book_id` AS `id`,
  b.`title` AS `title`,
  b.`abstract` AS `abstract`,
  b.`keywords` AS `keywords`,
  b.`authors` AS `author`,
  NULL AS `members`,
  b.`department` AS `department`,
  b.`course_strand` AS `course_strand`,
  b.`image` AS `image`,
  b.`document` AS `document`,
  b.`views` AS `views`,
  b.`submission_date` AS `submission_date`,
  b.`student_id` AS `student_id`,
  b.`adviser_id` AS `adviser_id`,
  b.`status` AS `status`,
  b.`year` AS `year`
FROM `cap_books` b;
