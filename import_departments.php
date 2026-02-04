<?php
require 'db.php';

try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Disable FK checks to avoid constraint violations during drop
    $conn->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Drop existing table
    $conn->exec("DROP TABLE IF EXISTS `departments`");

    // Create table with correct schema including compatibility column 'id'
    // Added AUTO_INCREMENT to department_id to allow future inserts
    $sqlCreate = "CREATE TABLE `departments` (
      `department_id` int(11) NOT NULL AUTO_INCREMENT,
      `department_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `is_active` tinyint(1) NOT NULL DEFAULT '1',
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `id` int(11) GENERATED ALWAYS AS (`department_id`) VIRTUAL,
      PRIMARY KEY (`department_id`),
      UNIQUE KEY `name` (`department_name`),
      UNIQUE KEY `code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $conn->exec($sqlCreate);

    // Insert the provided data
    $sqlInsert = "INSERT INTO `departments` (`department_id`, `department_name`, `code`, `is_active`, `created_at`, `updated_at`) VALUES
    (2, 'College of Computer Studies', 'CCS', 1, '2025-12-01 06:29:42', '2026-02-02 08:15:05'),
    (3, 'College of Education', 'COE', 1, '2025-12-01 06:30:22', '2026-02-02 08:16:13'),
    (4, 'College of Business Studies', 'CBS', 1, '2025-12-01 06:31:36', '2026-02-02 08:15:37'),
    (5, 'Senior High School', 'SENIOR HIGH SCHOOL', 1, '2025-12-01 06:38:33', '2026-02-04 00:40:16'),
    (6, 'Elementary School', 'Elementary School', 1, '2026-02-02 08:19:02', '2026-02-02 08:19:02'),
    (9, 'ELEMENTARY', NULL, 1, '2026-02-03 03:10:14', '2026-02-03 03:10:14')";

    $conn->exec($sqlInsert);

    // Re-enable FK checks
    $conn->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "Departments table repaired and data imported successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
