<?php
session_start();
require_once 'db.php';

// Only admins can access
if (!isset($_SESSION['admin_id'])) {
  $_SESSION['error'] = 'Only administrators can manage courses.';
  header('Location: admin_dashboard.php');
  exit();
}

$errors = [];
$messages = [];

// Ensure core tables exist (idempotent)
try {
  // 1. Departments table schema check/migration
  $conn->exec("CREATE TABLE IF NOT EXISTS departments (
        department_id INT AUTO_INCREMENT PRIMARY KEY,
        department_name VARCHAR(100) NOT NULL UNIQUE,
        code VARCHAR(20) NULL UNIQUE,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        id INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $colCheck = $conn->query("SHOW COLUMNS FROM departments LIKE 'name'")->fetch();
  if ($colCheck) {
    $conn->exec("ALTER TABLE departments CHANGE COLUMN `name` `department_name` VARCHAR(100) NOT NULL");
  }
  $idCheck = $conn->query("SHOW COLUMNS FROM departments LIKE 'id'")->fetch();
  $deptIdCheck = $conn->query("SHOW COLUMNS FROM departments LIKE 'department_id'")->fetch();
  if ($idCheck && !$deptIdCheck) {
    $conn->exec("ALTER TABLE departments CHANGE COLUMN `id` `department_id` INT AUTO_INCREMENT PRIMARY KEY");
  }

  // 2. Strands table
  $conn->exec("CREATE TABLE IF NOT EXISTS strands (
        strand_id INT AUTO_INCREMENT PRIMARY KEY,
        strand VARCHAR(50) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  // 3. Courses table
  $conn->exec("CREATE TABLE IF NOT EXISTS courses (
        course_id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NULL,
        strand_id INT NULL,
        course_name VARCHAR(150) NOT NULL,
        course_code VARCHAR(50) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_course_per_dept (department_id, course_name),
        UNIQUE KEY uniq_course_per_strand (strand_id, course_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
  $errors[] = 'Failed to ensure core tables schema: ' . htmlspecialchars($e->getMessage());
}

// Get departments and strands for dropdowns
$departments = [];
try {
  $departments = $conn->query('SELECT department_id AS id, department_name AS name, code FROM departments WHERE is_active = 1 ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $errors[] = 'Failed to load departments: ' . htmlspecialchars($e->getMessage());
}

$strands = [];
try {
  // Prefer strand_id; fallback to legacy id if migration not yet run
  try {
    $strands = $conn->query('SELECT strand_id AS id, strand FROM strands ORDER BY strand')->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e1) {
    $strands = $conn->query('SELECT id AS id, strand FROM strands ORDER BY strand')->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (PDOException $e) {
  $errors[] = 'Failed to load strands: ' . htmlspecialchars($e->getMessage());
}

// Mode can be 'department' or 'strand'
$mode = isset($_GET['mode']) && $_GET['mode'] === 'strand' ? 'strand' : 'department';
$selectedDeptId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$selectedStrandId = isset($_GET['strand_id']) ? (int)$_GET['strand_id'] : 0;

$selectedDept = null;
if ($selectedDeptId > 0 && $departments) {
  foreach ($departments as $d) {
    if ((int)$d['id'] === $selectedDeptId) {
      $selectedDept = $d;
      break;
    }
  }
}
$selectedStrand = null;
if ($selectedStrandId > 0 && $strands) {
  foreach ($strands as $s) {
    if ((int)$s['id'] === $selectedStrandId) {
      $selectedStrand = $s;
      break;
    }
  }
}

// If user picked Senior High School while in Department mode, auto-switch to Strand mode
if ($mode === 'department' && $selectedDept) {
  $nm = strtolower($selectedDept['name']);
  $cd = strtolower((string)($selectedDept['code'] ?? ''));
  if ($nm === 'senior high school' || $cd === 'shs') {
    $mode = 'strand';
    // clear department selection in this request scope; page will render strand selector
    $selectedDeptId = 0;
    $selectedDept = null;
  }
}

// Handle add/delete when a department is selected
// Handle actions based on mode
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postMode = $_POST['mode'] ?? $mode;
  try {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
      $name = trim($_POST['course_name'] ?? '');
      $code = trim($_POST['course_code'] ?? '');
      if ($name === '') {
        $errors[] = 'Course/Program name is required.';
      } else {
        if ($postMode === 'strand') {
          $sid = (int)($_POST['strand_id'] ?? 0);
          if ($sid <= 0) {
            $errors[] = 'Please select a strand.';
          } else {
            // Check for duplicate course in this strand
            $dup = $conn->prepare('SELECT course_id FROM courses WHERE strand_id = ? AND LOWER(TRIM(course_name)) = LOWER(TRIM(?))');
            $dup->execute([$sid, $name]);
            if ($dup->fetch()) {
              $errors[] = 'This course/program already exists in the selected strand.';
            } else {
              $stmt = $conn->prepare('INSERT INTO courses (strand_id, course_name, course_code) VALUES (?, ?, ?)');
              $stmt->execute([$sid, $name, $code !== '' ? $code : null]);
              $messages[] = 'Course added to strand.';
            }
            $selectedStrandId = $sid;
            $mode = 'strand';
          }
        } else {
          $did = (int)($_POST['department_id'] ?? 0);
          if ($did <= 0) {
            $errors[] = 'Please select a department.';
          } else {
            // Validate department exists to satisfy FK
            $chk = $conn->prepare('SELECT department_id FROM departments WHERE department_id = ?');
            $chk->execute([$did]);
            if (!$chk->fetch()) {
              $errors[] = 'Selected department does not exist. Please reload the page.';
            } else {
              // Check for duplicate course in this department
              $dup = $conn->prepare('SELECT course_id FROM courses WHERE department_id = ? AND LOWER(TRIM(course_name)) = LOWER(TRIM(?))');
              $dup->execute([$did, $name]);
              if ($dup->fetch()) {
                $errors[] = 'This course/program already exists in the selected department.';
              } else {
                $stmt = $conn->prepare('INSERT INTO courses (department_id, course_name, course_code) VALUES (?, ?, ?)');
                $stmt->execute([$did, $name, $code !== '' ? $code : null]);
                $messages[] = 'Course added to department.';
              }
              $selectedDeptId = $did;
              $mode = 'department';
            }
          }
        }
      }
    }
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
      $courseId = (int)($_POST['course_id'] ?? 0);
      if ($courseId > 0) {
        if ($postMode === 'strand') {
          $sid = (int)($_POST['strand_id'] ?? 0);
          $stmt = $conn->prepare('DELETE FROM courses WHERE course_id = ? AND strand_id = ?');
          $stmt->execute([$courseId, $sid]);
          $messages[] = 'Course deleted.';
        } else {
          $did = (int)($_POST['department_id'] ?? 0);
          // Validate department exists
          $chk = $conn->prepare('SELECT department_id FROM departments WHERE department_id = ?');
          $chk->execute([$did]);
          if (!$chk->fetch()) {
            $errors[] = 'Selected department not found.';
          } else {
            $stmt = $conn->prepare('DELETE FROM courses WHERE course_id = ? AND department_id = ?');
            $stmt->execute([$courseId, $did]);
            $messages[] = 'Course deleted.';
          }
        }
      }
    }
  } catch (PDOException $e) {
    $errors[] = 'DB Error: ' . htmlspecialchars($e->getMessage());
  }
}

// Load courses for the selected scope
$courses = [];
try {
  if ($mode === 'strand' && $selectedStrandId > 0) {
    $stmt = $conn->prepare('SELECT course_id, course_name, course_code, is_active FROM courses WHERE strand_id = ? ORDER BY course_name');
    $stmt->execute([$selectedStrandId]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } elseif ($mode === 'department' && $selectedDeptId > 0) {
    $stmt = $conn->prepare('SELECT course_id, course_name, course_code, is_active FROM courses WHERE department_id = ? ORDER BY course_name');
    $stmt->execute([$selectedDeptId]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (PDOException $e) {
  $errors[] = 'Failed to load courses: ' . htmlspecialchars($e->getMessage());
}

$isSHS = false; // Not used in Option C UI; keeping flag available if needed.
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Manage Courses</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
</head>

<body class="bg-gray-50">
  <div class="flex">
    <?php include 'admin_sidebar.php'; ?>
    <main class="flex-1 p-6 lg:ml-72">
      <h1 class="text-2xl font-bold mb-4">Manage Courses / Programs</h1>

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
        <form method="get" class="grid gap-3 md:grid-cols-3 items-end">
          <div>
            <label class="block text-sm text-gray-600">Scope</label>
            <select name="mode" class="w-full border rounded p-2">
              <option value="department" <?= $mode === 'department' ? 'selected' : '' ?>>Department</option>
              <option value="strand" <?= $mode === 'strand' ? 'selected' : '' ?>>Strand (SHS)</option>
            </select>
          </div>
          <?php if ($mode === 'strand'): ?>
            <div>
              <label class="block text-sm text-gray-600">Select Strand</label>
              <select name="strand_id" class="w-full border rounded p-2" required>
                <option value="">-- choose strand --</option>
                <?php foreach ($strands as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $selectedStrandId ? 'selected' : '') ?>><?= htmlspecialchars($s['strand']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <div>
              <label class="block text-sm text-gray-600">Select Department</label>
              <select name="department_id" class="w-full border rounded p-2" required>
                <option value="">-- choose department --</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= (int)$d['id'] ?>" <?= ((int)$d['id'] === $selectedDeptId ? 'selected' : '') ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div>
            <button class="bg-blue-600 text-white px-4 py-2 rounded">Load</button>
          </div>
        </form>
      </section>

      <?php if ($mode === 'strand' && $selectedStrandId > 0): ?>
        <section class="bg-white rounded shadow p-4 mb-6">
          <h2 class="font-semibold mb-3">Add Course/Program for Strand: <?= htmlspecialchars($selectedStrand['strand'] ?? '') ?></h2>
          <form method="post" class="grid gap-3 md:grid-cols-4 items-end">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="mode" value="strand">
            <input type="hidden" name="strand_id" value="<?= (int)$selectedStrandId ?>">
            <div class="md:col-span-2">
              <label class="block text-sm text-gray-600">Course Name</label>
              <input name="course_name" class="w-full border rounded p-2" placeholder="e.g., Pre-Calculus" required>
            </div>
            <div>
              <label class="block text-sm text-gray-600">Course Code (optional)</label>
              <input name="course_code" class="w-full border rounded p-2" placeholder="e.g., PRECALC">
            </div>
            <div>
              <button class="bg-blue-600 text-white px-4 py-2 rounded"><i class="fa fa-plus mr-2"></i>Add</button>
            </div>
          </form>
        </section>

        <section class="bg-white rounded shadow p-4">
          <h2 class="font-semibold mb-3">Courses for Strand: <?= htmlspecialchars($selectedStrand['strand'] ?? '') ?></h2>
          <div class="overflow-x-auto">
            <table class="min-w-full border">
              <thead class="bg-gray-100">
                <tr>
                  <th class="text-left p-2 border">Name</th>
                  <th class="text-left p-2 border">Code</th>
                  <th class="text-left p-2 border">Active</th>
                  <th class="p-2 border">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$courses): ?>
                  <tr>
                    <td colspan="4" class="p-3 text-center text-gray-500">No courses yet.</td>
                  </tr>
                  <?php else: foreach ($courses as $c): ?>
                    <tr>
                      <td class="p-2 border"><?= htmlspecialchars($c['course_name']) ?></td>
                      <td class="p-2 border"><?= htmlspecialchars($c['course_code'] ?? '') ?></td>
                      <td class="p-2 border"><?= ((int)$c['is_active'] ? 'Yes' : 'No') ?></td>
                      <td class="p-2 border text-center">
                        <form method="post" onsubmit="return confirm('Delete this course?');" class="inline">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="mode" value="strand">
                          <input type="hidden" name="strand_id" value="<?= (int)$selectedStrandId ?>">
                          <input type="hidden" name="course_id" value="<?= (int)$c['course_id'] ?>">
                          <button class="px-3 py-1 bg-red-600 text-white rounded"><i class="fa fa-trash"></i></button>
                        </form>
                      </td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php elseif ($mode === 'department' && $selectedDeptId > 0): ?>
        <section class="bg-white rounded shadow p-4 mb-6">
          <h2 class="font-semibold mb-3">Add Course/Program for Department: <?= htmlspecialchars($selectedDept['name'] ?? '') ?></h2>
          <form method="post" class="grid gap-3 md:grid-cols-4 items-end">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="mode" value="department">
            <input type="hidden" name="department_id" value="<?= (int)$selectedDeptId ?>">
            <div class="md:col-span-2">
              <label class="block text-sm text-gray-600">Course Name</label>
              <input name="course_name" class="w-full border rounded p-2" placeholder="e.g., BS Information Systems" required>
            </div>
            <div>
              <label class="block text-sm text-gray-600">Course Code (optional)</label>
              <input name="course_code" class="w-full border rounded p-2" placeholder="e.g., BSIS">
            </div>
            <div>
              <button class="bg-blue-600 text-white px-4 py-2 rounded"><i class="fa fa-plus mr-2"></i>Add</button>
            </div>
          </form>
        </section>

        <section class="bg-white rounded shadow p-4">
          <h2 class="font-semibold mb-3">Courses for Department: <?= htmlspecialchars($selectedDept['name'] ?? '') ?></h2>
          <div class="overflow-x-auto">
            <table class="min-w-full border">
              <thead class="bg-gray-100">
                <tr>
                  <th class="text-left p-2 border">Name</th>
                  <th class="text-left p-2 border">Code</th>
                  <th class="text-left p-2 border">Active</th>
                  <th class="p-2 border">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$courses): ?>
                  <tr>
                    <td colspan="4" class="p-3 text-center text-gray-500">No courses yet.</td>
                  </tr>
                  <?php else: foreach ($courses as $c): ?>
                    <tr>
                      <td class="p-2 border"><?= htmlspecialchars($c['course_name']) ?></td>
                      <td class="p-2 border"><?= htmlspecialchars($c['course_code'] ?? '') ?></td>
                      <td class="p-2 border"><?= ((int)$c['is_active'] ? 'Yes' : 'No') ?></td>
                      <td class="p-2 border text-center">
                        <form method="post" onsubmit="return confirm('Delete this course?');" class="inline">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="mode" value="department">
                          <input type="hidden" name="department_id" value="<?= (int)$selectedDeptId ?>">
                          <input type="hidden" name="course_id" value="<?= (int)$c['course_id'] ?>">
                          <button class="px-3 py-1 bg-red-600 text-white rounded"><i class="fa fa-trash"></i></button>
                        </form>
                      </td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
    </main>
  </div>
</body>

</html>