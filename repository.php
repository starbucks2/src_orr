<?php
include __DIR__ . '/include/session_init.php';
include 'db.php';

// Determine department and course/strand based on session (students and sub-admins restricted) or URL
$user_department = null;
$user_course_strand = null;
if (
    isset($_SESSION['user_type']) && !empty($_SESSION['department']) &&
    (($_SESSION['user_type'] === 'student') || ($_SESSION['user_type'] === 'sub_admins'))
) {
    $user_department = $_SESSION['department'];
    $user_course_strand = $_SESSION['course_strand'] ?? null;
    $department_filter = $user_department; // force to user's department
} else {
    // Fallback: if a sub-admin is logged in but user_type/department not set, derive from employees
    if (!empty($_SESSION['subadmin_id']) && $conn) {
        try {
            // Get department via employees.department_id when available; fallback to legacy employees.department
            // role_id = 2 for RESEARCH_ADVISER
            $qDept = $conn->prepare("SELECT COALESCE(d.name, e.department) AS department_label
                                     FROM employees e
                                     INNER JOIN roles r ON e.employee_id = r.employee_id
                                     LEFT JOIN departments d ON d.department_id = e.department_id
                                     WHERE e.employee_id = ? AND r.role_id = 2
                                     LIMIT 1");
            $qDept->execute([$_SESSION['subadmin_id']]);
            $dept = (string)($qDept->fetchColumn() ?: '');
            if ($dept !== '') {
                $user_department = $dept;
                $department_filter = $dept; // force filter for sub-admin
                // Persist to session for subsequent pages
                $_SESSION['department'] = $dept;
            }
        } catch (Throwable $_) { /* ignore and fall back to URL */
        }
    }
    if ($user_department === null) {
        // Others can use URL filter (accept legacy strand for compatibility)
        $department_filter = $_GET['department'] ?? ($_GET['strand'] ?? 'all');
    }
}

// Course/Strand filter from URL
$course_strand_filter = $_GET['course_strand'] ?? 'all';

$research_papers = [];
// If a student is logged in, allow them to see their own submissions regardless of status
$current_student_id = (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student' && isset($_SESSION['student_id']))
    ? $_SESSION['student_id']
    : null;

// Ensure these exist even if an exception path skips assignments later
$filtered_total_items = 0;
$filtered_total_pages = 0;
$total_items = 0;
$total_pages = 1;

// --- Helper functions for citations ---
if (!function_exists('format_author_name_apa')) {
    function format_author_name_apa($name)
    {
        $name = trim($name);
        if ($name === '') return '';
        // Try to split "Lastname, Firstname Middlename" or "Firstname Middlename Lastname"
        if (strpos($name, ',') !== false) {
            // Already "Last, First Middle"
            [$last, $firsts] = array_map('trim', array_pad(explode(',', $name, 2), 2, ''));
        } else {
            $parts = preg_split('/\s+/', $name);
            $last = array_pop($parts);
            $firsts = implode(' ', $parts);
        }
        $initials = '';
        foreach (preg_split('/\s+/', $firsts) as $p) {
            $p = trim($p);
            if ($p !== '') $initials .= strtoupper(mb_substr($p, 0, 1)) . '. ';
        }
        $initials = trim($initials);
        if ($last === '') return $initials;
        return $last . ($initials ? ', ' . $initials : '');
    }
}

if (!function_exists('format_authors_apa')) {
    function format_authors_apa($members_raw)
    {
        if (!$members_raw) return '';
        // Split by commas or ' and '
        $authors = preg_split('/\s*,\s*|\s+and\s+/i', $members_raw);
        // Use a traditional anonymous function for broader PHP compatibility
        $authors = array_values(
            array_filter(
                array_map('trim', $authors),
                function ($a) {
                    return $a !== '';
                }
            )
        );
        $formatted = array_map('format_author_name_apa', $authors);
        $count = count($formatted);
        if ($count === 0) return '';
        if ($count == 1) return $formatted[0];
        if ($count <= 20) {
            $last = array_pop($formatted);
            return implode(', ', $formatted) . ', & ' . $last;
        }
        // More than 20: list first 19, an ellipsis, then last
        $first19 = array_slice($formatted, 0, 19);
        $last = end($formatted);
        return implode(', ', $first19) . ', ... ' . $last;
    }
}

if (!function_exists('build_absolute_url')) {
    function build_absolute_url($relativePath)
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $rel = ltrim($relativePath, '/');
        return $scheme . '://' . $host . $base . '/' . $rel;
    }
}

// Academic year filtering (required). Compute selected AY and use it throughout
$__academic_year = isset($_GET['academic_year']) ? trim($_GET['academic_year']) : (isset($_GET['school_year']) ? trim($_GET['school_year']) : '');
// Default to current AY if none provided (June cutoff)
if ($__academic_year === '') {
    $nowYear = (int)date('Y');
    $nowMonth = (int)date('n');
    $startYear = ($nowMonth >= 6) ? $nowYear : ($nowYear - 1);
    $__academic_year = $startYear . '-' . ($startYear + 1);
}
// We will filter by substring match on the 'YYYY-YYYY' portion
$__ay_like = '%' . $__academic_year . '%';

// Build dynamic list of academic year options
$__year_options = [];
if ($conn) {
    try {
        // From academic_years table if present
        $stmtY = $conn->query("SELECT span FROM academic_years WHERE is_active = 1 ORDER BY SUBSTRING_INDEX(span,'-',1) DESC");
        if ($stmtY) {
            $__year_options = $stmtY->fetchAll(PDO::FETCH_COLUMN, 0);
        }
    } catch (Throwable $_) { /* ignore */
    }
    try {
        // Also include spans found in existing data (books and research_submission)
        $qYears = "SELECT DISTINCT TRIM(SUBSTRING_INDEX(y, ' ', -1)) AS span FROM (
                    SELECT year AS y FROM books WHERE year IS NOT NULL AND TRIM(year) <> ''
                    UNION ALL
                    SELECT year AS y FROM research_submission WHERE year IS NOT NULL AND TRIM(year) <> ''
               ) t
               WHERE TRIM(SUBSTRING_INDEX(y, ' ', -1)) REGEXP '^[0-9]{4}-[0-9]{4}$'
               ORDER BY CAST(SUBSTRING_INDEX(span,'-',1) AS UNSIGNED) DESC";
        $stmtD = $conn->query($qYears);
        if ($stmtD) {
            $spans = $stmtD->fetchAll(PDO::FETCH_COLUMN, 0);
            $__year_options = array_values(array_unique(array_merge($__year_options, $spans)));
        }
    } catch (Throwable $_) { /* ignore */
    }
    if (empty($__year_options)) {
        // Fallback to a reasonable range around current year
        $nowYear = (int)date('Y');
        for ($y = $nowYear + 1; $y >= $nowYear - 5; $y--) {
            $__year_options[] = ($y - 1) . '-' . $y;
        }
    }

    try {
        // Determine if research_submission exists (hosting compatibility)
        $has_rs_view = false;
        try {
            // Using a more reliable check for table existence
            $chk = $conn->query("SHOW TABLES LIKE 'research_submission'");
            $has_rs_view = ($chk && $chk->rowCount() > 0);
        } catch (Throwable $_) {
            $has_rs_view = false;
        }

        // Parameters for the base union to allow students to see their OWN pending work
        $base_params = [];
        $stu_id_val = $current_student_id ?? '___NONE___';

        // Build a combined dataset of admin uploads (books) and student uploads (research_submission)
        // Students see their own projects regardless of status
        $base_books = "SELECT 
            b.book_id AS id,
            b.title,
            b.year,
            b.abstract,
            b.keywords,
            b.authors AS author,
            NULL AS members,
            b.department,
            b.course_strand,
            b.image,
            b.document,
            COALESCE(b.views, 0) AS views,
            COALESCE(b.submission_date, NOW()) AS submission_date,
            b.student_id,
            'admin' AS uploader_type
        FROM books b
        WHERE b.status = 1";

        $base_union = '(' . $base_books;
        if ($has_rs_view) {
            $base_params[] = $stu_id_val;
            $base_union .= "
        UNION ALL
        SELECT 
            rs.id,
            rs.title,
            rs.year,
            rs.abstract,
            rs.keywords,
            rs.author,
            rs.members,
            rs.department,
            rs.course_strand,
            rs.image,
            rs.document,
            COALESCE(rs.views, 0) AS views,
            rs.submission_date,
            rs.student_id,
            'student' AS uploader_type
        FROM research_submission rs
        WHERE (rs.status = 1 OR (rs.student_id = ? AND rs.student_id IS NOT NULL))";
        }
        $base_union .= ')';

        // Build filters for combined alias "rs" by wrapping the union as a subquery
        // Logic: 
        // 1. Year must match the selected filter (unless it's the user's OWN submission)
        // 2. Department must match the filter (unless it's the user's OWN submission OR it's a student viewing the repository and not restricted)
        // 3. Status must be 1 (already filtered in the base_union)

        $filters_params = [];
        $filters_sql = " WHERE (rs.year LIKE ? OR (rs.student_id = ? AND rs.student_id IS NOT NULL))";
        $filters_params[] = $__ay_like;
        $filters_params[] = $current_student_id ?? '___NONE___';

        // Optional server-side search
        if (isset($_GET['search']) && trim($_GET['search']) !== '') {
            $search_term = '%' . trim($_GET['search']) . '%';
            $filters_sql .= " AND (rs.title LIKE ? OR rs.keywords LIKE ? OR rs.author LIKE ? OR rs.department LIKE ?)";
            array_push($filters_params, $search_term, $search_term, $search_term, $search_term);
        }

        // Department filter
        $curr_dept = $user_department ?? ($department_filter === 'all' ? null : $department_filter);
        if ($curr_dept) {
            $filters_sql .= " AND (
                TRIM(LOWER(rs.department)) = TRIM(LOWER(?)) 
                OR TRIM(LOWER(d.code)) = TRIM(LOWER(?)) 
                OR TRIM(LOWER(d.name)) = TRIM(LOWER(?)) 
                OR rs.department LIKE ? 
                OR TRIM(LOWER(?)) LIKE CONCAT('%', TRIM(LOWER(rs.department)), '%')
                OR (rs.student_id = ? AND rs.student_id IS NOT NULL)
            )";
            array_push($filters_params, $curr_dept, $curr_dept, $curr_dept, '%' . $curr_dept . '%', $curr_dept, $current_student_id ?? '___NONE___');
        }

        // Course/Strand filter
        $url_course = $_GET['course_strand'] ?? ($_GET['strand'] ?? 'all');
        if ($url_course === 'all') {
            $curr_course = null; // Let them see everything in the department if they choose "all"
        } else {
            $curr_course = $user_course_strand ?? ($course_strand_filter === 'all' ? null : $course_strand_filter);
        }

        if ($curr_course) {
            $filters_sql .= " AND (
                TRIM(LOWER(rs.course_strand)) = TRIM(LOWER(?)) 
                OR TRIM(LOWER(c.course_code)) = TRIM(LOWER(?)) 
                OR TRIM(LOWER(c.course_name)) = TRIM(LOWER(?)) 
                OR rs.course_strand LIKE ?
                OR ? LIKE CONCAT('%', rs.course_strand, '%')
                OR (rs.student_id = ? AND rs.student_id IS NOT NULL)
            )";
            $search_course = '%' . $curr_course . '%';
            array_push($filters_params, $curr_course, $curr_course, $curr_course, $search_course, $curr_course, $current_student_id ?? '___NONE___');
        }

        // Count total
        $count_query = "SELECT COUNT(*) AS total FROM (" . $base_union . ") rs 
                        LEFT JOIN departments d ON (TRIM(LOWER(d.name)) = TRIM(LOWER(rs.department)) OR TRIM(LOWER(d.code)) = TRIM(LOWER(rs.department)))
                        LEFT JOIN courses c ON (TRIM(LOWER(c.course_name)) = TRIM(LOWER(rs.course_strand)) OR TRIM(LOWER(c.course_code)) = TRIM(LOWER(rs.course_strand)))
                        " . $filters_sql;
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->execute(array_merge($base_params, $filters_params));
        $total_items = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Pagination
        $items_per_page = 10;
        $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $current_page = max(1, $current_page);
        $total_pages = max(1, (int)ceil($total_items / $items_per_page));
        if ($current_page > $total_pages) {
            $current_page = $total_pages;
        }
        $offset = ($current_page - 1) * $items_per_page;

        // Main query over combined data
        $query = "SELECT 
                rs.id,
                rs.title,
                rs.year,
                rs.abstract,
                rs.keywords,
                rs.author,
                rs.members,
                rs.department,
                rs.course_strand,
                rs.image,
                rs.document,
                rs.views,
                rs.submission_date,
                rs.student_id,
                s.firstname AS student_firstname,
                rs.uploader_type
            FROM (" . $base_union . ") rs
            LEFT JOIN departments d ON (TRIM(LOWER(d.name)) = TRIM(LOWER(rs.department)) OR TRIM(LOWER(d.code)) = TRIM(LOWER(rs.department)))
            LEFT JOIN courses c ON (TRIM(LOWER(c.course_name)) = TRIM(LOWER(rs.course_strand)) OR TRIM(LOWER(c.course_code)) = TRIM(LOWER(rs.course_strand)))
            LEFT JOIN students s ON rs.student_id = s.student_id
            " . $filters_sql . "
            ORDER BY rs.submission_date DESC
            LIMIT " . (int)$items_per_page . " OFFSET " . (int)$offset . "";

        $stmt = $conn->prepare($query);
        $stmt->execute(array_merge($base_params, $filters_params));
        $research_papers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If we have student_ids referenced, fetch those students in batch to map names/strands
        $studentIds = array();
        $researchIds = array();
        foreach ($research_papers as $r) {
            if (!empty($r['student_id'])) {
                $studentIds[] = $r['student_id'];
            }
            $researchIds[] = $r['id'];
        }

        $studentsMap = [];
        if (count($studentIds) > 0) {
            // Unique ids
            $studentIds = array_values(array_unique($studentIds));
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $sstmt = $conn->prepare("SELECT student_id, firstname, department, course_strand FROM students WHERE student_id IN ($placeholders)");
            $sstmt->execute($studentIds);
            $students = $sstmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($students as $s) {
                $studentsMap[$s['student_id']] = $s;
            }
        }

        // Fetch reviews stats in batch
        $reviewsMap = [];
        if (count($researchIds) > 0) {
            $researchIds = array_values(array_unique($researchIds));
            $placeholders = implode(',', array_fill(0, count($researchIds), '?'));
            $rstmt = $conn->prepare("SELECT research_id, AVG(rating) as avg_rating, COUNT(*) as num_reviews FROM reviews WHERE research_id IN ($placeholders) GROUP BY research_id");
            $rstmt->execute($researchIds);
            $reviewRows = $rstmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reviewRows as $rr) {
                $reviewsMap[$rr['research_id']] = $rr;
            }
        }

        // Enrich research_papers with computed fields used by the template
        foreach ($research_papers as &$paper) {
            // Only process if uploader_type is set
            if ($paper['uploader_type'] === 'student') {
                if (isset($studentsMap[$paper['student_id']])) {
                    // Student found in database
                    $paper['firstname'] = $studentsMap[$paper['student_id']]['firstname'];
                    // Ensure department present on paper
                    if (empty($paper['department'])) {
                        $paper['department'] = $studentsMap[$paper['student_id']]['department'] ?? '';
                    }
                } else {
                    // Student upload but student record not found
                    $paper['firstname'] = 'Student';
                    if (empty($paper['department'])) {
                        $paper['department'] = $paper['department'] ?? '';
                    }
                }
            } else {
                // Admin upload
                $paper['firstname'] = 'Admin';
                // department stays as saved on rs
            }

            // reviews
            $paperId = $paper['id'];
            if (isset($reviewsMap[$paperId])) {
                $paper['avg_rating'] = $reviewsMap[$paperId]['avg_rating'];
                $paper['num_reviews'] = $reviewsMap[$paperId]['num_reviews'];
            } else {
                $paper['avg_rating'] = null;
                $paper['num_reviews'] = 0;
            }
        }

        unset($paper); // break reference


        // Total items after count
        $filtered_total_items = $total_items;

        // If nothing is found (hosting join/view differences), fallback to books-only listing with department filter
        if ($filtered_total_items === 0) {
            try {
                // Fallback query: show approved admin books, filtered by selected department
                $fallback_where = " WHERE b.status = 1";
                $fallback_params = [];
                
                // Apply academic year filter in fallback too
                $fallback_where .= " AND b.year LIKE ?";
                $fallback_params[] = $__ay_like;
                
                // Apply department filter in fallback too
                if ($curr_dept) {
                    $fallback_where .= " AND (TRIM(LOWER(b.department)) = TRIM(LOWER(?)) OR b.department LIKE ?)";
                    $fallback_params[] = $curr_dept;
                    $fallback_params[] = '%' . $curr_dept . '%';
                }
                
                $sql = "SELECT 
                        b.book_id AS id,
                        b.title,
                        b.year,
                        b.abstract,
                        b.keywords,
                        b.authors AS author,
                        NULL AS members,
                        b.department,
                        b.course_strand,
                        b.image,
                        b.document,
                        COALESCE(b.views, 0) AS views,
                        COALESCE(b.submission_date, NOW()) AS submission_date,
                        NULL AS student_id,
                        'admin' AS uploader_type
                    FROM books b
                    " . $fallback_where . "
                    ORDER BY b.submission_date DESC LIMIT 100";

                $bstmt = $conn->prepare($sql);
                $bstmt->execute($fallback_params);
                $research_papers = $bstmt->fetchAll(PDO::FETCH_ASSOC);
                $filtered_total_items = count($research_papers);
            } catch (Throwable $_) { /* ignore */
            }
        }
    } catch (PDOException $e) {
        // Fallback: books-only listing (admin uploads) - also respect department filter
        try {
            $fallback_where = " WHERE b.status = 1";
            $fallback_params = [];
            
            // Apply academic year filter in PDOException fallback too
            $fallback_where .= " AND b.year LIKE ?";
            $fallback_params[] = $__ay_like;
            
            // Apply department filter in PDOException fallback too
            if ($curr_dept) {
                $fallback_where .= " AND (TRIM(LOWER(b.department)) = TRIM(LOWER(?)) OR b.department LIKE ?)";
                $fallback_params[] = $curr_dept;
                $fallback_params[] = '%' . $curr_dept . '%';
            }
            
            $sql = "SELECT 
                    b.book_id AS id,
                    b.title,
                    b.year,
                    b.abstract,
                    b.keywords,
                    b.authors AS author,
                    NULL AS members,
                    b.department,
                    b.course_strand,
                    b.image,
                    b.document,
                    COALESCE(b.views, 0) AS views,
                    COALESCE(b.submission_date, NOW()) AS submission_date,
                    NULL AS student_id,
                    'admin' AS uploader_type
                FROM books b
                " . $fallback_where . "
                ORDER BY b.submission_date DESC LIMIT 100";

            $stmt = $conn->prepare($sql);
            $stmt->execute($fallback_params);
            $research_papers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filtered_total_items = count($research_papers);
            $total_items = $filtered_total_items;
            $total_pages = 1;
            $current_page = 1;
        } catch (Exception $e2) {
            $research_papers = [];
            $filtered_total_items = 0;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/head_meta.php'; ?>

    <!-- Google Fonts - Matching Google Scholar -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">

    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
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

    <style>
        body {
            font-family: 'Roboto', Arial, sans-serif;
            background-color: #f8f9fa;
            color: #202124;
            line-height: 1.6;
        }

        /* Ensure app sidebar (admin/subadmin/student) is visible inline on desktop */
        @media (min-width: 1024px) {
            #sidebar {
                position: static !important;
                transform: none !important;
            }
        }

        /* Header - Google Scholar Style */
        .scholar-header {
            background-color: white;
            border-bottom: 1px solid #dadce0;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .logo-container .logo {
            display: flex;
            align-items: center;
            font-size: 1.5rem;
            font-weight: 500;
            color: #1a73e8;
            text-decoration: none;
        }

        .logo i {
            margin-right: 8px;
            color: #34a853;
        }

        .search-container {
            flex: 1;
            max-width: 600px;
            margin: 0 24px;
            position: relative;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #70757a;
        }

        .search-box {
            width: 100%;
            padding: 10px 16px 10px 45px;
            border: 1px solid #dfe1e5;
            border-radius: 24px;
            font-size: 16px;
            outline: none;
            transition: box-shadow 0.3s;
        }

        .search-box:focus {
            box-shadow: 0 1px 6px rgba(32, 33, 36, 0.28);
        }

        /* Sidebar Filters */
        .filters {
            width: 240px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e8e8e8;
            align-self: start;
        }

        .filter-title {
            font-size: 14px;
            font-weight: 500;
            color: #5f6368;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-options li {
            list-style: none;
            padding: 8px 0;
            cursor: pointer;
            color: #1a73e8;
            font-size: 14px;
        }

        .filter-options li:hover {
            text-decoration: underline;
        }

        /* Results List */
        .results {
            flex: 1;
            padding: 20px 0;
        }

        .paper-card {
            background: white;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            transition: box-shadow 0.2s;
        }

        .paper-card:hover {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
        }

        .paper-title {
            font-size: 18px;
            font-weight: 500;
            color: #1a0dab;
            text-decoration: none;
        }

        .paper-title:hover {
            text-decoration: underline;
        }

        .paper-meta {
            font-size: 14px;
            color: #70757a;
            margin: 4px 0;
        }

        .paper-abstract {
            font-size: 14px;
            color: #5f6368;
            margin: 8px 0;
            line-height: 1.5;
        }

        /* Highlight style for matched search terms */
        mark.search-hit {
            background-color: #ffcccc;
            /* light red background */
            color: #991b1b;
            /* dark red text for contrast */
            padding: 0 2px;
            border-radius: 2px;
        }


        .paper-actions {
            font-size: 14px;
            color: #1a73e8;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .paper-actions a {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }

        .paper-img {
            width: 120px;
            height: 90px;
            object-fit: cover;
            border-radius: 6px;
            margin-left: 16px;
        }


        .paper-row {
            display: flex;
            gap: 16px;
        }

        /* Responsive Styles */
        @media (max-width: 1024px) {
            .container {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }

            .content-wrapper {
                flex-direction: column;
                gap: 0;
            }

            .filters {
                width: 100%;
                margin-bottom: 20px;
                border-radius: 8px;
                border: 1px solid #e8e8e8;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
            }

            .results {
                padding: 10px 0;
            }
        }

        @media (max-width: 768px) {
            .scholar-header {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 10px 8px;
            }

            .logo-container {
                justify-content: center;
                margin-bottom: 6px;
            }

            .search-container {
                margin: 0;
                max-width: 100%;
            }

            .filters {
                padding: 12px;
                font-size: 15px;
            }

            .paper-row {
                flex-direction: column;
                gap: 10px;
            }

            .paper-img {
                width: 100%;
                height: 140px;
                margin-left: 0;
                margin-top: 10px;
            }

            .paper-card {
                padding: 10px;
            }

            .paper-title {
                font-size: 16px;
            }

            .paper-meta,
            .paper-abstract,
            .paper-actions {
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            .scholar-header {
                font-size: 15px;
                padding: 8px 2px;
            }

            .filters {
                font-size: 14px;
                padding: 8px;
            }

            .paper-title {
                font-size: 15px;
            }

            .paper-img {
                height: 100px;
            }
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen flex flex-col lg:flex-row">
    <?php
    $__ut = $_SESSION['user_type'] ?? '';
    if ($__ut === 'admin') {
        include 'admin_sidebar.php';
    } elseif ($__ut === 'sub_admins') {
        include 'subadmin_sidebar.php';
    } elseif ($__ut === 'student') {
        include 'student_sidebar.php';
    }
    ?>

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
                    text: 'Are you sure you want to sign out?\nYou will be logged out of your session.',
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

    <div class="flex-1 w-full px-4 py-6">
        <!-- Overlay for mobile sidebars (used by student sidebar) -->
        <div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden lg:hidden"></div>
        <div class="content-wrapper flex gap-6">
            <!-- Filters Sidebar -->
            <aside class="filters">
                <?php $is_subadmin_ctx = isset($_SESSION['subadmin_id']);
                $lockedDept = $user_department ?: ($_SESSION['department'] ?? ''); ?>
                <h3 class="filter-title"><?= ($is_subadmin_ctx || $lockedDept) ? 'YOUR DEPARTMENT' : 'FILTER BY DEPARTMENT' ?></h3>
                <ul class="filter-options">
                    <?php if ($is_subadmin_ctx || $lockedDept): ?>
                        <li style="font-weight:bold; cursor: default; color:#202124;">
                            <?= htmlspecialchars($lockedDept ?: $department_filter) ?>
                        </li>
                        <?php if ($user_course_strand): ?>
                            <li style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e8e8e8;">
                                <div style="font-size: 11px; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                                    <?= (($lockedDept ?: $user_department) === 'Senior High School') ? 'Your Strand' : 'Your Course' ?>
                                </div>
                                <div style="font-weight:bold; color:#1a73e8;">
                                    <?= htmlspecialchars($user_course_strand) ?>
                                </div>
                            </li>
                        <?php endif; ?>
                    <?php else: ?>
                        <li onclick="filterBy('CCS')" style="font-weight:<?= $department_filter === 'CCS' ? 'bold' : 'normal' ?>">CCS (College of Computer Studies)</li>
                        <li onclick="filterBy('COE')" style="font-weight:<?= $department_filter === 'COE' ? 'bold' : 'normal' ?>">COE (College of Education)</li>
                        <li onclick="filterBy('CBS')" style="font-weight:<?= $department_filter === 'CBS' ? 'bold' : 'normal' ?>">CBS (College of Business Studies)</li>
                        <li onclick="filterBy('Senior High School')" style="font-weight:<?= $department_filter === 'Senior High School' ? 'bold' : 'normal' ?>">Senior High School</li>
                    <?php endif; ?>
                </ul>
                <?php if (!$user_department): // Only show course/strand filter for admins/sub-admins 
                ?>
                    <hr class="my-4">
                    <h3 class="filter-title"><?= ($department_filter === 'Senior High School') ? 'FILTER BY STRAND' : 'FILTER BY COURSE' ?></h3>
                    <ul class="filter-options">
                        <li onclick="filterByCourseStrand('all')" style="font-weight:<?= $course_strand_filter === 'all' ? 'bold' : 'normal' ?>">All</li>
                        <?php if ($department_filter === 'CCS'): ?>
                            <li onclick="filterByCourseStrand('BSIS')" style="font-weight:<?= $course_strand_filter === 'BSIS' ? 'bold' : 'normal' ?>">BSIS</li>
                        <?php elseif ($department_filter === 'CBS'): ?>
                            <li onclick="filterByCourseStrand('BSAIS')" style="font-weight:<?= $course_strand_filter === 'BSAIS' ? 'bold' : 'normal' ?>">BSAIS</li>
                            <li onclick="filterByCourseStrand('BSENTREP')" style="font-weight:<?= $course_strand_filter === 'BSENTREP' ? 'bold' : 'normal' ?>">BSENTREP</li>
                        <?php elseif ($department_filter === 'COE'): ?>
                            <li onclick="filterByCourseStrand('BEED')" style="font-weight:<?= $course_strand_filter === 'BEED' ? 'bold' : 'normal' ?>">BEED</li>
                            <li onclick="filterByCourseStrand('BSED')" style="font-weight:<?= $course_strand_filter === 'BSED' ? 'bold' : 'normal' ?>">BSED</li>
                        <?php elseif ($department_filter === 'Senior High School'): ?>
                            <li onclick="filterByCourseStrand('ABM')" style="font-weight:<?= $course_strand_filter === 'ABM' ? 'bold' : 'normal' ?>">ABM</li>
                            <li onclick="filterByCourseStrand('TVL')" style="font-weight:<?= $course_strand_filter === 'TVL' ? 'bold' : 'normal' ?>">TVL</li>
                            <li onclick="filterByCourseStrand('STEM')" style="font-weight:<?= $course_strand_filter === 'STEM' ? 'bold' : 'normal' ?>">STEM</li>
                            <li onclick="filterByCourseStrand('GAS')" style="font-weight:<?= $course_strand_filter === 'GAS' ? 'bold' : 'normal' ?>">GAS</li>
                            <li onclick="filterByCourseStrand('HUMSS')" style="font-weight:<?= $course_strand_filter === 'HUMSS' ? 'bold' : 'normal' ?>">HUMSS</li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
                <hr class="my-4">
                <h3 class="filter-title">ACADEMIC YEAR</h3>
                <form method="get" id="schoolYearForm">
                    <!-- Department filter -->
                    <input type="hidden" name="department" value="<?= htmlspecialchars($department_filter) ?>">
                    <input type="hidden" name="course_strand" value="<?= htmlspecialchars($course_strand_filter) ?>">
                    <div class="flex gap-2 items-center mb-2">
                        <?php $__sy = $__academic_year; ?>
                        <select name="academic_year" class="border rounded px-2 py-1 w-full">
                            <?php foreach ($__year_options as $__optSpan): ?>
                                <option value="<?= htmlspecialchars($__optSpan) ?>" <?= ($__sy === $__optSpan) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($__optSpan) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600 text-sm">Apply</button>
                </form>
            </aside>

            <!-- Main Content: Search and Results -->
            <div class="flex-1 flex flex-col">
                <div class="flex justify-between items-center mb-2 gap-3 flex-wrap">
                    <form id="searchForm" method="get" action="repository.php" class="relative flex items-center w-full max-w-xl">
                        <input type="hidden" name="department" value="<?= htmlspecialchars($department_filter) ?>">
                        <input type="hidden" name="course_strand" value="<?= htmlspecialchars($course_strand_filter) ?>">
                        <input type="hidden" name="academic_year" value="<?= isset($_GET['academic_year']) ? htmlspecialchars($_GET['academic_year']) : (isset($_GET['school_year']) ? htmlspecialchars($_GET['school_year']) : '') ?>">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="search-box" name="search" placeholder="Search research papers..." id="searchInput" value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>" autocomplete="off">
                        <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 bg-blue-500 text-white px-3 py-1 rounded-full hover:bg-blue-600 text-sm">Search</button>
                    </form>
                </div>
                <?php if (isset($_SESSION['subadmin_id'])): ?>
                    <?php $lockedDept = $user_department ?: ($_SESSION['department'] ?? $department_filter ?? ''); ?>
                    <?php if (!empty($lockedDept)): ?>
                        <div class="mb-3 text-sm">
                            <span class="inline-flex items-center gap-2 bg-blue-50 text-blue-800 px-3 py-1 rounded border border-blue-200">
                                <i class="fas fa-lock"></i>
                                Viewing <?= htmlspecialchars($lockedDept) ?> research only
                            </span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="mb-2 text-right text-gray-700 text-sm">
                    <?php $___fti = isset($filtered_total_items) && $filtered_total_items > 0 ? $filtered_total_items : count($research_papers); ?>
                    Showing <span class="font-semibold"><?= $___fti ?></span> research paper<?= $___fti == 1 ? '' : 's' ?> found
                </div>
                <div class="results" id="results">
                    <?php if (count($research_papers) > 0): ?>
                        <?php foreach ($research_papers as $paper): ?>
                            <div class="paper-card" data-title="<?= htmlspecialchars(strtolower($paper['title'] ?? '')) ?>"
                                data-author="<?= htmlspecialchars(strtolower(($paper['members'] ?? $paper['author'] ?? ''))) ?>"
                                data-department="<?= htmlspecialchars(strtolower($paper['department'] ?? '')) ?>"
                                data-course-strand="<?= htmlspecialchars(strtolower($paper['course_strand'] ?? '')) ?>"
                                data-keywords="<?= htmlspecialchars(strtolower($paper['keywords'] ?? '')) ?>">
                                <div class="paper-row">
                                    <div class="flex-1">
                                        <h3>
                                            <?php
                                            // Document path logic (robust to different stored formats and platforms)
                                            $docPath = '';
                                            $docHref = '';
                                            if (!empty($paper['document'])) {
                                                $raw = trim((string)$paper['document']);
                                                // If full URL, use as-is
                                                if (preg_match('~^https?://~i', $raw)) {
                                                    $docPath = $raw;
                                                    $docHref = $raw;
                                                } else {
                                                    // Normalize slashes and leading characters
                                                    $clean = preg_replace('#[\\/]+#', '/', $raw);
                                                    $clean = ltrim($clean, "./\/");
                                                    $lower = strtolower($clean);

                                                    // If the stored value contains uploads/research_documents anywhere (even absolute path), extract relative
                                                    $needle1 = 'uploads/research_documents/';
                                                    $needle2 = 'research_documents/';
                                                    $needle3 = 'uploads/';
                                                    if (($p = strpos($lower, $needle1)) !== false) {
                                                        $rel = substr($clean, $p);
                                                        $docPath = $rel; // already starts with uploads/research_documents/
                                                    } elseif (($p = strpos($lower, $needle2)) !== false) {
                                                        $rel = substr($clean, $p); // starts with research_documents/
                                                        $docPath = 'uploads/' . $rel;
                                                    } elseif (strpos($lower, $needle3) === 0) {
                                                        // already starts with uploads/
                                                        $docPath = $clean;
                                                    } else {
                                                        // Fallback: use only the filename
                                                        $filename = basename($clean);
                                                        $docPath = 'uploads/research_documents/' . $filename;
                                                    }

                                                    // Build absolute URL for href
                                                    $docHref = build_absolute_url($docPath);
                                                }
                                            }
                                            ?>
                                            <?php if (!empty($docHref)): ?>
                                                <a href="<?= htmlspecialchars($docHref) ?>" target="_blank" rel="noopener" class="paper-title" onclick="incrementViewOnly(<?= (int)$paper['id'] ?>)">
                                                    <?= htmlspecialchars($paper['title']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="paper-title">
                                                    <?= htmlspecialchars($paper['title']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <div class="paper-meta">
                                            <?php
                                            $displayAuthors = trim((string)($paper['members'] ?? ''));
                                            if ($displayAuthors === '') {
                                                $displayAuthors = trim((string)($paper['author'] ?? ''));
                                            }
                                            echo htmlspecialchars($displayAuthors);
                                            ?> - <?= htmlspecialchars($paper['year']) ?>
                                            <span class="mx-1">•</span>
                                            <?php
                                            $deptLabel = $paper['department'] ?? ($paper['paper_strand'] ?? '');
                                            if (!empty($deptLabel)) {
                                                echo 'Department: ' . htmlspecialchars($deptLabel);
                                            } else {
                                                echo "Department not specified";
                                            }
                                            ?>
                                            <?php if (!empty($paper['course_strand'])): ?>
                                                <span class="mx-1">•</span>
                                                <?php
                                                $csLabel = ($deptLabel === 'Senior High School') ? 'Strand' : 'Course';
                                                echo htmlspecialchars($csLabel) . ': ' . htmlspecialchars($paper['course_strand']);
                                                ?>
                                            <?php endif; ?>
                                        </div>
                                        <p class="paper-abstract"><?= htmlspecialchars($paper['abstract']) ?></p>
                                        <?php if (!empty($paper['keywords'])): ?>
                                            <?php
                                            $kwList = array_filter(array_map('trim', preg_split('/\s*,\s*/', (string)$paper['keywords'])));
                                            ?>
                                            <?php if (count($kwList) > 0): ?>
                                                <div class="mt-2 flex flex-wrap gap-2 text-sm">
                                                    <?php foreach ($kwList as $kw): ?>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-100">
                                                            <i class="fas fa-tag mr-1 text-xs"></i> <?= htmlspecialchars($kw) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <div class="paper-actions">
                                            <?php if (!empty($docHref)): ?>
                                                <a href="<?= htmlspecialchars($docHref) ?>" target="_blank" rel="noopener" onclick="incrementViewOnly(<?= (int)$paper['id'] ?>)">
                                                    <i class="fas fa-file-pdf"></i> PDF
                                                </a>
                                            <?php endif; ?>
                                            <span title="Views" class="flex items-center gap-1 text-gray-600">
                                                <i class="fas fa-eye"></i> <span id="views-<?= (int)$paper['id'] ?>"><?= (int)$paper['views'] ?></span>
                                            </span>
                                            <a href="#" onclick="openCiteModal(<?= (int)$paper['id'] ?>); return false;" title="Cite">
                                                <i class="fas fa-quote-right"></i> Cite
                                            </a>
                                            <!-- Student-only actions -->
                                            <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student'): ?>
                                                <button class="ml-2 text-blue-500 hover:text-blue-700 focus:outline-none" title="Bookmark this research" onclick="handleBookmarkClick(<?= (int)$paper['id'] ?>)">
                                                    <i class="fas fa-bookmark"></i>
                                                </button>
                                            <?php endif; ?>
                                            <span><?= date('M j, Y', strtotime($paper['submission_date'])) ?></span>
                                        </div>
                                        <?php
                                        // Build simple APA-style citation with clickable URL in modal
                                        $authorsApa = '';
                                        if (!empty($paper['members'])) {
                                            $authorsApa = format_authors_apa($paper['members']);
                                        } elseif (!empty($paper['author'])) {
                                            $authorsApa = format_authors_apa($paper['author']);
                                        }
                                        $yearText = trim((string)($paper['year'] ?? ''));
                                        if ($yearText === '') {
                                            $yearText = 'n.d.';
                                        }
                                        $titleText = trim((string)($paper['title'] ?? 'Untitled'));
                                        $institution = 'Santa Rita College of Pampanga';
                                        // Build core strings
                                        $citationCorePlain = trim(($authorsApa ? $authorsApa . '. ' : '') . '(' . $yearText . '). ' . $titleText . '. ' . $institution . '.');
                                        $docUrl = '';
                                        if (!empty($docHref)) {
                                            $docUrl = $docHref;
                                        }
                                        // Plain text for copy textarea
                                        $citationApa = $citationCorePlain . ($docUrl ? ' ' . $docUrl : '');
                                        // HTML for modal display (institution italicized to match journal-style look)
                                        $authorsEsc = htmlspecialchars($authorsApa);
                                        $titleEsc = htmlspecialchars($titleText);
                                        $instEsc = htmlspecialchars($institution);
                                        $citationCoreHtml = trim(($authorsEsc ? $authorsEsc . '. ' : '') . '(' . htmlspecialchars($yearText) . '). ' . $titleEsc . '. <i>' . $instEsc . '</i>.');
                                        if ($docUrl) {
                                            $citationApaHtml = $citationCoreHtml . ' <a href="' . htmlspecialchars($docUrl) . '" target="_blank" rel="noopener">' . htmlspecialchars($docUrl) . '</a>';
                                        } else {
                                            $citationApaHtml = $citationCoreHtml;
                                        }
                                        ?>
                                        <!-- Cite Modal -->
                                        <div id="cite-modal-<?= (int)$paper['id'] ?>" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
                                            <div class="bg-white w-11/12 max-w-2xl rounded-lg shadow-lg p-4 sm:p-5 max-h-[85vh] overflow-y-auto">
                                                <div class="flex items-center justify-between mb-3">
                                                    <h4 class="text-base sm:text-lg font-semibold flex items-center gap-2"><i class="fas fa-quote-right text-blue-600"></i><span>Citations</span></h4>
                                                    <button class="text-gray-500 hover:text-gray-700" onclick="closeCiteModal(<?= (int)$paper['id'] ?>)" title="Close"><i class="fas fa-times"></i></button>
                                                </div>
                                                <div class="mb-2 sm:mb-4 grid grid-cols-1 sm:grid-cols-[3.5rem_1fr] gap-2 sm:gap-4">
                                                    <div class="sm:text-right sm:pr-2 text-gray-500 font-medium text-sm">APA</div>
                                                    <div class="text-sm leading-6 text-gray-900 break-words">
                                                        <p class="whitespace-normal"><?= $citationApaHtml ?></p>
                                                    </div>
                                                </div>
                                                <!-- Hidden textarea for copy to clipboard -->
                                                <textarea id="cite-text-<?= (int)$paper['id'] ?>" class="sr-only" readonly><?= htmlspecialchars($citationApa) ?></textarea>
                                                <div class="flex flex-col sm:flex-row sm:justify-end gap-2 pt-1">
                                                    <button class="w-full sm:w-auto px-3 py-2 bg-gray-100 rounded hover:bg-gray-200" onclick="closeCiteModal(<?= (int)$paper['id'] ?>)">Close</button>
                                                    <button class="w-full sm:w-auto px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700" onclick="copyCitation(<?= (int)$paper['id'] ?>)" title="Copy citation"><i class="fas fa-copy mr-1"></i>Copy</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Image -->
                                    <?php
                                    $imagePath = '';
                                    if (!empty($paper['image'])) {
                                        // Remove any leading slashes and normalize path
                                        $cleanImagePath = ltrim($paper['image'], '/');

                                        // If path already contains uploads/, use it directly
                                        if (strpos($cleanImagePath, 'uploads/') === 0) {
                                            $imagePath = $cleanImagePath;
                                        } else {
                                            // Otherwise, prepend uploads/research_images/
                                            $imagePath = 'uploads/research_images/' . $cleanImagePath;
                                        }
                                    }
                                    ?>
                                    <?php if (!empty($imagePath)): ?>
                                        <img src="<?= htmlspecialchars($imagePath) ?>" alt="Research Image" class="paper-img">
                                    <?php else: ?>
                                        <div class="paper-img bg-gray-100 flex items-center justify-center">
                                            <i class="fas fa-image text-gray-400"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fas fa-folder-open text-6xl mb-4 opacity-50"></i>
                            <h3 class="text-xl">No research papers found</h3>
                            <p>Try adjusting your filter.</p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

                <!-- Pagination Controls -->
                <?php if (isset($total_pages) && $total_pages > 1): ?>
                    <div class="mt-4 flex items-center justify-center gap-3">
                        <?php
                        // Preserve existing query params and update `page`
                        $qp = $_GET;
                        if ($current_page > 1) {
                            $qp['page'] = $current_page - 1;
                            echo '<a href="repository.php?' . htmlspecialchars(http_build_query($qp)) . '" class="px-3 py-1 bg-white border rounded text-blue-600 hover:bg-gray-50">&larr; Prev</a>';
                        }
                        echo '<span class="px-3 py-1 text-sm text-gray-700">Page ' . (int)$current_page . ' of ' . (int)$total_pages . '</span>';
                        if ($current_page < $total_pages) {
                            $qp['page'] = $current_page + 1;
                            echo '<a href="repository.php?' . htmlspecialchars(http_build_query($qp)) . '" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">Next &rarr;</a>';
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <!-- Search & Filter Script -->
        <script>
            function filterBy(department) {
                const u = new URL(window.location.href);
                u.searchParams.set('department', department);
                u.searchParams.delete('course_strand'); // Reset course/strand when changing department
                // Keep existing search/year params automatically
                window.location.href = u.toString();
            }

            function filterByCourseStrand(courseStrand) {
                const u = new URL(window.location.href);
                u.searchParams.set('course_strand', courseStrand);
                // Keep existing department/search/year params automatically
                window.location.href = u.toString();
            }

            const searchInput = document.getElementById('searchInput');
            const results = document.getElementById('results');
            const cards = Array.from(results.querySelectorAll('.paper-card'));

            // Cache original text contents to safely re-render highlights
            function cacheOriginals() {
                cards.forEach(card => {
                    const titleEl = card.querySelector('.paper-title');
                    const metaEl = card.querySelector('.paper-meta');
                    const absEl = card.querySelector('.paper-abstract');
                    const keywordEls = card.querySelectorAll('.inline-flex');

                    if (titleEl && !titleEl.dataset.orig) titleEl.dataset.orig = titleEl.textContent;
                    if (metaEl && !metaEl.dataset.orig) metaEl.dataset.orig = metaEl.textContent;
                    if (absEl && !absEl.dataset.orig) absEl.dataset.orig = absEl.textContent;
                    keywordEls.forEach((kEl) => {
                        if (!kEl.dataset.orig) kEl.dataset.orig = kEl.textContent;
                    });
                });
            }

            function escapeRegExp(string) {
                return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }

            function highlightInElement(el, term) {
                if (!el) return;
                const orig = el.dataset.orig ?? el.textContent;
                if (!term) {
                    el.innerHTML = '';
                    el.textContent = orig;
                    return;
                }
                const pattern = new RegExp(`(${escapeRegExp(term)})`, 'gi');
                const safe = orig.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                el.innerHTML = safe.replace(pattern, '<mark class="search-hit">$1</mark>');
            }

            function applyHighlights(term) {
                cards.forEach(card => {
                    const titleEl = card.querySelector('.paper-title');
                    const metaEl = card.querySelector('.paper-meta');
                    const absEl = card.querySelector('.paper-abstract');
                    const keywordEls = card.querySelectorAll('.inline-flex');
                    highlightInElement(titleEl, term);
                    highlightInElement(metaEl, term);
                    highlightInElement(absEl, term);
                    keywordEls.forEach(kEl => highlightInElement(kEl, term));
                });
            }

            // Initialize caches once DOM is ready
            cacheOriginals();
            // If there is an initial search value (from URL), apply filtering and highlights immediately
            (function initSearchOnce() {
                if (!searchInput) return;
                const term = searchInput.value.toLowerCase();
                if (!term) return;
                cards.forEach(card => {
                    const title = card.dataset.title;
                    const author = card.dataset.author ?? '';
                    const dept = card.dataset.department ?? '';
                    const course = card.dataset.courseStrand ?? card.dataset['course-strand'] ?? '';
                    const keywords = card.dataset.keywords ?? '';

                    if (
                        (title && title.includes(term)) ||
                        (author && author.includes(term)) ||
                        (dept && dept.includes(term)) ||
                        (course && course.includes(term)) ||
                        (keywords && keywords.includes(term))
                    ) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
                applyHighlights(searchInput.value.trim());
            })();

            searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase();

                cards.forEach(card => {
                    const title = card.dataset.title;
                    const author = card.dataset.author ?? '';
                    const dept = card.dataset.department ?? '';
                    const course = card.dataset.courseStrand ?? card.dataset['course-strand'] ?? '';
                    const keywords = card.dataset.keywords ?? '';

                    if (
                        (title && title.includes(term)) ||
                        (author && author.includes(term)) ||
                        (dept && dept.includes(term)) ||
                        (course && course.includes(term)) ||
                        (keywords && keywords.includes(term))
                    ) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });

                // Apply visual highlight to visible cards
                applyHighlights(this.value.trim());
            });

            // Citation modal helpers
            function openCiteModal(paperId) {
                const m = document.getElementById(`cite-modal-${paperId}`);
                if (!m) return;
                m.classList.remove('hidden');
                m.classList.add('flex');
            }

            function closeCiteModal(paperId) {
                const m = document.getElementById(`cite-modal-${paperId}`);
                if (!m) return;
                m.classList.add('hidden');
                m.classList.remove('flex');
            }

            async function copyCitation(paperId) {
                const ta = document.getElementById(`cite-text-${paperId}`);
                if (!ta) return;
                try {
                    await navigator.clipboard.writeText(ta.value);
                    // Simple feedback
                    const original = ta.value;
                    ta.value = original + '\n\n(Copied to clipboard)';
                    setTimeout(() => {
                        ta.value = original;
                    }, 800);
                } catch (e) {
                    ta.select();
                    document.execCommand('copy');
                }
            }

            // Bookmark helpers
            function handleBookmarkClick(researchId) {
                bookmarkResearch(researchId);
            }

            function bookmarkResearch(researchId) {
                fetch('bookmark_research.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'research_id=' + encodeURIComponent(researchId)
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (typeof Swal === 'undefined') {
                            // Fallback if SweetAlert2 failed to load
                            if (result.success) {
                                alert('Bookmarked successfully!');
                            } else {
                                alert(result.message || 'Failed to bookmark.');
                            }
                            return;
                        }
                        if (result.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Bookmarked!',
                                text: 'The research has been added to your bookmarks.',
                                confirmButtonText: 'OK'
                            });
                        } else {
                            Swal.fire({
                                icon: 'info',
                                title: 'Notice',
                                text: (result.message || 'Failed to bookmark.'),
                                confirmButtonText: 'OK'
                            });
                        }
                    })
                    .catch(() => {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Error bookmarking. Please try again.',
                                confirmButtonText: 'OK'
                            });
                        } else {
                            alert('Error bookmarking.');
                        }
                    });
            }

            // Increment views only (used when links open in new tab)
            function incrementViewOnly(researchId) {
                try {
                    fetch('increment_view.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'research_id=' + encodeURIComponent(researchId)
                    }).catch(() => {});
                } catch (e) {}
                const vc = document.getElementById('views-' + researchId);
                if (vc) {
                    const n = parseInt(vc.textContent || '0', 10);
                    if (!Number.isNaN(n)) vc.textContent = (n + 1).toString();
                }
            }
        </script>
</body>

</html>