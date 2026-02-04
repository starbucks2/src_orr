<?php
include __DIR__ . '/include/session_init.php';
include 'db.php'; // Ensure this file initializes $conn

// Minimal env helper for reCAPTCHA keys
if (!function_exists('get_env_var')) {
    function get_env_var($key, $default = '')
    {
        $val = getenv($key);
        if ($val !== false && $val !== null && $val !== '') return $val;
        static $env = null;
        if ($env === null) {
            $env = [];
            $envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
            if (is_file($envPath) && is_readable($envPath)) {
                foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (!$line || $line[0] === '#') continue;
                    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                    $k = trim($k);
                    $v = trim($v, "\"' \t");
                    if ($k !== '') $env[$k] = $v;
                }
            }
        }
        return $env[$key] ?? $default;
    }
}

$RECAPTCHA_SITE_KEY = get_env_var('RECAPTCHA_SITE_KEY', '');
$RECAPTCHA_SECRET_KEY = get_env_var('RECAPTCHA_SECRET_KEY', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Match HTML input names: firstname, middlename, lastname
    $firstname = trim($_POST['firstname'] ?? '');
    $middlename = trim($_POST['middlename'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    // Support legacy field name 'lrn' but use 'student_id' moving forward
    $student_id_raw = isset($_POST['student_id']) ? trim($_POST['student_id']) : trim($_POST['lrn'] ?? '');
    // Remove all non-digits
    $sn_digits = preg_replace('/\D/', '', $student_id_raw);
    $student_id = $sn_digits; // Store as-is for now, will validate based on department
    // Department selection
    $department = isset($_POST['department']) ? trim($_POST['department']) : '';
    // Course/Strand selection (depends on department)
    $course_strand = isset($_POST['course_strand']) ? trim($_POST['course_strand']) : '';
    // Removed: grade, role, section, group_number

    // Prepare profile picture variables (file or captured image)
    $profilePic = $_FILES['profile_pic'] ?? null;
    $capturedImage = $_POST['captured_image'] ?? '';
    $profilePicName = '';
    $targetDir = 'images/';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    // Helper: store old values except password and file
    function store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department = '', $course_strand = '')
    {
        $_SESSION['old'] = [
            'firstname' => $firstname,
            'middlename' => $middlename,
            'lastname' => $lastname,
            'suffix' => $suffix,
            'email' => $email,
            'student_id' => $student_id,
            'department' => $department,
            'course_strand' => $course_strand
        ];
    }

    if (empty($firstname) || empty($lastname) || empty($email) || empty($password) || empty($student_id) || empty($department) || empty($course_strand)) {
        $_SESSION['error'] = "All fields are required.";
        store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
        header("Location: register.php");
        exit();
    }


    // Validate department from DB
    try {
        $deptStmt = $conn->prepare("SELECT department_id, department_name, code, is_active FROM departments WHERE department_name = ? LIMIT 1");
        $deptStmt->execute([$department]);
        $deptRow = $deptStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $deptRow = false;
    }
    if (!$deptRow || (int)$deptRow['is_active'] !== 1) {
        $_SESSION['error'] = "Invalid department selected.";
        store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
        header("Location: register.php");
        exit();
    }
    // Validate course/strand based on department from DB
    $isSHS = (strtolower($deptRow['department_name']) === 'senior high school' || strtolower((string)$deptRow['code']) === 'shs');
    if ($isSHS) {
        try {
            // strands by name
            $sStmt = $conn->prepare("SELECT COUNT(*) FROM strands WHERE strand = ?");
            $sStmt->execute([$course_strand]);
            if ((int)$sStmt->fetchColumn() === 0) {
                throw new Exception('Invalid strand');
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Invalid strand selected.";
            store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
            header("Location: register.php");
            exit();
        }
    } else {
        try {
            $cStmt = $conn->prepare("SELECT COUNT(*) FROM courses WHERE department_id = ? AND course_name = ? AND is_active = 1");
            $cStmt->execute([(int)$deptRow['department_id'], $course_strand]);
            if ((int)$cStmt->fetchColumn() === 0) {
                throw new Exception('Invalid course');
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Invalid course selected for the chosen department.";
            store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
            header("Location: register.php");
            exit();
        }
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format.";
        store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
        header("Location: register.php");
        exit();
    }

    if ($password !== $confirm_password) {
        $_SESSION['error'] = "Passwords do not match.";
        store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
        header("Location: register.php");
        exit();
    }

    // Validate Student ID/LRN format based on department
    if ($department === 'Senior High School') {
        // Senior High School: LRN must be exactly 12 digits
        if (!preg_match('/^\d{12}$/', $student_id)) {
            $_SESSION['error'] = "LRN NO. must be exactly 12 digits for Senior High School students.";
            store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
            header("Location: register.php");
            exit();
        }
    } else {
        // College (CCS, CBS, COE): Student ID format YY-XXXXXXX
        if (strlen($sn_digits) >= 9) {
            $student_id = substr($sn_digits, 0, 2) . '-' . substr($sn_digits, 2, 7);
        }
        if (!preg_match('/^\d{2}-\d{7}$/', $student_id)) {
            $_SESSION['error'] = "Student ID must follow the format 'YY-XXXXXXX' (e.g., 22-0002155) for college students.";
            store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
            header("Location: register.php");
            exit();
        }
    }

    try {
        // Ensure students.department exists
        try {
            $colCheckDept = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'department'");
            $colCheckDept->execute();
            if ((int)$colCheckDept->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE students ADD COLUMN department VARCHAR(50) NULL AFTER email");
            }
        } catch (Exception $e) { /* ignore */
        }
        // Ensure students.student_id exists (main column for student identification)
        try {
            $colCheckSID = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'student_id'");
            $colCheckSID->execute();
            if ((int)$colCheckSID->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE students ADD COLUMN student_id VARCHAR(32) NULL AFTER department, ADD UNIQUE KEY uniq_student_id (student_id)");
            } else {
                // If the column exists but is numeric, convert to VARCHAR to preserve dash
                $dtypeStmt = $conn->prepare("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'student_id'");
                $dtypeStmt->execute();
                $dtype = strtolower((string)$dtypeStmt->fetchColumn());
                if (in_array($dtype, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'double', 'float'])) {
                    $conn->exec("ALTER TABLE students MODIFY COLUMN student_id VARCHAR(32) NULL");
                }
            }
        } catch (Exception $e) { /* ignore */
        }

        // Ensure optional name columns exist
        try {
            $colChkMid = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'middlename'");
            $colChkMid->execute();
            if ((int)$colChkMid->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE students ADD COLUMN middlename VARCHAR(100) NULL AFTER firstname");
            }
        } catch (Exception $e) { /* ignore */
        }
        try {
            $colChkSuf = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'suffix'");
            $colChkSuf->execute();
            if ((int)$colChkSuf->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE students ADD COLUMN suffix VARCHAR(50) NULL AFTER lastname");
            }
        } catch (Exception $e) { /* ignore */
        }

        // Ensure auth/media/status columns exist
        try {
            $colChkPwd = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'password'");
            $colChkPwd->execute();
            if ((int)$colChkPwd->fetchColumn() === 0) {
                // Place password after student_id if exists, else after email
                $afterCol = 'student_id';
                $existsStudentId = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'student_id'");
                $existsStudentId->execute();
                if ((int)$existsStudentId->fetchColumn() === 0) {
                    $afterCol = 'email';
                }
                $conn->exec("ALTER TABLE students ADD COLUMN password VARCHAR(255) NOT NULL AFTER `" . $afterCol . "`");
            }
        } catch (Exception $e) { /* ignore */
        }
        try {
            $colChkPic = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'profile_pic'");
            $colChkPic->execute();
            if ((int)$colChkPic->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE students ADD COLUMN profile_pic VARCHAR(255) NULL AFTER password");
            }
        } catch (Exception $e) { /* ignore */
        }

        // Ensure rfid_number, if present, is NULL-able (not empty-string default) to avoid duplicate '' unique constraint errors
        try {
            $colChkRfid = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'rfid_number'");
            $colChkRfid->execute();
            if ((int)$colChkRfid->fetchColumn() > 0) {
                // Make it nullable with NULL default
                $conn->exec("ALTER TABLE students MODIFY COLUMN rfid_number VARCHAR(64) NULL DEFAULT NULL");
                // Normalize existing empty strings to NULL so unique index won't collide
                $conn->exec("UPDATE students SET rfid_number = NULL WHERE rfid_number = ''");
                // Ensure a unique index exists (allowing multiple NULLs)
                try {
                    $conn->exec("ALTER TABLE students ADD UNIQUE KEY uniq_rfid_number (rfid_number)");
                } catch (Throwable $_) { /* might already exist */
                }
            }
        } catch (Exception $e) { /* ignore */
        }
        try {
            $colChkVer = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'is_verified'");
            $colChkVer->execute();
            if ((int)$colChkVer->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE students ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_pic");
            }
        } catch (Exception $e) { /* ignore */
        }

        // Ensure students.course_strand column exists
        try {
            $colCheckCourse = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'course_strand'");
            $colCheckCourse->execute();
            if ((int)$colCheckCourse->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE students ADD COLUMN course_strand VARCHAR(50) NULL AFTER department");
            }
        } catch (Exception $e) { /* ignore */
        }

        $stmt = $conn->prepare("SELECT * FROM students WHERE email = ? OR student_id = ?");
        $stmt->execute([$email, $student_id]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['error'] = "Email or Student ID already exists.";
            store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
            header("Location: register.php");
            exit();
        }

        if (!preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[\W]/', $password)) {
            $_SESSION['error'] = "Password must contain at least one uppercase letter, one number, and one special character.";
            store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
            header("Location: register.php");
            exit();
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Save image either from captured base64 or uploaded file
        $imageSaved = false;
        if (!empty($capturedImage) && strpos($capturedImage, 'data:image') === 0) {
            // data:image/png;base64,....
            if (preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $capturedImage, $m)) {
                $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                $data = substr($capturedImage, strpos($capturedImage, ',') + 1);
                $data = base64_decode($data);
                if ($data !== false) {
                    $profilePicName = time() . '_captured.' . $ext;
                    $filePath = $targetDir . $profilePicName;
                    if (file_put_contents($filePath, $data) !== false) {
                        $imageSaved = true;
                    }
                }
            }
        } elseif (!empty($profilePic) && isset($profilePic['tmp_name']) && is_uploaded_file($profilePic['tmp_name'])) {
            $profilePicName = time() . '_' . basename($profilePic['name']);
            $filePath = $targetDir . $profilePicName;
            $imageSaved = move_uploaded_file($profilePic['tmp_name'], $filePath);
        }

        if (!$imageSaved) {
            $_SESSION['error'] = "Please provide a profile picture (upload or capture).";
            store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
            header("Location: register.php");
            exit();
        }

        // Insert student (pending verification) using student_id
        // Dynamically map to existing name columns to avoid 'Unknown column' errors
        $colsRes = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'");
        $colsRes->execute();
        $studentCols = $colsRes->fetchAll(PDO::FETCH_COLUMN, 0);
        $colFirst  = in_array('firstname', $studentCols, true)  ? 'firstname'  : (in_array('first_name', $studentCols, true)  ? 'first_name'  : null);
        $colMiddle = in_array('middlename', $studentCols, true) ? 'middlename' : (in_array('middle_name', $studentCols, true) ? 'middle_name' : null);
        $colLast   = in_array('lastname', $studentCols, true)   ? 'lastname'   : (in_array('last_name', $studentCols, true)   ? 'last_name'   : null);
        $colSuffix = in_array('suffix', $studentCols, true)     ? 'suffix'     : null;

        $insertCols = [];
        $insertVals = [];
        if ($colFirst) {
            $insertCols[] = $colFirst;
            $insertVals[] = $firstname;
        }
        if ($colMiddle) {
            $insertCols[] = $colMiddle;
            $insertVals[] = $middlename;
        }
        if ($colLast) {
            $insertCols[] = $colLast;
            $insertVals[] = $lastname;
        }
        if ($colSuffix) {
            $insertCols[] = $colSuffix;
            $insertVals[] = $suffix;
        }
        $insertCols[] = 'email';
        $insertVals[] = $email;
        $insertCols[] = 'department';
        $insertVals[] = $department;
        $insertCols[] = 'course_strand';
        $insertVals[] = $course_strand;
        $insertCols[] = 'student_id';
        $insertVals[] = $student_id;
        $insertCols[] = 'password';
        $insertVals[] = $hashed_password;
        $insertCols[] = 'profile_pic';
        $insertVals[] = $profilePicName;
        $insertCols[] = 'is_verified';
        $insertVals[] = 0;

        // If rfid_number column exists, set it to NULL explicitly when not provided by form
        if (in_array('rfid_number', $studentCols, true)) {
            $insertCols[] = 'rfid_number';
            $insertVals[] = null; // avoid empty-string unique collisions
        }

        $placeholders = rtrim(str_repeat('?,', count($insertCols)), ',');
        $sql = "INSERT INTO students (" . implode(',', $insertCols) . ") VALUES ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->execute($insertVals);

        unset($_SESSION['old']);
        $_SESSION['success'] = "Registration successfully! Please wait for the Teacher to verify your account.";
        header("Location: login.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Registration failed: " . $e->getMessage();
        store_old($firstname, $middlename, $lastname, $suffix, $email, $student_id, $department, $course_strand);
        header("Location: register.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Students</title>
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

        /* Hide built-in password reveal button (Edge/IE) so only our custom eye toggles appear */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none;
        }

        .glass {
            background: rgba(255, 255, 255, 0.6);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.18);
            animation: fadeIn 1s ease;
            /* Ensure reCAPTCHA challenge is not clipped on mobile */
            overflow: visible;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .custom-file-input::-webkit-file-upload-button {
            visibility: hidden;
        }

        .custom-file-input::before {
            content: 'Choose Image';
            display: inline-block;
            background: #3b82f6;
            color: white;
            border-radius: 0.375rem;
            padding: 0.5rem 1rem;
            outline: none;
            white-space: nowrap;
            cursor: pointer;
            font-size: 0.95rem;
            margin-right: 1rem;
        }

        .custom-file-input:hover::before {
            background: #2563eb;
        }

        .custom-file-input:active::before {
            background: #1d4ed8;
        }

        /* Raise z-index for reCAPTCHA widget and any bubbles/challenge */
        #recaptcha-holder,
        .g-recaptcha,
        .grecaptcha-badge,
        div[style*="z-index: 2000000000"] {
            position: relative;
            z-index: 10000;
        }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen">
    <div class="glass p-4 sm:p-8 md:p-10 w-full max-w-lg mx-2 sm:mx-auto shadow-2xl">
        <div class="flex flex-col items-center mb-4 sm:mb-6">
            <img src="srclogo.png" alt="Logo" class="w-20 h-20 sm:w-24 sm:h-24 rounded-full border-4 border-blue-500 shadow-lg mb-2 animate-bounce-slow">
            <h2 class="text-2xl sm:text-3xl font-extrabold text-blue-700 mb-1 tracking-tight text-center">Student Registration</h2>
            <p class="text-gray-600 text-center text-sm sm:text-base">Create your account to access the SRC Online Research Repository</p>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Registration Successful',
                    text: <?php echo json_encode($_SESSION['success']); ?>,
                    confirmButtonColor: '#2563eb'
                });
            </script>
            <?php unset($_SESSION['success']); ?>
        <?php elseif (isset($_SESSION['error'])): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Registration Error',
                    text: <?php echo json_encode($_SESSION['error']); ?>,
                    confirmButtonColor: '#ef4444'
                });
            </script>
            <?php unset($_SESSION['error']);
            unset($_SESSION['old']); ?>
        <?php endif; ?>

        <?php $old = isset($_SESSION['old']) ? $_SESSION['old'] : []; ?>
        <form action="register.php" method="POST" enctype="multipart/form-data" class="space-y-4 sm:space-y-5">
            <!-- Profile Picture -->
            <div class="mb-2">
                <label for="profile_pic" class="block text-sm font-semibold text-gray-800">Profile Picture</label>
                <p class="text-xs text-gray-600 mb-3">Choose a clear photo of yourself. Pick a mode below to upload or take a photo.</p>
                <!-- Mode selector to minimize UI -->
                <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-700">Mode:</span>
                        <select id="picMode" class="px-3 py-2 border rounded-md focus:ring-blue-500 focus:border-blue-500 bg-white">
                            <option value="upload" selected>Upload Image</option>
                            <option value="camera">Take Photo</option>
                        </select>
                    </div>
                </div>
                <div class="flex flex-col items-center gap-3 w-full">
                    <!-- Upload controls -->
                    <div id="uploadControls" class="flex items-center gap-3 w-full sm:w-80 justify-center">
                        <input type="file" name="profile_pic" id="profile_pic" accept="image/*" class="custom-file-input w-full text-gray-700" onchange="previewProfilePic(event)">
                    </div>
                    <!-- Camera controls (trigger button only; live view opens below) -->
                    <div id="cameraControls" class="hidden w-full sm:w-80">
                        <button type="button" id="openCameraBtn" onclick="openCamera()" class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 flex items-center justify-center gap-2 shadow-sm">
                            <i class="fas fa-camera"></i>
                            <span>Use Camera</span>
                        </button>
                    </div>
                    <div class="flex flex-col items-center gap-2">
                        <span class="inline-flex items-center justify-center rounded-full ring-4 ring-blue-400 bg-white p-1 shadow-sm">
                            <img id="profile-pic-preview" src="" alt="Preview" class="hidden w-24 h-24 sm:w-28 sm:h-28 rounded-full object-cover" />
                        </span>
                        <span id="profile-pic-name" class="text-xs text-gray-700 max-w-[16rem] sm:max-w-[20rem] truncate text-center"></span>
                    </div>
                </div>
                <input type="hidden" name="captured_image" id="captured_image" />
                <!-- Camera Modal/Area -->
                <div id="camera-area" class="hidden mt-3 p-3 border rounded-lg bg-white/80">
                    <div class="flex flex-col items-center gap-3">
                        <video id="camera-video" class="w-56 h-56 sm:w-64 sm:h-64 bg-black rounded-xl object-cover shadow-md" autoplay playsinline></video>
                        <div class="flex gap-2">
                            <button type="button" onclick="capturePhoto()" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 flex items-center gap-2 shadow-sm">
                                <i class="fas fa-camera"></i>
                                <span>Capture</span>
                            </button>
                            <button type="button" onclick="closeCamera()" class="px-4 py-2 bg-gray-200 rounded-md hover:bg-gray-300 shadow-sm">Close</button>
                        </div>
                    </div>
                    <canvas id="camera-canvas" class="hidden"></canvas>
                </div>
            </div>

            <!-- First Name & Last Name -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                <div class="relative">
                    <label for="firstname" class="block text-sm font-medium text-gray-700 mb-1">First Name <span class="text-red-500">*</span></label>
                    <span class="absolute left-3 top-9 text-gray-400"><i class="fas fa-user"></i></span>
                    <input type="text" name="firstname" id="firstname" class="mt-1 block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" required value="<?php echo isset($old['firstname']) ? htmlspecialchars($old['firstname']) : ''; ?>">
                </div>
                <div class="relative">
                    <label for="lastname" class="block text-sm font-medium text-gray-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                    <span class="absolute left-3 top-9 text-gray-400"><i class="fas fa-user"></i></span>
                    <input type="text" name="lastname" id="lastname" class="mt-1 block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" required value="<?php echo isset($old['lastname']) ? htmlspecialchars($old['lastname']) : ''; ?>">
                </div>
            </div>

            <!-- Middle Name & Suffix -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                <div class="relative">
                    <label for="middlename" class="block text-sm font-medium text-gray-700 mb-1">Middle Name (Optional)</label>
                    <span class="absolute left-3 top-9 text-gray-400"><i class="fas fa-user"></i></span>
                    <input type="text" name="middlename" id="middlename" class="mt-1 block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" value="<?php echo isset($old['middlename']) ? htmlspecialchars($old['middlename']) : ''; ?>">
                </div>
                <div class="relative">
                    <label for="suffix" class="block text-sm font-medium text-gray-700 mb-1">Suffix (Optional)</label>
                    <span class="absolute left-3 top-9 text-gray-400"><i class="fas fa-user-tag"></i></span>
                    <input type="text" name="suffix" id="suffix" class="mt-1 block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" value="<?php echo isset($old['suffix']) ? htmlspecialchars($old['suffix']) : ''; ?>">
                </div>
            </div>

            <!-- Email -->
            <div class="relative">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                <span class="absolute left-3 top-9 text-gray-400"><i class="fas fa-envelope"></i></span>
                <input type="email" name="email" id="email" class="mt-1 block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" required value="<?php echo isset($old['email']) ? htmlspecialchars($old['email']) : ''; ?>">
            </div>

            <!-- Department Selection -->
            <div class="relative">
                <label for="department" class="block text-sm font-medium text-gray-700 mb-1">Department <span class="text-red-500">*</span></label>
                <select name="department" id="department" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" required>
                    <?php $oldDept = $old['department'] ?? ''; ?>
                    <option value="" <?= empty($oldDept) ? 'selected' : '' ?>>Select Department</option>
                </select>
            </div>

            <!-- Course/Strand Selection (Dynamic based on Department) -->
            <div class="relative" id="course-strand-container" style="display: none;">
                <label for="course_strand" class="block text-sm font-medium text-gray-700 mb-1">
                    <span id="course-strand-label">Course/Strand</span> <span class="text-red-500">*</span>
                </label>
                <select name="course_strand" id="course_strand" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" required>
                    <?php $oldCS = $old['course_strand'] ?? ''; ?>
                    <option value="" <?= empty($oldCS) ? 'selected' : '' ?>>Select Course/Strand</option>
                </select>
            </div>

            <!-- Student ID / LRN NO. (Dynamic label based on department) -->
            <div class="relative">
                <label for="lrn" class="block text-sm font-medium text-gray-700 mb-1">
                    <span id="student-id-label">Student ID</span> <span class="text-red-500">*</span>
                </label>
                <span class="absolute left-3 top-9 text-gray-400"><i class="fas fa-id-card"></i></span>
                <input type="text" name="lrn" id="lrn" inputmode="numeric" placeholder="YY-XXXXXXX" class="mt-1 block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" required oninput="validateLRN(this)" value="<?php echo isset($old['student_id']) ? htmlspecialchars($old['student_id']) : ''; ?>">
                <span id="lrn-error" class="text-xs text-red-600 mt-1 block"></span>
            </div>

            <!-- Password -->
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                <div class="relative mt-1">
                    <span class="absolute inset-y-0 left-3 flex items-center text-gray-400 pointer-events-none"><i class="fas fa-lock"></i></span>
                    <input type="password" name="password" id="password" class="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" required>
                    <button type="button" id="togglePassword" class="absolute inset-y-0 right-3 flex items-center text-gray-500 hover:text-blue-600 focus:outline-none">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
            </div>
            <!-- Password Strength Indicator -->
            <div id="password-strength-indicator" class="h-2 w-full bg-gray-200 rounded-full mt-2">
                <div id="password-strength-bar" class="h-full rounded-full transition-all" style="width: 0%;"></div>
            </div>
            <p id="password-strength-text" class="text-xs text-center mt-1"></p>

            <!-- Confirm Password -->
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                <div class="relative mt-1">
                    <span class="absolute inset-y-0 left-3 flex items-center text-gray-400 pointer-events-none"><i class="fas fa-lock"></i></span>
                    <input type="password" name="confirm_password" id="confirm_password" class="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 transition" required>
                    <button type="button" id="toggleConfirmPassword" class="absolute inset-y-0 right-3 flex items-center text-gray-500 hover:text-blue-600 focus:outline-none">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
            </div>
            <p id="confirm-password-match" class="text-xs text-center mt-1"></p>



            <!-- Register Button -->
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 sm:py-3 px-4 rounded-md font-semibold text-base sm:text-lg shadow-md transition duration-200 flex items-center justify-center gap-2">
                <i class="fas fa-user-plus"></i> Register
            </button>
        </form>
        <p class="mt-4 sm:mt-6 text-center text-xs sm:text-sm text-gray-600">
            Have an account? <a href="login.php" class="text-blue-600 hover:text-blue-800 font-medium">Login here</a>.
        </p>

        <!-- Back to homepage -->
        <div class="mt-6 sm:mt-8 flex justify-center">
            <a href="index.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-gray-300 text-gray-700 bg-white/80 backdrop-blur hover:bg-white shadow-sm hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-200 text-xs sm:text-sm">
                <i class="fas fa-arrow-left"></i>
                <span class="font-medium">Back to Homepage</span>
            </a>
        </div>
    </div>

    <script>
        // Profile picture preview
        function previewProfilePic(event) {
            const input = event.target;
            const preview = document.getElementById('profile-pic-preview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.src = '';
                preview.classList.add('hidden');
            }
        }

        // Student ID/LRN validation: format depends on department
        function validateLRN(input) {
            const departmentSelect = document.getElementById('department');
            const department = departmentSelect ? departmentSelect.value : '';
            const error = document.getElementById('lrn-error');
            const prev = input.value;
            let digits = prev.replace(/\D/g, '');

            if (department === 'Senior High School') {
                // Senior High School: 12 digits LRN, no formatting
                digits = digits.slice(0, 12);
                input.value = digits;

                if (digits.length === 0) {
                    error.textContent = '';
                } else if (digits.length < 12) {
                    error.textContent = `LRN NO. must be 12 digits (${digits.length}/12)`;
                } else {
                    error.textContent = '';
                }
            } else if (department && department !== '') {
                // College: YY-XXXXXXX format (9 digits total)
                digits = digits.slice(0, 9);
                let formatted = digits;
                if (digits.length > 2) {
                    formatted = digits.slice(0, 2) + '-' + digits.slice(2, 9);
                }
                input.value = formatted;

                if (formatted.length === 0) {
                    error.textContent = '';
                } else if (!/^\d{2}-\d{7}$/.test(formatted)) {
                    error.textContent = "Format: YY-XXXXXXX (e.g., 22-0002155)";
                } else {
                    error.textContent = '';
                }
            } else {
                // No department selected yet
                input.value = digits.slice(0, 12);
                error.textContent = 'Please select a department first';
            }
        }

        // Toggle password visibility for main password (guarded)
        const toggleBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        if (toggleBtn && passwordInput) {
            const eyeIcon = toggleBtn.querySelector('i');
            toggleBtn.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                if (eyeIcon) {
                    eyeIcon.classList.toggle('fa-eye');
                    eyeIcon.classList.toggle('fa-eye-slash');
                }
            });
        }

        // Toggle password visibility for confirm password
        const toggleConfirmBtn = document.getElementById('toggleConfirmPassword');
        const confirmPasswordInput = document.getElementById('confirm_password');
        if (toggleConfirmBtn && confirmPasswordInput) {
            const confirmEyeIcon = toggleConfirmBtn.querySelector('i');
            toggleConfirmBtn.addEventListener('click', function() {
                const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPasswordInput.setAttribute('type', type);
                if (confirmEyeIcon) {
                    confirmEyeIcon.classList.toggle('fa-eye');
                    confirmEyeIcon.classList.toggle('fa-eye-slash');
                }
            });
        }

        // Password match check
        const matchMsg = document.getElementById('confirm-password-match');

        function checkPasswordMatch() {
            if (!confirmPasswordInput.value) {
                matchMsg.textContent = '';
                matchMsg.className = 'text-xs text-center mt-1';
                return;
            }
            if (passwordInput.value === confirmPasswordInput.value) {
                matchMsg.textContent = '✔ Passwords match';
                matchMsg.className = 'text-xs text-center mt-1 text-green-600';
            } else {
                matchMsg.textContent = '✖ Passwords do not match';
                matchMsg.className = 'text-xs text-center mt-1 text-red-600';
            }
        }
        if (passwordInput) passwordInput.addEventListener('input', checkPasswordMatch);
        if (confirmPasswordInput) confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        // Profile picture preview
        function previewProfilePic(event) {
            try {
                const input = event.target;
                const preview = document.getElementById('profile-pic-preview');
                const nameEl = document.getElementById('profile-pic-name');
                const capturedInput = document.getElementById('captured_image');
                if (capturedInput) capturedInput.value = '';

                if (input.files && input.files[0]) {
                    const file = input.files[0];
                    // Prefer fast preview via object URL
                    if (window.URL && URL.createObjectURL) {
                        const objUrl = URL.createObjectURL(file);
                        preview.src = objUrl;
                        preview.onload = () => {
                            try {
                                URL.revokeObjectURL(objUrl);
                            } catch (_) {}
                        };
                        preview.classList.remove('hidden');
                    } else {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.src = e.target.result;
                            preview.classList.remove('hidden');
                        };
                        reader.readAsDataURL(file);
                    }
                    if (nameEl) nameEl.textContent = file.name || '';
                } else {
                    preview.src = '';
                    preview.classList.add('hidden');
                    if (nameEl) nameEl.textContent = '';
                }
            } catch (err) {
                console.error('Preview error:', err);
            }
        }


        // Password strength checker
        const strengthBar = document.getElementById('password-strength-bar');
        const strengthText = document.getElementById('password-strength-text');

        passwordInput.addEventListener('input', function() {
            const password = passwordInput.value;
            let score = 0;
            if (password.length >= 8) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[a-z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^A-Za-z0-9]/.test(password)) score++;

            let strength = {
                width: '0%',
                color: '',
                text: ''
            };

            switch (score) {
                case 0:
                case 1:
                    strength = {
                        width: '20%',
                        color: 'bg-red-500',
                        text: 'Very Weak'
                    };
                    break;
                case 2:
                    strength = {
                        width: '40%',
                        color: 'bg-orange-500',
                        text: 'Weak'
                    };
                    break;
                case 3:
                    strength = {
                        width: '60%',
                        color: 'bg-yellow-500',
                        text: 'Medium'
                    };
                    break;
                case 4:
                    strength = {
                        width: '80%',
                        color: 'bg-blue-500',
                        text: 'Strong'
                    };
                    break;
                case 5:
                    strength = {
                        width: '100%',
                        color: 'bg-green-500',
                        text: 'Very Strong'
                    };
                    break;
            }

            if (password.length === 0) {
                strength = {
                    width: '0%',
                    color: '',
                    text: ''
                };
            }

            strengthBar.style.width = strength.width;
            strengthBar.className = `h-full rounded-full transition-all ${strength.color}`;
            strengthText.textContent = strength.text;
        });

        // Camera controls
        let mediaStream = null;
        const cameraArea = document.getElementById('camera-area');
        const video = document.getElementById('camera-video');
        const canvas = document.getElementById('camera-canvas');
        const capturedInput = document.getElementById('captured_image');

        async function openCamera() {
            try {
                cameraArea.classList.remove('hidden');
                mediaStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'user'
                    },
                    audio: false
                });
                video.srcObject = mediaStream;
            } catch (err) {
                Swal && Swal.fire({
                    icon: 'error',
                    title: 'Camera Error',
                    text: 'Cannot access camera: ' + err.message
                });
            }
        }

        function closeCamera() {
            cameraArea.classList.add('hidden');
            if (mediaStream) {
                mediaStream.getTracks().forEach(t => t.stop());
                mediaStream = null;
            }
        }

        function capturePhoto() {
            const w = video.videoWidth || 480;
            const h = video.videoHeight || 480;
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, w, h);
            const dataUrl = canvas.toDataURL('image/png');
            capturedInput.value = dataUrl;
            // Show preview
            const preview = document.getElementById('profile-pic-preview');
            preview.src = dataUrl;
            preview.classList.remove('hidden');
            const nameEl = document.getElementById('profile-pic-name');
            if (nameEl) nameEl.textContent = 'Captured image';
            // Clear file input to prefer captured image
            const fileInput = document.getElementById('profile_pic');
            if (fileInput) fileInput.value = '';
            closeCamera();
        }
        window.openCamera = openCamera;
        window.closeCamera = closeCamera;
        window.capturePhoto = capturePhoto;

        // Picture mode toggle (Upload vs Camera)
        (function setupPictureModeToggle() {
            const modeSel = document.getElementById('picMode');
            const uploadControls = document.getElementById('uploadControls');
            const cameraControls = document.getElementById('cameraControls');
            const cameraArea = document.getElementById('camera-area');
            const fileInput = document.getElementById('profile_pic');
            const nameEl = document.getElementById('profile-pic-name');
            const preview = document.getElementById('profile-pic-preview');
            const capturedInput = document.getElementById('captured_image');

            function applyPicMode() {
                const mode = (modeSel && modeSel.value) || 'upload';
                if (mode === 'upload') {
                    // Show file upload, hide camera UI
                    if (uploadControls) uploadControls.classList.remove('hidden');
                    if (cameraControls) cameraControls.classList.add('hidden');
                    if (cameraArea) cameraArea.classList.add('hidden');
                    closeCamera();
                    // Clear captured data when returning to upload mode
                    if (capturedInput) capturedInput.value = '';
                } else {
                    // Show camera button, hide file input
                    if (uploadControls) uploadControls.classList.add('hidden');
                    if (cameraControls) cameraControls.classList.remove('hidden');
                    // Clear file input filename and value to avoid confusion
                    if (fileInput) fileInput.value = '';
                    if (nameEl && nameEl.textContent && nameEl.textContent !== 'Captured image') nameEl.textContent = '';
                    // Do not auto-open the camera; user will click the button
                }
            }

            if (modeSel) {
                modeSel.addEventListener('change', applyPicMode);
                // Initialize state
                applyPicMode();
            }
        })();

        // Dynamic Course/Strand Selection based on Department (DB-backed)
        (async function setupDynamicSelections() {
            const deptSel = document.getElementById('department');
            const csContainer = document.getElementById('course-strand-container');
            const csSel = document.getElementById('course_strand');
            const csLabel = document.getElementById('course-strand-label');
            const studentIdLabel = document.getElementById('student-id-label');
            const oldDept = <?php echo json_encode($old['department'] ?? ''); ?>;
            const oldCS = <?php echo json_encode($old['course_strand'] ?? ''); ?>;

            async function loadDepartments() {
                try {
                    const res = await fetch('include/get_departments.php');
                    const json = await res.json();
                    if (!json.ok) throw new Error(json.error || 'Failed to load departments');
                    const keep = deptSel.value;
                    deptSel.innerHTML = '<option value="">Select Department</option>';
                    json.data.forEach(d => {
                        const opt = document.createElement('option');
                        opt.value = d.name;
                        opt.textContent = d.name;
                        opt.dataset.deptId = d.id;
                        if ((oldDept && d.name === oldDept) || (!oldDept && keep && d.name === keep)) opt.selected = true;
                        deptSel.appendChild(opt);
                    });
                } catch (e) {
                    console.error(e);
                }
            }

            async function loadStrands() {
                try {
                    const res = await fetch('include/get_strands.php');
                    const json = await res.json();
                    if (!json.ok) throw new Error(json.error || 'Failed to load strands');
                    csSel.innerHTML = '<option value="">Select Strand</option>';
                    json.data.forEach(s => {
                        const opt = document.createElement('option');
                        opt.value = s.strand;
                        opt.textContent = s.strand;
                        if (oldCS && s.strand === oldCS) opt.selected = true;
                        csSel.appendChild(opt);
                    });
                } catch (e) {
                    console.error(e);
                }
            }

            async function loadCoursesForDepartmentId(deptId) {
                try {
                    const res = await fetch('include/get_courses.php?department_id=' + encodeURIComponent(deptId));
                    const json = await res.json();
                    if (!json.ok) throw new Error(json.error || 'Failed to load courses');
                    csSel.innerHTML = '<option value="">Select Course</option>';
                    json.data.forEach(c => {
                        const opt = document.createElement('option');
                        opt.value = c.name;
                        opt.textContent = c.name + (c.code ? ` (${c.code})` : '');
                        if (oldCS && c.name === oldCS) opt.selected = true;
                        csSel.appendChild(opt);
                    });
                } catch (e) {
                    console.error(e);
                }
            }

            async function onDepartmentChange() {
                const opt = deptSel.options[deptSel.selectedIndex];
                const deptId = opt ? opt.dataset.deptId : '';
                const deptName = opt ? opt.value : '';
                if (!deptId) {
                    csContainer.style.display = 'none';
                    csSel.required = false;
                    csSel.innerHTML = '<option value="">Select Course/Strand</option>';
                    if (studentIdLabel) studentIdLabel.textContent = 'Student ID';
                    return;
                }
                csContainer.style.display = '';
                csSel.required = true;
                if (deptName.toLowerCase() === 'senior high school') {
                    csLabel.textContent = 'Strand';
                    if (studentIdLabel) studentIdLabel.textContent = 'LRN NO.';
                    await loadStrands();
                } else {
                    csLabel.textContent = 'Course';
                    if (studentIdLabel) studentIdLabel.textContent = 'Student ID';
                    await loadCoursesForDepartmentId(deptId);
                }
                // Reset oldCS after first render to avoid sticky selection on mode switch
            }

            await loadDepartments();
            await onDepartmentChange();
            deptSel.addEventListener('change', onDepartmentChange);
        })();
    </script>
</body>

</html>