<?php
// Setup core tables for unified database Src_db
// Usage: open this file in browser once: http://localhost/SRC_ORR/setup_core_tables.php

require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

function ensureColumn(PDO $conn, string $table, string $column, string $definition): void
{
    try {
        $q = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $q->execute([$table, $column]);
        if ((int)$q->fetchColumn() === 0) {
            $conn->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
            echo "Added column {$column} to {$table}\n";
        }
    } catch (Throwable $e) {
        echo "[warn] ensureColumn {$table}.{$column}: " . $e->getMessage() . "\n";
    }
}

try {
    // admin table
    $conn->exec("CREATE TABLE IF NOT EXISTS admin (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fullname VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Ensured table: admin\n";

    // sub_admins table
    $conn->exec("CREATE TABLE IF NOT EXISTS sub_admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fullname VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        permissions TEXT NULL,
        department VARCHAR(50) NULL,
        strand VARCHAR(50) NULL,
        profile_pic VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Ensured table: sub_admins\n";

    // students table (minimal columns inferred from codebase)
    $conn->exec("CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        firstname VARCHAR(100) NOT NULL,
        middlename VARCHAR(100) NULL,
        lastname VARCHAR(100) NOT NULL,
        suffix VARCHAR(50) NULL,
        email VARCHAR(150) NOT NULL,
        department VARCHAR(50) NULL,
        course_strand VARCHAR(50) NULL,
        student_id VARCHAR(32) NULL,
        password VARCHAR(255) NOT NULL,
        profile_pic VARCHAR(255) NULL,
        is_verified TINYINT(1) NOT NULL DEFAULT 0,
        research_file VARCHAR(255) NULL,
        reset_token VARCHAR(255) NULL,
        reset_token_expiry DATETIME NULL,
        last_password_change DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_id (student_id),
        UNIQUE KEY uniq_student_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Ensured table: students\n";

    // research_submission table
    $conn->exec("CREATE TABLE IF NOT EXISTS research_submission (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        abstract TEXT NULL,
        keywords TEXT NULL,
        author TEXT NULL,
        department VARCHAR(50) NULL,
        course_strand VARCHAR(50) NULL,
        image VARCHAR(255) NULL,
        document VARCHAR(255) NULL,
        views INT NOT NULL DEFAULT 0,
        submission_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        student_id VARCHAR(32) NULL,
        status TINYINT(1) NOT NULL DEFAULT 0,
        year VARCHAR(25) NULL,
        INDEX idx_status (status),
        INDEX idx_year (year),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Ensured table: research_submission\n";

    // cap_bookmarks table
    $conn->exec("CREATE TABLE IF NOT EXISTS cap_bookmarks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(32) NOT NULL,
        book_id INT NOT NULL,
        bookmarked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_bookmark (student_id, book_id),
        INDEX idx_student (student_id),
        INDEX idx_book (book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Ensured table: cap_bookmarks\n";


    // activity_logs table (if include not yet created it)
    $conn->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        actor_type VARCHAR(20) NOT NULL,
        actor_id VARCHAR(64) NULL,
        action VARCHAR(100) NOT NULL,
        details JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Ensured table: activity_logs\n";

    // Best-effort ensure important student columns exist (for older DBs)
    ensureColumn($conn, 'students', 'department', 'department VARCHAR(50) NULL');
    ensureColumn($conn, 'students', 'course_strand', 'course_strand VARCHAR(50) NULL');
    ensureColumn($conn, 'students', 'student_id', 'student_id VARCHAR(32) NULL');
    $conn->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_student_id ON students (student_id)");
    ensureColumn($conn, 'students', 'profile_pic', 'profile_pic VARCHAR(255) NULL');
    ensureColumn($conn, 'students', 'is_verified', 'is_verified TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($conn, 'students', 'reset_token', 'reset_token VARCHAR(255) NULL');
    ensureColumn($conn, 'students', 'reset_token_expiry', 'reset_token_expiry DATETIME NULL');
    ensureColumn($conn, 'students', 'last_password_change', 'last_password_change DATETIME NULL');
    ensureColumn($conn, 'students', 'research_file', 'research_file VARCHAR(255) NULL');

    // research_submission: ensure course_strand exists
    ensureColumn($conn, 'research_submission', 'course_strand', 'course_strand VARCHAR(50) NULL');
    // research_submission: ensure author column exists (rename from legacy 'members' or 'member')
    ensureColumn($conn, 'research_submission', 'author', 'author TEXT NULL');
    // If legacy 'members' column exists, migrate its content to 'author' where author is NULL or empty
    try {
        $hasMembers = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'research_submission' AND COLUMN_NAME = 'members'");
        $hasMembers->execute();
        if ((int)$hasMembers->fetchColumn() > 0) {
            $conn->exec("UPDATE research_submission SET author = members WHERE (author IS NULL OR author = '') AND members IS NOT NULL AND members != ''");
        }
    } catch (Throwable $e) { /* ignore */
    }

    echo "\nAll core tables ensured for database: Src_db\n";
    echo "You can now log in and use the system.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Setup failed: " . $e->getMessage() . "\n";
}
