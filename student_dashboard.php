<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include __DIR__ . '/include/session_init.php';
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}
include 'db.php'; // Database connection

// Ensure PH time for all date()/time() usage on this page
date_default_timezone_set('Asia/Manila');

// Initialize variables with defaults
$firstname = $_SESSION['firstname'] ?? 'Student';
$middlename = $_SESSION['middlename'] ?? '';
$lastname = $_SESSION['lastname'] ?? 'Student';
$email = $_SESSION['email'] ?? 'Not Available';
$profile_pic = !empty($_SESSION['profile_pic']) ? 'images/' . $_SESSION['profile_pic'] : 'images/default.jpg';
$department = $_SESSION['department'] ?? ($_SESSION['strand'] ?? '');
$course_strand = $_SESSION['course_strand'] ?? '';

$submissions = [];
$submissionCount = 0;
$approvedCount = 0;
$pendingCount = 0;
$db_error = null;

try {
    if (!isset($conn)) {
        throw new Exception("Database connection failed: " . ($GLOBALS['DB_CONNECT_ERROR'] ?? 'Unknown error'));
    }

    // Ensure the profile picture file exists
    if (!file_exists($profile_pic) || empty($_SESSION['profile_pic'])) {
        $profile_pic = 'images/default.jpg';
    }

    // Fetch research submissions (student uploads) and admin uploads
    // Admin uploads are included when they are "approved" (status = 1)
    // and match the student's department or course/strand.
    // Include `source` so we can tell which table the row came from (research_submission vs books)
    $sql = "SELECT * FROM (
        SELECT id, title, year, department, course_strand, status, document, submission_date, 'student' as uploader_type, 'research_submission' AS source, student_id
        FROM research_submission
        WHERE student_id = ?
        UNION ALL
        SELECT book_id AS id, title, year, department, course_strand, status, document, submission_date, 'admin' as uploader_type, 'cap_books' AS source, student_id
        FROM cap_books
        WHERE (student_id = ? OR (student_id IS NULL AND status = 1 AND (department = ? OR course_strand = ?)))
    ) AS allsub
    ORDER BY submission_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$_SESSION['student_id'], $_SESSION['student_id'], $department, $course_strand]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Submission counters for stats
    $submissionCount = is_array($submissions) ? count($submissions) : 0;
    foreach ($submissions as $s) {
        if (!empty($s['status']) && (int)$s['status'] === 1) {
            $approvedCount++;
        }
    }
    $pendingCount = max(0, $submissionCount - $approvedCount);
} catch (Throwable $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    $db_error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/head_meta.php'; ?>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Custom styles -->
    <style>
        .sidebar-transition {
            transition: all 0.3s ease;
        }

        .dropdown-menu {
            animation: slideDown 0.3s ease forwards;
            opacity: 0;
            transform: translateY(-10px);
        }

        @keyframes slideDown {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .countdown-glow {
            text-shadow: 0 0 8px rgba(59, 130, 246, 0.3);
        }

        .card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-approved {
            background-color: #dcfce7;
            color: #166534;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .action-btn {
            transition: all 0.2s ease;
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        /* Subtle background pattern */
        .bg-pattern {
            background-image: radial-gradient(circle at 1px 1px, rgba(30, 64, 175, 0.06) 1px, transparent 1px);
            background-size: 24px 24px;
        }

        /* Glass card effect */
        .glass {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: saturate(140%) blur(6px);
            -webkit-backdrop-filter: saturate(140%) blur(6px);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }

        /* Smooth scroll */
        html {
            scroll-behavior: smooth;
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'blue-primary': '#1e40af',
                        'blue-secondary': '#1e3a8a',
                        'gray-light': '#f3f4f6'
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 min-h-screen flex flex-col lg:flex-row font-sans">

    <?php
    try {
        include 'student_sidebar.php';
    } catch (Throwable $e) {
        echo "<div class='bg-red-500 text-white p-4'>Sidebar Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>

    <!-- Main Content -->
    <div class="flex-1 lg:ml-0 w-full">
        <!-- Error Notification -->
        <?php if ($db_error): ?>
            <div class="m-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded shadow-md">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <strong>Database Connection Issue:</strong>
                <?php echo htmlspecialchars($db_error); ?>
                <p class="mt-2 text-sm italic">Please check your hosting credentials in db.php</p>
            </div>
        <?php endif; ?>

        <!-- Top Header with Gmail-style Profile -->
        <header class="bg-white shadow-sm border-b border-gray-200 px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900"></h1>
                    <div class="text-right sm:text-left leading-tight">
                        <p class="text-sm sm:text-base font-semibold text-blue-900"><?= date('l') ?></p>
                        <p class="text-xs sm:text-sm text-gray-600"><?= date('M d, Y') ?></p>
                        <p class="text-xs sm:text-sm text-gray-600"><?= date('h:i A') ?></p>
                    </div>
                </div>

                <!-- Notification + Profile Section -->
                <div id="topUserControls" class="flex items-center space-x-4 relative" style="z-index: 9999;">
                    <!-- Gmail-style Profile -->
                    <div id="profileDropdownContainer" class="relative pointer-events-auto">
                        <button type="button" id="profileDropdown" class="flex items-center space-x-3 bg-gray-50 hover:bg-gray-100 rounded-full p-2 transition-colors duration-200">
                            <div class="relative">
                                <img src="<?php echo htmlspecialchars($profile_pic); ?>"
                                    class="w-10 h-10 rounded-full object-cover border-2 border-gray-200"
                                    alt="Profile Picture">
                                <div class="absolute -bottom-0.5 -right-0.5 bg-green-500 w-3 h-3 rounded-full border-2 border-white"></div>
                            </div>
                            <div class="hidden sm:block text-left">
                                <p class="text-sm font-medium text-gray-900 flex items-center gap-2">
                                    <?php echo htmlspecialchars($firstname . ' ' . $lastname); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    Department: <?php echo htmlspecialchars($department); ?>
                                </p>
                            </div>
                            <i class="fas fa-chevron-down text-gray-400 text-sm"></i>
                        </button>

                        <!-- Dropdown Menu -->
                        <div id="profileDropdownMenu" class="hidden absolute right-0 mt-2 w-[92vw] sm:w-72 max-w-[92vw] sm:max-w-[18rem] bg-white rounded-lg shadow-lg border border-gray-200 z-50">
                            <div class="p-4 border-b border-gray-100">
                                <div class="flex items-center space-x-3">
                                    <img src="<?php echo htmlspecialchars($profile_pic); ?>"
                                        class="w-12 h-12 rounded-full object-cover"
                                        alt="Profile Picture">
                                    <div class="min-w-0 max-w-full">
                                        <p class="font-medium text-gray-900 flex items-center gap-2 break-words">
                                            <?php echo htmlspecialchars($firstname . ' ' . $lastname); ?>
                                        </p>
                                        <p class="text-sm text-gray-500 break-words leading-snug max-w-full"><?php echo htmlspecialchars($email); ?></p>
                                        <p class="text-xs text-blue-600 break-words leading-snug max-w-full">Department: <?php echo htmlspecialchars($department); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="py-2">
                                <button onclick="toggleModal()" class="w-full flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 text-left">
                                    <i class="fas fa-edit mr-3 text-gray-400"></i>
                                    Edit Profile
                                </button>
                                <a href="logout.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-sign-out-alt mr-3 text-gray-400"></i>
                                    Sign Out
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
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
                        text: <?php echo json_encode(preg_replace('/^Error updating profile:\\s*/i', '', $_SESSION['error'])); ?>,
                        confirmButtonText: 'OK'
                    });
                });
            </script>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Main Content Area -->
        <div class="p-4 sm:p-6 lg:p-8 space-y-6 lg:space-y-8">
            <!-- Overlay for mobile (single, used by sidebar) -->
            <div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden lg:hidden"></div>
            <!-- Welcome Section -->
            <section id="welcome" class="bg-gradient-to-r from-blue-primary to-blue-secondary text-white p-6 sm:p-8 rounded-xl shadow-lg card-hover transition-all duration-300">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between">
                    <div class="mb-4 sm:mb-0">
                        <h2 class="text-2xl sm:text-3xl font-bold mb-2">Welcome, <?php echo htmlspecialchars($firstname); ?>!</h2>
                        <p class="text-blue-100 text-sm sm:text-base max-w-2xl">
                            Welcome to the <span class="font-semibold">Online Research Repository</span><span class="font-semibold"> at Santa Rita College of Pampanga</span>.
                        </p>
                        <p class="text-blue-100 text-sm sm:text-base mt-2">
                            Upload your research projects and manage your submissions in one place.
                        </p>
                    </div>
                    <div class="flex-shrink-0">
                        <div class="bg-white bg-opacity-20 rounded-full p-3 inline-flex">
                            <i class="fas fa-graduation-cap text-4xl text-white"></i>
                        </div>
                    </div>
                </div>
            </section>
            <!-- Edit Profile Modal -->
            <div id="editProfileModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
                <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center">
                    <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="toggleModal()"></div>

                    <div class="inline-block w-full max-w-md p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
                        <div class="flex justify-between items-center mb-5 border-b pb-4">
                            <h3 class="text-lg font-bold text-gray-800">Edit Profile</h3>
                            <button type="button" class="text-gray-400 hover:text-gray-600" onclick="toggleModal()">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>

                        <form action="update_profile.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                            <div class="grid grid-cols-1 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Profile Picture</label>
                                    <div class="flex items-center space-x-3">
                                        <img src="<?php echo htmlspecialchars($profile_pic); ?>" alt="Current" class="w-12 h-12 rounded-full object-cover">
                                        <input type="file" name="profile_pic" accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-lg file:mr-4 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-primary hover:file:bg-blue-100 transition-all duration-200">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                                    <input type="text" name="firstname" value="<?php echo htmlspecialchars($firstname); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-primary focus:border-transparent transition-all duration-200" required>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Middle Name</label>
                                    <input type="text" name="middlename" value="<?php echo htmlspecialchars($middlename); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-primary focus:border-transparent transition-all duration-200">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                                    <input type="text" name="lastname" value="<?php echo htmlspecialchars($lastname); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-primary focus:border-transparent transition-all duration-200" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-primary focus:border-transparent transition-all duration-200" required>
                                </div>

                                <!-- Strand is fixed and not editable by students -->


                            </div>

                            <!-- Password Change Section -->
                            <div class="pt-4 mt-4 border-t border-gray-200">
                                <h4 class="text-sm font-medium text-gray-700 mb-3 flex items-center">
                                    <i class="fas fa-lock mr-2"></i>
                                    Change Password
                                </h4>

                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                                        <div class="relative">
                                            <input type="text" name="current_password" id="current_password" class="w-full px-3 py-2 border border-gray-300 rounded-lg pr-10 focus:ring-2 focus:ring-blue-primary focus:border-transparent transition-all duration-200">
                                            <button type="button" onclick="togglePassword('current_password')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                                <i class="fas fa-eye" id="current_password_icon"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                                        <div class="relative">
                                            <input type="text" name="new_password" id="new_password" class="w-full px-3 py-2 border border-gray-300 rounded-lg pr-10 focus:ring-2 focus:ring-blue-primary focus:border-transparent transition-all duration-200">
                                            <button type="button" onclick="togglePassword('new_password')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                                <i class="fas fa-eye" id="new_password_icon"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                                        <div class="relative">
                                            <input type="text" name="confirm_password" id="confirm_password" class="w-full px-3 py-2 border border-gray-300 rounded-lg pr-10 focus:ring-2 focus:ring-blue-primary focus:border-transparent transition-all duration-200">
                                            <button type="button" onclick="togglePassword('confirm_password')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                                <i class="fas fa-eye" id="confirm_password_icon"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3 pt-2">
                                <button type="button" onclick="toggleModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors duration-200">
                                    Cancel
                                </button>
                                <button type="submit" class="px-4 py-2 bg-blue-primary text-white rounded-lg hover:bg-blue-secondary transition-colors duration-200 flex items-center">
                                    <i class="fas fa-save mr-2"></i>
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>





            <!-- Upload Research Section (visible to all students) -->
            <section id="upload-research" class="bg-white p-5 sm:p-6 rounded-xl shadow-lg card-hover transition-all duration-300">
                <div class="flex items-center mb-5">
                    <i class="fas fa-cloud-upload-alt text-2xl text-blue-primary mr-3"></i>
                    <h3 class="text-xl font-bold text-gray-800">Upload Research</h3>
                </div>

                <?php
                // Students can now upload anytime - no announcement requirement
                // Check if student has already submitted a document
                $hasSubmitted = false;
                if ($conn) {
                    try {
                        $stmt = $conn->prepare("SELECT COUNT(*) as submission_count FROM research_submission WHERE student_id = ?");
                        $stmt->execute([$_SESSION['student_id']]);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        $hasSubmitted = ($result['submission_count'] > 0);
                    } catch (Throwable $e) {
                        $hasSubmitted = false;
                    }
                }
                ?>

                <?php
                if ($hasSubmitted) {
                ?>
                    <div class="text-center py-10">
                        <div class="mx-auto w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-exclamation-triangle text-3xl text-yellow-500"></i>
                        </div>
                        <div class="text-yellow-600 text-lg font-semibold mb-2">Document Already Submitted</div>
                        <p class="text-gray-600 max-w-md mx-auto">
                            You have already submitted a research document. Multiple submissions are not allowed. If you need to make changes, please edit or delete your existing submission.
                        </p>
                    </div>
                <?php
                } elseif (!$conn) {
                ?>
                    <div class="text-center py-10 bg-red-50 rounded-lg border border-red-200">
                        <i class="fas fa-database text-3xl text-red-500 mb-3"></i>
                        <p class="text-red-700 font-semibold">Upload temporarily unavailable</p>
                        <p class="text-sm text-red-600 mt-2">The system detected a database connection issue. Please check your credentials in <code class="bg-red-100 px-1 rounded">db.php</code>.</p>
                    </div>
                <?php
                } else {
                ?>
                    <form id="uploadForm" action="upload_research.php" method="POST" enctype="multipart/form-data" class="space-y-5">
                        <input type="hidden" name="student_id" value="<?php echo $_SESSION['student_id']; ?>">
                        <input type="hidden" name="department" value="<?php echo htmlspecialchars($department); ?>">

                        <!-- Header like the provided mock -->
                        <div class="-mt-2 -mx-2 px-2 py-1">
                            <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-600"><i class="fas fa-cloud-upload-alt"></i></span>
                                <span>Upload <span class="underline">Research</span></span>
                            </h3>
                        </div>

                        <!-- Row 1: Title + Academic Year -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Research Title <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><i class="fas fa-book"></i></span>
                                    <input type="text" name="title" class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-primary focus:border-blue-primary transition-all placeholder-gray-400" placeholder="Enter research title" required>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Academic Year <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><i class="fas fa-calendar"></i></span>
                                    <?php
                                    // Compute real-time School Year with June (6) cutoff
                                    // If month >= 6 (June-Dec) => SY is currentYear-currentYear+1
                                    // If month <= 5 (Jan-May) => SY is (currentYear-1)-currentYear
                                    $nowYear = (int)date('Y');
                                    $nowMonth = (int)date('n');
                                    $startYear = ($nowMonth >= 6) ? $nowYear : ($nowYear - 1);
                                    $computedSY = 'A.Y. ' . $startYear . '-' . ($startYear + 1);
                                    ?>
                                    <input type="text" value="<?php echo htmlspecialchars($computedSY); ?>" class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700 cursor-not-allowed" readonly>
                                    <input type="hidden" name="year" value="<?php echo htmlspecialchars($computedSY, ENT_QUOTES); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Row 2: Abstract -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Abstract <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <span class="absolute top-2 left-0 pl-3 text-gray-400"><i class="fas fa-align-left"></i></span>
                                <textarea name="abstract" rows="4" class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-primary focus:border-blue-primary transition-all placeholder-gray-400" placeholder="Enter a brief summary of your research..." required></textarea>
                            </div>
                        </div>

                        <!-- Row 3: Keywords -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Keywords <span class="text-gray-400">(comma-separated)</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><i class="fas fa-tags"></i></span>
                                <input type="text" name="keywords" class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-primary focus:border-blue-primary transition-all placeholder-gray-400" placeholder="e.g., machine learning, climate change, data mining">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Add 3–8 keywords separated by commas to improve search visibility.</p>
                        </div>

                        <!-- Row 4: Author(s) -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Author(s) <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><i class="fas fa-users"></i></span>
                                <textarea name="author" rows="2" class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-primary focus:border-blue-primary transition-all placeholder-gray-400" placeholder="Enter author names separated by commas" required></textarea>
                            </div>
                        </div>

                        <!-- Row 5: Department + Course/Strand -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Department</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><i class="fas fa-building"></i></span>
                                    <input type="text" class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700 cursor-not-allowed" value="<?php echo htmlspecialchars($department ?: '—'); ?>" readonly>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1"><?php echo ($department === 'Senior High School') ? 'Strand' : 'Course' ?></label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><i class="fas fa-user-graduate"></i></span>
                                    <input type="text" class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700 cursor-not-allowed" value="<?php echo htmlspecialchars($_SESSION['course_strand'] ?? '—'); ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <!-- Row: Status -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Status</label>
                            <div class="relative">
                                <div class="w-full px-4 py-2 bg-green-50 border border-green-200 rounded-lg text-green-700 flex items-center gap-2">
                                    <i class="fas fa-check-circle"></i>
                                    <span class="font-medium">Approved</span>
                                </div>
                            </div>
                        </div>

                        <!-- Row 6: Files -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Poster <span class="text-gray-400">(Optional)</span></label>
                                <input type="file" name="image" accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-lg file:mr-4 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-primary hover:file:bg-blue-100 transition-all duration-200">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Research Document (PDF) <span class="text-gray-400">(Optional)</span></label>
                                <input type="file" name="document" accept=".pdf" class="w-full px-3 py-2 border border-gray-300 rounded-lg file:mr-4 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-primary hover:file:bg-blue-100 transition-all duration-200">
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="w-full bg-blue-primary hover:bg-blue-secondary text-white py-3 rounded-lg transition-colors duration-200 flex items-center justify-center font-semibold shadow-sm">
                            <i class="fas fa-upload mr-2"></i>
                            Upload Research
                        </button>
                    </form>
                <?php
                }
                ?>
            </section>

            <!-- Research Submissions Section (visible to all students) -->
            <section id="submissions" class="bg-white p-5 sm:p-6 rounded-xl shadow-lg card-hover transition-all duration-300">
                <div class="flex items-center mb-5">
                    <i class="fas fa-list-alt text-2xl text-blue-primary mr-3"></i>
                    <h3 class="text-xl font-bold text-gray-800">My Research Submissions</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-gray-50 border-b-2 border-gray-200">
                                <th class="text-left px-4 py-3 text-sm font-semibold text-gray-700">Title</th>
                                <th class="text-left px-4 py-3 text-sm font-semibold text-gray-700">Academic Year</th>
                                <th class="text-left px-4 py-3 text-sm font-semibold text-gray-700">Department</th>
                                <th class="text-left px-4 py-3 text-sm font-semibold text-gray-700"><?php echo ($department === 'Senior High School') ? 'Strand' : 'Course'; ?></th>
                                <th class="text-left px-4 py-3 text-sm font-semibold text-gray-700">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($submissions) > 0): ?>
                                <?php foreach ($submissions as $submission): ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                                        <td class="px-4 py-3 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($submission['title']); ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($submission['year']); ?></td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <?php echo htmlspecialchars($submission['department'] ?? $department); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <?php echo htmlspecialchars($submission['course_strand'] ?? $course_strand); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <div class="flex items-center space-x-3">
                                                <?php
                                                // Build a robust href for the stored document value
                                                $docHref = '';
                                                if (!empty($submission['document'])) {
                                                    $raw = trim((string)$submission['document']);
                                                    if (preg_match('~^https?://~i', $raw)) {
                                                        $docHref = $raw;
                                                    } else {
                                                        $clean = preg_replace('#[\\/]+#', '/', $raw);
                                                        $clean = ltrim($clean, "./\\/");
                                                        $lower = strtolower($clean);
                                                        $needle1 = 'uploads/research_documents/';
                                                        $needle2 = 'research_documents/';
                                                        $needle3 = 'uploads/';
                                                        if (($p = strpos($lower, $needle1)) !== false) {
                                                            $rel = substr($clean, $p);
                                                            $docPath = $rel;
                                                        } elseif (($p = strpos($lower, $needle2)) !== false) {
                                                            $rel = substr($clean, $p);
                                                            $docPath = 'uploads/' . $rel;
                                                        } elseif (strpos($lower, $needle3) === 0) {
                                                            $docPath = $clean;
                                                        } else {
                                                            $filename = basename($clean);
                                                            $docPath = 'uploads/research_documents/' . $filename;
                                                        }
                                                        // Build absolute URL
                                                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                                                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                                        $base = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                                                        $docHref = $scheme . '://' . $host . $base . '/' . $docPath;
                                                    }
                                                }
                                                ?>
                                                <?php if (!empty($docHref)): ?>
                                                    <a href="<?php echo htmlspecialchars($docHref); ?>" class="text-blue-600 hover:text-blue-800 transition-colors duration-200 flex items-center action-btn" target="_blank">
                                                        <i class="fas fa-file-pdf mr-1"></i>
                                                        View
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-gray-500">No document</span>
                                                <?php endif; ?>

                                                <?php if (($submission['source'] ?? '') === 'research_submission'): ?>
                                                    <a href="edit_research.php?id=<?php echo $submission['id']; ?>" class="text-yellow-600 hover:text-yellow-800 transition-colors duration-200 flex items-center action-btn">
                                                        <i class="fas fa-edit mr-1"></i>
                                                        Edit
                                                    </a>
                                                    <a href="delete_research.php?id=<?php echo $submission['id']; ?>" class="text-red-600 hover:text-red-800 transition-colors duration-200 flex items-center action-btn" onclick="return confirm('Are you sure you want to delete this research? This action cannot be undone.');">
                                                        <i class="fas fa-trash-alt mr-1"></i>
                                                        Delete
                                                    </a>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">Admin Upload</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-8 text-gray-500">
                                        <div class="flex flex-col items-center">
                                            <i class="fas fa-folder-open text-4xl mb-3 text-gray-300"></i>
                                            <p>No research submitted yet.</p>
                                            <p class="text-sm mt-1">Upload your first research to get started.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Scripts -->
        <script>
            // Profile modal functions
            function toggleModal() {
                const modal = document.getElementById('editProfileModal');
                if (!modal) return;
                modal.classList.toggle('hidden');
                const topControls = document.getElementById('topUserControls');
                const notifMenu = document.getElementById('notifMenu');
                // Prevent background scroll when modal is open and hide top controls (bell/profile)
                if (!modal.classList.contains('hidden')) {
                    document.body.classList.add('overflow-hidden');
                    if (topControls) topControls.classList.add('hidden');
                    if (notifMenu && !notifMenu.classList.contains('hidden')) notifMenu.classList.add('hidden');
                } else {
                    document.body.classList.remove('overflow-hidden');
                    if (topControls) topControls.classList.remove('hidden');
                }
            }

            // Mobile menu functions
            document.addEventListener('DOMContentLoaded', function() {
                const mobileMenuButton = document.getElementById('mobileMenuButton');
                const closeSidebar = document.getElementById('closeSidebar');
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('overlay');
                const repositoryDropdown = document.getElementById('repositoryDropdown');
                const dropdownMenu = document.getElementById('dropdownMenu');
                let chevron = null;
                if (repositoryDropdown) {
                    chevron = repositoryDropdown.querySelector('.fa-chevron-down');
                }

                // Mobile menu toggle
                if (mobileMenuButton && closeSidebar && sidebar && overlay) {
                    mobileMenuButton.addEventListener('click', function() {
                        sidebar.classList.remove('-translate-x-full');
                        overlay.classList.remove('hidden');
                        document.body.classList.add('overflow-hidden');
                    });

                    closeSidebar.addEventListener('click', function() {
                        sidebar.classList.add('-translate-x-full');
                        overlay.classList.add('hidden');
                        document.body.classList.remove('overflow-hidden');
                    });

                    overlay.addEventListener('click', function() {
                        sidebar.classList.add('-translate-x-full');
                        overlay.classList.add('hidden');
                        document.body.classList.remove('overflow-hidden');
                    });
                }

                // Repository dropdown
                if (repositoryDropdown && dropdownMenu && chevron) {
                    repositoryDropdown.addEventListener('click', function(e) {
                        if (window.innerWidth >= 1024) { // Only on desktop
                            e.preventDefault();
                            dropdownMenu.classList.toggle('hidden');
                            chevron.classList.toggle('rotate-180');

                            if (!dropdownMenu.classList.contains('hidden')) {
                                dropdownMenu.classList.add('dropdown-menu');
                            }
                        }
                    });

                    // Close dropdown when clicking outside
                    document.addEventListener('click', function(e) {
                        if (!repositoryDropdown.contains(e.target)) {
                            dropdownMenu.classList.add('hidden');
                            chevron.classList.remove('rotate-180');
                        }
                    });
                }

                // Close mobile menu when clicking nav links
                const navLinks = document.querySelectorAll('.nav-link');
                navLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        if (window.innerWidth < 1024) {
                            setTimeout(() => {
                                sidebar.classList.add('-translate-x-full');
                                overlay.classList.add('hidden');
                                document.body.classList.remove('overflow-hidden');
                            }, 300);
                        }
                    });
                });

                // Profile dropdown functionality - aligned with Sub-Admin behavior
                const profileDropdown = document.getElementById('profileDropdown');
                const profileDropdownMenu = document.getElementById('profileDropdownMenu');

                if (profileDropdown && profileDropdownMenu) {
                    profileDropdown.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                        e.stopPropagation();
                        profileDropdownMenu.classList.toggle('hidden');
                        profileDropdownMenu.style.display = profileDropdownMenu.classList.contains('hidden') ? 'none' : 'block';
                        // Close notifications menu if open
                        const notifMenuEl = document.getElementById('notifMenu');
                        if (notifMenuEl && !notifMenuEl.classList.contains('hidden')) {
                            notifMenuEl.classList.add('hidden');
                        }
                    });

                    // Prevent clicks inside the menu from bubbling to document
                    profileDropdownMenu.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });

                    document.addEventListener('click', function(e) {
                        if (!profileDropdown.contains(e.target) && !profileDropdownMenu.contains(e.target)) {
                            profileDropdownMenu.classList.add('hidden');
                            profileDropdownMenu.style.display = 'none';
                        }
                    });

                    // Close on Escape key
                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') {
                            profileDropdownMenu.classList.add('hidden');
                            profileDropdownMenu.style.display = 'none';
                        }
                    });
                }


                // Direct bind SweetAlert2 to dropdown Sign Out (fallback to ensure it always shows)
                const studentDropdownLogout = document.querySelector('#profileDropdownMenu a[href="logout.php"]');
                if (studentDropdownLogout) {
                    studentDropdownLogout.addEventListener('click', function(e) {
                        e.preventDefault();
                        const href = this.getAttribute('href');
                        if (typeof Swal === 'undefined') {
                            if (confirm('Are you sure you want to sign out?')) {
                                window.location.href = href;
                            }
                            return;
                        }
                        Swal.fire({
                            title: 'Sign out?',
                            text: 'Are you sure you want to sign out?',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#d33',
                            cancelButtonColor: '#3085d6',
                            confirmButtonText: 'Yes, sign out',
                            cancelButtonText: 'Cancel',
                            reverseButtons: true
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = href;
                            }
                        });
                    });
                }
            });

            // Close modal when clicking outside
            document.addEventListener('click', function(e) {
                const modal = document.getElementById('editProfileModal');
                if (modal && !modal.contains(e.target) && e.target.classList.contains('bg-opacity-75')) {
                    toggleModal();
                }
            });

            // Auto-scroll to upload section on page load (for new students or after login)
            window.addEventListener('DOMContentLoaded', function() {
                const uploadSection = document.getElementById('upload-research');
                if (uploadSection) {
                    // Small delay to ensure page is fully rendered
                    setTimeout(function() {
                        uploadSection.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                        // Add a subtle highlight effect
                        uploadSection.style.transition = 'box-shadow 0.3s ease';
                        uploadSection.style.boxShadow = '0 0 20px rgba(59, 130, 246, 0.5)';
                        setTimeout(function() {
                            uploadSection.style.boxShadow = '';
                        }, 2000);
                    }, 500);
                }
            });
        </script>
        <script>
            function togglePassword(inputId) {
                try {
                    var input = document.getElementById(inputId);
                    if (!input) return;
                    var icon = document.getElementById(inputId + '_icon');
                    var isPassword = input.getAttribute('type') === 'password';
                    input.setAttribute('type', isPassword ? 'text' : 'password');
                    if (icon) {
                        icon.classList.toggle('fa-eye');
                        icon.classList.toggle('fa-eye-slash');
                    }
                } catch (_) {}
            }
        </script>
</body>

</html>