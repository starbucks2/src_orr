<?php
include __DIR__ . '/include/session_init.php';
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}
include 'db.php';

// Get user info from session
$student_id = $_SESSION['student_id'];

// Initial values from session (will refresh from DB)
$firstname = $_SESSION['firstname'] ?? 'Student';
$middlename = $_SESSION['middlename'] ?? '';
$lastname = $_SESSION['lastname'] ?? 'Student';
$email = $_SESSION['email'] ?? 'Not Available';
$department = $_SESSION['department'] ?? '';
$course_strand = $_SESSION['course_strand'] ?? '';
$profile_pic_file = $_SESSION['profile_pic'] ?? 'default.jpg';

// Fetch current data from DB
try {
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $user = $stmt->fetch();

    if ($user) {
        $firstname = $user['first_name'] ?? $user['firstname'] ?? $firstname;
        $middlename = $user['middle_name'] ?? $user['middlename'] ?? $middlename;
        $lastname = $user['last_name'] ?? $user['lastname'] ?? $lastname;
        $email = $user['email'] ?? $email;
        $department = $user['department'] ?? $department;
        $course_strand = $user['course_strand'] ?? $course_strand;
        // Ensure we prioritize profile_picture column
        $profile_pic_file = $user['profile_picture'] ?? $profile_pic_file;

        // Update session to keep it in sync
        $_SESSION['firstname'] = $firstname;
        $_SESSION['middlename'] = $middlename;
        $_SESSION['lastname'] = $lastname;
        $_SESSION['profile_pic'] = $profile_pic_file;
    }
} catch (PDOException $e) {
    // Fail silently or log
}

