<?php
session_start();
require_once 'db.php';

// Admin only
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['error'] = 'Only administrators can manage strands.';
    header('Location: admin_dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Update student strand (store in students.course_strand for SHS)
        if (isset($_POST['update_student'])) {
            $studentId = trim($_POST['student_id'] ?? '');
            $strand = trim($_POST['student_strand'] ?? '');
            if ($studentId !== '' && $strand !== '') {
                $stmt = $conn->prepare('SELECT student_id FROM students WHERE student_id = ?');
                $stmt->execute([$studentId]);
                if ($stmt->fetch()) {
                    $upd = $conn->prepare('UPDATE students SET course_strand = ? WHERE student_id = ?');
                    $upd->execute([$strand, $studentId]);
                    $_SESSION['success'] = 'Student strand updated successfully.';
                } else {
                    $_SESSION['error'] = 'Student not found.';
                }
            }
        }

        // Update research adviser strand via employees table (canonical)
        if (isset($_POST['update_subadmin'])) {
            $subadminId = trim($_POST['subadmin_id'] ?? '');
            $strand = trim($_POST['subadmin_strand'] ?? '');
            if ($subadminId !== '' && $strand !== '') {
                $stmt = $conn->prepare("SELECT employee_id FROM employees WHERE employee_id = ? AND employee_type = 'RESEARCH_ADVISER'");
                $stmt->execute([$subadminId]);
                if ($stmt->fetch()) {
                    // Resolve department_id from code or name
                    $deptIdVal = null;
                    try {
                        $q = $conn->prepare("SELECT department_id FROM departments WHERE LOWER(TRIM(code)) = LOWER(TRIM(?)) OR LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                        $q->execute([$strand, $strand]);
                        $did = $q->fetchColumn();
                        if ($did !== false && $did !== null) { $deptIdVal = (int)$did; }
                    } catch (Throwable $_) { $deptIdVal = null; }

                    // Update employees: set textual department and, when available, department_id
                    $hasDeptIdCol = false;
                    try {
                        $chk = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'department_id'");
                        $chk->execute();
                        $hasDeptIdCol = ((int)$chk->fetchColumn() > 0);
                    } catch (Throwable $_) { $hasDeptIdCol = false; }

                    if ($hasDeptIdCol && $deptIdVal !== null) {
                        $upd = $conn->prepare('UPDATE employees SET department = ?, department_id = ? WHERE employee_id = ?');
                        $upd->execute([$strand, $deptIdVal, $subadminId]);
                    } else {
                        $upd = $conn->prepare('UPDATE employees SET department = ? WHERE employee_id = ?');
                        $upd->execute([$strand, $subadminId]);
                    }
                    $_SESSION['success'] = 'Research adviser strand updated successfully.';
                } else {
                    $_SESSION['error'] = 'Research adviser not found.';
                }
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error updating strand: ' . $e->getMessage();
    }
}

header('Location: update_strands.php');
exit();
?>
