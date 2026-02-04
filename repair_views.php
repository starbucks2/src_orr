<?php
// Repair database views to match current employees schema and avoid definer issues
// Usage: open this in browser once: http://localhost/SRC_ORR/repair_views.php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/db.php';

function hasColumn(PDO $conn, string $table, string $column): bool
{
    $q = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute([$table, $column]);
    return (int)$q->fetchColumn() > 0;
}

try {
    // Detect role vs employee_type column
    $roleCol = hasColumn($conn, 'employees', 'role') ? 'role' : 'employee_type';

    // 1) Recreate admin view
    $conn->exec("DROP VIEW IF EXISTS `admin`");
    $sqlAdmin = "CREATE VIEW `admin` SQL SECURITY INVOKER AS
        SELECT
          e.`employee_id` AS `id`,
          CONCAT_WS(' ', e.`first_name`, NULLIF(e.`middle_name`, ''), e.`last_name`) AS `fullname`,
          e.`email` AS `email`,
          e.`password` AS `password`,
          e.`created_at` AS `created_at`
        FROM `employees` e
        WHERE e.`$roleCol` = 'ADMIN'";
    $conn->exec($sqlAdmin);
    echo "Recreated view: admin (using column: $roleCol)\n";

    // 2) Recreate sub_admins view (mapped to Research Advisers)
    $conn->exec("DROP VIEW IF EXISTS `sub_admins`");
    $hasDept = hasColumn($conn, 'employees', 'department');
    $hasPic  = hasColumn($conn, 'employees', 'profile_pic');
    $hasPerm = hasColumn($conn, 'employees', 'permissions');

    $deptSel = $hasDept ? "e.`department` AS `department`, e.`department` AS `strand`," : "NULL AS `department`, NULL AS `strand`,";
    $picSel  = $hasPic  ? "e.`profile_pic` AS `profile_pic`," : "NULL AS `profile_pic`,";
    $permSel = $hasPerm ? "e.`permissions` AS `permissions`," : "NULL AS `permissions`,";

    $sqlSub = "CREATE VIEW `sub_admins` SQL SECURITY INVOKER AS
        SELECT
          e.`employee_id` AS `id`,
          CONCAT_WS(' ', e.`first_name`, NULLIF(e.`middle_name`, ''), e.`last_name`) AS `fullname`,
          e.`email` AS `email`,
          e.`password` AS `password`,
          $permSel
          $deptSel
          $picSel
          e.`created_at` AS `created_at`,
          COALESCE(e.`is_archived`, 0) AS `is_archived`
        FROM `employees` e
        WHERE e.`$roleCol` = 'RESEARCH_ADVISER'";
    $conn->exec($sqlSub);
    echo "Recreated view: sub_admins (using column: $roleCol)\n";

    // 3) Ensure cap_bookmarks has VIRTUAL alias paper_id (ok if fails on some MySQL versions)
    try {
        if (!hasColumn($conn, 'cap_bookmarks', 'paper_id')) {
            $conn->exec("ALTER TABLE `cap_bookmarks` ADD COLUMN `paper_id` INT AS (`book_id`) VIRTUAL");
            echo "Added virtual column: cap_bookmarks.paper_id\n";
        }
    } catch (Throwable $e) {
        echo "[warn] bookmarks.paper_id: " . $e->getMessage() . "\n";
    }

    echo "\nAll views repaired successfully.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Repair failed: " . $e->getMessage() . "\n";
}
