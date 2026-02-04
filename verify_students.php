<?php
session_start();
include 'db.php';

// Check if user is logged in (either admin or sub-admin with proper permissions)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['subadmin_id'])) {
    $_SESSION['error'] = "You must be logged in to access this page.";
    header("Location: login.php");
    exit();
}

// Check permissions for sub-admins
$can_verify_students = isset($_SESSION['admin_id']); // Admins can always verify
$strand = isset($_SESSION['department']) ? strtolower($_SESSION['department']) : (isset($_SESSION['strand']) ? strtolower($_SESSION['strand']) : '');

if (!$can_verify_students && isset($_SESSION['subadmin_id'])) {
    $permissions = json_decode($_SESSION['permissions'] ?? '[]', true);
    $can_verify_students = in_array('verify_students', $permissions) ||
        in_array('verify_students_' . $strand, $permissions);

    if (!$can_verify_students) {
        $_SESSION['error'] = "You don't have permission to verify students.";
        header("Location: subadmin_dashboard.php");
        exit();
    }
}

// Bulk Approve All within scope (admins: all; sub-admins: only their department)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_all']) && $can_verify_students) {
    try {
        if (isset($_SESSION['admin_id'])) {
            $stmtApprove = $conn->prepare("UPDATE students SET is_verified = 1 WHERE is_verified = 0");
            $stmtApprove->execute();
        } else {
            $stmtApprove = $conn->prepare("UPDATE students SET is_verified = 1 WHERE is_verified = 0 AND LOWER(department) = LOWER(?)");
            $stmtApprove->execute([$strand]);
        }
        $affected = $stmtApprove->rowCount();
        $_SESSION['success'] = ($affected > 0)
            ? ("Approved " . $affected . " student" . ($affected == 1 ? '' : 's') . ".")
            : "No students to approve.";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Bulk approve failed: " . $e->getMessage();
    }
    header("Location: verify_students.php");
    exit();
}

// Fetch unverified students
try {
    if (isset($_SESSION['admin_id'])) {
        // Admins can see all unverified students
        $stmt = $conn->prepare("SELECT * FROM students WHERE is_verified = 0 ORDER BY created_at DESC");
        $stmt->execute();
    } else {
        // Sub-admins can only see students from their department
        $stmt = $conn->prepare("SELECT * FROM students WHERE is_verified = 0 AND LOWER(department) = LOWER(?) ORDER BY created_at DESC");
        $stmt->execute([$strand]);
    }
    $unverified_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching students: " . $e->getMessage();
    $unverified_students = [];
}

