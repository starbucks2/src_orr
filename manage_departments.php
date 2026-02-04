<?php
session_start();
require_once 'db.php';

// Only admins can access
if (!isset($_SESSION['admin_id'])) {
  $_SESSION['error'] = 'Only administrators can manage departments.';
  header('Location: admin_dashboard.php');
  exit();
}

$errors = [];
$messages = [];

// Ensure departments table exists (idempotent) with department_name
try {
  // 1. Create table if not exists with correct schema
  $conn->exec("CREATE TABLE IF NOT EXISTS departments (
        department_id INT AUTO_INCREMENT PRIMARY KEY,
        department_name VARCHAR(100) NOT NULL UNIQUE,
        code VARCHAR(20) NULL UNIQUE,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        id INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  // 2. Automated Migration: Check if 'name' still exists (old schema) and rename to 'department_name'
  $colCheck = $conn->query("SHOW COLUMNS FROM departments LIKE 'name'")->fetch();
  if ($colCheck) {
    $conn->exec("ALTER TABLE departments CHANGE COLUMN `name` `department_name` VARCHAR(100) NOT NULL");
  }
  // Rename 'id' to 'department_id' if 'id' is the PK and 'department_id' doesn't exist
  $idCheck = $conn->query("SHOW COLUMNS FROM departments LIKE 'id'")->fetch();
  $deptIdCheck = $conn->query("SHOW COLUMNS FROM departments LIKE 'department_id'")->fetch();
  if ($idCheck && !$deptIdCheck) {
    // If 'id' is there but not 'department_id', rename it
    $conn->exec("ALTER TABLE departments CHANGE COLUMN `id` `department_id` INT AUTO_INCREMENT PRIMARY KEY");
  } elseif ($idCheck && $deptIdCheck) {
    // If both exist, just make 'id' nullable/alias if not already handles (we added it in CREATE TABLE)
  }

  // 3. Automated Data Cleanup: Update codes to full names if they match abbreviations
  $conn->exec("UPDATE departments SET department_name = 'College of Computer Studies' WHERE department_name = 'CCS'");
  $conn->exec("UPDATE departments SET department_name = 'College of Education' WHERE department_name = 'COE'");
  $conn->exec("UPDATE departments SET department_name = 'College of Business Studies' WHERE department_name = 'CBS'");
} catch (PDOException $e) {
  $errors[] = 'Failed to ensure departments table schema or data: ' . htmlspecialchars($e->getMessage());
}

// Handle add/delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
      $name = trim($_POST['name'] ?? '');
      $code = trim($_POST['code'] ?? '');
      if ($name === '') {
        $errors[] = 'Department name is required.';
      } else {
        // Check for duplicate department name
        $dup = $conn->prepare('SELECT department_id FROM departments WHERE LOWER(TRIM(department_name)) = LOWER(TRIM(?))');
        $dup->execute([$name]);
        if ($dup->fetch()) {
          $errors[] = 'A department with this name already exists.';
        } else {
          $stmt = $conn->prepare('INSERT INTO departments (department_name, code) VALUES (?, ?)');
          $stmt->execute([$name, $code !== '' ? $code : null]);
          $messages[] = 'Department added successfully.';
        }
      }
    }
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM departments WHERE department_id = ?');
        $stmt->execute([$id]);
        $messages[] = 'Department deleted.';
      }
    }
  } catch (PDOException $e) {
    $errors[] = 'DB Error: ' . htmlspecialchars($e->getMessage());
  }
}

