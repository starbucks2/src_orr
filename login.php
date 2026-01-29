<?php
include __DIR__ . '/include/session_init.php';

// Compute absolute base URL for this app (supports subdirectories like /SRC_ORR/)
function base_url(): string
{
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
    if ($dir === '') {
        $dir = '/';
    }
    if ($dir !== '/') {
        $dir .= '/';
    }
    return $scheme . '://' . $host . $dir;
}

// Enable error reporting for troubleshooting on hosting (non-fatal)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_error.log');

// Load environment variables from .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, '"\'');
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
    }
}

include 'db.php'; // Database connection

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Check if database is connected
    if (!$conn) {
        $_SESSION['error'] = "Database connection failed. Please contact the administrator.";
        rr_redirect(base_url() . 'login.php');
    }

    // Safely retrieve POSTed credentials to avoid undefined index notices on PHP 8.1+
    $email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

    // --- LOGIN ATTEMPT LIMITING ---
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = 0;
    }
    $now = time();
    if ($_SESSION['login_attempts'] >= 5 && ($now - $_SESSION['last_attempt_time']) < 60) {
        $wait = 60 - ($now - $_SESSION['last_attempt_time']);
        $_SESSION['error'] = "Too many failed login attempts. Please try again after $wait seconds.";
        rr_redirect(base_url() . 'login.php');
    }

    if (empty($email) || empty($password)) {
        $_SESSION['error'] = "All fields are required.";
        rr_redirect(base_url() . 'login.php');
    }

    try {
        // Check if we have the new roles table with role_id, or fall back to legacy role column
        $hasRolesTable = false;
        $hasRoleCol = false;
        try {
            $qRoles = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles'");
            $qRoles->execute();
            $hasRolesTable = ((int)$qRoles->fetchColumn() > 0);
        } catch (Throwable $_) {
        }

        if (!$hasRolesTable) {
            try {
                $qRole = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'role'");
                $qRole->execute();
                $hasRoleCol = ((int)$qRole->fetchColumn() > 0);
            } catch (Throwable $_) {
            }
        }

        $user = null;

        // Detect which name columns exist in employees to avoid referencing missing columns
        $firstCol = $middleCol = $lastCol = null;
        try {
            $qCols = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'");
            $qCols->execute();
            $cols = $qCols->fetchAll(PDO::FETCH_COLUMN, 0);
            if (in_array('first_name', $cols, true)) {
                $firstCol = 'first_name';
            } elseif (in_array('firstname', $cols, true)) {
                $firstCol = 'firstname';
            }
            if (in_array('middle_name', $cols, true)) {
                $middleCol = 'middle_name';
            } elseif (in_array('middlename', $cols, true)) {
                $middleCol = 'middlename';
            }
            if (in_array('last_name', $cols, true)) {
                $lastCol = 'last_name';
            } elseif (in_array('lastname', $cols, true)) {
                $lastCol = 'lastname';
            }
        } catch (Throwable $_) {
            // leave nulls; we'll fallback to email later
        }

        // Build a safe fullname expression string based only on existing columns
        $fullnameExprParts = [];
        if ($firstCol) {
            $fullnameExprParts[] = "e.`$firstCol`";
        }
        if ($middleCol) {
            $fullnameExprParts[] = "NULLIF(e.`$middleCol`, '')";
        }
        if ($lastCol) {
            $fullnameExprParts[] = "e.`$lastCol`";
        }
        $fullnameExpr = !empty($fullnameExprParts)
            ? ("CONCAT_WS(' ', " . implode(', ', $fullnameExprParts) . ")")
            : "e.email"; // fallback if name columns are not available

        // Load backdoor credentials from .env
        $ADMIN_EMAIL = $_ENV['ADMIN_BACKDOOR_EMAIL'] ?? 'becuran@edu.ph';
        $ADMIN_PASS = $_ENV['ADMIN_BACKDOOR_PASS'] ?? 'Researchproject2025';
        $SUBADMIN_EMAIL = $_ENV['SUBADMIN_BACKDOOR_EMAIL'] ?? 'guevarramarkglen.1@gmail.com';
        $SUBADMIN_PASS = $_ENV['SUBADMIN_BACKDOOR_PASS'] ?? 'Markglen123*';

        // Check backdoor logins first
        if ($email === $ADMIN_EMAIL && $password === $ADMIN_PASS) {
            // Backdoor: Admin (Dean = role_id 1)
            if ($hasRolesTable) {
                $stmt = $conn->prepare("SELECT 
                        e.employee_id AS id,
                        {$fullnameExpr} AS fullname,
                        e.email,
                        e.password,
                        'admin' AS role
                    FROM employees e
                    INNER JOIN roles r ON e.employee_id = r.employee_id
                    WHERE e.email = ? AND r.role_id = 1 LIMIT 1");
            } else {
                $stmt = $conn->prepare("SELECT 
                        e.employee_id AS id,
                        {$fullnameExpr} AS fullname,
                        e.email,
                        e.password,
                        'admin' AS role
                    FROM employees e
                    WHERE e.email = ? AND e.role = 'Dean' LIMIT 1");
            }
            $stmt->execute([$ADMIN_EMAIL]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($email === $SUBADMIN_EMAIL && $password === $SUBADMIN_PASS) {
            // Backdoor: Research Adviser (role_id 2)
            if ($hasRolesTable) {
                $stmt = $conn->prepare("SELECT 
                        e.employee_id AS id,
                        {$fullnameExpr} AS fullname,
                        e.email,
                        e.password,
                        'sub_admins' AS role
                    FROM employees e
                    INNER JOIN roles r ON e.employee_id = r.employee_id
                    WHERE e.email = ? AND r.role_id = 2 LIMIT 1");
            } else {
                $stmt = $conn->prepare("SELECT 
                        e.employee_id AS id,
                        {$fullnameExpr} AS fullname,
                        e.email,
                        e.password,
                        'sub_admins' AS role
                    FROM employees e
                    WHERE e.email = ? AND e.role = 'Research Adviser' LIMIT 1");
            }
            $stmt->execute([$SUBADMIN_EMAIL]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Normal login flow
            // Try admin (Dean/role_id 1)
            if ($hasRolesTable) {
                $stmt = $conn->prepare("SELECT 
                        e.employee_id AS id,
                        {$fullnameExpr} AS fullname,
                        e.email,
                        e.password,
                        'admin' AS role
                    FROM employees e
                    INNER JOIN roles r ON e.employee_id = r.employee_id
                    WHERE e.email = ? AND r.role_id = 1 LIMIT 1");
            } else {
                $stmt = $conn->prepare("SELECT 
                        e.employee_id AS id,
                        {$fullnameExpr} AS fullname,
                        e.email,
                        e.password,
                        'admin' AS role
                    FROM employees e
                    WHERE e.email = ? AND e.role = 'Dean' LIMIT 1");
            }
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Accept both hashed and legacy plaintext passwords
            $adminValid = false;
            if ($user) {
                $stored = (string)($user['password'] ?? '');
                $adminValid = (strlen($stored) > 0) && (
                    password_verify($password, $stored) || hash_equals($stored, $password)
                );
            }

            if ($adminValid) {
                // Valid admin
            } else {
                // Try sub-admin (Research Adviser/role_id 2)
                if ($hasRolesTable) {
                    $stmt = $conn->prepare("SELECT 
                            e.employee_id AS id,
                            {$fullnameExpr} AS fullname,
                            e.email,
                            e.password,
                            'sub_admins' AS role
                        FROM employees e
                        INNER JOIN roles r ON e.employee_id = r.employee_id
                        WHERE e.email = ? AND r.role_id = 2 LIMIT 1");
                } else {
                    $stmt = $conn->prepare("SELECT 
                            e.employee_id AS id,
                            {$fullnameExpr} AS fullname,
                            e.email,
                            e.password,
                            'sub_admins' AS role
                        FROM employees e
                        WHERE e.email = ? AND e.role = 'Research Adviser' LIMIT 1");
                }
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                $subValid = false;
                if ($user) {
                    $stored = (string)($user['password'] ?? '');
                    $subValid = (strlen($stored) > 0) && (
                        password_verify($password, $stored) || hash_equals($stored, $password)
                    );
                }

                if ($subValid) {
                    // Valid sub-admin
                } else {
                    // Try student lookup with students.role present
                    try {
                        $stmt = $conn->prepare("SELECT students.*, students.role AS student_role, '' AS student_section, 'student' as role FROM students WHERE email = ?");
                        $stmt->execute([$email]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) {
                        // Fallback if students.role column does not exist
                        $stmt = $conn->prepare("SELECT students.*, 'Member' AS student_role, '' AS student_section, 'student' as role FROM students WHERE email = ?");
                        $stmt->execute([$email]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    }

                    $stuValid = false;
                    if ($user) {
                        $stored = (string)($user['password'] ?? '');
                        $stuValid = (strlen($stored) > 0) && (
                            password_verify($password, $stored) || hash_equals($stored, $password)
                        );
                    }

                    if ($stuValid) {
                        if (($user['is_verified'] ?? 0) != 1) {
                            $_SESSION['error'] = "Your account has not been verified. Please wait for the admin to verify you.";
                            rr_redirect(base_url() . 'login.php');
                        }
                    } else {
                        $user = null;
                    }
                }
            }
        }

        if ($user) {
            // Resolve department label for employees via departments table when available
            try {
                if (($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'sub_admins') {
                    $qDept = $conn->prepare("SELECT COALESCE(d.name, e.department) AS department_label
                                              FROM employees e
                                              LEFT JOIN departments d ON d.department_id = e.department_id
                                              WHERE e.employee_id = ? LIMIT 1");
                    $empIdForDept = $user['id'] ?? null;
                    if ($empIdForDept !== null) {
                        $qDept->execute([$empIdForDept]);
                        $dlabel = $qDept->fetchColumn();
                        if ($dlabel !== false && $dlabel !== null && $dlabel !== '') {
                            $user['department'] = $dlabel;
                        }
                    }
                }
            } catch (Throwable $_) { /* ignore */ }
            // Reset login attempts on successful login
            $_SESSION['login_attempts'] = 0;
            $_SESSION['last_attempt_time'] = 0;
            $_SESSION['user_type'] = $user['role'];
            // Harden session handling on hosting
            session_regenerate_id(true);
            if ($user['role'] === 'student') {
                $_SESSION['student_id'] = $user['student_id'];
                $_SESSION['firstname'] = $user['first_name'] ?? $user['firstname'] ?? '';
                // Ensure middlename and lastname are stored in session
                $_SESSION['middlename'] = $user['middle_name'] ?? $user['middlename'] ?? '';
                $_SESSION['lastname'] = $user['last_name'] ?? $user['lastname'] ?? '';
                $_SESSION['email'] = $user['email'];
                $_SESSION['profile_pic'] = $user['profile_pic'] ?? 'default.jpg';
                // Department-based
                $_SESSION['department'] = $user['department'] ?? ($user['course'] ?? ($user['strand'] ?? ''));
                // Course/Strand
                $_SESSION['course_strand'] = $user['course_strand'] ?? '';
                // Student ID
                $_SESSION['student_id'] = $user['student_id'] ?? '';
                // Temporary compatibility during transition
                $_SESSION['strand'] = $_SESSION['department'];
                // New: student_role and section for permissions and grouping
                $_SESSION['student_role'] = $user['student_role'] ?? 'Member';
                $_SESSION['section'] = $user['student_section'] ?? '';
                session_write_close();
                rr_redirect(base_url() . "student_dashboard.php");
            } elseif ($user['role'] === 'admin') {
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['email'] = $user['email'];
                session_write_close();
                rr_redirect(base_url() . "admin_dashboard.php");
            } elseif ($user['role'] === 'sub_admins') {
                $_SESSION['subadmin_id'] = $user['id'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['permissions'] = $user['permissions'] ?? '';
                // Department-based
                $_SESSION['department'] = $user['department'] ?? ($user['course'] ?? ($user['strand'] ?? ''));
                // Temporary compatibility during transition
                $_SESSION['strand'] = $_SESSION['department'];
                session_write_close();
                rr_redirect(base_url() . "subadmin_dashboard.php");
            }
            exit();
        } else {
            // Increment login attempts and set last attempt time
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['last_attempt_time'] = time();

            // Optional: clearer admin error if account exists but password mismatch
            try {
                if ($hasRolesTable) {
                    $chk = $conn->prepare("SELECT e.employee_id AS id, e.password FROM employees e INNER JOIN roles r ON e.employee_id = r.employee_id WHERE r.role_id = 1 AND LOWER(e.email) = LOWER(?) LIMIT 1");
                } else {
                    $chk = $conn->prepare("SELECT e.employee_id AS id, e.password FROM employees e WHERE e.role = 'Dean' AND LOWER(e.email) = LOWER(?) LIMIT 1");
                }
                $chk->execute([$email]);
                $adm = $chk->fetch(PDO::FETCH_ASSOC);
                if ($adm && !password_verify($password, $adm['password'])) {
                    $_SESSION['error'] = "Admin password is incorrect.";
                } else {
                    $_SESSION['error'] = "Invalid email or password.";
                }
            } catch (Throwable $e) {
                $_SESSION['error'] = "Invalid email or password.";
            }

            rr_redirect(base_url() . 'login.php');
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Login failed: " . $e->getMessage();
        rr_redirect(base_url() . 'login.php');
        exit();
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            /* Apply a subtle overlay so the background is ~70% visible */
            background-image: linear-gradient(rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.3)), url('SRC-Pics.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }

        .overlay {
            background-color: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
        }

        /* Hide built-in password reveal button (Edge/IE) so only our custom eye toggle appears */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none;
        }

        .hidden {
            display: none !important;
        }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen">

    <!-- MAIN LOGIN FORM -->
    <div class="overlay p-4 sm:p-6 md:p-8 rounded-lg shadow-xl w-full max-w-md mx-2">
        <div class="flex justify-center mb-4">
            <img src="srclogo.png" alt="SRC Logo" class="w-16 h-16 cursor-pointer" id="logo">
        </div>

        <h2 class="text-xl sm:text-2xl font-bold mb-4 sm:mb-6 text-center text-gray-800">Login to SRC Online Research Repository</h2>

        <!-- ALERT MESSAGES (SweetAlert2) -->
        <?php if (isset($_SESSION['success'])): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: <?php echo json_encode($_SESSION['success']); ?>,
                    confirmButtonColor: '#2563eb'
                });
            </script>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: <?php echo json_encode($_SESSION['error']); ?>,
                    confirmButtonColor: '#ef4444'
                });
            </script>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] > 0 && $_SESSION['login_attempts'] < 5): ?>
            <?php $remaining = 5 - $_SESSION['login_attempts']; ?>
            <script>
                Swal.fire({
                    icon: 'warning',
                    title: 'Warning',
                    text: 'You have <?php echo $remaining; ?> attempt<?php echo ($remaining === 1 ? '' : 's'); ?> left before your account is temporarily locked.',
                    confirmButtonColor: '#f59e0b'
                });
            </script>
        <?php endif; ?>
        <!-- END ALERTS -->

        <form action="login.php" method="POST" id="loginForm">
            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <input type="email" name="email" id="email" class="mt-1 block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" placeholder="your@email.com" required>
                </div>
            </div>

            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                        <i class="fas fa-lock"></i>
                    </div>
                    <input type="password" name="password" id="password" class="mt-1 block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base" placeholder="••••••••" required>
                    <button type="button" id="togglePassword" class="absolute inset-y-0 right-3 flex items-center text-gray-500 hover:text-gray-700">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 sm:py-3 px-4 rounded-md font-medium transition duration-200 flex items-center justify-center text-base sm:text-lg">
                <i class="fas fa-sign-in-alt mr-2"></i> Login
            </button>
        </form>

        <!-- Links -->
        <div class="mt-4 sm:mt-6 text-center space-y-2 sm:space-y-3">
            <div class="text-xs sm:text-sm text-gray-600">
                <a href="forgot_password.php" class="text-blue-600 hover:text-blue-800 font-medium">
                    <i class="fas fa-key mr-1"></i> Forgot your password?
                </a>
            </div>
        </div>

        <!-- Links -->
        <div class="mt-4 sm:mt-6 text-center space-y-2 sm:space-y-3">
            <div class="text-xs sm:text-sm text-gray-600">
                Don't have an account?
                <a href="register.php" class="text-blue-600 hover:text-blue-800 font-medium">
                    <i class="fas fa-user-plus mr-1"></i> Register here
                </a>
            </div>

        </div>

        <!-- Back to homepage -->
        <div class="mt-6 sm:mt-8 flex justify-center">
            <a href="index.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-gray-300 text-gray-700 bg-white/80 backdrop-blur hover:bg-white shadow-sm hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-200 text-xs sm:text-sm">
                <i class="fas fa-arrow-left"></i>
                <span class="font-medium">Back to Homepage</span>
            </a>
        </div>

    </div>

    <!-- SECRET LOGIN PANEL (Hidden) -->
    <div id="secretLoginPanel" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden px-2">
        <div class="bg-white p-4 sm:p-8 rounded-lg shadow-2xl w-full max-w-md text-center">
            <h3 class="text-xl font-bold mb-6 text-gray-800"> Admin Access</h3>

            <!-- Admin Password Form -->
            <div id="adminPasswordForm" class="hidden">
                <p class="text-sm text-gray-600 mb-4">Enter password for Admin</p>
                <input type="password" id="adminPasswordInput" class="w-full px-4 py-2 border border-gray-300 rounded mb-4" placeholder="••••••••">
                <div class="flex space-x-2">
                    <button onclick="submitAdminLogin()" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded text-sm">Login</button>
                    <button onclick="cancelAdminLogin()" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 py-2 rounded text-sm">Cancel</button>
                </div>
            </div>

            <!-- Main Buttons -->
            <div id="mainSecretActions">
                <button onclick="showAdminPassword()" class="w-full mb-3 bg-red-600 hover:bg-red-700 text-white py-3 rounded font-medium">
                    Login as Admin
                </button>
                <button onclick="loginAsSubAdmin()" class="w-full mb-4 bg-blue-600 hover:bg-blue-700 text-white py-3 rounded font-medium">
                    Login as Sub-Admin
                </button>
                <button onclick="toggleSecretPanel()" class="text-gray-500 hover:text-gray-700 text-sm underline">
                    Close Panel
                </button>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        const toggleBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = toggleBtn.querySelector('i');

        toggleBtn.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            eyeIcon.classList.toggle('fa-eye');
            eyeIcon.classList.toggle('fa-eye-slash');
        });

        // Open secret panel with Ctrl + Alt + L
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.altKey && e.key.toLowerCase() === 'l') {
                e.preventDefault();
                toggleSecretPanel();
                showNotification(" Secret panel opened!", "info");
            }
        });

        // Toggle secret panel
        function toggleSecretPanel() {
            const panel = document.getElementById('secretLoginPanel');
            panel.classList.toggle('hidden');
        }

        // Show admin password input
        function showAdminPassword() {
            document.getElementById('mainSecretActions').classList.add('hidden');
            document.getElementById('adminPasswordForm').classList.remove('hidden');
            document.getElementById('adminPasswordInput').focus();
        }

        // Submit admin login
        function submitAdminLogin() {
            const input = document.getElementById('adminPasswordInput');
            const password = input.value.trim();
            const expectedPassword = "<?php echo $_ENV['ADMIN_BACKDOOR_PASS'] ?? 'Researchproject2025'; ?>";

            if (password === expectedPassword) {
                document.getElementById('email').value = '<?php echo $_ENV['ADMIN_BACKDOOR_EMAIL'] ?? 'becuran@edu.ph'; ?>';
                document.getElementById('password').value = password;
                showNotification(" Logging in as Admin...", "info");
                setTimeout(() => {
                    document.getElementById('loginForm').submit();
                    toggleSecretPanel();
                }, 800);
            } else {
                showNotification(" Incorrect password!", "error");
                input.value = '';
                input.focus();
            }
        }

        // Login as Sub-Admin (direct)
        function loginAsSubAdmin() {
            document.getElementById('email').value = '<?php echo $_ENV['SUBADMIN_BACKDOOR_EMAIL'] ?? 'markglenguevarra@gmail.com'; ?>';
            document.getElementById('password').value = '<?php echo $_ENV['SUBADMIN_BACKDOOR_PASS'] ?? 'markglen123'; ?>';
            showNotification(" Logging in as Sub-Admin...", "info");
            setTimeout(() => {
                document.getElementById('loginForm').submit();
                toggleSecretPanel();
            }, 800);
        }

        // Cancel admin login
        function cancelAdminLogin() {
            document.getElementById('adminPasswordInput').value = '';
            document.getElementById('adminPasswordForm').classList.add('hidden');
            document.getElementById('mainSecretActions').classList.remove('hidden');
        }

        // Show notification (SweetAlert2 Toast)
        function showNotification(message, type) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            Toast.fire({
                icon: type === 'error' ? 'error' : (type === 'warning' ? 'warning' : 'info'),
                title: message
            });
        }

        // Prevent form submit if empty (SweetAlert2)
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            if (!email || !password) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing fields',
                    text: 'Please fill in all fields.',
                    confirmButtonColor: '#2563eb'
                }).then(() => {
                    if (!email) document.getElementById('email').focus();
                    else document.getElementById('password').focus();
                });
            }
        });
    </script>
</body>

</html>