<?php
session_start();
include 'db.php';

// Only admins can access
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['error'] = "You must be logged in as an admin.";
    header("Location: login.php");
    exit();
}

// Use unified employees table for Research Advisers (sub-admins)
// Detect optional columns in employees
try {
    $q = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'");
    $q->execute();
    $cols = $q->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasEmpTable = !empty($cols);
    $hasDepartment = in_array('department', $cols, true);
    $hasProfilePic = in_array('profile_pic', $cols, true);
    $hasIsArchived = in_array('is_archived', $cols, true);
    $hasRoleCol = in_array('role', $cols, true);
} catch (Throwable $e) {
    $hasEmpTable = false; $hasDepartment = false; $hasProfilePic = false; $hasIsArchived = false; $hasRoleCol = false;
}
// Determine role column name
$roleCol = $hasRoleCol ? 'role' : 'employee_type';

// Handle restore
if (isset($_GET['restore'])) {
    $id = (int)($_GET['restore'] ?? 0);
    $empId = $_GET['restore'] ?? '';
    // For employees, IDs are strings (employee_id)
    if (!empty($empId) && is_string($empId)) {
        if (!$hasEmpTable) {
            $_SESSION['error'] = "Archiving feature is not available on this database (employees table missing).";
        } else {
            try {
                // Restore using roles table (role_id 2=RESEARCH_ADVISER only)
                $stmt = $conn->prepare("UPDATE employees SET is_archived = 0 WHERE employee_id = ? AND EXISTS (SELECT 1 FROM roles r WHERE r.employee_id = employees.employee_id AND r.role_id = 2)");
                $stmt->execute([$empId]);
                if ($stmt->rowCount() > 0) {
                    $_SESSION['success'] = "Sub-admin restored successfully.";
                } else {
                    $_SESSION['error'] = "Sub-admin not found or already active.";
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error restoring sub-admin: " . $e->getMessage();
            }
        }
    }
    header("Location: archived_subadmins.php");
    exit();
}

// Fetch archived sub-admins from employees
if ($hasEmpTable) {
    // Prefer department name via departments table when employees.department_id exists
    $hasDepartmentId = in_array('department_id', $cols, true);
    $strandSelect = $hasDepartmentId
        ? "COALESCE(d.name, e.department) AS strand"
        : ($hasDepartment ? "e.department AS strand" : "NULL AS strand");
    $picSelect = $hasProfilePic ? "e.profile_pic" : "NULL AS profile_pic";
    $whereArchived = $hasIsArchived ? "COALESCE(e.is_archived,0) = 1" : "1=0"; // if no column, show empty
    // Build safe fullname based on existing columns
    $firstCol = in_array('first_name', $cols, true) ? 'first_name' : (in_array('firstname', $cols, true) ? 'firstname' : null);
    $middleCol = in_array('middle_name', $cols, true) ? 'middle_name' : (in_array('middlename', $cols, true) ? 'middlename' : null);
    $lastCol = in_array('last_name', $cols, true) ? 'last_name' : (in_array('lastname', $cols, true) ? 'lastname' : null);
    $nameParts = [];
    if ($firstCol) { $nameParts[] = "e.`$firstCol`"; }
    if ($middleCol) { $nameParts[] = "NULLIF(e.`$middleCol`,'')"; }
    if ($lastCol) { $nameParts[] = "e.`$lastCol`"; }
    $nameExpr = !empty($nameParts) ? ("CONCAT_WS(' ', " . implode(', ', $nameParts) . ")") : "e.email";
    $hasCreatedAt = in_array('created_at', $cols, true);
    $createdAtSelect = $hasCreatedAt ? 'e.created_at' : "NULL AS created_at";
    $orderBy = $hasCreatedAt ? 'e.created_at DESC' : 'e.employee_id DESC';
    // Use roles table to filter for sub-admins only (role_id 2=RESEARCH_ADVISER, not Deans)
    $sql = "SELECT 
        e.employee_id AS id,
        {$nameExpr} AS fullname,
        e.email,
        {$strandSelect},
        {$picSelect},
        {$createdAtSelect}
      FROM employees e
      INNER JOIN roles r ON e.employee_id = r.employee_id
      " . ($hasDepartmentId ? "LEFT JOIN departments d ON d.department_id = e.department_id" : "") . "
      WHERE r.role_id = 2 AND {$whereArchived}
      ORDER BY {$orderBy}";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $subadmins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $subadmins = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Sub-Admins</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-gray-100 flex">
    <?php include 'admin_sidebar.php'; ?>

    <main class="flex-1 p-4 sm:p-6 lg:p-10 w-full max-w-7xl mx-auto">
        <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 lg:p-8">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <h2 class="text-2xl sm:text-3xl font-bold text-blue-900">Archived Sub-Admins</h2>
                <a href="manage_subadmins.php" class="inline-flex items-center justify-center bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 w-full sm:w-auto">
                    <i class="fas fa-users-cog mr-2"></i> Back to Manage
                </a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        Swal.fire({ icon: 'success', title: <?= json_encode($_SESSION['success']) ?>, timer: 1800, showConfirmButton: false, toast: true, position: 'top-end' });
                    });
                </script>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        Swal.fire({ icon: 'error', title: 'Action failed', text: <?= json_encode($_SESSION['error']) ?> });
                    });
                </script>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="overflow-x-auto">
                <table class="min-w-full bg-white border border-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profile</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Archived</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($subadmins)): ?>
                            <tr><td colspan="6" class="px-6 py-4 text-center text-gray-500">No archived sub-admins</td></tr>
                        <?php else: foreach ($subadmins as $sa): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="w-10 h-10 rounded-full overflow-hidden bg-gray-100 border flex items-center justify-center">
                                        <?php if (!empty($sa['profile_pic'])): ?>
                                            <img src="images/<?= htmlspecialchars($sa['profile_pic']) ?>" alt="Profile" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <i class="fas fa-user text-gray-400"></i>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($sa['fullname']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($sa['email']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($sa['strand'] ?? '') ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">Yes</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="archived_subadmins.php?restore=<?= htmlspecialchars($sa['id']) ?>" class="text-green-600 hover:text-green-800 restore-link"><i class="fas fa-undo"></i> Restore</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.restore-link').forEach(function(el){
            el.addEventListener('click', function(e){
                const href = this.getAttribute('href');
                if (typeof Swal === 'undefined') return;
                e.preventDefault();
                Swal.fire({
                    title: 'Restore this sub-admin?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#16a34a',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, restore'
                }).then((res)=>{ if(res.isConfirmed){ window.location.href = href; }});
            });
        });
    });
    </script>
</body>
</html>
