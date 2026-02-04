<?php
session_start();
include 'db.php';

// Determine role and permissions
$is_admin = isset($_SESSION['admin_id']);
$is_subadmin = isset($_SESSION['subadmin_id']);
$user_role = '';
$permissions = [];
$assigned_strand = '';
$assigned_department = $_SESSION['department'] ?? '';

if ($is_admin) {
    $user_role = 'admin';
    // give admins full view
    $can_view = true;
} elseif ($is_subadmin) {
    $user_role = 'subadmin';
    $permissions = json_decode($_SESSION['permissions'] ?? '[]', true) ?: [];
    $assigned_strand = $_SESSION['strand'] ?? '';
    // Allow if they have generic or strand-specific view/verify permission
    $view_perm = 'view_students_' . strtolower($assigned_strand);
    $verify_perm = 'verify_students_' . strtolower($assigned_strand);
    if (in_array('view_students', $permissions) || in_array($view_perm, $permissions) || in_array('verify_students', $permissions) || in_array($verify_perm, $permissions)) {
        $can_view = true;
    } else {
        $can_view = false;
    }
} else {
    $can_view = false;
}

if (empty($can_view)) {
    $_SESSION['error'] = "You do not have permission to access this page.";
    if ($is_subadmin) {
        header("Location: subadmin_dashboard.php");
    } else {
        header("Location: login.php");
    }
    exit();
}

// Initialize result containers so HTML won't throw undefined errors
$pendingStudents = [];
$verifiedStudents = [];
$pendingCount = 0;
$verifiedCount = 0;

