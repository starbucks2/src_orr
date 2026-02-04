<?php
session_start();
require 'db.php'; // Database connection

// Ensure user is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$current = null;
// Load current student row using correct column names
try {
    $stCur = $conn->prepare("SELECT first_name, middle_name, last_name, email, department, course_strand, profile_picture FROM students WHERE student_id = ?");
    $stCur->execute([$student_id]);
    $current = $stCur->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $current = [];
}

$firstname = isset($_POST['firstname']) ? trim($_POST['firstname']) : ($current['first_name'] ?? '');
$middlename = isset($_POST['middlename']) ? trim($_POST['middlename']) : ($current['middle_name'] ?? '');
$lastname = isset($_POST['lastname']) ? trim($_POST['lastname']) : ($current['last_name'] ?? '');
$email = isset($_POST['email']) ? trim($_POST['email']) : ($current['email'] ?? '');
$department = isset($_POST['department']) ? trim($_POST['department']) : ($current['department'] ?? '');
$course_strand = isset($_POST['course_strand']) ? trim($_POST['course_strand']) : ($current['course_strand'] ?? '');
$profile_pic_name = $_FILES['profile_picture']['name'] ?? '';
$upload_dir = __DIR__ . '/images/';
$updated_profile_pic = $current['profile_picture'] ?? '';

// Handle profile picture upload if provided
if (!empty($profile_pic_name) && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    // Ensure upload dir exists
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (in_array($ext, $allowed_ext)) {
        $genName = 'student_' . $student_id . '_' . time() . '.' . $ext;
        $dest = $upload_dir . $genName;
        if (@move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dest)) {
            // Remove old profile picture if exists and is different from default
            $old = $current['profile_picture'] ?? '';
            if ($old && $old !== 'default.jpg' && is_file($upload_dir . $old)) {
                @unlink($upload_dir . $old);
            }
            $updated_profile_pic = $genName;
        }
    }
}

try {
    $conn->beginTransaction();

    // Detect available columns
    $cols = [];
    try {
        $qCols = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'");
        $qCols->execute();
        $cols = $qCols->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Throwable $_) {
        $cols = [];
    }

    $colFirst  = in_array('first_name', $cols, true) ? 'first_name' : (in_array('firstname', $cols, true) ? 'firstname' : 'firstname');
    $colMiddle = in_array('middle_name', $cols, true) ? 'middle_name' : (in_array('middlename', $cols, true) ? 'middlename' : 'middlename');
    $colLast   = in_array('last_name', $cols, true) ? 'last_name' : (in_array('lastname', $cols, true) ? 'lastname' : 'lastname');
    $colPic    = in_array('profile_picture', $cols, true) ? 'profile_picture' : (in_array('profile_pic', $cols, true) ? 'profile_pic' : 'profile_pic');

    // Update main profile info
    $sql = "UPDATE students SET `$colFirst` = ?, `$colMiddle` = ?, `$colLast` = ?, email = ?, department = ?, course_strand = ?, `$colPic` = ? WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$firstname, $middlename, $lastname, $email, $department, $course_strand, $updated_profile_pic, $student_id]);

    // Handle password change if provided
    if (!empty($_POST['current_password']) && !empty($_POST['new_password']) && !empty($_POST['confirm_password'])) {
        $stmt = $conn->prepare("SELECT password FROM students WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_hash = $row['password'] ?? '';

        if (password_verify($_POST['current_password'], $current_hash)) {
            if ($_POST['new_password'] === $_POST['confirm_password']) {
                $new_hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE students SET password = ?, last_password_change = NOW() WHERE student_id = ?");
                $stmt->execute([$new_hash, $student_id]);
            } else {
                throw new Exception("New passwords do not match.");
            }
        } else {
            throw new Exception("Current password is incorrect.");
        }
    }

    $conn->commit();

    // Update session variables
    $_SESSION['firstname'] = $firstname;
    $_SESSION['middlename'] = $middlename;
    $_SESSION['lastname'] = $lastname;
    $_SESSION['email'] = $email;
    $_SESSION['profile_pic'] = $updated_profile_pic;
    $_SESSION['department'] = $department;
    $_SESSION['course_strand'] = $course_strand;

    $_SESSION['success'] = "Profile updated successfully!";
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $_SESSION['error'] = "Error updating profile: " . $e->getMessage();
}

header("Location: student_dashboard.php");
exit();
