<?php
session_start();
require_once __DIR__ . '/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

if (!$conn) {
    die('Database connection failed');
}

$student_id = $_SESSION['student_id'];
$result = [];

try {
    // Fetch all bookmarks for this student
    $sql_all = "SELECT book_id, bookmarked_at FROM cap_bookmarks WHERE student_id = :student_id ORDER BY bookmarked_at DESC";
    $stmt_all = $conn->prepare($sql_all);
    $stmt_all->execute(['student_id' => $student_id]);
    $all_bookmarks = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

    // For each bookmark, fetch the actual paper data from either research_submission or books
    foreach ($all_bookmarks as $bm) {
        $book_id = $bm['book_id'];
        $bookmarked_at = $bm['bookmarked_at'];

        // Try research_submission first
        try {
            $sql_rs = "SELECT id, title, author, department, year, document FROM research_submission WHERE id = ? LIMIT 1";
            $stmt_rs = $conn->prepare($sql_rs);
            $stmt_rs->execute([$book_id]);
            $rs_row = $stmt_rs->fetch(PDO::FETCH_ASSOC);

            if ($rs_row) {
                $rs_row['bookmarked_at'] = $bookmarked_at;
                $rs_row['upload_type'] = 'student';
                $result[] = $rs_row;
                continue;
            }
        } catch (Throwable $e) {
            // Continue to next check
        }

        // Try cap_books table if not found in research_submission
        try {
            $sql_book = "SELECT book_id AS id, title, authors AS author, department, year, document FROM cap_books WHERE book_id = ? LIMIT 1";
            $stmt_book = $conn->prepare($sql_book);
            $stmt_book->execute([$book_id]);
            $book_row = $stmt_book->fetch(PDO::FETCH_ASSOC);

            if ($book_row) {
                $book_row['bookmarked_at'] = $bookmarked_at;
                $book_row['upload_type'] = 'admin';
                $result[] = $book_row;
            }
        } catch (Throwable $e) {
            // Silently skip if not found in either table
        }
    }
} catch (Throwable $e) {
    error_log("Bookmarks query error: " . $e->getMessage());
    $result = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookmarks</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tailwind CSS -->
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
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Ensure sidebar has consistent blue background */
        #sidebar {
            background: #1e3a8a !important;
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }

        #sidebar .bg-red-700 {
            background-color: #dc2626 !important;
        }

        #sidebar .hover\:bg-blue-primary:hover {
            background-color: #1e40af !important;
        }

        /* Subtle card glow */
        .card-shadow {
            box-shadow: 0 12px 24px -12px rgba(30, 64, 175, 0.25), 0 4px 8px -4px rgba(15, 23, 42, 0.08);
        }

        /* Modern, student-friendly background */
        .modern-bg {
            background: radial-gradient(1200px 800px at -10% -10%, rgba(59, 130, 246, 0.15), transparent 60%),
                radial-gradient(900px 700px at 110% 10%, rgba(99, 102, 241, 0.12), transparent 60%),
                radial-gradient(1000px 600px at 50% 120%, rgba(56, 189, 248, 0.14), transparent 60%),
                linear-gradient(135deg, #eef6ff, #edf7ff 40%, #eaf0ff 100%);
        }

        /* Card footer bar */
        .card-footer {
            background: linear-gradient(180deg, rgba(241, 245, 249, 0.75), rgba(241, 245, 249, 0.55));
            border: 1px solid rgba(203, 213, 225, 0.6);
        }

        /* Mobile: slide-in overlay only; sidebar styles come from student_sidebar.php */
        @media (max-width: 1024px) {
            body {
                overflow-x: hidden;
            }

            .mobile-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
                z-index: 40;
            }

            .mobile-overlay.active {
                display: block;
            }

            /* On mobile, keep content full width and let student_sidebar.php manage the sidebar */
            .with-sidebar {
                margin-left: 0 !important;
            }

            /* Hide the Student Portal mobile top bar (logo/title) just for this page */
            div.lg\:hidden.bg-blue-primary {
                display: none !important;
            }

            /* Keep the nice background visible beyond the main card */
            main {
                background: transparent !important;
            }
        }
    </style>
</head>