try {
    if (!$conn) {
        throw new Exception('Database connection not available');
    }

    // Detect available name columns for ordering
    $cols = [];
    try {
        $qCols = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'");
        $qCols->execute();
        $cols = $qCols->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Throwable $_) {
        $cols = [];
    }
    $firstCol = in_array('firstname', $cols, true) ? 'firstname' : (in_array('first_name', $cols, true) ? 'first_name' : null);
    $lastCol  = in_array('lastname',  $cols, true) ? 'lastname'  : (in_array('last_name',  $cols, true) ? 'last_name'  : null);
    $orderBy  = 'email';
    if ($lastCol && $firstCol) {
        $orderBy = "`$lastCol`, `$firstCol`";
    } elseif ($lastCol) {
        $orderBy = "`$lastCol`";
    } elseif ($firstCol) {
        $orderBy = "`$firstCol`";
    }

    if ($is_admin) {
        // Admin: view all students
        $sqlPending = "SELECT * FROM students WHERE is_verified = 0 ORDER BY $orderBy";
        $sqlVerified = "SELECT * FROM students WHERE is_verified = 1 ORDER BY $orderBy";
        $pendingStmt = $conn->prepare($sqlPending);
        $verifiedStmt = $conn->prepare($sqlVerified);
        $pendingStmt->execute();
        $verifiedStmt->execute();
    } else {
        // Sub-admin: restrict to their assigned department (case-insensitive)
        $sqlPending = "SELECT * FROM students WHERE is_verified = 0 AND UPPER(department) = UPPER(?) ORDER BY $orderBy";
        $sqlVerified = "SELECT * FROM students WHERE is_verified = 1 AND UPPER(department) = UPPER(?) ORDER BY $orderBy";
        $pendingStmt = $conn->prepare($sqlPending);
        $verifiedStmt = $conn->prepare($sqlVerified);
        $params = [$assigned_department];
        $pendingStmt->execute($params);
        $verifiedStmt->execute($params);
    }

    $pendingStudents = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
    $verifiedStudents = $verifiedStmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = count($pendingStudents);
    $verifiedCount = count($verifiedStudents);
} catch (PDOException $e) {
    error_log('DB error in view_students: ' . $e->getMessage());
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    error_log('Error in view_students: ' . $e->getMessage());
    $_SESSION['error'] = 'Error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Students</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body class="bg-gray-100 flex">

    <!-- Sidebar -->
    <?php
    if ($user_role === 'admin') {
        include 'admin_sidebar.php';
    } else {
        include 'subadmin_sidebar.php';
    }
    ?>

    <!-- Main Content -->
    <main class="flex-1 p-10">
        <div class="bg-white rounded-lg shadow-md p-8 mb-8">
            <h2 class="text-4xl font-extrabold text-blue-900 mb-6">Students</h2>

            <!-- Error/Success Messages -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                    <p><?php echo $_SESSION['error']; ?></p>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['info'])): ?>
                <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4" role="alert">
                    <p><?php echo $_SESSION['info']; ?></p>
                    <?php unset($_SESSION['info']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['debug'])): ?>
                <div class="bg-gray-100 border-l-4 border-gray-500 text-gray-700 p-4 mb-4" role="alert">
                    <p><strong>Debug Info:</strong> <?php echo $_SESSION['debug']; ?></p>
                    <?php unset($_SESSION['debug']); ?>
                </div>
            <?php endif; ?>

            <!-- Database connection status removed from UI; use session messages for errors if needed -->

            <!-- (Pending Verification removed; only Verified Students are displayed) -->

            <!-- Verified Students -->
            <h3 class="text-2xl font-semibold text-gray-800 mt-4 mb-3">Verified Students (<?= $verifiedCount ?>)</h3>
            <div class="overflow-x-auto hidden xl:block">
                <table class="min-w-full bg-white border border-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Profile</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Student ID Number</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">First Name</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Last Name</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Department</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Course/Strand</th>
                            <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Email</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($verifiedStudents)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-gray-600">No verified students.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($verifiedStudents as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        // Prefer profile_picture column as shown in Image 2
                                        $pImg = $row['profile_picture'] ?? $row['profile_pic'] ?? '';
                                        $pic = !empty($pImg) ? 'images/' . htmlspecialchars($pImg) : 'images/default.jpg';
                                        ?>
                                        <img src="<?= $pic ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover border" />
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($row['first_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($row['last_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($row['department'] ?? ''); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($row['course_strand'] ?? 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($row['email']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile / Tablet Card List -->
            <div class="xl:hidden grid grid-cols-1 gap-3 mt-4">
                <?php if (empty($verifiedStudents)): ?>
                    <div class="text-center text-gray-600 p-6 bg-white rounded-lg shadow">
                        <div class="flex flex-col items-center">
                            <i class="fas fa-users text-3xl mb-2 opacity-50"></i>
                            <span>No verified students.</span>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($verifiedStudents as $row): ?>
                        <?php
                        $pImg = $row['profile_picture'] ?? $row['profile_pic'] ?? '';
                        $pic = !empty($pImg) ? 'images/' . htmlspecialchars($pImg) : 'images/default.jpg';
                        ?>
                        <div class="bg-white rounded-lg shadow p-4">
                            <div class="flex items-start gap-3">
                                <img src="<?= $pic ?>" alt="Profile" class="w-12 h-12 rounded-full border-4 border-blue-500 mb-4 object-cover shadow-lg">
                                <div class="min-w-0">
                                    <h4 class="text-base font-semibold text-gray-900 truncate"><?= htmlspecialchars(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?></h4>
                                    <p class="text-xs text-gray-500">Student ID: <span class="font-medium text-gray-700"><?= htmlspecialchars($row['lrn'] ?? '') ?></span></p>
                                    <p class="text-xs text-gray-500">Department: <span class="font-medium text-gray-700"><?= htmlspecialchars($row['department'] ?? '') ?></span></p>
                                    <p class="text-xs text-gray-500"><?= ($row['department'] === 'Senior High School') ? 'Strand:' : 'Course:'; ?> <span class="font-medium text-gray-700"><?= htmlspecialchars($row['course_strand'] ?? 'N/A') ?></span></p>
                                    <p class="text-xs text-gray-500">Section/Group: <span class="font-medium text-gray-700"><?= htmlspecialchars($row['section'] ?? '-') ?></span> • <span class="font-medium text-gray-700"><?= (!empty($row['group_number']) && (int)$row['group_number'] > 0) ? ('Group ' . (int)$row['group_number']) : '-' ?></span></p>
                                    <p class="text-xs text-gray-500">Email: <span class="font-medium text-gray-700"><?= htmlspecialchars($row['email'] ?? '') ?></span></p>
                                </div>
                            </div>
                            <div class="mt-3 flex justify-end">
                                <button type="button" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm" onclick="showProfileModal('<?= htmlspecialchars(addslashes($row['first_name'] ?? $row['firstname'] ?? '')) ?>','<?= htmlspecialchars(addslashes($row['last_name'] ?? $row['lastname'] ?? '')) ?>','<?= htmlspecialchars(addslashes($row['email'] ?? '')) ?>','<?= htmlspecialchars(addslashes($row['student_id'] ?? '')) ?>','<?= htmlspecialchars(addslashes($row['department'] ?? '')) ?>','<?= htmlspecialchars(addslashes($row['course_strand'] ?? '')) ?>','<?= htmlspecialchars(addslashes($row['profile_picture'] ?? $row['profile_pic'] ?? '')) ?>')">
                                    <i class="fas fa-user mr-1"></i> View
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Profile Modal -->
            <div id="profileModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden transition-opacity duration-300">
                <div class="bg-white rounded-lg shadow-xl p-8 max-w-sm w-full relative transform transition-all duration-300 scale-95">
                    <button onclick="closeProfileModal()" class="absolute top-3 right-3 text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
                    <div class="flex flex-col items-center text-center">
                        <img id="modalProfilePic" src="" alt="Profile Picture" class="w-32 h-32 rounded-full border-4 border-blue-500 mb-4 object-cover shadow-lg">
                        <h3 id="modalName" class="text-2xl font-bold text-gray-800 mb-2"></h3>
                        <div class="text-sm text-gray-700 space-y-1">
                            <p id="modalEmail"></p>
                            <p id="modalLRN"></p>
                            <p id="modalStrand"></p>
                            <p id="modalCourseStrand"></p>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                const profileModal = document.getElementById('profileModal');
                const modalContent = profileModal.querySelector('.transform');

                function showProfileModal(firstname, lastname, email, studentNumber, department, courseStrand, profilePic) {
                    document.getElementById('modalName').textContent = firstname + ' ' + lastname;
                    document.getElementById('modalEmail').textContent = 'Email: ' + email;
                    document.getElementById('modalLRN').textContent = 'Student Number: ' + (studentNumber || '');
                    document.getElementById('modalStrand').textContent = 'Department: ' + (department || '');
                    const label = (department === 'Senior High School') ? 'Strand:' : 'Course:';
                    document.getElementById('modalCourseStrand').textContent = label + ' ' + (courseStrand || 'N/A');
                    document.getElementById('modalProfilePic').src = profilePic ? 'images/' + profilePic : 'images/default.jpg';

                    profileModal.classList.remove('hidden');
                    setTimeout(() => {
                        profileModal.style.opacity = '1';
                        modalContent.style.transform = 'scale(1)';
                    }, 10);
                }

                function closeProfileModal() {
                    profileModal.style.opacity = '0';
                    modalContent.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        profileModal.classList.add('hidden');
                    }, 300);
                }

                // Close modal on outside click
                profileModal.addEventListener('click', function(e) {
                    if (e.target === profileModal) {
                        closeProfileModal();
                    }
                });
            </script>


        </div>
    </main>

</body>

</html>