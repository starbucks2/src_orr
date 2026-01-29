<?php
// Centralized secure session initialization (PHP 8.1+ safe)
include __DIR__ . '/include/session_init.php';

// Allow admin or sub-admin with permission
$is_admin = isset($_SESSION['admin_id']);
$is_subadmin = isset($_SESSION['subadmin_id']);
$can_upload = false;

if ($is_admin) {
    $can_upload = true;
} elseif ($is_subadmin) {
    $permissions = json_decode($_SESSION['permissions'] ?? '[]', true);
    if (in_array('upload_research', $permissions)) {
        $can_upload = true;
    }
}

if (!$can_upload) {
    $_SESSION['error'] = "Unauthorized access.";
    header("Location: " . ($is_subadmin ? "subadmin_dashboard.php" : "login.php"));
    exit();
}

// Always include DB for GET to pre-populate selects (for both admin and subadmin)
try { include_once 'db.php'; } catch (Throwable $_) {}

// Server-side fallback data for selects
$server_departments = [];
$server_year_spans = [];
// Strand-specific server data
$server_strands = [];
$server_strand_courses = [];
// Preselected values (retain user input on validation error)
$preselectedYearPHP = isset($_POST['year']) ? (string)$_POST['year'] : '';
$preselectedDeptPHP = isset($_POST['department']) ? (string)$_POST['department'] : '';
$preselectedCoursePHP = isset($_POST['course_strand']) ? (string)$_POST['course_strand'] : '';
// Preselected strand (for SHS)
$preselectedStrandPHP = isset($_POST['strand']) ? (string)$_POST['strand'] : '';
// Server-side fallback for course/strand based on selected department
$server_course_strand_options = [];
try {
    $effectiveDept = $preselectedDeptPHP !== '' ? $preselectedDeptPHP : (isset($_SESSION['department']) ? (string)$_SESSION['department'] : '');
    if ($effectiveDept !== '' && isset($conn)) {
        // Lookup department id and code
        $dstmt = $conn->prepare("SELECT department_id AS id, name, code FROM departments WHERE name = ? LIMIT 1");
        $dstmt->execute([$effectiveDept]);
        $drow = $dstmt->fetch(PDO::FETCH_ASSOC);
        if ($drow) {
            $isSHS = (strtolower((string)$drow['name']) === 'senior high school') || (strtolower((string)$drow['code']) === 'shs');
            if ($isSHS) {
                // Load strands and, if a strand was chosen, load its courses
                try {
                    try {
                        $s = $conn->query('SELECT strand_id AS id, strand FROM strands ORDER BY strand');
                    } catch (Throwable $e2) {
                        $s = $conn->query('SELECT id AS id, strand FROM strands ORDER BY strand');
                    }
                    $server_strands = $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
                    // If a specific strand is preselected, load its courses
                    $selStrandName = $preselectedStrandPHP;
                    if ($selStrandName !== '' && $server_strands) {
                        $sid = null;
                        foreach ($server_strands as $sr) {
                            if (strcasecmp((string)$sr['strand'], $selStrandName) === 0) { $sid = (int)$sr['id']; break; }
                        }
                        if ($sid) {
                            $c = $conn->prepare('SELECT course_name AS name FROM courses WHERE strand_id = ? AND is_active = 1 ORDER BY course_name');
                            $c->execute([$sid]);
                            $server_strand_courses = $c ? array_map(function($r){ return (string)$r['name']; }, $c->fetchAll(PDO::FETCH_ASSOC)) : [];
                        }
                    }
                    // Do not use $server_course_strand_options for SHS path
                    $server_course_strand_options = [];
                } catch (Throwable $_) {}
            } else {
                // Load courses for department id
                try {
                    $c = $conn->prepare('SELECT course_name AS name, course_code AS code FROM courses WHERE department_id = ? AND is_active = 1 ORDER BY course_name');
                    $c->execute([(int)$drow['id']]);
                    $server_course_strand_options = $c ? $c->fetchAll(PDO::FETCH_ASSOC) : [];
                } catch (Throwable $_) {}
            }
        }
    }
} catch (Throwable $_) {}
try {
    if (isset($conn)) {
        // Departments
        try {
            $stmtDept = $conn->query("SELECT department_id AS id, name, code FROM departments WHERE is_active = 1 ORDER BY name");
        } catch (Throwable $e1) {
            $stmtDept = $conn->query("SELECT id AS id, name, code FROM departments WHERE is_active = 1 ORDER BY name");
        }
        $server_departments = $stmtDept ? $stmtDept->fetchAll(PDO::FETCH_ASSOC) : [];

        // Academic Years (ensure table similar to API)
        $conn->exec("CREATE TABLE IF NOT EXISTS academic_years (id INT AUTO_INCREMENT PRIMARY KEY, span VARCHAR(15) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $count = (int)$conn->query('SELECT COUNT(*) FROM academic_years')->fetchColumn();
        if ($count === 0) {
            $cur = (int)date('Y');
            $ins = $conn->prepare('INSERT IGNORE INTO academic_years(span, is_active) VALUES(?, 1)');
            for ($y = $cur + 1; $y >= 2000; $y--) {
                $span = ($y-1) . '-' . $y;
                $ins->execute([$span]);
            }
        }
        $stmtYears = $conn->query("SELECT span FROM academic_years WHERE is_active = 1 ORDER BY SUBSTRING_INDEX(span,'-',1) DESC");
        $server_year_spans = $stmtYears ? $stmtYears->fetchAll(PDO::FETCH_COLUMN, 0) : [];
        
        // Build courses by department map (dept name => [{name, code}, ...])
        $courses_by_department_map = [];
        try {
            $q = "SELECT d.name AS dept_name, d.code AS dept_code, c.course_name AS name, c.course_code AS code
                  FROM departments d
                  JOIN courses c ON c.department_id = d.department_id
                  WHERE d.is_active = 1 AND c.is_active = 1
                  ORDER BY d.name, c.course_name";
            $stmt = $conn->query($q);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $_) {
            $rows = [];
            try {
                $q = "SELECT d.name AS dept_name, d.code AS dept_code, c.course_name AS name, c.course_code AS code
                      FROM departments d
                      JOIN courses c ON c.department_id = d.id
                      WHERE d.is_active = 1 AND c.is_active = 1
                      ORDER BY d.name, c.course_name";
                $stmt = $conn->query($q);
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $__) {}
        }
        foreach ($rows as $r) {
            $dn = (string)$r['dept_name'];
            if (!isset($courses_by_department_map[$dn])) $courses_by_department_map[$dn] = [];
            $courses_by_department_map[$dn][] = [
                'name' => (string)$r['name'],
                'code' => $r['code'] !== null ? (string)$r['code'] : ''
            ];
        }

        // Build strands list and courses by strand map (strand name => [{name, code}, ...])
        try {
            try {
                $s = $conn->query('SELECT strand_id AS id, strand FROM strands ORDER BY strand');
            } catch (Throwable $e2) {
                $s = $conn->query('SELECT id AS id, strand FROM strands ORDER BY strand');
            }
            $server_strands = $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $_) { /* ignore */ }
        $courses_by_strand_map = [];
        try {
            $q = "SELECT s.strand AS strand, c.course_name AS name, c.course_code AS code
                  FROM strands s
                  JOIN courses c ON c.strand_id = s.strand_id
                  WHERE c.is_active = 1
                  ORDER BY s.strand, c.course_name";
            $cs = $conn->query($q);
            $rows2 = $cs ? $cs->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $_) {
            $rows2 = [];
        }
        foreach ($rows2 as $r) {
            $sn = (string)$r['strand'];
            if (!isset($courses_by_strand_map[$sn])) $courses_by_strand_map[$sn] = [];
            $courses_by_strand_map[$sn][] = [
                'name' => (string)$r['name'],
                'code' => $r['code'] !== null ? (string)$r['code'] : ''
            ];
        }
    }
} catch (Throwable $_) { /* ignore, JS will try via fetch */ }

// Handle form submission
$message = '';
$message_type = ''; // 'success' or 'error'

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    include 'db.php';

    // Safely read inputs to avoid undefined index notices on PHP 8.1+
    $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
    $year = isset($_POST['year']) ? trim((string)$_POST['year']) : '';
    $abstract = isset($_POST['abstract']) ? trim((string)$_POST['abstract']) : '';
    $author = isset($_POST['author']) ? trim((string)$_POST['author']) : '';
    // Use department field (replaces legacy 'strand')
    $department = isset($_POST['department']) ? trim((string)$_POST['department']) : '';
    $course_strand = isset($_POST['course_strand']) ? trim((string)$_POST['course_strand']) : '';
    // For SHS, strand is selected separately
    $strand_name = isset($_POST['strand']) ? trim((string)$_POST['strand']) : '';
    $keywords = isset($_POST['keywords']) ? trim((string)$_POST['keywords']) : '';
    $status = 1; // Approved

    // Default department to session department for sub-admins if not provided
    if ($department === '' && isset($_SESSION['subadmin_id']) && !empty($_SESSION['department'])) {
        $department = (string)$_SESSION['department'];
    }

    // Validate required fields with better feedback
    $missing = [];
    if ($title === '') { $missing[] = 'Title'; }
    if ($year === '') { $missing[] = 'Academic/School Year'; }
    if ($abstract === '') { $missing[] = 'Abstract'; }
    if ($author === '') { $missing[] = 'Author(s)'; }
    if ($department === '') { $missing[] = 'Department'; }
    if (!empty($missing)) {
        $message = 'Please fill the following: ' . implode(', ', $missing) . '.';
        $message_type = 'error';
    } else {
        $image = '';
        $document = '';

        // Validate Department and Course/Strand against DB (Option C hybrid)
        try {
            require_once 'db.php';
            $deptStmt = $conn->prepare("SELECT department_id, name, code, is_active FROM departments WHERE name = ? LIMIT 1");
            $deptStmt->execute([$department]);
            $deptRow = $deptStmt->fetch(PDO::FETCH_ASSOC);
            if (!$deptRow || (int)$deptRow['is_active'] !== 1) {
                $message = "Invalid department selected.";
                $message_type = 'error';
            } else {
                $isSHS = (strtolower($deptRow['name']) === 'senior high school' || strtolower((string)$deptRow['code']) === 'shs');
                if ($isSHS) {
                    // Validate selected strand name and map to strand_id
                    $sid = null;
                    try {
                        $sStmt = $conn->prepare("SELECT strand_id FROM strands WHERE strand = ? LIMIT 1");
                        $sStmt->execute([$strand_name !== '' ? $strand_name : $course_strand]);
                        $sid = (int)($sStmt->fetchColumn());
                    } catch (Throwable $_) { $sid = null; }
                    if (!$sid) {
                        $message = "Invalid or missing strand.";
                        $message_type = 'error';
                    } else if ($course_strand !== '') {
                        // If a course is chosen, ensure it belongs to this strand
                        $cStmt = $conn->prepare("SELECT COUNT(*) FROM courses WHERE strand_id = ? AND course_name = ? AND is_active = 1");
                        $cStmt->execute([$sid, $course_strand]);
                        if ((int)$cStmt->fetchColumn() === 0) {
                            $message = "Invalid course selected for the chosen strand.";
                            $message_type = 'error';
                        }
                    }
                } else {
                    // Validate course belongs to department
                    $cStmt = $conn->prepare("SELECT COUNT(*) FROM courses WHERE department_id = ? AND course_name = ? AND is_active = 1");
                    $cStmt->execute([(int)$deptRow['department_id'], $course_strand]);
                    if ((int)$cStmt->fetchColumn() === 0) {
                        $message = "Invalid course selected for the chosen department.";
                        $message_type = 'error';
                    }
                }
            }
        } catch (Throwable $e) {
            // If validation fails due to DB error, provide message
            if (!$message) { $message = 'Validation error: ' . htmlspecialchars($e->getMessage()); $message_type = 'error'; }
        }

        // Prepare upload directories
        $uploadsBase = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
        $imgDir = $uploadsBase . DIRECTORY_SEPARATOR . 'research_images';
        $docDir = $uploadsBase . DIRECTORY_SEPARATOR . 'research_documents';
        if (!is_dir($uploadsBase)) { @mkdir($uploadsBase, 0755, true); }
        if (!is_dir($imgDir)) { @mkdir($imgDir, 0755, true); }
        if (!is_dir($docDir)) { @mkdir($docDir, 0755, true); }
        // Attempt to ensure writability (best-effort, ignore failures on restrictive hosts)
        if (!is_writable($docDir)) { @chmod($docDir, 0755); }

        // Upload image (optional)
        if (!empty($_FILES['image']['name'])) {
            if (isset($_FILES['image']['error']) && $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $message = 'Image upload error (code ' . (int)$_FILES['image']['error'] . ').';
                $message_type = 'error';
            } else {
                $imgName = $_FILES['image']['name'];
                $imgTmp = $_FILES['image']['tmp_name'];
                $imgSize = (int)$_FILES['image']['size'];
                $imgExt = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];

                // MIME check for image
                $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
                $mime = $finfo ? finfo_file($finfo, $imgTmp) : null;
                if ($finfo) finfo_close($finfo);
                $allowedMime = ['image/jpeg','image/png','image/gif'];

                if (in_array($imgExt, $allowedExts) && ($mime === null || in_array($mime, $allowedMime)) && $imgSize < 10 * 1024 * 1024) {
                    $destRel = 'uploads/research_images/' . uniqid('img_') . '.' . $imgExt;
                    $destAbs = $imgDir . DIRECTORY_SEPARATOR . basename($destRel);
                    if (!move_uploaded_file($imgTmp, $destAbs)) {
                        $message = 'Failed to save image to disk.';
                        $message_type = 'error';
                    } else {
                        // store relative web path
                        $image = $destRel;
                    }
                } else {
                    $message = "Invalid image file. Use JPG, PNG, GIF under 10MB.";
                    $message_type = 'error';
                }
            }
        }

        // Upload PDF document (optional)
        $message_doc_warning = '';
        if (!empty($_FILES['document']['name'])) {
            if (isset($_FILES['document']['error']) && $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                // Treat as warning; proceed without document
                $message_doc_warning = 'Document upload error (code ' . (int)$_FILES['document']['error'] . '). Saved without PDF.';
                $document = '';
            } else {
                $docName = $_FILES['document']['name'];
                $docTmp = $_FILES['document']['tmp_name'];
                $docSize = (int)$_FILES['document']['size'];
                $docExt = strtolower(pathinfo($docName, PATHINFO_EXTENSION));

                // MIME check for PDF
                $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
                $mime = $finfo ? finfo_file($finfo, $docTmp) : null;
                if ($finfo) finfo_close($finfo);

                // Accept common PDF MIME variants and octet-stream when extension is .pdf (hosts vary)
                $allowedPdfMimes = [
                    'application/pdf',
                    'application/x-pdf',
                    'application/acrobat',
                    'application/nappdf',
                    'application/octet-stream', // some servers label uploads this way
                ];
                $isPdfMimeOk = ($mime === null) || in_array(strtolower($mime), $allowedPdfMimes, true);

                if ($docExt === 'pdf' && $isPdfMimeOk && $docSize < 25 * 1024 * 1024) {
                    // Check if a research with same title exists in books (case-insensitive)
                    $check_stmt = $conn->prepare("SELECT book_id FROM books WHERE LOWER(TRIM(title)) = LOWER(TRIM(?)) AND status = 1");
                    $check_stmt->execute([$title]);
                    if ($check_stmt->rowCount() > 0) {
                        $message = "A research with this title already exists in the repository. Please use a different title.";
                        $message_type = 'error';
                    } else {
                        // Create unique filename using timestamp and sanitized title
                        $safe_title = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
                        $fileBase = time() . '_' . $safe_title . '_' . bin2hex(random_bytes(4));
                        $destRel = 'uploads/research_documents/' . $fileBase . '.pdf';
                        $destAbs = $docDir . DIRECTORY_SEPARATOR . ($fileBase . '.pdf');
                        if (!move_uploaded_file($docTmp, $destAbs)) {
                            // Treat as warning; proceed without document
                            $message_doc_warning = 'Failed to upload document to disk. Saved without PDF.';
                            $document = '';
                        } else {
                            $document = $destRel; // store relative web path
                        }
                    }
                } else {
                    // Treat as warning; proceed without document
                    $message_doc_warning = "Invalid PDF or file too large (limit 25MB). Saved without PDF.";
                    $document = '';
                }
            }
        }

        // Insert into database if no errors
        if (!$message) {
            try {
                // For admin uploads, set student_id to NULL and adviser_id to current admin
                $adviser_id = $_SESSION['admin_id'] ?? null;
                $stmt = $conn->prepare("INSERT INTO books (student_id, adviser_id, title, year, abstract, keywords, authors, department, course_strand, document, image, status) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$adviser_id, $title, $year, $abstract, $keywords, $author, $department, $course_strand, $document, $image]);

                // Activity log
                require_once __DIR__ . '/include/activity_log.php';
                log_activity($conn, 'admin', $_SESSION['admin_id'] ?? null, 'upload_research', [
                    'title' => $title,
                    'department' => $department,
                    'year' => $year,
                    'document' => $document,
                ]);
                $message = "Research project uploaded successfully!" . ($message_doc_warning ? " Note: $message_doc_warning" : "");
                $message_type = 'success';
                $_POST = []; // Clear form
            } catch (PDOException $e) {
                $message = "Database error: " . htmlspecialchars($e->getMessage());
                $message_type = 'error';
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
    <title>Upload Research | SRC Research Repository</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        }
        .card-hover:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .upload-card {
            border: 2px dashed #3b82f6;
        }
        .upload-card:hover {
            border-color: #1d4ed8;
            background-color: #eff6ff;
        }
        .section-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 2px;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex">

    <!-- Include Sidebar -->
    <?php include 'admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-8">
        <h1 class="text-3xl font-bold text-gray-800">Upload Research</h1>

        <!-- Message Output -->
        <?php if ($message): ?>
            <div id="alert-message" class="relative mb-6">
                <div class="flex items-center p-4 rounded-lg shadow-md text-white 
                    <?php echo $message_type === 'success' ? 'bg-green-500' : 'bg-red-500'; ?>">
                    <i class="fas fa-bell mr-3"></i>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
                <button onclick="document.getElementById('alert-message').remove()" 
                        class="absolute top-0 right-0 m-2 text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <script>
                // Auto-hide message after 5 seconds
                setTimeout(function() {
                    const alert = document.getElementById('alert-message');
                    if (alert) {
                        alert.style.transition = "opacity 0.5s ease";
                        alert.style.opacity = "0";
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 5000);
            </script>
        <?php endif; ?>

        <!-- Upload Research Form -->
        <section class="bg-white p-6 rounded-2xl shadow-lg card-hover transition-all duration-300 border border-gray-100 upload-card">
            <div class="flex items-center mb-6">
                <div class="bg-blue-100 p-2 rounded-xl mr-3">
                    <i class="fas fa-cloud-upload-alt text-blue-600 text-xl"></i>
                </div>
                <h3 class="section-header text-2xl font-bold text-gray-800 relative">Upload Research</h3>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <!-- Title & Year -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Research Title *</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-book"></i>
                            </div>
                            <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                                   class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="Enter research title" required>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Academic/School Year *</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <select name="year" id="year_select" required class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <?php if (!empty($server_year_spans)): ?>
                                    <option value="" disabled <?php echo $preselectedYearPHP === '' ? 'selected' : ''; ?>>Select Academic/School Year</option>
                                    <?php
                                    // Default prefix A.Y., will be adjusted by JS when department changes
                                    foreach ($server_year_spans as $span) {
                                        $label = 'A.Y. ' . $span;
                                        $sel = ($preselectedYearPHP === $label) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($label, ENT_QUOTES) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
                                    }
                                    ?>
                                <?php else: ?>
                                    <option value="">Loading years...</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Abstract -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Abstract *</label>
                    <textarea name="abstract" rows="4"
                              class="w-full px-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="Enter a brief summary of your research..." required><?= htmlspecialchars($_POST['abstract'] ?? '') ?></textarea>
                </div>

                <!-- Keywords -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Keywords (comma-separated)</label>
                    <input type="text" name="keywords" value="<?= htmlspecialchars($_POST['keywords'] ?? '') ?>"
                           class="w-full px-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="e.g., machine learning, climate change, data mining">
                    <p class="text-xs text-gray-500 mt-1">Add 3–8 keywords separated by commas to improve search visibility.</p>
                </div>

                <!-- Members -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Author(s) *</label>
                    <textarea name="author" rows="2"
                              class="w-full px-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="Enter author names separated by commas" required><?= htmlspecialchars($_POST['author'] ?? '') ?></textarea>
                </div>

                <!-- Department, Course/Strand & Status -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Department *</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-building"></i>
                            </div>
                            <select name="department" id="department" required class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <?php if (!empty($server_departments)): ?>
                                    <option value="" <?php echo $preselectedDeptPHP === '' ? 'selected' : ''; ?>>Select Department</option>
                                    <?php foreach ($server_departments as $d): ?>
                                        <?php 
                                            $name = isset($d['name']) ? (string)$d['name'] : ''; 
                                            $did  = isset($d['id']) ? (int)$d['id'] : 0;
                                            $code = isset($d['code']) ? (string)$d['code'] : '';
                                        ?>
                                        <option 
                                            value="<?php echo htmlspecialchars($name, ENT_QUOTES); ?>"
                                            <?php echo ($preselectedDeptPHP === $name) ? 'selected' : ''; ?>
                                            <?php if ($did) { echo ' data-dept-id="' . $did . '"'; } ?>
                                            <?php if ($code !== '') { echo ' data-code="' . htmlspecialchars($code, ENT_QUOTES) . '"'; } ?>
                                        >
                                            <?php echo htmlspecialchars($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" selected>Select Department</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <!-- Strand (visible only for Senior High School) -->
                    <div id="strand_container" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Strand</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <select name="strand" id="strand_select" class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <?php if (!empty($server_strands)): ?>
                                    <option value="">Select Strand</option>
                                    <?php foreach ($server_strands as $s): ?>
                                        <?php $sname = isset($s['strand']) ? (string)$s['strand'] : ''; ?>
                                        <option value="<?php echo htmlspecialchars($sname, ENT_QUOTES); ?>" <?php echo ($preselectedStrandPHP === $sname) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sname); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">Select Strand</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2"><span id="course_label">Course/Strand</span></label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <select name="course_strand" id="course_strand" class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <?php 
                                    $initialCourseOptions = !empty($server_strand_courses) ? $server_strand_courses : $server_course_strand_options;
                                ?>
                                <?php if (!empty($initialCourseOptions)): ?>
                                    <option value="">Select Course/Strand</option>
                                    <?php foreach ($initialCourseOptions as $opt): ?>
                                        <?php
                                            if (is_array($opt)) {
                                                $val = (string)($opt['name'] ?? '');
                                                $code = isset($opt['code']) && $opt['code'] !== null && $opt['code'] !== '' ? ' (' . $opt['code'] . ')' : '';
                                                $label = $val . $code;
                                                $isSel = ($preselectedCoursePHP === $val);
                                            } else {
                                                $val = (string)$opt;
                                                $label = $val; // strand courses may not have codes on server fallback
                                                $isSel = ($preselectedCoursePHP === $val);
                                            }
                                        ?>
                                        <option value="<?php echo htmlspecialchars($val, ENT_QUOTES); ?>" <?php echo $isSel ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">Select Course/Strand</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-green-500">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <input type="text" value="Approved" class="w-full pl-10 pr-3 py-3 border border-green-300 bg-green-50 text-green-800 rounded-xl" disabled>
                            <input type="hidden" name="status" value="1">
                        </div>
                    </div>
                </div>

                <!-- Image & Document Uploads -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Poster (Optional)</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-image"></i>
                            </div>
                            <input type="file" name="image" accept="image/*"
                                   class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl file:mr-4 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100 transition-all duration-200">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Research Document (PDF) <span class=\"text-gray-400\">(Optional)</span></label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <input type="file" name="document" accept=".pdf"
                                   class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl file:mr-4 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100 transition-all duration-200">
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white py-4 rounded-xl transition-all duration-300 flex items-center justify-center font-medium text-lg shadow-lg hover:shadow-xl">
                    <i class="fas fa-upload mr-3 text-xl"></i>
                    Upload Research
                </button>
            </form>
        </section>
    </main>

    <script>
        // Dynamic Department and Course/Strand loading (DB-backed)
        (function(){
            const deptSel = document.getElementById('department');
            const csSel = document.getElementById('course_strand');
            const csLabel = document.getElementById('course_label');
            const yearSel = document.getElementById('year_select');
            const preselectedYear = <?= json_encode($_POST['year'] ?? '') ?>;
            const preselectedDept = <?= json_encode($_POST['department'] ?? '') ?>;
            const preselectedCourse = <?= json_encode($_POST['course_strand'] ?? '') ?>;
            const preselectedStrand = <?= json_encode($_POST['strand'] ?? '') ?>;
            const serverDepartments = <?= json_encode($server_departments) ?>;
            const serverYearSpans = <?= json_encode($server_year_spans) ?>;
            const serverCourseStrandOptions = <?= json_encode($server_course_strand_options) ?>;
            const serverStrands = <?= json_encode($server_strands) ?>;
            const serverStrandCourses = <?= json_encode($server_strand_courses) ?>;
            const sessionDept = <?= json_encode($_SESSION['department'] ?? '') ?>;
            const coursesByDepartment = <?= json_encode($courses_by_department_map ?? []) ?>;
            const coursesByStrand = <?= json_encode($courses_by_strand_map ?? []) ?>;

            let cachedYearSpans = Array.isArray(serverYearSpans) ? serverYearSpans.slice() : [];

            function currentYearPrefixForDeptName(deptName) {
                if (!deptName) return 'A.Y.'; // default
                const dn = String(deptName).trim().toLowerCase();
                // Senior High School => S.Y.
                if (dn === 'senior high school' || dn === 'shs') return 'S.Y.';
                // CCS, COE, CBS and others => A.Y.
                return 'A.Y.';
            }

            function rebuildYearOptions(prefix) {
                yearSel.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select ' + (prefix === 'S.Y.' ? 'School' : 'Academic') + ' Year';
                placeholder.disabled = true;
                placeholder.selected = true;
                yearSel.appendChild(placeholder);

                cachedYearSpans.forEach(span => {
                    const full = `${prefix} ${span}`;
                    const opt = document.createElement('option');
                    opt.value = full;
                    opt.textContent = full;
                    if (preselectedYear && preselectedYear === full) opt.selected = true;
                    yearSel.appendChild(opt);
                });
                // If nothing preselected, default to the first available year
                if (!preselectedYear && yearSel.options.length > 1) {
                    yearSel.options[1].selected = true;
                }
            }

            function loadAcademicYears(){
                // Static: use serverYearSpans only
                cachedYearSpans = Array.isArray(serverYearSpans) ? serverYearSpans.slice() : [];
                const opt = deptSel.options[deptSel.selectedIndex];
                const deptName = opt ? opt.value : '';
                const prefix = currentYearPrefixForDeptName(deptName);
                rebuildYearOptions(prefix);
            }

            function loadDepartments(){
                // Static: departments are already server-rendered; ensure dataset attributes exist
                if (serverDepartments && serverDepartments.length) {
                    const seen = new Set();
                    for (let i = 0; i < deptSel.options.length; i++) {
                        const name = deptSel.options[i].value;
                        if (!name) continue;
                        seen.add(name.toLowerCase());
                    }
                    serverDepartments.forEach(d => {
                        const name = (d.name || '').toLowerCase();
                        for (let i = 0; i < deptSel.options.length; i++) {
                            if (deptSel.options[i].value.toLowerCase() === name) {
                                if (d.id !== undefined && d.id !== null) deptSel.options[i].dataset.deptId = d.id;
                                if (d.code) deptSel.options[i].dataset.code = d.code;
                                break;
                            }
                        }
                    });
                }
            }

            const strandSel = document.getElementById('strand_select');
            const strandContainer = document.getElementById('strand_container');

            function loadStrands(){
                // Static: use serverStrands only
                strandContainer.classList.remove('hidden');
                strandSel.innerHTML = '<option value="">Select Strand</option>';
                if (serverStrands && serverStrands.length) {
                    serverStrands.forEach(s => {
                        const name = s.strand || '';
                        const opt = document.createElement('option');
                        opt.value = name;
                        opt.textContent = name;
                        if (s.id !== undefined && s.id !== null) opt.dataset.strandId = s.id;
                        strandSel.appendChild(opt);
                    });
                    if (preselectedStrand) { strandSel.value = preselectedStrand; }
                }
            }

            function loadStrandCourses(strandName){
                // Static: use coursesByStrand map
                csSel.innerHTML = '<option value="">Select Course</option>';
                const list = (strandName && coursesByStrand[strandName]) ? coursesByStrand[strandName] : [];
                (list || []).forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.name;
                    opt.textContent = c.name + (c.code ? ` (${c.code})` : '');
                    csSel.appendChild(opt);
                });
                if (preselectedCourse) { csSel.value = preselectedCourse; }
            }

            function loadCoursesForDepartmentId(deptId, deptName, deptCode){
                // Static: use coursesByDepartment map by deptName
                csSel.innerHTML = '<option value="">Select Course</option>';
                const list = (deptName && coursesByDepartment[deptName]) ? coursesByDepartment[deptName] : [];
                (list || []).forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.name;
                    opt.textContent = c.name + (c.code ? ` (${c.code})` : '');
                    csSel.appendChild(opt);
                });
                if (csSel.options.length === 1) {
                    const nopt = document.createElement('option');
                    nopt.value = '';
                    nopt.textContent = 'No courses found for this department';
                    nopt.disabled = true;
                    csSel.appendChild(nopt);
                }
                if (preselectedCourse) { csSel.value = preselectedCourse; }
            }

            async function onDepartmentChange(){
                const opt = deptSel.options[deptSel.selectedIndex];
                let deptId = opt ? opt.dataset.deptId : '';
                const deptName = opt ? opt.value : '';
                
                if (!deptName) {
                    csLabel.textContent = 'Course/Strand';
                    csSel.innerHTML = '<option value="">Select Course/Strand</option>';
                    rebuildYearOptions(currentYearPrefixForDeptName(''));
                    return;
                }
                
                if (!deptId) {
                    // Try to resolve deptId from serverDepartments by name
                    if (serverDepartments && serverDepartments.length && deptName) {
                        const found = serverDepartments.find(d => (d.name || '').toLowerCase() === String(deptName).toLowerCase());
                        if (found && found.id) deptId = String(found.id);
                    }
                }
                
                const deptCode = opt && opt.dataset && opt.dataset.code ? String(opt.dataset.code) : '';
                const codeLc = deptCode ? deptCode.toLowerCase() : '';
                if (deptName.toLowerCase() === 'senior high school' || codeLc === 'shs') {
                    csLabel.textContent = 'Strand Course';
                    strandContainer.classList.remove('hidden');
                    loadStrands();
                    // Load courses for selected strand if any (by name)
                    const sName = strandSel.value || '';
                    if (sName) loadStrandCourses(sName); else csSel.innerHTML = '<option value="">Select Course</option>';
                } else {
                    csLabel.textContent = 'Course';
                    strandContainer.classList.add('hidden');
                    loadCoursesForDepartmentId(deptId, deptName, deptCode);
                }
                // Update year options prefix according to department
                rebuildYearOptions(currentYearPrefixForDeptName(deptName));
            }

            // Pre-populate from server data immediately (no network)
            (function immediatePopulate(){
                // Department
                if (serverDepartments && serverDepartments.length) {
                    const keep = deptSel.value;
                    deptSel.innerHTML = '<option value="">Select Department</option>';
                    serverDepartments.forEach(d => {
                        const name = d.name || '';
                        const opt = document.createElement('option');
                        opt.value = name;
                        opt.textContent = name;
                        if (d.id !== undefined && d.id !== null) opt.dataset.deptId = d.id;
                        if (d.code) opt.dataset.code = d.code;
                        deptSel.appendChild(opt);
                    });
                    if (preselectedDept) deptSel.value = preselectedDept;
                    else if (sessionDept) deptSel.value = sessionDept;
                }
                // Course/Strand - populate immediately if server data exists
                if (serverCourseStrandOptions && serverCourseStrandOptions.length) {
                    csSel.innerHTML = '<option value="">Select Course/Strand</option>';
                    serverCourseStrandOptions.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt;
                        option.textContent = opt;
                        csSel.appendChild(option);
                    });
                    if (preselectedCourse) csSel.value = preselectedCourse;
                    
                    // Update label based on department
                    const deptOpt = deptSel.options[deptSel.selectedIndex];
                    const deptName = deptOpt ? deptOpt.value : '';
                    if (deptName.toLowerCase() === 'senior high school') {
                        csLabel.textContent = 'Strand Course';
                    } else {
                        csLabel.textContent = 'Course';
                    }
                }
                // If server provided strands/courses for SHS
                if (serverStrands && serverStrands.length) {
                    strandContainer.classList.remove('hidden');
                    strandSel.innerHTML = '<option value="">Select Strand</option>';
                    serverStrands.forEach(s => {
                        const name = s.strand || '';
                        const opt = document.createElement('option');
                        opt.value = name;
                        opt.textContent = name;
                        if (s.id !== undefined && s.id !== null) opt.dataset.strandId = s.id;
                        strandSel.appendChild(opt);
                    });
                    if (preselectedStrand) strandSel.value = preselectedStrand;
                    // Now courses for selected strand if provided
                    if (serverStrandCourses && serverStrandCourses.length) {
                        csSel.innerHTML = '<option value="">Select Course</option>';
                        serverStrandCourses.forEach(n => {
                            const opt = document.createElement('option');
                            opt.value = n;
                            opt.textContent = n;
                            csSel.appendChild(opt);
                        });
                        if (preselectedCourse) csSel.value = preselectedCourse;
                    }
                }
                // Year
                if (serverYearSpans && serverYearSpans.length) {
                    cachedYearSpans = serverYearSpans.slice();
                    const deptName = deptSel.value || '';
                    const prefix = currentYearPrefixForDeptName(deptName);
                    rebuildYearOptions(prefix);
                }
            })();

            // Update department option metadata after initial population
            (function updateDeptMetadata(){
                if (serverDepartments && serverDepartments.length) {
                    serverDepartments.forEach(d => {
                        const name = d.name || '';
                        for (let i = 0; i < deptSel.options.length; i++) {
                            if (deptSel.options[i].value === name) {
                                if (d.id !== undefined && d.id !== null) deptSel.options[i].dataset.deptId = d.id;
                                if (d.code) deptSel.options[i].dataset.code = d.code;
                                break;
                            }
                        }
                    });
                }
            })();

            // Static: initialize from server data only
            loadDepartments();
            loadAcademicYears();
            onDepartmentChange();
            deptSel.addEventListener('change', onDepartmentChange);
            strandSel.addEventListener('change', function(){
                const sName = strandSel.value || '';
                loadStrandCourses(sName);
            });
        })();
    </script>
</body>
</html>