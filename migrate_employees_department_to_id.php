<?php
session_start();
require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo 'Forbidden: Admin login required.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

$report = [
    'added_column' => false,
    'populated' => 0,
    'set_fk' => false,
    'errors' => []
];

try {
    // Ensure departments table exists
    $conn->query("SELECT 1 FROM departments LIMIT 1");
} catch (Throwable $e) {
    $report['errors'][] = 'Departments table not found. Create departments first.';
}

try {
    // Add department_id column if missing
    $chk = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'department_id'");
    $chk->execute();
    if ((int)$chk->fetchColumn() === 0) {
        $conn->exec("ALTER TABLE employees ADD COLUMN department_id INT NULL AFTER profile_pic");
        $report['added_column'] = true;
    }
} catch (Throwable $e) {
    $report['errors'][] = 'Failed adding department_id: ' . $e->getMessage();
}

try {
    // Build mapping of department name/code -> id
    $map = [];
    $stmt = $conn->query("SELECT department_id, name, code FROM departments");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['department_id'];
        $names = [];
        if (!empty($r['name'])) $names[] = strtolower(trim($r['name']));
        if (!empty($r['code'])) $names[] = strtolower(trim($r['code']));
        foreach (array_unique($names) as $k) { $map[$k] = $id; }
    }

    // Populate department_id from employees.department text when empty
    $stmt = $conn->query("SELECT employee_id, department FROM employees WHERE department_id IS NULL");
    $upd = $conn->prepare("UPDATE employees SET department_id = :did WHERE employee_id = :eid");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $label = strtolower(trim((string)($row['department'] ?? '')));
        if ($label === '') continue;
        $did = $map[$label] ?? null;
        // Also try fuzzy: CCS (College of Computer Studies) style => extract token before space/parenthesis
        if ($did === null) {
            if (preg_match('/^[A-Za-z]+/', (string)$row['department'], $m)) {
                $token = strtolower($m[0]);
                $did = $map[$token] ?? null;
            }
        }
        if ($did !== null) {
            $upd->execute([':did' => $did, ':eid' => $row['employee_id']]);
            $report['populated']++;
        }
    }
} catch (Throwable $e) {
    $report['errors'][] = 'Populate error: ' . $e->getMessage();
}

try {
    // Add FK if not present (best-effort)
    $hasFk = false;
    try {
        $q = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'department_id' AND REFERENCED_TABLE_NAME = 'departments' LIMIT 1");
        $hasFk = (bool)$q->fetchColumn();
    } catch (Throwable $_) {}
    if (!$hasFk) {
        $conn->exec("ALTER TABLE employees ADD CONSTRAINT fk_employees_department_id FOREIGN KEY (department_id) REFERENCES departments(department_id) ON UPDATE CASCADE ON DELETE SET NULL");
        $report['set_fk'] = true;
    }
} catch (Throwable $e) {
    $report['errors'][] = 'FK error: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>Migrate employees.department -> department_id</title>
<style>body{font-family:system-ui,Arial,sans-serif;margin:20px} .ok{color:#065f46} .err{color:#991b1b}</style>
</head>
<body>
    <h2>Migrate employees.department to department_id</h2>
    <p class="ok">Added column: <strong><?php echo $report['added_column'] ? 'yes' : 'no'; ?></strong></p>
    <p class="ok">Populated rows: <strong><?php echo (int)$report['populated']; ?></strong></p>
    <p class="ok">Foreign key set: <strong><?php echo $report['set_fk'] ? 'yes' : 'no'; ?></strong></p>
    <?php if (!empty($report['errors'])): ?>
        <h3>Errors</h3>
        <ul>
            <?php foreach ($report['errors'] as $e): ?>
                <li class="err"><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p><a href="admin_dashboard.php">Back to Admin Dashboard</a></p>
</body>
</html>