// Build distinct filter options
$strandOptions = [];
foreach ($unverified_students as $s) {
    if (!empty($s['department'])) $strandOptions[strtolower($s['department'])] = $s['department'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Students</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="bg-gray-100 flex min-h-screen">
    <!-- Sidebar -->
    <?php if (isset($_SESSION['admin_id'])) {
        include 'admin_sidebar.php';
    } else {
        include 'subadmin_sidebar.php';
    } ?>

    <!-- Main Content -->
    <main class="flex-1 p-6">
        <!-- Header -->
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <h2 class="text-3xl font-bold text-gray-800 mb-4 md:mb-0 flex items-center">
                <i class="fas fa-user-shield mr-3"></i> Verify Students
            </h2>
            <!-- Filters -->
            <div class="w-full md:w-auto grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 gap-2 md:gap-3 mt-2 md:mt-0">
                <input id="vsSearch" type="text" placeholder="Search name, email, Student ID" class="border rounded px-3 py-2 text-sm w-full" />
                <select id="vsStrand" class="border rounded px-3 py-2 text-sm w-full">
                    <option value="">All Departments</option>
                    <?php foreach ($strandOptions as $k => $v): ?>
                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </header>
        <?php if ($can_verify_students): ?>
            <div class="mb-4 flex justify-end">
                <form id="approveAllForm" method="POST" action="verify_students.php">
                    <input type="hidden" name="approve_all" value="1">
                    <button type="button" id="approveAllBtn" class="inline-flex items-center bg-green-700 hover:bg-green-800 text-white px-4 py-2 rounded font-semibold shadow">
                        <i class="fas fa-check-double mr-2"></i> Approve All
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Info Message for Sub-Admins without Permission -->
        <?php if (isset($_SESSION['subadmin_id']) && !$can_verify_students): ?>
            <div class="mb-6 p-4 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded-lg flex items-center">
                <i class="fas fa-info-circle mr-2"></i>
                <span>You can view unverified students but cannot verify them.</span>
            </div>
        <?php endif; ?>

        <!-- SweetAlert2: Flash messages -->
        <?php
        $__flash_success = $_SESSION['success'] ?? null;
        $__flash_error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);
        ?>
        <script>
            (function() {
                const successMsg = <?php echo json_encode($__flash_success); ?>;
                const errorMsg = <?php echo json_encode($__flash_error); ?>;
                if (successMsg) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: successMsg,
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
                if (errorMsg) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: errorMsg
                    });
                }
            })();
        </script>

        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="overflow-x-auto hidden xl:block">
                <table class="w-full border-collapse">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Name</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Email</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Student ID</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Department</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Course/Strand</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (count($unverified_students) > 0): ?>
                            <?php foreach ($unverified_students as $student): ?>
                                <?php
                                $sfname = $student['firstname'] ?? $student['first_name'] ?? '';
                                $slname = $student['lastname'] ?? $student['last_name'] ?? '';
                                ?>
                                <tr class="hover:bg-gray-50 transition duration-150" data-name="<?= htmlspecialchars(strtolower($sfname . ' ' . $slname)) ?>" data-email="<?= htmlspecialchars(strtolower($student['email'] ?? '')) ?>" data-lrn="<?= htmlspecialchars(strtolower($student['student_id'] ?? '')) ?>" data-strand="<?= htmlspecialchars(strtolower($student['department'] ?? '')) ?>">
                                    <td class="border border-gray-300 px-4 py-3">
                                        <div class="font-medium text-gray-900">
                                            <?= htmlspecialchars($sfname . ' ' . $slname); ?>
                                        </div>
                                    </td>
                                    <td class="border border-gray-300 px-4 py-3 text-gray-700">
                                        <?= htmlspecialchars($student['email']); ?>
                                    </td>
                                    <td class="border border-gray-300 px-4 py-3 text-gray-700">
                                        <?= htmlspecialchars($student['student_id'] ?? ''); ?>
                                    </td>
                                    <td class="border border-gray-300 px-4 py-3 text-gray-700">
                                        <?= htmlspecialchars($student['department']); ?>
                                    </td>
                                    <td class="border border-gray-300 px-4 py-3 text-gray-700">
                                        <?= htmlspecialchars($student['course_strand'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="border border-gray-300 px-4 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" onclick="showProfileModal('<?= htmlspecialchars(addslashes($sfname)) ?>','<?= htmlspecialchars(addslashes($slname)) ?>','<?= htmlspecialchars(addslashes($student['email'] ?? '')) ?>','<?= htmlspecialchars(addslashes($student['student_id'] ?? '')) ?>','<?= htmlspecialchars(addslashes($student['department'] ?? '')) ?>','<?= htmlspecialchars(addslashes($student['course_strand'] ?? '')) ?>','<?= htmlspecialchars(addslashes($student['profile_picture'] ?? $student['profile_pic'] ?? '')) ?>')" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded font-semibold shadow transition duration-200">
                                                <i class="fas fa-user mr-2"></i> View Profile
                                            </button>
                                            <form action="approve_student.php" method="POST" class="inline">
                                                <input type="hidden" name="email" value="<?= htmlspecialchars($student['email']); ?>">
                                                <button type="button"
                                                    class="js-approve-student inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded font-semibold shadow transition duration-200">
                                                    <i class="fas fa-check mr-1"></i> Approve
                                                </button>
                                            </form>
                                            <form action="reject_student.php" method="POST" class="inline">
                                                <input type="hidden" name="student_id" value="<?= $student['student_id']; ?>">
                                                <button type="button"
                                                    class="js-reject-student inline-flex items-center bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded font-semibold shadow transition duration-200">
                                                    <i class="fas fa-times mr-1"></i> Reject
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-8 text-gray-600 dark:text-gray-400">
                                    <div class="flex flex-col items-center">
                                        <i class="fas fa-users text-4xl mb-2 opacity-50"></i>
                                        <span class="text-lg">No students waiting for verification.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Mobile / Tablet Card List -->
            <div class="xl:hidden grid grid-cols-1 gap-3 p-4">
                <?php if (count($unverified_students) > 0): ?>
                    <?php foreach ($unverified_students as $student): ?>
                        <?php
                        $sfname = $student['firstname'] ?? $student['first_name'] ?? '';
                        $slname = $student['lastname'] ?? $student['last_name'] ?? '';
                        ?>
                        <div class="bg-white rounded-lg shadow p-4" data-name="<?= htmlspecialchars(strtolower($sfname . ' ' . $slname)) ?>" data-email="<?= htmlspecialchars(strtolower($student['email'] ?? '')) ?>" data-lrn="<?= htmlspecialchars(strtolower($student['student_id'] ?? '')) ?>" data-strand="<?= htmlspecialchars(strtolower($student['department'] ?? '')) ?>">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-base font-semibold text-gray-900">
                                        <?= htmlspecialchars($sfname . ' ' . $slname); ?>
                                    </h3>
                                    <p class="text-xs text-gray-500">Email: <span class="font-medium text-gray-700"><?= htmlspecialchars($student['email']); ?></span></p>
                                    <p class="text-xs text-gray-500">Student ID: <span class="font-medium text-gray-700"><?= htmlspecialchars($student['student_id'] ?? ''); ?></span></p>
                                    <p class="text-xs text-gray-500">Department: <span class="font-medium text-gray-700"><?= htmlspecialchars($student['department']); ?></span></p>
                                    <p class="text-xs text-gray-500"><?= ($student['department'] === 'Senior High School') ? 'Strand:' : 'Course:'; ?> <span class="font-medium text-gray-700"><?= htmlspecialchars($student['course_strand'] ?? 'N/A'); ?></span></p>
                                </div>
                                <div class="shrink-0">
                                    <button type="button" onclick="showProfileModal('<?= htmlspecialchars(addslashes($sfname)) ?>','<?= htmlspecialchars(addslashes($slname)) ?>','<?= htmlspecialchars(addslashes($student['email'] ?? '')) ?>','<?= htmlspecialchars(addslashes($student['student_id'] ?? '')) ?>','<?= htmlspecialchars(addslashes($student['department'] ?? '')) ?>','<?= htmlspecialchars(addslashes($student['course_strand'] ?? '')) ?>','<?= htmlspecialchars(addslashes($student['profile_picture'] ?? $student['profile_pic'] ?? '')) ?>')" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm">
                                        <i class="fas fa-user mr-1"></i> View
                                    </button>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <form action="approve_student.php" method="POST" class="inline">
                                    <input type="hidden" name="email" value="<?= htmlspecialchars($student['email']); ?>">
                                    <button type="button" class="js-approve-student inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded text-sm">
                                        <i class="fas fa-check mr-1"></i> Approve
                                    </button>
                                </form>
                                <form action="reject_student.php" method="POST" class="inline">
                                    <input type="hidden" name="student_id" value="<?= $student['student_id']; ?>">
                                    <button type="button" class="js-reject-student inline-flex items-center bg-red-500 hover:bg-red-600 text-white px-3 py-1.5 rounded text-sm">
                                        <i class="fas fa-times mr-1"></i> Reject
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-gray-600 p-6">
                        <div class="flex flex-col items-center">
                            <i class="fas fa-users text-3xl mb-2 opacity-50"></i>
                            <span>No students waiting for verification.</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <!-- Profile Modal -->
    <div id="profileModal" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 hidden" onclick="backgroundCloseModal(event)">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl p-10 max-w-md w-full relative flex flex-col items-center" onclick="event.stopPropagation()">
            <button onclick="closeProfileModal()" class="absolute top-2 right-2 text-gray-500 hover:text-gray-700 dark:text-gray-300 text-2xl">&times;</button>
            <img id="modalProfilePic" src="" alt="Profile Picture" class="w-40 h-40 rounded-full border-4 border-blue-500 mb-4 object-cover shadow-lg">
            <h3 id="modalName" class="text-2xl font-bold mb-2 text-center"></h3>
            <p id="modalEmail" class="text-gray-700 dark:text-gray-200 mb-1 text-center"></p>
            <p id="modalLRN" class="text-gray-700 dark:text-gray-200 mb-1 text-center"></p>
            <p id="modalStrand" class="text-gray-700 dark:text-gray-200 mb-1 text-center"></p>
            <p id="modalCourseStrand" class="text-gray-700 dark:text-gray-200 mb-1 text-center"></p>
        </div>
    </div>
    <script>
        function showProfileModal(firstname, lastname, email, studentId, department, courseStrand, profilePic) {
            document.getElementById('modalName').textContent = firstname + ' ' + lastname;
            document.getElementById('modalEmail').textContent = 'Email: ' + email;
            document.getElementById('modalLRN').textContent = 'Student ID: ' + studentId;
            document.getElementById('modalStrand').textContent = 'Department: ' + department;
            const label = (department === 'Senior High School') ? 'Strand:' : 'Course:';
            document.getElementById('modalCourseStrand').textContent = label + ' ' + (courseStrand || 'N/A');
            document.getElementById('modalProfilePic').src = profilePic ? 'images/' + profilePic : 'images/default.jpg';
            document.getElementById('profileModal').classList.remove('hidden');
        }

        function closeProfileModal() {
            document.getElementById('profileModal').classList.add('hidden');
        }

        function backgroundCloseModal(event) {
            if (event.target.id === 'profileModal') {
                closeProfileModal();
            }
        }
        // Delegated SweetAlert2 confirm for Approve/Reject
        document.addEventListener('click', function(e) {
            const approveBtn = e.target.closest('.js-approve-student');
            const rejectBtn = e.target.closest('.js-reject-student');
            if (!approveBtn && !rejectBtn) return;
            e.preventDefault();
            const form = (approveBtn || rejectBtn).closest('form');
            if (!form) return;
            const isApprove = !!approveBtn;
            const cfg = isApprove ? {
                title: 'Approve this student?',
                text: 'This will verify the student account.',
                confirmButtonText: 'Yes, approve'
            } : {
                title: 'Reject this student?',
                text: 'This will reject and may notify the student.',
                confirmButtonText: 'Yes, reject'
            };
            Swal.fire({
                title: cfg.title,
                text: cfg.text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: cfg.confirmButtonText,
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((res) => {
                if (res.isConfirmed) form.submit();
            });
        });

        // Approve All confirmation
        (function() {
            const btn = document.getElementById('approveAllBtn');
            const form = document.getElementById('approveAllForm');
            if (!btn || !form) return;
            btn.addEventListener('click', function(ev) {
                ev.preventDefault();
                Swal.fire({
                    title: 'Approve all students?',
                    text: 'This will verify all unverified students' + (<?= isset($_SESSION['admin_id']) ? '""' : '" in your department"' ?>) + '.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, approve all',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true
                }).then((res) => {
                    if (res.isConfirmed) form.submit();
                });
            });
        })();
        // Client-side filtering for table rows and cards
        (function() {
            const q = document.getElementById('vsSearch');
            const fStrand = document.getElementById('vsStrand');

            function norm(x) {
                return (x || '').toString().trim().toLowerCase();
            }

            function matches(el) {
                const name = el.getAttribute('data-name') || '';
                const email = el.getAttribute('data-email') || '';
                const lrn = el.getAttribute('data-lrn') || '';
                const strand = el.getAttribute('data-strand') || '';
                const qq = norm(q.value);
                if (qq && !(name.includes(qq) || email.includes(qq) || lrn.includes(qq))) return false;
                const fs = norm(fStrand.value);
                if (fs && strand !== fs) return false;
                return true;
            }

            function apply() {
                // table rows
                document.querySelectorAll('tbody tr[data-name]').forEach(tr => {
                    tr.style.display = matches(tr) ? '' : 'none';
                });
                // cards
                document.querySelectorAll('.xl\\:hidden [data-name]').forEach(card => {
                    const show = matches(card);
                    card.style.display = show ? '' : 'none';
                });
            }
            ['input', 'change'].forEach(ev => {
                q.addEventListener(ev, apply);
                fStrand.addEventListener(ev, apply);
            });
            apply();
        })();
    </script>
</body>

</html>