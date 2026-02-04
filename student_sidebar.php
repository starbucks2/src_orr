<?php
// Determine current script name for active link highlighting
$___current = basename(parse_url($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'], PHP_URL_PATH));
if (!function_exists('___is_active')) {
    function ___is_active($current, $targets)
    {
        if (!is_array($targets)) {
            $targets = [$targets];
        }
        return in_array($current, $targets, true);
    }
}
// Match admin/subadmin behavior: keep width stable (no font-weight change), add ring + left border
// Match user design: Dark blue theme, simpler active state
$active_cls = ' bg-blue-700 shadow-inner font-semibold';
$base_link_cls = 'nav-link flex items-center p-3 rounded-lg transition-all duration-200 hover:bg-blue-800 hover:text-white w-full text-blue-100';
// Determine student role if session exists (pages including this already start session)
$___role = strtoupper($_SESSION['student_role'] ?? 'MEMBER');
?>

<!-- Mobile Top Bar: hamburger on LEFT, title center-left, logo on RIGHT -->
<div class="lg:hidden bg-blue-primary p-4 flex items-center shadow-md z-50">
    <button id="open-student-sidebar-btn" class="text-white mr-3" aria-label="Open menu">
        <i class="fas fa-bars text-2xl"></i>
    </button>
    <div class="flex items-center">
        <h2 class="text-white font-bold text-2xl ml-2">Student Dashboard</h2>
    </div>
    <img src="srclogo.png" alt="SRC Logo" class="ml-auto h-10 w-auto rounded-full border-2 border-yellow-300">
</div>

<!-- NOTE: Overlay element is defined in the page (`#overlay`) to avoid duplicate overlays. -->

<!-- Sidebar -->
<aside id="sidebar" class="flex-none w-72 min-w-[18rem] bg-blue-900 text-white h-screen p-6 fixed lg:static top-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out sidebar-transition shadow-xl border-r border-blue-800">
    <div class="flex items-center justify-between mb-8 lg:mb-10">
        <div class="flex items-center gap-3">
            <img src="srclogo.png" alt="Logo" class="h-16 w-16 rounded-full shadow-lg bg-white p-1">
            <h2 class="text-2xl font-bold ml-1">Student Dashboard</h2>
        </div>
        <button id="close-student-sidebar-btn" class="lg:hidden text-white">
            <i class="fas fa-times text-xl"></i>
        </button>
    </div>

    <nav class="space-y-2">

        <a href="student_dashboard.php" class="<?= $base_link_cls . (___is_active($___current, 'student_dashboard.php') ? ' ' . $active_cls : '') ?>">
            <i class="fas fa-home mr-3 text-lg"></i>
            <span>Dashboard</span>
        </a>
        <a href="student_profile.php" class="<?= $base_link_cls . (___is_active($___current, 'student_profile.php') ? ' ' . $active_cls : '') ?>">
            <i class="fas fa-user-circle mr-3 text-lg"></i>
            <span>Profile</span>
        </a>


        <a href="repository.php?department=<?= htmlspecialchars($_SESSION['department'] ?? '') ?>" class="<?= $base_link_cls . (___is_active($___current, 'repository.php') ? ' ' . $active_cls : '') ?>">
            <i class="fas fa-book mr-3 text-lg"></i>
            <span>Research Repository</span>
        </a>
        <a href="student_bookmarks.php" class="<?= $base_link_cls . (___is_active($___current, 'student_bookmarks.php') ? ' ' . $active_cls : '') ?>">
            <i class="fas fa-bookmark mr-3 text-lg"></i>
            <span>Bookmarks</span>
        </a>
        <a href="logout.php" class="nav-link flex items-center p-3 rounded-lg mt-6 bg-red-700 hover:bg-red-800 transition-all duration-200">
            <i class="fas fa-sign-out-alt mr-3 text-lg"></i>
            <span>Sign Out</span>
        </a>
    </nav>
</aside>

<!-- SweetAlert2 for logout confirmation -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Global delegated logout confirmation (guarded to bind once)
    if (!window._logoutConfirmBound) {
        window._logoutConfirmBound = true;
        document.addEventListener('click', function(e) {
            const a = e.target.closest('a[href]');
            if (!a) return;
            const href = a.getAttribute('href') || '';
            const isLogout = href.endsWith('logout.php') || href.endsWith('admin_logout.php');
            if (!isLogout) return;
            e.preventDefault();
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
</script>

<script>
    // Sidebar open/close logic for mobile
    const studentSidebar = document.getElementById('sidebar');
    const openStudentBtn = document.getElementById('open-student-sidebar-btn');
    const closeStudentBtn = document.getElementById('close-student-sidebar-btn');
    // Use the page-level overlay element (#overlay) instead of a sidebar-specific duplicate.
    const studentOverlay = document.getElementById('overlay');

    function openStudentSidebar() {
        studentSidebar.classList.remove('-translate-x-full');
        if (studentOverlay) studentOverlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        // Hide top header controls (bell/profile) while sidebar is open
        var tuc = document.getElementById('topUserControls');
        if (tuc) tuc.classList.add('hidden');
        // Also close any open notif menu
        var nm = document.getElementById('notifMenu');
        if (nm && !nm.classList.contains('hidden')) nm.classList.add('hidden');
    }

    function closeStudentSidebar() {
        studentSidebar.classList.add('-translate-x-full');
        if (studentOverlay) studentOverlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        // Restore top header controls when sidebar closes
        var tuc = document.getElementById('topUserControls');
        if (tuc) tuc.classList.remove('hidden');
    }

    if (openStudentBtn) openStudentBtn.addEventListener('click', openStudentSidebar);
    if (closeStudentBtn) closeStudentBtn.addEventListener('click', closeStudentSidebar);
    if (studentOverlay) studentOverlay.addEventListener('click', closeStudentSidebar);

    // Ensure sidebar is visible on large screens
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            studentSidebar.classList.remove('-translate-x-full');
            if (studentOverlay) studentOverlay.classList.remove('active');
            document.body.classList.remove('overflow-hidden');
        } else {
            studentSidebar.classList.add('-translate-x-full');
        }
    });
</script>