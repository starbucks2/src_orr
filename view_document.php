<?php
session_start();
include 'db.php';

// Accept either book_id or legacy paper_id as GET parameter
$book_id = null;
if (isset($_GET['book_id']) && is_numeric($_GET['book_id'])) {
    $book_id = (int)$_GET['book_id'];
} elseif (isset($_GET['paper_id']) && is_numeric($_GET['paper_id'])) {
    $book_id = (int)$_GET['paper_id'];
}

if (!$book_id || $book_id <= 0) {
    $_SESSION['error'] = "Invalid document ID. Please select a valid research paper.";
    header("Location: repository.php");
    exit();
}

// Try direct from cap_books (preferred)
$research = null;
try {
    $stmt = $conn->prepare("UPDATE cap_books SET views = views + 1 WHERE book_id = ?");
    $stmt->execute([$book_id]);

    $stmt = $conn->prepare("SELECT title, authors AS author, year, department, document FROM cap_books WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $research = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Fallback to legacy view if direct cap_books access fails
    $stmt = $conn->prepare("UPDATE research_submission SET views = views + 1 WHERE id = ?");
    $stmt->execute([$book_id]);

    $stmt = $conn->prepare("SELECT title, author, year, department, document FROM research_submission WHERE id = ?");
    $stmt->execute([$book_id]);
    $research = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($research && !empty($research['document'])) {
    $filename = $research['document'];
    // Normalize to a relative web path like uploads/research_documents/<file>
    $clean = ltrim($filename, '/\\');
    if (strpos($clean, 'uploads/') === 0) {
        $relative_path = $clean;
    } else {
        $relative_path = 'uploads/research_documents/' . basename($clean);
    }

    // Validate path resolves under uploads/
    $real_base = realpath(__DIR__ . '/uploads');
    $real_file = realpath(__DIR__ . '/' . $relative_path);
    if (!$real_file || strpos($real_file, $real_base) !== 0 || !file_exists($real_file)) {
        $error = "Invalid or missing file.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Document</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-50 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="mb-4 flex items-center justify-between">
            <a href="javascript:history.back()" class="inline-flex items-center gap-2 text-white bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg shadow">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
        </div>

        <div class="bg-white rounded-xl shadow p-5 md:p-6 border border-slate-200">
            <div class="mb-4">
                <h1 class="text-xl md:text-2xl font-bold text-slate-800">
                    <?= isset($research['title']) ? htmlspecialchars($research['title']) : 'Untitled Document' ?>
                </h1>
                <div class="mt-1 text-slate-600 text-sm md:text-base">
                    <?php if (!empty($research['author'])): ?>
                        <span class="font-semibold">Author(s):</span>
                        <span><?= htmlspecialchars($research['author']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($research['year'])): ?>
                        <span class="mx-2">•</span>
                        <span><?= htmlspecialchars($research['year']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($research['department'])): ?>
                        <span class="mx-2">•</span>
                        <span>Department: <?= htmlspecialchars($research['department']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="p-4 rounded-lg bg-red-50 text-red-700 border border-red-200">
                    <i class="fas fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php else: ?>
                <div class="w-full h-[70vh] md:h-[80vh] border rounded-lg overflow-hidden">
                    <iframe src="<?= htmlspecialchars($relative_path) ?>" class="w-full h-full" title="PDF Viewer"></iframe>
                </div>
                <div class="mt-4 flex gap-3">
                    <a href="<?= htmlspecialchars($relative_path) ?>" target="_blank" class="inline-flex items-center gap-2 bg-slate-800 text-white px-4 py-2 rounded-lg hover:bg-slate-900">
                        <i class="fas fa-up-right-from-square"></i>
                        Open in new tab
                    </a>
                    <a href="<?= htmlspecialchars($relative_path) ?>" download class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-download"></i>
                        Download PDF
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>