<?php
include __DIR__ . '/include/session_init.php';
include 'db.php';
// Use Philippine Standard Time for all date()/time() on this page
date_default_timezone_set('Asia/Manila');

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['error'] = "You must be logged in as an admin.";
    header("Location: login.php");
    exit();
}

// Detect availability of role / employee_type columns and build a safe expression
try {
    $qRole = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'role'");
    $qRole->execute();
    $hasRoleCol = ((int)$qRole->fetchColumn() > 0);
} catch (Throwable $_) {
    $hasRoleCol = false;
}
try {
    $qEmpType = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'employee_type'");
    $qEmpType->execute();
    $hasEmpTypeCol = ((int)$qEmpType->fetchColumn() > 0);
} catch (Throwable $_) {
    $hasEmpTypeCol = false;
}
try {
    $qRoleID = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'role_id'");
    $qRoleID->execute();
    $hasRoleIDCol = ((int)$qRoleID->fetchColumn() > 0);
} catch (Throwable $_) {
    $hasRoleIDCol = false;
}
// Expression we can safely use in SQL without referencing a missing column
$roleExpr = $hasRoleCol && $hasEmpTypeCol
    ? "COALESCE(role, employee_type, '')"
    : ($hasRoleCol
        ? "COALESCE(role, '')"
        : ($hasEmpTypeCol ? "COALESCE(employee_type, '')" : "''"));

// Ensure admin display name is available using employees schema
try {
    if (empty($_SESSION['admin_name']) && !empty($_SESSION['admin_id'])) {
        $stmt = $conn->prepare("SELECT CONCAT_WS(' ', COALESCE(first_name, firstname), NULLIF(COALESCE(middle_name, middlename), ''), COALESCE(last_name, lastname)) AS fullname FROM employees WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['admin_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['fullname'])) {
            $_SESSION['admin_name'] = $row['fullname'];
        }
    }
} catch (Throwable $e) { /* non-fatal */
}

// Initialize variables
$total_students = $verified_students = $unverified_students = 0;
$total_subadmins = 0;
$strand_counts = $research_stats = $year_data = $recent_research = $strand_research_data = [];
$activity_logs = [];
$unread_logs_count = 0;
$gradeStats = [];

$section_labels = [];
$section_values = [];