$profile_pic_path = 'images/' . $profile_pic_file;
if (!file_exists($profile_pic_path) || empty($profile_pic_file) || $profile_pic_file === 'default.jpg') {
    $profile_pic_path = 'images/default.jpg';
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $new_firstname = trim($_POST['firstname']);
        $new_middlename = trim($_POST['middlename']);
        $new_lastname = trim($_POST['lastname']);

        $errors = [];
        if (empty($new_firstname) || empty($new_lastname)) {
            $errors[] = 'First name and last name are required.';
        }

        // Handle profile picture upload
        $new_profile_pic = $profile_pic_file;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $targetDir = "images/";
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }
            $fileExtension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (in_array($fileExtension, $allowedExtensions)) {
                $newFileName = time() . '_' . $student_id . '.' . $fileExtension;
                $targetFile = $targetDir . $newFileName;

                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetFile)) {
                    // Success! Delete old one if it's not default
                    if ($profile_pic_file !== 'default.jpg' && file_exists('images/' . $profile_pic_file)) {
                        @unlink('images/' . $profile_pic_file);
                    }
                    $new_profile_pic = $newFileName;
                } else {
                    $errors[] = 'Failed to upload profile picture.';
                }
            } else {
                $errors[] = 'Invalid file type for profile picture.';
            }
        }

        if (empty($errors)) {
            try {
                // Update using correct column names from Image 2
                $sql = "UPDATE students SET first_name = ?, middle_name = ?, last_name = ?, profile_picture = ? WHERE student_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt->execute([$new_firstname, $new_middlename, $new_lastname, $new_profile_pic, $student_id])) {
                    $firstname = $new_firstname;
                    $middlename = $new_middlename;
                    $lastname = $new_lastname;
                    $profile_pic_file = $new_profile_pic;
                    $profile_pic_path = 'images/' . $profile_pic_file;

                    // Update Session
                    $_SESSION['firstname'] = $firstname;
                    $_SESSION['middlename'] = $middlename;
                    $_SESSION['lastname'] = $lastname;
                    $_SESSION['profile_pic'] = $profile_pic_file;

                    $message = 'Profile updated successfully!';
                } else {
                    $error = 'Failed to update profile in database.';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        } else {
            $error = implode(' ', $errors);
        }
    }

    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } else {
            try {
                $stmt = $conn->prepare("SELECT password FROM students WHERE student_id = ?");
                $stmt->execute([$student_id]);
                $user_pwd = $stmt->fetch();

                if ($user_pwd && password_verify($current_password, $user_pwd['password'])) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE students SET password = ?, last_password_change = NOW() WHERE student_id = ?");
                    if ($stmt->execute([$hashed_password, $student_id])) {
                        $message = 'Password changed successfully!';
                    } else {
                        $error = 'Failed to change password.';
                    }
                } else {
                    $error = 'Current password is incorrect.';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile | SRC Research Repository</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="bg-gray-50 min-h-screen flex flex-col lg:flex-row font-sans">
    <?php include 'student_sidebar.php'; ?>

    <div class="flex-1 p-4 sm:p-6 lg:p-8">
        <!-- Messages via SweetAlert2 -->
        <?php if ($message || $error): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    <?php if ($message): ?>
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: '<?php echo addslashes($message); ?>',
                            confirmButtonColor: '#2563EB'
                        });
                    <?php endif; ?>

                    <?php if ($error): ?>
                        Swal.fire({
                            icon: 'error',
                            title: 'Oops...',
                            text: '<?php echo addslashes($error); ?>',
                            confirmButtonColor: '#DC2626'
                        });
                    <?php endif; ?>
                });
            </script>
        <?php endif; ?>

        <!-- Profile Card -->
        <div class="max-w-2xl mx-auto">
            <div class="bg-white rounded-lg shadow-lg p-6">
                <!-- Profile Header -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8 gap-4 pb-6 border-b">
                    <div class="flex items-center space-x-4">
                        <div class="relative group">
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>"
                                class="w-24 h-24 rounded-full object-cover border-4 border-blue-50 shadow-md transition group-hover:opacity-90"
                                alt="Profile picture">
                            <div class="absolute -bottom-1 -right-1 bg-green-500 w-5 h-5 rounded-full border-4 border-white"></div>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($firstname . ' ' . $lastname); ?></h1>
                            <p class="text-gray-500"><?php echo htmlspecialchars($email); ?></p>
                            <span class="inline-block mt-1 px-3 py-1 bg-blue-50 text-blue-600 rounded-full text-xs font-semibold uppercase">
                                <?php echo htmlspecialchars($department); ?>
                            </span>
                        </div>
                    </div>
                    <button onclick="document.getElementById('editModal').classList.remove('hidden')"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg transition-all shadow-md flex items-center justify-center gap-2 self-start sm:self-center">
                        <i class="fas fa-user-edit"></i> Edit Profile
                    </button>
                </div>

                <!-- Info Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-gray-50 p-5 rounded-xl border border-gray-100">
                        <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Personal Details</h3>
                        <div class="space-y-3">
                            <div><span class="text-gray-500 text-sm">First Name:</span>
                                <p class="font-medium"><?php echo htmlspecialchars($firstname); ?></p>
                            </div>
                            <div><span class="text-gray-500 text-sm">Middle Name:</span>
                                <p class="font-medium"><?php echo htmlspecialchars($middlename ?: 'None'); ?></p>
                            </div>
                            <div><span class="text-gray-500 text-sm">Last Name:</span>
                                <p class="font-medium"><?php echo htmlspecialchars($lastname); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 p-5 rounded-xl border border-gray-100">
                        <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Academic Status</h3>
                        <div class="space-y-3">
                            <div><span class="text-gray-500 text-sm">Student ID:</span>
                                <p class="font-medium"><?php echo htmlspecialchars($student_id); ?></p>
                            </div>
                            <div><span class="text-gray-500 text-sm">Department:</span>
                                <p class="font-medium"><?php echo htmlspecialchars($department); ?></p>
                            </div>
                            <div><span class="text-gray-500 text-sm"><?php echo ($department === 'Senior High School') ? 'Strand:' : 'Course:'; ?></span>
                                <p class="font-medium"><?php echo htmlspecialchars($course_strand ?: 'Not Set'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Password Change Section -->
            <div class="bg-white rounded-lg shadow-lg p-6 mt-8">
                <h2 class="text-xl font-bold text-gray-900 mb-6 flex items-center gap-2">
                    <i class="fas fa-shield-alt text-blue-600"></i> Change Password
                </h2>
                <form action="student_profile.php" method="POST" class="space-y-4">
                    <input type="hidden" name="change_password" value="1">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Current Password</label>
                        <input type="password" name="current_password" required
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 transition outline-none border-gray-200">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">New Password</label>
                            <input type="password" name="new_password" required
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 transition outline-none border-gray-200">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Confirm New Password</label>
                            <input type="password" name="confirm_password" required
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 transition outline-none border-gray-200">
                        </div>
                    </div>
                    <button type="submit" class="bg-gray-800 hover:bg-black text-white px-6 py-2 rounded-lg transition shadow-md">
                        Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div id="editModal" class="hidden fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden animate-in fade-in zoom-in duration-200">
            <div class="bg-blue-600 p-4 flex justify-between items-center text-white">
                <h2 class="text-lg font-bold">Edit Profile</h2>
                <button onclick="document.getElementById('editModal').classList.add('hidden')" class="hover:bg-white/20 p-1 rounded-full transition">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form action="student_profile.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
                <input type="hidden" name="update_profile" value="1">

                <!-- Pic Upload Preview -->
                <div class="flex flex-col items-center mb-4">
                    <div class="relative cursor-pointer group" onclick="document.getElementById('profile_picture_input').click()">
                        <img id="modal_preview" src="<?php echo htmlspecialchars($profile_pic_path); ?>"
                            class="w-28 h-28 rounded-full object-cover border-4 border-gray-100 shadow-md group-hover:brightness-90 transition">
                        <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition">
                            <i class="fas fa-camera text-white text-3xl"></i>
                        </div>
                        <input type="file" name="profile_picture" id="profile_picture_input" class="hidden" accept="image/*" onchange="previewImage(this)">
                    </div>
                    <p class="text-[10px] text-gray-400 mt-2 uppercase font-bold tracking-widest">Click to change photo</p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">First Name</label>
                    <input type="text" name="firstname" value="<?php echo htmlspecialchars($firstname); ?>"
                        class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-blue-500 transition outline-none border-gray-100" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Middle Name</label>
                    <input type="text" name="middlename" value="<?php echo htmlspecialchars($middlename); ?>"
                        class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-blue-500 transition outline-none border-gray-100 placeholder-gray-300" placeholder="Optional">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Last Name</label>
                    <input type="text" name="lastname" value="<?php echo htmlspecialchars($lastname); ?>"
                        class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-blue-500 transition outline-none border-gray-100" required>
                </div>

                <div class="pt-4 flex gap-3">
                    <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')"
                        class="flex-1 px-4 py-2 border border-gray-200 rounded-xl text-gray-600 hover:bg-gray-50 transition font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition shadow-md font-bold">
                        Save Details
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('modal_preview').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>

</html>