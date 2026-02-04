<?php
session_start();
include 'db.php';
require_once __DIR__ . '/include/activity_log.php';

// Try to raise upload limits at runtime (some hosts allow this)
@ini_set('upload_max_filesize', '1024M');
@ini_set('post_max_size', '1024M');
@ini_set('memory_limit', '1536M');
@ini_set('max_execution_time', '900');
@ini_set('max_input_time', '900');
@ini_set('file_uploads', '1');

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    $_SESSION['error'] = "Unauthorized access.";
    header("Location: login.php");
    exit();
}

// Role-based restriction removed: all students may upload anytime (no announcement requirement)

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Trust the logged-in session for student identity
    $student_id = $_SESSION['student_id'] ?? ($_POST['student_id'] ?? '');
    $title = trim($_POST['title']);
    $year = $_POST['year'];
    $abstract = trim($_POST['abstract']);
    $author = trim($_POST['author']); // maps to books.authors
    $keywords = trim($_POST['keywords'] ?? '');
    // Department targeting (replaces legacy 'strand')
    $department = $_POST['department'] ?? ($_SESSION['department'] ?? '');
    // Course/Strand
    $course_strand = $_POST['course_strand'] ?? ($_SESSION['course_strand'] ?? '');
    // Derive course_strand from the database for this student if missing
    try {
        if (!empty($student_id)) {
            $gstmt = $conn->prepare("SELECT course_strand FROM students WHERE student_id = ? LIMIT 1");
            $gstmt->execute([$student_id]);
            $grow = $gstmt->fetch(PDO::FETCH_ASSOC);
            if ($grow && isset($grow['course_strand']) && empty($course_strand)) {
                $course_strand = (string)$grow['course_strand'];
            }
        }
    } catch (Exception $e) { /* ignore */
    }
    // Section removed from the model
    $status = 1; // Automatically approved (like admin uploads)

    // Validate required fields
    if (empty($student_id) || empty($title) || empty($abstract) || empty($author)) {
        $_SESSION['error'] = "All fields are required.";
        header("Location: student_dashboard.php");
        exit();
    }

    // Check for duplicate title (case-insensitive, approved research only)
    try {
        $dup_check = $conn->prepare("SELECT book_id FROM cap_books WHERE LOWER(TRIM(title)) = LOWER(TRIM(?)) AND status = 1 LIMIT 1");
        $dup_check->execute([$title]);
        if ($dup_check->fetch()) {
            $_SESSION['error'] = "A research with this title already exists in the repository. Please use a different title.";
            header("Location: student_dashboard.php");
            exit();
        }
    } catch (PDOException $e) {
        // If check fails, log but allow upload to proceed
        error_log('Duplicate title check failed: ' . $e->getMessage());
    }

    // --- Handle PDF Document Upload ---
    $document = '';
    if (!empty($_FILES['document']['name'])) { // optional PDF
        $docName = $_FILES['document']['name'];
        $docTmp = $_FILES['document']['tmp_name'];
        $docSize = $_FILES['document']['size'];
        $docExt = strtolower(pathinfo($docName, PATHINFO_EXTENSION));
        $allowedDocs = ['pdf'];

        if (!in_array($docExt, $allowedDocs)) {
            $_SESSION['error'] = "Invalid document type. Only PDF files are allowed.";
            header("Location: student_dashboard.php");
            exit();
        }

        // Do not enforce an application-level size limit. Server limits (upload_max_filesize/post_max_size) may still apply.

        $document = 'uploads/research_documents/' . uniqid('doc_') . '.' . $docExt;
        $uploadDir = 'uploads/research_documents';
        // Ensure directory exists and is writable
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0777, true)) {
                $_SESSION['error'] = "Upload failed: cannot create uploads/research_documents directory (permissions).";
                header("Location: student_dashboard.php");
                exit();
            }
        }
        if (!is_writable($uploadDir)) {
            @chmod($uploadDir, 0777);
        }

        // Surface native PHP upload errors clearly
        $upErr = $_FILES['document']['error'] ?? UPLOAD_ERR_OK;
        if ($upErr !== UPLOAD_ERR_OK) {
            $map = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
            ];
            $msg = $map[$upErr] ?? 'Unknown upload error.';
            // If ini limits are the cause, show the current limits
            if ($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE) {
                $um = ini_get('upload_max_filesize');
                $pm = ini_get('post_max_size');
                $msg .= " (Server limits: upload_max_filesize=$um, post_max_size=$pm)";
            }
            $_SESSION['error'] = 'Upload failed: ' . $msg;
            header("Location: student_dashboard.php");
            exit();
        }

        if (!is_uploaded_file($docTmp)) {
            $_SESSION['error'] = "Upload failed: temporary file missing (is_uploaded_file check).";
            header("Location: student_dashboard.php");
            exit();
        }

        if (!@move_uploaded_file($docTmp, $document)) {
            $_SESSION['error'] = "Failed to move uploaded file into uploads/research_documents. Please check folder permissions.";
            header("Location: student_dashboard.php");
            exit();
        }
    } // if no file provided, leave $document empty

    // --- Handle Image Upload (Optional) ---
    $image = '';
    if (!empty($_FILES['image']['name'])) {
        $imgErr = $_FILES['image']['error'] ?? UPLOAD_ERR_OK;
        if ($imgErr !== UPLOAD_ERR_OK) {
            $msg = 'Image upload error.';
            if ($imgErr === UPLOAD_ERR_INI_SIZE) $msg = 'Image exceeds server upload_max_filesize limit.';
            elseif ($imgErr === UPLOAD_ERR_FORM_SIZE) $msg = 'Image exceeds the form MAX_FILE_SIZE limit.';
            elseif ($imgErr === UPLOAD_ERR_PARTIAL) $msg = 'Image was only partially uploaded.';
            elseif ($imgErr === UPLOAD_ERR_NO_FILE) $msg = 'No image file was uploaded.';
            elseif ($imgErr === UPLOAD_ERR_NO_TMP_DIR) $msg = 'Missing temporary folder on server.';
            elseif ($imgErr === UPLOAD_ERR_CANT_WRITE) $msg = 'Failed to write image to disk.';
            elseif ($imgErr === UPLOAD_ERR_EXTENSION) $msg = 'A PHP extension stopped the image upload.';
            $_SESSION['error'] = $msg;
            header("Location: student_dashboard.php");
            exit();
        }

        $imageName = $_FILES['image']['name'];
        $imageTmp = $_FILES['image']['tmp_name'];
        $imageSize = $_FILES['image']['size'];
        $imageExt = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
        $allowedImages = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($imageExt, $allowedImages)) {
            $_SESSION['error'] = "Invalid image format. Only JPG, PNG, GIF allowed.";
            header("Location: student_dashboard.php");
            exit();
        }

        if ($imageSize > 10 * 1024 * 1024) { // 10MB limit
            $_SESSION['error'] = "Image too large. Maximum 10MB allowed.";
            header("Location: student_dashboard.php");
            exit();
        }

        $targetDir = 'uploads/research_images';
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                $_SESSION['error'] = "Failed to create image upload directory.";
                header("Location: student_dashboard.php");
                exit();
            }
        }
        if (!is_uploaded_file($imageTmp)) {
            $_SESSION['error'] = "Invalid image upload (temp file missing).";
            header("Location: student_dashboard.php");
            exit();
        }

        $image = $targetDir . '/' . uniqid('img_') . '.' . $imageExt;
        if (!move_uploaded_file($imageTmp, $image)) {
            $_SESSION['error'] = "Failed to upload image.";
            header("Location: student_dashboard.php");
            exit();
        }
    }

    // No legacy schema mutations; using new books table

    // Final duplicate check before insert
    $final_dup = $conn->prepare("SELECT book_id FROM cap_books WHERE LOWER(TRIM(title)) = LOWER(TRIM(?)) AND status = 1 LIMIT 1");
    $final_dup->execute([$title]);
    if ($final_dup->fetch()) {
        $_SESSION['error'] = "A research with this title already exists. Please use a different title.";
        header("Location: student_dashboard.php");
        exit();
    }

    try {
        // Insert into cap_books table
        $stmt = $conn->prepare("INSERT INTO cap_books (student_id, title, year, abstract, keywords, authors, department, course_strand, document, image, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$student_id, $title, $year, $abstract, $keywords, $author, $department, $course_strand, $document, $image, $status]);

        // Activity log
        try {
            log_activity($conn, 'student', $student_id, 'upload_research', [
                'title' => $title,
                'department' => $department,
                'course_strand' => $course_strand,
                'year' => $year,
                'document' => $document
            ]);
        } catch (Throwable $e) { /* ignore */
        }

        $_SESSION['success'] = "Research uploaded successfully and is now live in the repository!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Upload failed: " . $e->getMessage();
    }

    header("Location: student_dashboard.php");
    exit();
} else {
    // Invalid request method
    http_response_code(405);
    echo "Method not allowed.";
    exit();
}