<body class="min-h-screen modern-bg font-sans">
    <!-- Main Container - Centered Layout -->
    <div class="flex flex-col min-h-screen">
        <!-- Sidebar + Content Wrapper -->
        <div id="layoutWrapper" class="flex flex-1 w-full mt-2 sm:mt-4 mb-8">
            <!-- Fallback Mobile Hamburger to open the shared student sidebar -->
            <button type="button" id="fallbackOpenSidebar" aria-label="Open Menu" class="lg:hidden fixed top-3 left-3 z-[1000] bg-blue-600 text-white rounded-full p-2 shadow-md">
                <i class="fas fa-bars"></i>
            </button>
            <!-- Page-level overlay used by student_sidebar.php -->
            <div id="overlay" class="mobile-overlay"></div>
            <?php include __DIR__ . '/student_sidebar.php'; ?>

            <!-- Main Content Area -->
            <main class="flex-1 bg-white/70 sm:bg-white/80 backdrop-blur-sm">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 py-10 md:py-14">
                    <!-- Page Title -->
                    <header class="mb-10 sm:mb-14 md:mb-20">
                        <div class="flex items-center gap-3 bg-blue-50 rounded-2xl px-4 sm:px-6 py-3 shadow-sm border border-blue-200 w-fit">
                            <span class="inline-flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-blue-600 text-white">
                                <i class="fas fa-bookmark text-lg"></i>
                            </span>
                            <h1 class="text-xl sm:text-2xl md:text-3xl font-black tracking-tight text-blue-900">
                                My Bookmarked Research
                            </h1>
                        </div>


                        <!-- Bookmarks Grid -->
                        <?php if ($result && count($result) > 0): ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 sm:gap-7 md:gap-8 xl:gap-10 content-start mt-6 sm:mt-8 md:mt-12">
                                <?php foreach ($result as $row): ?>
                                    <div class="relative bg-white rounded-2xl card-shadow p-4 sm:p-5 pt-8 sm:pt-9 pb-5 flex flex-col border border-blue-100 hover:border-blue-200 hover:-translate-y-0.5 hover:shadow-xl transition-all duration-200 w-full min-h-[240px] overflow-hidden" data-paper-id="<?php echo (int)$row['id']; ?>">
                                        <span class="absolute top-2.5 right-2.5 inline-flex items-center gap-2 text-[10px] sm:text-[11px] font-medium text-slate-600 bg-white/90 backdrop-blur border border-slate-200 rounded-full px-2 py-0.5 sm:px-2.5 sm:py-1 shadow-sm">
                                            <i class="fa-regular fa-calendar"></i>
                                            <?php echo date('M d, Y', strtotime($row['bookmarked_at'])); ?>
                                        </span>
                                        <div class="flex-1">
                                            <div class="flex items-start justify-between gap-2 mb-2">
                                                <h2 class="text-base sm:text-lg md:text-xl font-semibold text-blue-800 line-clamp-2">
                                                    <?php echo htmlspecialchars($row['title']); ?>
                                                </h2>
                                            </div>
                                            <p class="text-gray-700 mb-1 text-sm sm:text-base">
                                                <span class="font-semibold">Authors:</span>
                                                <?php echo htmlspecialchars($row['author'] ?? ''); ?>
                                            </p>
                                            <div class="flex flex-wrap items-center gap-2 mt-2 sm:mt-3">
                                                <span class="inline-flex items-center gap-2 text-[11px] sm:text-xs font-medium text-blue-700 bg-blue-50 border border-blue-200 rounded-full px-2.5 py-1">
                                                    <i class="fa-solid fa-layer-group"></i>
                                                    <?php echo htmlspecialchars($row['department'] ?? ''); ?>
                                                </span>
                                                <span class="inline-flex items-center gap-2 text-[11px] sm:text-xs font-medium text-slate-700 bg-slate-50 border border-slate-200 rounded-full px-2.5 py-1">
                                                    <i class="fa-regular fa-calendar"></i>
                                                    <?php echo htmlspecialchars($row['year']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="mt-4 sm:mt-5 card-footer rounded-xl px-3 sm:px-4 py-2.5 sm:py-3">
                                            <div class="grid grid-cols-1 xs:grid-cols-2 sm:grid-cols-2 gap-3 sm:gap-4">
                                                <?php
                                                // Build direct PDF path similar to repository.php
                                                $docPath = '';
                                                if (!empty($row['document'])) {
                                                    $cleanPath = ltrim($row['document'], '/');
                                                    if (strpos($cleanPath, 'uploads/') === 0) {
                                                        $docPath = $cleanPath;
                                                    } else {
                                                        $docPath = 'uploads/research_documents/' . $cleanPath;
                                                    }
                                                }
                                                $viewHref = $docPath !== '' ? $docPath : ('view_document.php?paper_id=' . (int)$row['id']);
                                                ?>
                                                <a href="<?php echo htmlspecialchars($viewHref); ?>" target="_blank" rel="noopener"
                                                    class="col-span-1 text-center justify-center text-white bg-blue-600 hover:bg-blue-700 px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2 shadow-sm hover:shadow transition">
                                                    <i class="fas fa-file-pdf"></i>
                                                    <span>View</span>
                                                </a>
                                                <button class="col-span-1 text-center justify-center text-white bg-red-600 hover:bg-red-700 px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2 shadow-sm hover:shadow transition remove-bookmark-btn" data-id="<?php echo (int)$row['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                    <span>Remove</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <!-- No bookmarks -->
                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 p-10 sm:p-12 rounded-2xl shadow-inner text-center text-gray-500 max-w-lg mx-auto mt-16 sm:mt-20 border border-blue-100">
                                <i class="fas fa-bookmark text-5xl sm:text-6xl mb-4 text-blue-300"></i>
                                <p class="text-base sm:text-lg font-semibold text-gray-600">No bookmarks yet</p>
                                <p class="text-sm text-gray-500">Save research papers to quickly find them here.</p>
                                <?php
                                $browseDept = isset($_SESSION['department']) && $_SESSION['department'] !== '' ? ('?department=' . urlencode($_SESSION['department'])) : '?department=all';
                                $browseHref = 'repository.php' . $browseDept;
                                ?>
                                <a href="<?= $browseHref ?>" class="inline-flex items-center gap-2 mt-4 text-white bg-blue-600 hover:bg-blue-700 px-4 py-2.5 rounded-lg font-semibold shadow-sm hover:shadow transition">
                                    <i class="fas fa-search"></i>
                                    <span>Browse Research</span>
                                </a>
                            </div>
                        <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    <script>
        // Sidebar open/close is handled in student_sidebar.php using #overlay and built-in buttons
        // Fallback open button proxies to the built-in opener so sidebar is always accessible on mobile
        (function() {
            const fb = document.getElementById('fallbackOpenSidebar');
            const builtin = document.getElementById('open-student-sidebar-btn');
            if (fb && builtin) {
                fb.addEventListener('click', function() {
                    builtin.click();
                });
            }
            // Ensure content is not pushed under the sidebar on mobile; overlay behavior is handled by student_sidebar.php
            const wrapper = document.getElementById('layoutWrapper');
            if (wrapper) {
                wrapper.classList.remove('with-sidebar');
            }
        })();
        // Handle Remove bookmark actions
        document.querySelectorAll('.remove-bookmark-btn').forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.preventDefault();
                const paperId = this.dataset.id;
                const card = this.closest('[data-paper-id]');
                if (typeof Swal === 'undefined') {
                    if (confirm('Remove this bookmark?')) {
                        await doToggle(paperId, card);
                    }
                    return;
                }
                const res = await Swal.fire({
                    icon: 'warning',
                    title: 'Remove bookmark?',
                    text: 'This research will be removed from your bookmarks.',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, remove',
                    cancelButtonText: 'Cancel'
                });
                if (res.isConfirmed) {
                    await doToggle(paperId, card);
                }
            });
        });

        async function doToggle(paperId, card) {
            try {
                const response = await fetch('toggle_bookmark.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'paper_id=' + encodeURIComponent(paperId)
                });
                const result = await response.json();
                if (result.success) {
                    // If unbookmarked, remove card from DOM
                    if (card && result.bookmarked === false) {
                        card.remove();
                    }
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: result.bookmarked ? 'success' : 'info',
                            title: result.bookmarked ? 'Bookmarked' : 'Removed',
                            text: result.bookmarked ? 'Added back to your bookmarks.' : 'Removed from your bookmarks.',
                            confirmButtonText: 'OK'
                        });
                    }
                    // Optionally refresh if list becomes empty
                    if (document.querySelectorAll('[data-paper-id]').length === 0) {
                        window.location.reload();
                    }
                } else {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: result.message || 'Failed to update bookmark.',
                            confirmButtonText: 'OK'
                        });
                    } else {
                        alert(result.message || 'Failed to update bookmark.');
                    }
                }
            } catch (e) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Network error. Please try again.',
                        confirmButtonText: 'OK'
                    });
                } else {
                    alert('Network error. Please try again.');
                }
            }
        }
    </script>
</body>

</html>