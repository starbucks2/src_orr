<?php
session_start();
include 'db.php';

// Check if user is logged in (either admin or sub-admin)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['subadmin_id'])) {
    $_SESSION['error'] = "You must be logged in to access this page.";
    header("Location: login.php");
    exit();
}

// Determine who is logged in and what their permissions are
$is_admin = isset($_SESSION['admin_id']);
$is_subadmin = isset($_SESSION['subadmin_id']);
$can_approve_research = false;
$can_archive_research = false;

if ($is_admin) {
    // Admins can do everything
    $can_approve_research = true;
    $can_archive_research = true;
} elseif ($is_subadmin) {
    // Sub-admins need specific permissions
    $permissions = json_decode($_SESSION['permissions'] ?? '[]', true);
    $can_approve_research = in_array('approve_research', $permissions);
    $can_archive_research = in_array('archive_research', $permissions); // Assuming a separate permission for archiving
}

// --- ACTION HANDLING --

// Approve research submission
if (isset($_POST['approve'])) {
    if (!$can_approve_research) {
        $_SESSION['error'] = "You don't have permission to approve research.";
    } else {
        $research_id = (int)$_POST['research_id'];
        // Approve and backfill department from student if missing
        $approve_stmt = $conn->prepare("UPDATE cap_books b 
            LEFT JOIN students s ON b.student_id = s.student_id 
            SET b.status = 1,
                b.department = COALESCE(NULLIF(b.department, ''), s.department)
            WHERE b.book_id = ?");
        if ($approve_stmt->execute([$research_id])) {
            $_SESSION['success'] = "Research approved successfully.";
        } else {
            $_SESSION['error'] = "Failed to approve research.";
        }
    }
    header("Location: research_approvals.php");
    exit();
}

// Archive research submission
if (isset($_POST['archive'])) {
    // For this page, we assume anyone who can see it can archive pending items.
    // You could tie this to a specific permission like `$can_archive_research` if needed.
    $research_id = (int)$_POST['research_id'];
    // Prefer status-based archive (2 = Archived)
    $ok = false;
    try {
        $archive_stmt = $conn->prepare("UPDATE cap_books SET status = 2 WHERE book_id = ?");
        $ok = $archive_stmt->execute([$research_id]);
    } catch (Throwable $e) {
        $ok = false;
    }
    if ($ok) {
        $_SESSION['success'] = "Research has been archived and removed from this list.";
    } else {
        $_SESSION['error'] = "Failed to archive research.";
    }
    header("Location: research_approvals.php");
    exit();
}

// --- DATA FETCHING ---
try {
    // Show only pending items (status = 0); avoid dependency on is_archived column
    $sql = "SELECT * FROM research_submission WHERE status = 0 ORDER BY submission_date DESC";
    $research_stmt = $conn->prepare($sql);
    $research_stmt->execute();
    $pending_research = $research_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug output - uncomment to see the data
    echo "<!-- Debug: Found " . count($pending_research) . " pending submissions -->";
    echo "<!-- SQL Query: " . $sql . " -->";

    // Verify student uploads
    $verify_sql = "SELECT COUNT(*) FROM research_submission WHERE status = 0";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->execute();
    $total_pending = $verify_stmt->fetchColumn();

    echo "<!-- Total pending in database: " . $total_pending . " -->";

    if (empty($pending_research)) {
        // Check if there are any submissions at all
        $check_sql = "SELECT COUNT(*) FROM research_submission";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute();
        $total_submissions = $check_stmt->fetchColumn();

        echo "<!-- Total submissions in database: " . $total_submissions . " -->";
    }
} catch (PDOException $e) {
    $pending_research = [];
    $_SESSION['error'] = "Database Error: " . $e->getMessage();
    echo "<!-- Error: " . htmlspecialchars($e->getMessage()) . " -->";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Approvals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="bg-gray-100 flex min-h-screen">

    <!-- Dynamically include the correct sidebar -->
    <?php
    if ($is_admin) {
        include 'admin_sidebar.php';
    } elseif ($is_subadmin) {
        include 'subadmin_sidebar.php';
    }
    ?>

    <!-- Main Content -->
    <main class="flex-1 p-6">
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <h2 class="text-3xl font-bold text-gray-800 mb-4 md:mb-0 flex items-center">
                <i class="fas fa-check-double mr-3"></i> Research Approvals
            </h2>
        </header>

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
            <div class="overflow-x-auto hidden md:block">
                <table class="w-full border-collapse">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Title</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Abstract</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Year</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Members</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Document</th>
                            <th class="border border-gray-300 px-4 py-3 text-left text-sm font-semibold text-gray-700">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (is_array($pending_research) && !empty($pending_research)): ?>
                            <?php
                            // Build a student_id -> group_number map for rows that have student_id
                            $studentIdList = [];
                            foreach ($pending_research as $r) {
                                if (!empty($r['student_id']) && (int)$r['student_id'] > 0) {
                                    $studentIdList[] = (int)$r['student_id'];
                                }
                            }
                            // No section mapping required
                            foreach ($pending_research as $research): ?>
                                <tr class="hover:bg-gray-50 transition duration-150">
                                    <td class="border border-gray-300 px-4 py-3 text-gray-900"><?= htmlspecialchars($research['title']); ?></td>
                                    <td class="border border-gray-300 px-4 py-3 text-gray-700 max-w-xs truncate" title="<?= htmlspecialchars($research['abstract']); ?>"><?= htmlspecialchars($research['abstract']); ?></td>
                                    <td class="border border-gray-300 px-4 py-3 text-gray-700"><?= htmlspecialchars($research['year']); ?></td>
                                    <td class="border border-gray-300 px-4 py-3 text-gray-700"><?= htmlspecialchars($research['members']); ?></td>
                                    <td class="border border-gray-300 px-4 py-3">
                                        <?php if (!empty($research['document'])): ?>
                                            <a href="<?= htmlspecialchars($research['document']); ?>" target="_blank" class="inline-flex items-center text-blue-600 hover:text-blue-800">
                                                <i class="fas fa-file-pdf mr-1"></i> View PDF
                                            </a>
                                        <?php else: ?>
                                            <span class="text-gray-500">No file</span>
                                        <?php endif; ?>
                                    </td>


                                    <td class="border border-gray-300 px-4 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <?php if ($can_approve_research): ?>
                                                <form method="POST" action="research_approvals.php" class="inline">
                                                    <input type="hidden" name="research_id" value="<?= $research['id']; ?>">
                                                    <input type="hidden" name="approve" value="1">
                                                    <button type="button" class="js-approve inline-flex items-center bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded transition duration-200">
                                                        <i class="fas fa-check mr-1"></i> Approve
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" action="research_approvals.php" class="inline">
                                                <input type="hidden" name="research_id" value="<?= $research['id']; ?>">
                                                <input type="hidden" name="archive" value="1">
                                                <button type="button" class="js-archive inline-flex items-center bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded transition duration-200">
                                                    <i class="fas fa-archive mr-1"></i> Archive
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-8 text-gray-600">
                                    <div class="flex flex-col items-center">
                                        <i class="fas fa-folder-open text-4xl mb-2 opacity-50"></i>
                                        <span class="text-lg">No pending research submissions.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Mobile / Tablet Card List -->
            <div class="block md:hidden">
                <?php if (is_array($pending_research) && !empty($pending_research)): ?>
                    <ul class="divide-y divide-gray-200">
                        <?php
                        // No student section mapping required on mobile
                        $studentIdList = [];
                        foreach ($pending_research as $r) {
                            if (!empty($r['student_id']) && (int)$r['student_id'] > 0) {
                                $studentIdList[] = (int)$r['student_id'];
                            }
                        }
                        // No section query
                        ?>
                        <?php foreach ($pending_research as $research): ?>
                            <li class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="text-base font-semibold text-gray-900 truncate"><?= htmlspecialchars($research['title']); ?></h3>
                                        <p class="text-xs text-gray-500 mt-0.5">Year: <span class="font-medium text-gray-700"><?= htmlspecialchars($research['year']); ?></span></p>

                                    </div>
                                    <div class="shrink-0">
                                        <?php if (!empty($research['document'])): ?>
                                            <a href="<?= htmlspecialchars($research['document']); ?>" target="_blank" class="inline-flex items-center text-blue-600 hover:text-blue-800 text-sm">
                                                <i class="fas fa-file-pdf mr-1"></i> PDF
                                            </a>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-500">No file</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($research['abstract'])): ?>
                                    <p class="mt-2 text-sm text-gray-700 line-clamp-3" title="<?= htmlspecialchars($research['abstract']); ?>"><?= htmlspecialchars($research['abstract']); ?></p>
                                <?php endif; ?>
                                <p class="mt-2 text-xs text-gray-600"><span class="font-semibold">Members:</span> <?= htmlspecialchars($research['members']); ?></p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php if ($can_approve_research): ?>
                                        <form method="POST" action="research_approvals.php" class="inline">
                                            <input type="hidden" name="research_id" value="<?= $research['id']; ?>">
                                            <input type="hidden" name="approve" value="1">
                                            <button type="button" class="js-approve inline-flex items-center bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm">
                                                <i class="fas fa-check mr-1"></i> Approve
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="research_approvals.php" class="inline">
                                        <input type="hidden" name="research_id" value="<?= $research['id']; ?>">
                                        <input type="hidden" name="archive" value="1">
                                        <button type="button" class="js-archive inline-flex items-center bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded text-sm">
                                            <i class="fas fa-archive mr-1"></i> Archive
                                        </button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="p-6 text-center text-gray-600">
                        <div class="flex flex-col items-center">
                            <i class="fas fa-folder-open text-3xl mb-2 opacity-50"></i>
                            <span>No pending research submissions.</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script>
        // Delegated handlers for Approve/Archive with SweetAlert2
        (function() {
            document.addEventListener('click', function(e) {
                const approveBtn = e.target.closest('.js-approve');
                const archiveBtn = e.target.closest('.js-archive');
                if (!approveBtn && !archiveBtn) return;
                e.preventDefault();
                const form = (approveBtn || archiveBtn).closest('form');
                if (!form) return;
                const isApprove = !!approveBtn;
                const opts = isApprove ? {
                    title: 'Approve this research?',
                    text: 'This will mark the submission as approved.',
                    confirmButtonText: 'Yes, approve it!'
                } : {
                    title: 'Archive this research?',
                    text: 'This will move the submission to archive.',
                    confirmButtonText: 'Yes, archive it!'
                };
                Swal.fire({
                    title: opts.title,
                    text: opts.text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: opts.confirmButtonText,
                    cancelButtonText: 'Cancel',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) form.submit();
                });
            });
        })();
    </script>
</body>

</html>