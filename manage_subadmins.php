<?php
session_start();
include 'db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['error'] = "You must be logged in as an admin.";
    header("Location: login.php");
    exit();
}

// Handle archive action (instead of delete)
if (isset($_GET['archive'])) {
    $subadmin_id = trim($_GET['archive']);
    if ($subadmin_id === '') {
        $_SESSION['error'] = "Invalid sub-admin id.";
        header("Location: manage_subadmins.php");
        exit();
    }
    try {
        // Ensure is_archived column exists on employees
        try {
            $chk = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'is_archived'");
            $chk->execute();
            if ((int)$chk->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE employees ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (Throwable $_) { /* best-effort */ }

        // Archive using roles table (role_id 2=RESEARCH_ADVISER only)
        $stmt = $conn->prepare("UPDATE employees SET is_archived = 1 WHERE employee_id = :id AND EXISTS (SELECT 1 FROM roles r WHERE r.employee_id = employees.employee_id AND r.role_id = 2)");
        $stmt->execute([':id' => $subadmin_id]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['success'] = "Sub-admin archived successfully.";
        } else {
            $_SESSION['error'] = "Sub-admin not found or already archived.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error archiving sub-admin: " . $e->getMessage();
    }
    header("Location: manage_subadmins.php");
    exit();
}

// Fetch active (non-archived) sub-admins (Research Advisers)
// Detect if the runtime DB has the `department_id`, `department`, and `profile_pic` columns in `employees`
try {
    $colChk = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'");
    $colChk->execute();
    $empCols = $colChk->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasDepartmentCol = in_array('department', $empCols, true);
    $hasDepartmentIdCol = in_array('department_id', $empCols, true);
} catch (Throwable $_) {
    $hasDepartmentCol = false; // be safe
    $hasDepartmentIdCol = false;
}

try {
    $colChk2 = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'profile_pic'");
    $colChk2->execute();
    $hasProfilePicCol = ((int)$colChk2->fetchColumn() > 0);
} catch (Throwable $_) {
    $hasProfilePicCol = false; // be safe
}

try {
    $colChk3 = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'is_archived'");
    $colChk3->execute();
    $hasIsArchivedCol = ((int)$colChk3->fetchColumn() > 0);
} catch (Throwable $_) {
    $hasIsArchivedCol = false; // be safe
}

// Detect created_at column
try {
    $colChk4 = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'created_at'");
    $colChk4->execute();
    $hasCreatedAtCol = ((int)$colChk4->fetchColumn() > 0);
} catch (Throwable $_) {
    $hasCreatedAtCol = false; // be safe
}

// Detect role and employee_type columns for filtering
try {
    $colRole = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'role'");
    $colRole->execute();
    $hasRoleCol = ((int)$colRole->fetchColumn() > 0);
} catch (Throwable $_) { $hasRoleCol = false; }
try {
    $colEmpType = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'employee_type'");
    $colEmpType->execute();
    $hasEmpTypeCol = ((int)$colEmpType->fetchColumn() > 0);
} catch (Throwable $_) { $hasEmpTypeCol = false; }
$roleCol = $hasRoleCol ? 'role' : 'employee_type';

$strandSelect = $hasDepartmentIdCol
    ? "COALESCE(d.name, e.department) AS strand"
    : ($hasDepartmentCol ? "e.department AS strand" : "NULL AS strand");
$profilePicSelect = $hasProfilePicCol ? "e.profile_pic" : "NULL AS profile_pic";

// Build a safe fullname expression using only existing columns
$firstCol = $middleCol = $lastCol = null;
try {
    $qCols = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'");
    $qCols->execute();
    $cols = $qCols->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array('first_name', $cols, true)) { $firstCol = 'first_name'; }
    elseif (in_array('firstname', $cols, true)) { $firstCol = 'firstname'; }
    if (in_array('middle_name', $cols, true)) { $middleCol = 'middle_name'; }
    elseif (in_array('middlename', $cols, true)) { $middleCol = 'middlename'; }
    if (in_array('last_name', $cols, true)) { $lastCol = 'last_name'; }
    elseif (in_array('lastname', $cols, true)) { $lastCol = 'lastname'; }
} catch (Throwable $_) { /* leave nulls */ }
$nameParts = [];
if ($firstCol) { $nameParts[] = "e.`$firstCol`"; }
if ($middleCol) { $nameParts[] = "NULLIF(e.`$middleCol`,'')"; }
if ($lastCol) { $nameParts[] = "e.`$lastCol`"; }
$nameExpr = !empty($nameParts) ? ("CONCAT_WS(' ', " . implode(', ', $nameParts) . ")") : "e.email";

$createdAtSelect = $hasCreatedAtCol ? "e.created_at" : "NULL AS created_at";
$orderBy = $hasCreatedAtCol ? "e.created_at DESC" : "e.employee_id DESC";

// Fetch active (non-archived) sub-admins using roles table (role_id 2=RESEARCH_ADVISER only, not Deans)
$sql = "SELECT 
    e.employee_id AS id,
    {$nameExpr} AS fullname,
    e.email,
    {$strandSelect},
    {$createdAtSelect},
    {$profilePicSelect}
  FROM employees e
  INNER JOIN roles r ON e.employee_id = r.employee_id
  " . ($hasDepartmentIdCol ? "LEFT JOIN departments d ON d.department_id = e.department_id" : "") . "
  WHERE r.role_id = 2 AND " . ($hasIsArchivedCol ? "COALESCE(e.is_archived,0) = 0" : "1=1") . "
  ORDER BY {$orderBy}";
$stmt = $conn->prepare($sql);
$stmt->execute();
$subadmins = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Research Adviser</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-gray-100 flex">

    <!-- Sidebar -->
    <?php include 'admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-4 sm:p-6 lg:p-10 w-full max-w-7xl mx-auto">
        <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 lg:p-8">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <h2 class="text-2xl sm:text-3xl font-bold text-blue-900">Manage Research Adviser</h2>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <a href="archived_subadmins.php" class="inline-flex items-center justify-center bg-amber-600 text-white px-4 py-2 rounded-md hover:bg-amber-700 flex-1 sm:flex-none">
                        <i class="fas fa-box-archive mr-2"></i> View Archived
                    </a>
                    <a href="create_subadmin.php" class="inline-flex items-center justify-center bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 flex-1 sm:flex-none">
                        <i class="fas fa-plus mr-2"></i> Create New
                    </a>
                </div>
            </div>
            
            <!-- Success/Error Messages (SweetAlert2) -->
            <?php if (isset($_SESSION['success'])): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        Swal.fire({
                            icon: 'success',
                            title: <?php echo json_encode($_SESSION['success']); ?>,
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 2000,
                            timerProgressBar: true
                        });
                    });
                </script>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Action failed',
                            text: <?php echo json_encode($_SESSION['error']); ?>,
                            confirmButtonText: 'OK'
                        });
                    });
                </script>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <!-- Sub-Admins Mobile Cards (visible on small screens) -->
            <div class="grid grid-cols-1 sm:hidden gap-4">
                <?php if (empty($subadmins)): ?>
                    <div class="p-4 border rounded-lg text-center text-gray-500 bg-gray-50">No sub-admins found</div>
                <?php else: ?>
                    <?php foreach ($subadmins as $subadmin): ?>
                        <div class="p-4 border rounded-lg shadow-sm bg-white">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-full overflow-hidden bg-gray-100 border flex items-center justify-center shrink-0">
                                    <?php if (!empty($subadmin['profile_pic'])): ?>
                                        <img src="images/<?php echo htmlspecialchars($subadmin['profile_pic']); ?>" alt="Profile" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <i class="fas fa-user text-gray-400"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($subadmin['fullname']); ?></p>
                                    <p class="text-sm text-gray-600 truncate"><?php echo htmlspecialchars($subadmin['email']); ?></p>
                                    <p class="text-xs text-blue-700 mt-1">Department: <span class="font-medium"><?php echo htmlspecialchars($subadmin['strand'] ?? ''); ?></span></p>
                                    <p class="text-xs text-gray-500 mt-1">Created: <?php echo date('M j, Y', strtotime($subadmin['created_at'])); ?></p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 mt-4">
                                <a href="edit_subadmin.php?id=<?php echo $subadmin['id']; ?>" class="flex-1 inline-flex items-center justify-center px-3 py-2 text-sm font-medium rounded-md border border-blue-200 text-blue-700 hover:bg-blue-50">
                                    <i class="fas fa-edit mr-2"></i> Edit
                                </a>
                                <a href="manage_subadmins.php?archive=<?php echo $subadmin['id']; ?>" class="sa-archive-link flex-1 inline-flex items-center justify-center px-3 py-2 text-sm font-medium rounded-md border border-amber-300 text-amber-700 hover:bg-amber-50">
                                    <i class="fas fa-archive mr-2"></i> Archive
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Sub-Admins Table (hidden on small screens) -->
            <div class="overflow-x-auto hidden sm:block">
                <table class="min-w-full bg-white border border-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profile</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($subadmins)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-500">No sub-admins found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($subadmins as $subadmin): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="w-10 h-10 rounded-full overflow-hidden bg-gray-100 border flex items-center justify-center">
                                            <?php if (!empty($subadmin['profile_pic'])): ?>
                                                <img src="images/<?php echo htmlspecialchars($subadmin['profile_pic']); ?>" alt="Profile" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <i class="fas fa-user text-gray-400"></i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($subadmin['fullname']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($subadmin['email']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($subadmin['strand'] ?? ''); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo date('M j, Y', strtotime($subadmin['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <a href="edit_subadmin.php?id=<?php echo $subadmin['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="manage_subadmins.php?archive=<?php echo $subadmin['id']; ?>" 
                                           class="sa-archive-link text-amber-600 hover:text-amber-800">
                                            <i class="fas fa-archive"></i> Archive
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const attachSwalArchive = (el) => {
            el.addEventListener('click', function(e){
                const href = this.getAttribute('href');
                if (typeof Swal === 'undefined') return; // fallback
                e.preventDefault();
                Swal.fire({
                    title: 'Archive this sub-admin?',
                    text: 'This will hide the account from active list. You can restore it later.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d97706',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, archive',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = href;
                    }
                });
            });
        };
        document.querySelectorAll('.sa-archive-link').forEach(attachSwalArchive);
    });
    </script>

</body>
</html>