// Load departments with course counts
$departments = [];
try {
  $sql = "SELECT d.department_id AS id, d.department_name AS name, d.code, d.is_active, COALESCE(c.cnt,0) AS course_count
            FROM departments d
            LEFT JOIN (
              SELECT department_id, COUNT(*) AS cnt
              FROM courses
              WHERE department_id IS NOT NULL
              GROUP BY department_id
            ) c ON c.department_id = d.department_id
            ORDER BY d.department_name";
  $departments = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $errors[] = 'Failed to load departments: ' . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Manage Departments</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
</head>

<body class="bg-gray-50">
  <div class="flex">
    <?php include 'admin_sidebar.php'; ?>
    <main class="flex-1 p-6 lg:ml-72">
      <h1 class="text-2xl font-bold mb-4">Manage Departments</h1>

      <?php if ($errors): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded">
          <?php foreach ($errors as $e): ?><div>- <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($messages): ?>
        <div class="mb-4 p-3 bg-green-100 text-green-700 rounded">
          <?php foreach ($messages as $m): ?><div>• <?= htmlspecialchars($m) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <section class="bg-white rounded shadow p-4 mb-6">
        <h2 class="font-semibold mb-3">Add Department</h2>
        <form method="post" class="grid gap-3 md:grid-cols-3 items-end">
          <input type="hidden" name="action" value="add">
          <div>
            <label class="block text-sm text-gray-600">Name</label>
            <input name="name" class="w-full border rounded p-2" placeholder="e.g., College of Computer Studies" required>
          </div>
          <div>
            <label class="block text-sm text-gray-600">Code (optional)</label>
            <input name="code" class="w-full border rounded p-2" placeholder="e.g., CCS">
          </div>
          <div>
            <button class="bg-blue-600 text-white px-4 py-2 rounded"><i class="fa fa-plus mr-2"></i>Add</button>
          </div>
        </form>
      </section>

      <section class="bg-white rounded shadow p-4">
        <h2 class="font-semibold mb-3">Departments</h2>
        <div class="overflow-x-auto">
          <table class="min-w-full border">
            <thead class="bg-gray-100">
              <tr>
                <th class="text-left p-2 border">Name</th>
                <th class="text-left p-2 border">Code</th>
                <th class="text-left p-2 border">Courses</th>
                <th class="text-left p-2 border">Active</th>
                <th class="p-2 border">Actions</th>
              </tr>
            </thead>
            <tbody id="dept-table-body">
              <?php if (!$departments): ?>
                <tr>
                  <td colspan="5" class="p-3 text-center text-gray-500">No departments yet.</td>
                </tr>
                <?php else: foreach ($departments as $d): ?>
                  <tr class="dept-row" data-dept-id="<?= (int)$d['id'] ?>" data-dept-name="<?= htmlspecialchars($d['name']) ?>">
                    <td class="p-2 border"><?= htmlspecialchars($d['name']) ?></td>
                    <td class="p-2 border"><?= htmlspecialchars($d['code'] ?? '') ?></td>
                    <td class="p-2 border"><?= (int)($d['course_count'] ?? 0) ?></td>
                    <td class="p-2 border"><?= ((int)$d['is_active'] ? 'Yes' : 'No') ?></td>
                    <td class="p-2 border text-center">
                      <button type="button" class="px-3 py-1 bg-slate-600 text-white rounded inline-flex items-center gap-1 mr-1 expand-courses">
                        <i class="fa fa-list"></i><span>Show Courses</span>
                      </button>
                      <a href="manage_courses.php?mode=department&department_id=<?= (int)$d['id'] ?>" class="px-3 py-1 bg-blue-600 text-white rounded inline-flex items-center gap-1 mr-1">
                        <i class="fa fa-pen"></i><span>Manage</span>
                      </a>
                      <form method="post" onsubmit="return confirm('Delete this department? This will also remove its courses.');" class="inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <button class="px-3 py-1 bg-red-600 text-white rounded"><i class="fa fa-trash"></i></button>
                      </form>
                    </td>
                  </tr>
                  <tr class="course-detail hidden" id="dept-detail-<?= (int)$d['id'] ?>">
                    <td colspan="5" class="p-0">
                      <div class="p-3 bg-slate-50 border-t">
                        <div class="text-sm text-slate-700 mb-2 font-semibold">Courses for <?= htmlspecialchars($d['name']) ?></div>
                        <div class="overflow-x-auto">
                          <table class="min-w-full border text-sm">
                            <thead class="bg-slate-100">
                              <tr>
                                <th class="text-left p-2 border">Name</th>
                                <th class="text-left p-2 border">Code</th>
                                <th class="text-left p-2 border">Active</th>
                              </tr>
                            </thead>
                            <tbody data-courses-body></tbody>
                          </table>
                        </div>
                      </div>
                    </td>
                  </tr>
              <?php endforeach;
              endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>
</body>
<script>
  document.addEventListener('click', async function(e) {
    const btn = e.target.closest('.expand-courses');
    if (!btn) return;
    const tr = btn.closest('tr.dept-row');
    const deptId = tr?.dataset?.deptId;
    if (!deptId) return;
    const detailRow = document.getElementById('dept-detail-' + deptId);
    if (!detailRow) return;
    // Toggle
    const isHidden = detailRow.classList.contains('hidden');
    if (isHidden) {
      // Load courses via AJAX
      const tbody = detailRow.querySelector('[data-courses-body]');
      tbody.innerHTML = '<tr><td colspan="3" class="p-3 text-center text-slate-500">Loading...</td></tr>';
      try {
        const res = await fetch('include/get_courses.php?department_id=' + encodeURIComponent(deptId));
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Failed');
        if (!json.data || json.data.length === 0) {
          tbody.innerHTML = '<tr><td colspan="3" class="p-3 text-center text-slate-500">No courses found.</td></tr>';
        } else {
          tbody.innerHTML = '';
          json.data.forEach(c => {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td class="p-2 border">' + (c.name || '') + '</td>' +
              '<td class="p-2 border">' + (c.code || '') + '</td>' +
              '<td class="p-2 border">Yes</td>';
            tbody.appendChild(tr);
          });
        }
      } catch (err) {
        tbody.innerHTML = '<tr><td colspan="3" class="p-3 text-center text-red-600">Error loading courses</td></tr>';
      }
      detailRow.classList.remove('hidden');
      btn.innerHTML = '<i class="fa fa-chevron-up"></i><span>Hide Courses</span>';
      btn.classList.remove('bg-slate-600');
      btn.classList.add('bg-slate-700');
    } else {
      detailRow.classList.add('hidden');
      btn.innerHTML = '<i class="fa fa-list"></i><span>Show Courses</span>';
      btn.classList.remove('bg-slate-700');
      btn.classList.add('bg-slate-600');
    }
  });
</script>

</html>
</body>

</html>