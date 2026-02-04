<?php
// Prevent any previous output from breaking JSON
ob_start();
require_once __DIR__ . '/../db.php';
// clear buffer
ob_clean();

header('Content-Type: application/json');

if (!isset($conn) || $conn === null) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

try {
    try {
        // Try the new column name
        $stmt = $conn->query("SELECT department_id AS id, department_name AS name, code FROM departments WHERE is_active = 1 ORDER BY department_name");
    } catch (Throwable $e1) {
        try {
            // Try legacy 'name' column
            $stmt = $conn->query("SELECT department_id AS id, name AS name, code FROM departments WHERE is_active = 1 ORDER BY name");
        } catch (Throwable $e2) {
            // Try legacy 'id' and 'name'
            $stmt = $conn->query("SELECT id AS id, name AS name, code FROM departments WHERE is_active = 1 ORDER BY name");
        }
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
