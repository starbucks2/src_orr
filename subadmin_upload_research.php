<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Handle form submission
$message = '';
$message_type = ''; // 'success' or 'error'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    include 'db.php';

    $title = trim($_POST['title']);
    $year = $_POST['year'];
    $abstract = trim($_POST['abstract']);
    $author = trim($_POST['author']);
    $department = $_POST['department'] ?? ($_POST['strand'] ?? '');
    $course_strand = trim($_POST['course_strand'] ?? '');
    $keywords = trim($_POST['keywords'] ?? '');
    $status = 1; // Approved

    // Validate required fields
    if (empty($title) || empty($abstract) || empty($author)) {
        $message = "All fields are required.";
        $message_type = 'error';
    } else {
        $image = '';
        $document = '';

        // Upload image
        if (!empty($_FILES['image']['name'])) {
            $imgName = $_FILES['image']['name'];
            $imgTmp = $_FILES['image']['tmp_name'];
            $imgSize = $_FILES['image']['size'];
            $imgExt = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($imgExt, $allowedExts) && $imgSize < 5 * 1024 * 1024) {
                $image = 'uploads/research_images/' . uniqid('img_') . '.' . $imgExt;
                move_uploaded_file($imgTmp, $image);
            } else {
                $message = "Invalid image file. Use JPG, PNG, GIF under 5MB.";
                $message_type = 'error';
            }
        }

        // Upload PDF document (optional)
        if (!empty($_FILES['document']['name'])) {
            $docName = $_FILES['document']['name'];
            $docTmp = $_FILES['document']['tmp_name'];
            $docExt = strtolower(pathinfo($docName, PATHINFO_EXTENSION));

            if ($docExt === 'pdf' && $_FILES['document']['size'] < 10 * 1024 * 1024) {
                $document = 'uploads/research_documents/' . uniqid('doc_') . '.pdf';
                move_uploaded_file($docTmp, $document);
            } else {
                $message = "Only PDF files under 10MB allowed.";
                $message_type = 'error';
            }
        }

        // Insert into database if no errors
        if (!$message) {
            try {
                // Deduplicate title among approved cap_books (case-insensitive)
                $ck = $conn->prepare("SELECT book_id FROM cap_books WHERE LOWER(TRIM(title)) = LOWER(TRIM(?)) AND status = 1");
                $ck->execute([$title]);
                if ($ck->fetch()) {
                    throw new PDOException('A research with this title already exists in the repository. Please use a different title.');
                }
                // Insert into cap_books; uploads by subadmin have no student_id
                $adviser_id = $_SESSION['subadmin_id'] ?? null;
                $stmt = $conn->prepare("INSERT INTO cap_books 
                    (student_id, adviser_id, title, year, abstract, keywords, authors, department, status, image, document, submission_date) 
                    VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$adviser_id, $title, $year, $abstract, $keywords, $author, $department, $status, $image, $document]);

                // Activity log
                require_once __DIR__ . '/include/activity_log.php';
                log_activity($conn, 'subadmin', $_SESSION['subadmin_id'] ?? null, 'upload_research', [
                    'title' => $title,
                    'department' => $department,
                    'year' => $year,
                    'document' => $document,
                ]);
                $message = "Research project uploaded successfully!";
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
    <title>Upload Research | BNHS Research Repository</title>
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
    <?php include 'subadmin_sidebar.php'; ?>

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
                        <label class="block text-sm font-medium text-gray-700 mb-2">Academic Year *</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <select name="year" class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <?php
                                $currentYear = (int)date('Y');
                                for ($y = $currentYear; $y >= 2000; $y--) {
                                    $next = $y + 1;
                                    $sy = "S.Y. {$y}-{$next}";
                                    $sel = (($_POST['year'] ?? '') === $sy) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($sy, ENT_QUOTES) . '" ' . $sel . '>' . htmlspecialchars($sy) . '</option>';
                                }
                                ?>
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

                <!-- Department & Status -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Department *</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-user-tag"></i>
                            </div>
                            <select name="department" required class="w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="" disabled selected>Select Department</option>
                                <option value="CCS">CCS (College of Computer Studies)</option>
                                <option value="COE">COE (College of Education)</option>
                                <option value="CBS">CBS (College of Business Studies)</option>
                                <option value="Senior High School">Senior High School</option>
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
</body>

</html>