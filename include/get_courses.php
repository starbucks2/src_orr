<?php
// Prevent any previous output from breaking JSON
ob_start();
require_once __DIR__ . '/../db.php';
// clear buffer
ob_clean();

header('Content-Type: application/json');

$dbReady = (isset($conn) && $conn !== null);
$deptId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$strandId = isset($_GET['strand_id']) ? (int)$_GET['strand_id'] : 0;
$deptName = isset($_GET['department_name']) ? trim((string)$_GET['department_name']) : '';
if ($deptName === '' && isset($_GET['department'])) {
    $deptName = trim((string)$_GET['department']);
}
$deptCode = isset($_GET['department_code']) ? trim((string)$_GET['department_code']) : '';

if (!$dbReady) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

// Helper to output rows
$emit = function (array $rows) {
    echo json_encode(['ok' => true, 'data' => $rows]);
};

// If strand_id provided, prefer it for SHS
if ($strandId > 0) {
    try {
        $stmt = $conn->prepare("SELECT course_id AS id, course_name AS name, course_code AS code FROM courses WHERE strand_id = ? AND is_active = 1 ORDER BY course_name");
        $stmt->execute([$strandId]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Helper to output rows
$emit = function (array $rows) {
    echo json_encode(['ok' => true, 'data' => $rows]);
};

// Fallback: allow resolving by department name or code if ID not provided
if ($deptId <= 0 && $deptName !== '') {
    // Attempt direct name/code-based join to be robust to legacy/mismatched IDs
    try {
        $q = "SELECT c.course_id AS id, c.course_name AS name, c.course_code AS code
              FROM courses c
              JOIN departments d ON c.department_id = d.department_id
              WHERE (
                  TRIM(LOWER(d.name)) = TRIM(LOWER(?)) OR
                  TRIM(LOWER(d.code)) = TRIM(LOWER(?)) OR
                  (? <> '' AND TRIM(LOWER(d.code)) = TRIM(LOWER(?)))
              ) AND c.is_active = 1
              ORDER BY c.course_name";
        $stmt = $conn->prepare($q);
        $stmt->execute([$deptName, $deptName, $deptCode, $deptCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $emit($rows);
            exit;
        }
    } catch (Throwable $_) {
        // retry with departments.id if schema uses 'id'
        try {
            $q = "SELECT c.course_id AS id, c.course_name AS name, c.course_code AS code
                  FROM courses c
                  JOIN departments d ON c.department_id = d.id
                  WHERE (
                      TRIM(LOWER(d.name)) = TRIM(LOWER(?)) OR
                      TRIM(LOWER(d.code)) = TRIM(LOWER(?)) OR
                      (? <> '' AND TRIM(LOWER(d.code)) = TRIM(LOWER(?)))
                  ) AND c.is_active = 1
                  ORDER BY c.course_name";
            $stmt = $conn->prepare($q);
            $stmt->execute([$deptName, $deptName, $deptCode, $deptCode]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $emit($rows);
                exit;
            }
        } catch (Throwable $__) { /* ignore */
        }
    }
}

if ($deptId <= 0) {
    echo json_encode(['ok' => true, 'data' => []]);
    exit;
}

try {
    // Primary by department_id
    $stmt = $conn->prepare("SELECT course_id AS id, course_name AS name, course_code AS code FROM courses WHERE department_id = ? AND is_active = 1 ORDER BY course_name");
    $stmt->execute([$deptId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows && $deptName !== '') {
        // Fallback to name/code-based join in case of legacy mismatched IDs
        try {
            $q = "SELECT c.course_id AS id, c.course_name AS name, c.course_code AS code
                  FROM courses c
                  JOIN departments d ON c.department_id = d.department_id
                  WHERE (
                      TRIM(LOWER(d.name)) = TRIM(LOWER(?)) OR
                      TRIM(LOWER(d.code)) = TRIM(LOWER(?)) OR
                      (? <> '' AND TRIM(LOWER(d.code)) = TRIM(LOWER(?)))
                  ) AND c.is_active = 1
                  ORDER BY c.course_name";
            $s2 = $conn->prepare($q);
            $s2->execute([$deptName, $deptName, $deptCode, $deptCode]);
            $rows = $s2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $_) {
            try {
                $q = "SELECT c.course_id AS id, c.course_name AS name, c.course_code AS code
                      FROM courses c
                      JOIN departments d ON c.department_id = d.id
                      WHERE (
                          TRIM(LOWER(d.name)) = TRIM(LOWER(?)) OR
                          TRIM(LOWER(d.code)) = TRIM(LOWER(?)) OR
                          (? <> '' AND TRIM(LOWER(d.code)) = TRIM(LOWER(?)))
                      ) AND c.is_active = 1
                      ORDER BY c.course_name";
                $s3 = $conn->prepare($q);
                $s3->execute([$deptName, $deptName, $deptCode, $deptCode]);
                $rows = $s3->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $__) { /* ignore */
            }
        }
    }
    $emit($rows ?: []);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