// If DB failed to connect, show a clear error and skip analytics queries
if (!empty($GLOBALS['DB_CONNECT_ERROR'])) {
    $error_message = $GLOBALS['DB_CONNECT_ERROR'];
} else {
    try {
        // Total Students (all)
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM students");
        $stmt->execute();
        $total_students = $stmt->fetch()['total'];

        // Total Research Advisers (sub-admins) - check if roles table exists
        try {
            $qRolesTable = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles'");
            $qRolesTable->execute();
            $hasRolesTable = ((int)$qRolesTable->fetchColumn() > 0);
        } catch (Throwable $_) {
            $hasRolesTable = false;
        }

        if ($hasRoleIDCol) {
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees WHERE role_id = 2");
            $stmt->execute();
            $total_subadmins = $stmt->fetch()['total'];
        } elseif ($hasRolesTable) {
            // Use new roles table - role_id = 2 is RESEARCH_ADVISER; join on role_id if employee_id is missing
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees e INNER JOIN roles r ON e.role_id = r.role_id WHERE r.role_id = 2");
            $stmt->execute();
            $total_subadmins = $stmt->fetch()['total'];
        } else {
            // Fallback to old role/employee_type columns if roles table doesn't exist
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees 
            WHERE UPPER(REPLACE(TRIM({$roleExpr}),' ','_')) = 'RESEARCH_ADVISER'");
            $stmt->execute();
            $total_subadmins = $stmt->fetch()['total'];
        }


        $stmt = $conn->prepare("SELECT COUNT(*) as verified FROM students WHERE is_verified = 1");
        $stmt->execute();
        $verified_students = $stmt->fetch()['verified'];
        $unverified_students = $total_students - $verified_students;

        // Students by Department (all)
        $stmt = $conn->prepare("SELECT TRIM(LOWER(COALESCE(NULLIF(department,''),'unassigned'))) AS dept_key, COUNT(*) as count FROM students GROUP BY dept_key ORDER BY count DESC");
        $stmt->execute();
        $rawDept = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Canonical departments in fixed order
        $deptOrder = ['ccs' => 'CCS', 'cbs' => 'CBS', 'coe' => 'COE', 'senior high school' => 'Senior High School'];
        $deptCountsMap = ['ccs' => 0, 'cbs' => 0, 'coe' => 0, 'senior high school' => 0];
        foreach ($rawDept as $row) {
            $k = $row['dept_key'] ?? '';
            if (isset($deptCountsMap[$k])) {
                $deptCountsMap[$k] = (int)$row['count'];
            }
        }
        // Build arrays used by chart: labels and values
        $strand_counts = [];
        foreach ($deptOrder as $key => $label) {
            $strand_counts[] = ['department' => $label, 'count' => $deptCountsMap[$key]];
        }

        // Research Submission Stats
        $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_submissions,
            SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as rejected
        FROM research_submission
    ");
        $stmt->execute();
        $research_stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Removed: Students by Section (no longer tracked)

        // Research by Status (Approved, Pending)
        $researchStatus = ['Approved' => $research_stats['approved'] ?? 0, 'Pending' => $research_stats['pending'] ?? 0];

        // Students by Department (for pie chart)
        $strands = array_column($strand_counts, 'department');
        $strandCounts = array_column($strand_counts, 'count');

        // Activity Logs (latest 50)
        try {
            // Ensure table exists before selecting
            $conn->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_type VARCHAR(20) NOT NULL,
            actor_id VARCHAR(64) NULL,
            action VARCHAR(100) NOT NULL,
            details JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $stmt = $conn->prepare("SELECT id, actor_type, actor_id, action, details, created_at FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT 50");
            $stmt->execute();
            $activity_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Compute unread count using session marker
            $lastView = $_SESSION['logs_last_view'] ?? null;
            if ($lastView) {
                foreach ($activity_logs as $lg) {
                    if (strtotime($lg['created_at']) > strtotime($lastView)) {
                        $unread_logs_count++;
                    }
                }
            } else {
                $unread_logs_count = count($activity_logs);
            }
            // If session sticky flag set, force unread to zero across refreshes
            if (!empty($_SESSION['logs_viewed_ack'])) {
                $unread_logs_count = 0;
            }
        } catch (PDOException $e) {
            // If logs fail, keep dashboard working
            $activity_logs = [];
        }
    } catch (PDOException $e) {
        $error_message = "Error fetching analytics: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/head_meta.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .gradient-border {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            padding: 2px;
            border-radius: 1rem;
        }
    </style>
</head>

<body class="bg-gray-50 text-gray-900 min-h-screen flex flex-col md:flex-row">
    <!-- Include the sidebar -->
    <?php include 'admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-4 sm:p-6 md:p-8 w-full">
        <!-- Header -->
        <header class="bg-white rounded-2xl shadow-lg p-4 sm:p-6 md:p-8 mb-6 md:mb-8 border border-gray-200">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 md:gap-0">
                <div>
                    <h1 class="text-4xl font-extrabold text-blue-900 flex items-center">
                        <i class="fas fa-chart-line mr-3"></i> Analytics Dashboard
                    </h1>
                    <p class="text-lg text-gray-700 mt-2">
                        Comprehensive insights for the <span class="font-semibold text-blue-700">Online Research Repository</span> at <span class="font-semibold text-blue-700">Santa Rita College of Pampanga</span>.
                    </p>
                    <p class="mt-3 text-gray-600">Track student activity, submissions, and verification in real time.</p>
                </div>

                <div class="mt-4 md:mt-0 flex items-center gap-6">
                    <!-- Day, Date and Time (PH time) -->
                    <div class="text-right leading-tight">
                        <p class="text-lg font-semibold text-blue-900"><?= date('l') ?></p>
                        <p class="text-sm text-gray-600"><?= date('M d, Y') ?></p>
                        <p class="text-sm text-gray-600"><?= date('h:i A') ?></p>
                    </div>

                    <!-- Notifications Bell -->
                    <div class="relative" id="notifDropdown">
                        <button class="text-gray-500 hover:text-blue-600 relative" id="notifButton" aria-label="Activity Notifications">
                            <i class="fas fa-bell text-2xl"></i>
                            <?php if ($unread_logs_count > 0): ?>
                                <span id="notifBadge" class="absolute -top-1 -right-1 bg-red-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full">
                                    <?= $unread_logs_count > 99 ? '99+' : $unread_logs_count; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        <div id="notifMenu" class="hidden fixed sm:absolute top-16 sm:top-auto right-2 left-2 sm:left-auto sm:right-0 mt-2 w-[95vw] sm:w-96 max-h-[70vh] sm:max-h-96 overflow-auto bg-white rounded-lg shadow-lg border border-gray-100 z-50">
                            <div class="px-4 py-2 border-b space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold text-gray-800">Activity</span>
                                    <div class="flex items-center gap-3">
                                        <button id="markSeenBtn" class="text-xs text-blue-600 hover:underline">Mark as Viewed</button>
                                        <button id="clearActivityBtn" class="text-xs text-red-600 hover:underline">Clear</button>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <label for="activityFilter" class="text-xs text-gray-600">Filter:</label>
                                    <select id="activityFilter" class="w-full text-xs border border-gray-300 rounded px-2 py-1">
                                        <option value="all">All</option>
                                        <option value="subadmin">Sub-admins</option>
                                        <option value="student">Students</option>
                                    </select>
                                </div>

                            </div>
                            <ul id="activityList" class="divide-y">
                                <?php if (empty($activity_logs)): ?>
                                    <li class="p-4 text-gray-500">No recent activity.</li>
                                <?php else: ?>
                                    <?php foreach ($activity_logs as $log): ?>
                                        <?php
                                        $icon = 'fa-info-circle';
                                        $iconColor = 'text-gray-500';
                                        $label = '';
                                        switch ($log['action']) {
                                            case 'upload_research':
                                                $icon = 'fa-upload';
                                                $iconColor = 'text-blue-600';
                                                $label = 'Uploaded Research';
                                                break;
                                            case 'approve_student':
                                                $icon = 'fa-user-check';
                                                $iconColor = 'text-green-600';
                                                $label = 'Approved Student';
                                                break;
                                            case 'reject_student':
                                                $icon = 'fa-user-times';
                                                $iconColor = 'text-red-600';
                                                $label = 'Rejected Student';
                                                break;
                                            case 'post_announcement':
                                                $icon = 'fa-bullhorn';
                                                $iconColor = 'text-amber-600';
                                                $label = 'Posted Announcement';
                                                break;
                                            case 'archive_research':
                                                $icon = 'fa-archive';
                                                $iconColor = 'text-purple-600';
                                                $label = 'Archived Research';
                                                break;
                                            default:
                                                $label = ucfirst(str_replace('_', ' ', $log['action']));
                                        }
                                        $who = strtoupper($log['actor_type']);
                                        $ts = date('M d, Y h:i A', strtotime($log['created_at']));
                                        $details = [];
                                        if (!empty($log['details'])) {
                                            $decoded = json_decode($log['details'], true);
                                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                $details = $decoded;
                                            }
                                        }
                                        ?>
                                        <li class="p-3 flex items-start gap-3" data-actor-type="<?= htmlspecialchars(strtolower($log['actor_type'])) ?>" data-action="<?= htmlspecialchars($log['action']) ?>">
                                            <div class="mt-1"><i class="fas <?= $icon ?> <?= $iconColor ?>"></i></div>
                                            <div class="flex-1">
                                                <div class="flex items-center justify-between">
                                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($label) ?> <span class="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600"><?= htmlspecialchars($who) ?></span></div>
                                                    <div class="text-[11px] text-gray-500"><?= htmlspecialchars($ts) ?></div>
                                                </div>
                                                <?php if (!empty($details)): ?>
                                                    <div class="mt-1 text-xs text-gray-600">
                                                        <?php foreach ($details as $k => $v): ?>
                                                            <span class="inline-block mr-2"><span class="font-medium"><?= htmlspecialchars($k) ?>:</span> <?= htmlspecialchars(is_scalar($v) ? (string)$v : json_encode($v)) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- Admin Profile -->
                    <div class="flex items-center gap-3 border-l pl-6">
                        <div class="bg-gradient-to-br from-blue-500 to-blue-700 rounded-full p-1">
                            <div class="bg-white rounded-full p-1">
                                <i class="fas fa-user-circle text-blue-600 text-2xl"></i>
                            </div>
                        </div>
                        <div class="hidden md:block">
                            <p class="text-sm font-medium text-gray-600">Welcome,</p>
                            <p class="text-base font-bold text-blue-900"><?php echo isset($_SESSION['admin_name']) ? htmlspecialchars($_SESSION['admin_name']) : 'Administrator'; ?></p>
                        </div>
                        <div class="relative" id="profileDropdown">
                            <button class="text-gray-400 hover:text-blue-600 transition-colors" onclick="toggleDropdown(event)">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                            <!-- Dropdown Menu -->
                            <div id="profileMenu" class="absolute right-0 top-full mt-2 w-48 bg-white rounded-lg shadow-lg py-2 hidden border border-gray-100 z-50">
                                <a href="admin_update_profile.php" class="px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 flex items-center gap-2">
                                    <i class="fas fa-user-edit w-4"></i>
                                    <span>Edit Profile</span>
                                </a>
                                <a href="admin_change_password.php" class="px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 flex items-center gap-2">
                                    <i class="fas fa-key w-4"></i>
                                    <span>Change Password</span>
                                </a>
                                <hr class="my-1">
                                <a href="admin_logout.php" class="px-4 py-2 text-sm text-red-600 hover:bg-red-50 flex items-center gap-2">
                                    <i class="fas fa-sign-out-alt w-4"></i>
                                    <span>Sign Out</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <!-- Analytics Cards -->
        <div class="grid grid-cols-1 xs:grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 md:mb-8">

            <!-- Total Sub-admins -->
            <div class="bg-white rounded-xl p-4 sm:p-6 text-center shadow-md h-full 
        hover:shadow-lg transition-shadow duration-300 ease-in-out 
        outline-none focus:outline-none focus:ring-0 
        active:shadow-md active:transform-none"
                tabindex="-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wide">Research Adviser</p>
                        <p class="text-3xl font-bold text-indigo-600 mt-1"><?= number_format($total_subadmins) ?></p>
                    </div>
                    <div class="text-indigo-500 text-4xl">
                        <i class="fas fa-user-shield"></i>
                    </div>
                </div>
            </div>

            <!-- Total Students -->
            <div class="bg-white rounded-xl p-4 sm:p-6 text-center shadow-md h-full 
        hover:shadow-lg transition-shadow duration-300 ease-in-out 
        outline-none focus:outline-none focus:ring-0 
        active:shadow-md active:transform-none"
                tabindex="-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Students</p>
                        <p class="text-3xl font-bold text-blue-900 mt-1"><?= number_format($total_students) ?></p>
                    </div>
                    <div class="text-blue-600 text-4xl">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>

            <!-- Verified Students -->
            <div class="bg-white rounded-xl p-4 sm:p-6 text-center shadow-md h-full 
        hover:shadow-lg transition-shadow duration-300 ease-in-out 
        outline-none focus:outline-none focus:ring-0 
        active:shadow-md active:transform-none"
                tabindex="-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wide">Verified</p>
                        <p class="text-3xl font-bold text-green-600 mt-1"><?= number_format($verified_students) ?></p>
                    </div>
                    <div class="text-green-500 text-4xl">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
            </div>

            <!-- Research Submissions -->
            <div class="bg-white rounded-xl p-4 sm:p-6 text-center shadow-md h-full 
        hover:shadow-lg transition-shadow duration-300 ease-in-out 
        outline-none focus:outline-none focus:ring-0 
        active:shadow-md active:transform-none"
                tabindex="-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Submissions</p>
                        <p class="text-3xl font-bold text-purple-600 mt-1"><?= number_format($research_stats['total_submissions'] ?? 0) ?></p>
                    </div>
                    <div class="text-purple-500 text-4xl">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
            </div>

            <!-- Approved Research -->
            <div class="bg-white rounded-xl p-4 sm:p-6 text-center shadow-md h-full 
        hover:shadow-lg transition-shadow duration-300 ease-in-out 
        outline-none focus:outline-none focus:ring-0 
        active:shadow-md active:transform-none"
                tabindex="-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wide">Approved</p>
                        <p class="text-3xl font-bold text-orange-600 mt-1"><?= number_format($research_stats['approved'] ?? 0) ?></p>
                    </div>
                    <div class="text-orange-500 text-4xl">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>

        </div>
        <!-- Charts Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 mb-6 md:mb-8">
            <!-- Students by Department -->
            <div class="bg-white rounded-xl shadow-lg p-4 sm:p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-2">Students by Department</h3>
                <canvas id="studentsChart" class="w-full"></canvas>
            </div>
            <!-- Research Status -->
            <div class="bg-white rounded-xl shadow-lg p-4 sm:p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-2">Research Status</h3>
                <canvas id="researchChart" class="w-full"></canvas>
            </div>
        </div>


        <!-- Error Display -->
        <?php if (isset($error_message)): ?>
            <div class="mt-8 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                <strong>Error:</strong> <?= htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
    </main>
    <script>
        // Notification dropdown functionality
        (function() {
            const notifBtn = document.getElementById('notifButton');
            const notifMenu = document.getElementById('notifMenu');
            const notifDropdown = document.getElementById('notifDropdown');
            const markSeenBtn = document.getElementById('markSeenBtn');
            const badge = document.getElementById('notifBadge');
            const filterSel = document.getElementById('activityFilter');
            const activityList = document.getElementById('activityList');
            const clearBtn = document.getElementById('clearActivityBtn');
            if (notifBtn && notifMenu) {
                notifBtn.addEventListener('click', async function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    notifMenu.classList.toggle('hidden');
                    // On first open, optimistically remove the badge and persist "viewed"
                    if (!window._adminMarkedLogs && !notifMenu.classList.contains('hidden')) {
                        window._adminMarkedLogs = true;
                        if (badge) badge.remove();
                        try {
                            await fetch('include/mark_logs_viewed.php', {
                                credentials: 'same-origin'
                            });
                        } catch (_) {}
                    }
                });
                // Close when clicking outside
                document.addEventListener('click', function(e) {
                    if (notifDropdown && !notifDropdown.contains(e.target)) {
                        notifMenu.classList.add('hidden');
                    }
                });
                // Close on Escape
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') notifMenu.classList.add('hidden');
                });
            }
            if (markSeenBtn) {
                markSeenBtn.addEventListener('click', async function(e) {
                    e.preventDefault();
                    try {
                        const res = await fetch('include/mark_logs_viewed.php', {
                            credentials: 'same-origin'
                        });
                        await res.json().catch(() => ({}));
                        if (badge) badge.remove();
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Marked as viewed',
                                toast: true,
                                position: 'top-end',
                                timer: 1500,
                                showConfirmButton: false
                            });
                        }
                    } catch (err) {
                        /* ignore */ }
                });
            }
            // Filter logic
            function applyFilter() {
                if (!activityList) return;
                const val = (filterSel?.value || 'all').toLowerCase();
                activityList.querySelectorAll('li[data-actor-type]').forEach(li => {
                    const t = (li.getAttribute('data-actor-type') || '').toLowerCase();
                    li.style.display = (val === 'all' || t === val) ? '' : 'none';
                });
            }
            if (filterSel) {
                filterSel.addEventListener('change', applyFilter);
                applyFilter();
            }
            // Clear list
            if (clearBtn && activityList) {
                clearBtn.addEventListener('click', async function(e) {
                    e.preventDefault();
                    // Confirm via SweetAlert if available
                    const proceed = await (async () => {
                        if (typeof Swal === 'undefined') return true;
                        const res = await Swal.fire({
                            title: 'Clear all activity logs?',
                            text: 'This action cannot be undone.',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#d33',
                            cancelButtonColor: '#6b7280',
                            confirmButtonText: 'Yes, clear all',
                            cancelButtonText: 'Cancel'
                        });
                        return res.isConfirmed;
                    })();
                    if (!proceed) return;

                    try {
                        const res = await fetch('include/clear_activity_logs.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            credentials: 'same-origin',
                            body: 'confirm=1'
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || !data.ok) throw new Error('Failed to clear');
                    } catch (err) {
                        /* even if request fails, clear UI to avoid stale state */ }
                    activityList.innerHTML = '<li class="p-4 text-gray-500">No recent activity.</li>';
                    if (badge) badge.remove();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Activity logs cleared',
                            toast: true,
                            position: 'top-end',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    }
                });
            }
        })();
        // Students by Course (Pie Chart)
        const studentsChart = new Chart(document.getElementById('studentsChart'), {
            type: 'pie',
            data: {
                labels: <?= json_encode($strands) ?>,
                datasets: [{
                    data: <?= json_encode($strandCounts) ?>,
                    // Colors aligned with canonical department order (CCS, CBS, COE, Senior High School)
                    backgroundColor: [
                        '#FFD700', // CCS - golden yellow
                        '#FFF59D', // CBS - light yellow
                        '#3B82F6', // COE - blue (tailwind blue-500)
                        '#EF4444' // Senior High School - red
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Research Status (Bar Chart)
        const researchChart = new Chart(document.getElementById('researchChart'), {
            type: 'bar',
            data: {
                labels: ['Approved', 'Pending'],
                datasets: [{
                    label: 'Research Papers',
                    data: [<?= $researchStatus['Approved'] ?>, <?= $researchStatus['Pending'] ?>],
                    backgroundColor: ['#10B981', '#F59E0B'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });

        // Profile Dropdown JavaScript
        function toggleDropdown(event) {
            event.stopPropagation();
            const menu = document.getElementById('profileMenu');
            menu.classList.toggle('hidden');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('profileDropdown');
            const menu = document.getElementById('profileMenu');

            if (!dropdown.contains(event.target)) {
                menu.classList.add('hidden');
            }
        });
    </script>
</body>

</html>