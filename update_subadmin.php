<?php
session_start();
include 'db.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: subadmin_dashboard.php");
    exit();
}

// Determine if an admin is performing the update
$is_admin = isset($_SESSION['admin_id']);

// Target employee_id: if admin provides a target_id, use it; otherwise use logged-in sub-admin
$target_id = null;
if ($is_admin && isset($_POST['target_id']) && trim($_POST['target_id']) !== '') {
    $target_id = trim($_POST['target_id']);
} elseif (isset($_SESSION['subadmin_id']) && trim((string)$_SESSION['subadmin_id']) !== '') {
    $target_id = trim((string)$_SESSION['subadmin_id']);
}

if ($target_id === null || $target_id === '') {
    $_SESSION['error'] = "No sub-admin selected for update.";
    header('Location: ' . ($is_admin ? 'manage_subadmins.php' : 'subadmin_dashboard.php'));
    exit();
}


// Common fields
$fullname = trim($_POST['fullname'] ?? '');
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$current_password = $_POST['current_password'] ?? '';
// Profile picture handling vars
$newProfilePicName = null; // null means no change; string means set to new; special value false means remove
$oldProfilePic = null;

if ($fullname === '') {
    $_SESSION['error'] = 'Full name is required.';
    header('Location: ' . ($is_admin ? 'manage_subadmins.php' : 'subadmin_dashboard.php'));
    exit();
}

try {
    // Detect available employees columns
    $empCols = [];
    try {
        $qCols = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'");
        $qCols->execute();
        $empCols = $qCols->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Throwable $_) {
        $empCols = [];
    }
    $hasFirstCol = in_array('first_name', $empCols, true) || in_array('firstname', $empCols, true);
    $hasLastCol  = in_array('last_name',  $empCols, true) || in_array('lastname',  $empCols, true);
    $firstColName = in_array('first_name', $empCols, true) ? 'first_name' : (in_array('firstname', $empCols, true) ? 'firstname' : null);
    $lastColName  = in_array('last_name',  $empCols, true) ? 'last_name'  : (in_array('lastname',  $empCols, true)  ? 'lastname'  : null);
    $hasDeptCol   = in_array('department', $empCols, true);
    $hasDeptIdCol = in_array('department_id', $empCols, true);
    $hasPermCol   = in_array('permissions', $empCols, true);
    $hasPicCol    = in_array('profile_pic', $empCols, true);

    // Fetch target employee record by employee_id (string)
    $stmt = $conn->prepare("SELECT * FROM employees WHERE employee_id = ? LIMIT 1");
    $stmt->execute([$target_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['error'] = 'Research Adviser account not found.';
        header('Location: ' . ($is_admin ? 'manage_subadmins.php' : 'subadmin_dashboard.php'));
        exit();
    }

    $changePassword = false;
    $new_hashed = null;

    // Use employees profile_pic column availability
    $hasProfilePicCol = $hasPicCol;

    // Prepare current pic
    $oldProfilePic = $user['profile_pic'] ?? null;

    // Handle remove checkbox
    if (isset($_POST['remove_profile_pic']) && $_POST['remove_profile_pic'] == '1') {
        $newProfilePicName = false; // mark for removal
    }

    // Handle upload if provided
    if (isset($_FILES['profile_pic']) && is_array($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            // Robust MIME detection with fallbacks (some hosts lack mime_content_type)
            $mime = null;
            if (function_exists('mime_content_type')) {
                $mime = @mime_content_type($_FILES['profile_pic']['tmp_name']);
            }
            if (!$mime && function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = @finfo_file($finfo, $_FILES['profile_pic']['tmp_name']);
                    @finfo_close($finfo);
                }
            }
            if (!$mime) {
                // Fallback by extension
                $extGuess = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
                $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
                $mime = $map[$extGuess] ?? '';
            }
            if (isset($allowed[$mime])) {
                $ext = $allowed[$mime];
                $base = pathinfo($_FILES['profile_pic']['name'], PATHINFO_FILENAME);
                $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base);
                $gen = 'subadmin_' . time() . '_' . mt_rand(1000, 9999) . '_' . $safeBase . '.' . $ext;
                $dest = __DIR__ . '/images/' . $gen;
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dest)) {
                    $newProfilePicName = $gen; // set to new
                } else {
                    $_SESSION['error'] = 'Failed to save uploaded profile picture.';
                    header('Location: ' . ($is_admin ? 'manage_subadmins.php' : 'subadmin_dashboard.php'));
                    exit();
                }
            } else {
                $_SESSION['error'] = 'Unsupported profile picture format. Please upload JPG, PNG, or WEBP.';
                header('Location: ' . ($is_admin ? 'manage_subadmins.php' : 'subadmin_dashboard.php'));
                exit();
            }
        } else {
            $_SESSION['error'] = 'Error uploading profile picture.';
            header('Location: ' . ($is_admin ? 'manage_subadmins.php' : 'subadmin_dashboard.php'));
            exit();
        }
    }

    if ($is_admin) {
        // Admin editing another sub-admin: allow updating email, permissions, and password without current password
        $email = trim($_POST['email'] ?? ($user['email'] ?? ''));
        // permissions from UI (if any) or leave unchanged
        $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : null;
        // Determine department: if provided in POST, use it; otherwise keep existing
        $strand = isset($_POST['strand']) ? trim($_POST['strand']) : ($user['department'] ?? null);
        // Resolve department_id from departments when available
        $deptIdVal = null;
        if ($strand !== null && $strand !== '') {
            try {
                $q = $conn->prepare("SELECT department_id FROM departments WHERE LOWER(TRIM(code)) = LOWER(TRIM(?)) OR LOWER(TRIM(department_name)) = LOWER(TRIM(?)) LIMIT 1");
                $q->execute([$strand, $strand]);
                $did = $q->fetchColumn();
                if ($did !== false && $did !== null) {
                    $deptIdVal = (int)$did;
                }
            } catch (Throwable $_) {
                $deptIdVal = null;
            }
        }
        // Role change: only Dean or Research Adviser
        $role_name_raw = isset($_POST['role_name']) ? $_POST['role_name'] : ($user['role'] ?? ($user['employee_type'] ?? 'RESEARCH_ADVISER'));
        $role_key = strtoupper(str_replace(' ', '_', trim((string)$role_name_raw)));
        if ($role_key !== 'DEAN' && $role_key !== 'RESEARCH_ADVISER') {
            $role_key = 'RESEARCH_ADVISER';
        }
        // Resolve role_id if column exists
        $role_id_val = null;
        try {
            $hasRoleIdCol = false;
            $qRID = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'role_id'");
            $qRID->execute();
            $hasRoleIdCol = ((int)$qRID->fetchColumn() > 0);
            if ($hasRoleIdCol) {
                $qr = $conn->prepare("SELECT role_id FROM roles WHERE role_name = ? AND is_active = 1 LIMIT 1");
                $qr->execute([$role_key]);
                $role_id_val = $qr->fetchColumn();
            }
        } catch (Throwable $_) { /* ignore */
        }

        // Basic email validation
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'A valid email is required.';
            header('Location: manage_subadmins.php');
            exit();
        }

        // Email uniqueness check on employees
        $check = $conn->prepare("SELECT employee_id FROM employees WHERE email = ? AND employee_id <> ? LIMIT 1");
        $check->execute([$email, $target_id]);
        if ($check->rowCount() > 0) {
            $_SESSION['error'] = 'Email already in use by another account.';
            header('Location: manage_subadmins.php');
            exit();
        }

        // If admin provided a new password, validate
        if ($new_password !== '' || $confirm_password !== '') {
            if ($new_password === '' || $confirm_password === '') {
                $_SESSION['error'] = 'Please provide the new password and confirmation.';
                header('Location: manage_subadmins.php');
                exit();
            }
            if ($new_password !== $confirm_password) {
                $_SESSION['error'] = 'New passwords do not match.';
                header('Location: manage_subadmins.php');
                exit();
            }
            if (strlen($new_password) < 8) {
                $_SESSION['error'] = 'New password must be at least 8 characters long.';
                header('Location: manage_subadmins.php');
                exit();
            }
            $changePassword = true;
            $new_hashed = password_hash($new_password, PASSWORD_DEFAULT);
        }

        // Split fullname into first/last if columns exist
        $firstVal = $fullname;
        $lastVal = '';
        if ($hasFirstCol || $hasLastCol) {
            $parts = preg_split('/\s+/', trim($fullname));
            if (count($parts) > 1) {
                $lastVal = array_pop($parts);
                $firstVal = trim(implode(' ', $parts));
            } else {
                $firstVal = $fullname;
                $lastVal = '';
            }
        }

        // Build update query (employees)
        $sets = [];
        $params = [];
        if ($firstColName) {
            $sets[] = "$firstColName = ?";
            $params[] = $firstVal;
        }
        if ($lastColName) {
            $sets[] = "$lastColName = ?";
            $params[] = $lastVal;
        }
        $sets[] = "email = ?";
        $params[] = $email;
        if ($hasPermCol) {
            $sets[] = "permissions = ?";
            $params[] = ($permissions !== null ? json_encode($permissions) : ($user['permissions'] ?? null));
        }
        if ($changePassword) {
            $sets[] = "password = ?";
            $params[] = $new_hashed;
        }
        if ($strand !== null && $hasDeptCol) {
            $sets[] = "department = ?";
            $params[] = $strand;
        }
        if ($hasDeptIdCol && $deptIdVal !== null) {
            $sets[] = "department_id = ?";
            $params[] = $deptIdVal;
        }
        // Update textual role columns
        // Detect columns again: prefer employee_type for enum systems else role
        try {
            $hasRoleCol = in_array('role', $empCols, true);
            $hasEmpTypeCol = in_array('employee_type', $empCols, true);
        } catch (Throwable $_) {
            $hasRoleCol = false;
            $hasEmpTypeCol = false;
        }
        if ($hasEmpTypeCol) {
            $sets[] = "employee_type = ?";
            $params[] = $role_key;
        }
        if ($hasRoleCol) {
            $roleUi = ($role_key === 'DEAN') ? 'Dean' : 'Research Adviser';
            $sets[] = "role = ?";
            $params[] = $roleUi;
        }
        if ($role_id_val !== null) {
            $sets[] = "role_id = ?";
            $params[] = $role_id_val;
        }
        if ($hasProfilePicCol) {
            if ($newProfilePicName === false) {
                $sets[] = "profile_pic = NULL";
            } elseif (is_string($newProfilePicName)) {
                $sets[] = "profile_pic = ?";
                $params[] = $newProfilePicName;
            }
        }
        $sql = "UPDATE employees SET " . implode(', ', $sets) . " WHERE employee_id = ?";
        $params[] = $target_id;
        $stmt = $conn->prepare($sql);
        $ok = $stmt->execute($params);

        // Also upsert mapping into roles table if we resolved a role_id
        if ($ok && $role_id_val !== null) {
            try {
                $rname = ($role_key === 'DEAN') ? 'DEAN' : 'RESEARCH_ADVISER';
                $dname = ($role_key === 'DEAN') ? 'Dean' : 'Research Adviser';
                $up = $conn->prepare("INSERT INTO roles (employee_id, role_id, role_name, display_name, is_active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), role_name = VALUES(role_name), display_name = VALUES(display_name), is_active = VALUES(is_active)");
                $up->execute([$target_id, $role_id_val, $rname, $dname]);
            } catch (Throwable $_) { /* ignore mapping failure */
            }
        }

        if ($ok) {
            // Remove old file if replaced or removed
            if ($hasProfilePicCol && $oldProfilePic && ($newProfilePicName === false || is_string($newProfilePicName))) {
                $oldPath = __DIR__ . '/images/' . $oldProfilePic;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $_SESSION['success'] = $changePassword ? 'Password changed successfully.' : 'Research Adviser updated successfully.';
            header('Location: manage_subadmins.php');
            exit();
        } else {
            $_SESSION['error'] = 'No changes were saved.';
            header('Location: manage_subadmins.php');
            exit();
        }
    } else {
        // Sub-admin editing their own profile: require current password to change password, only fullname and password allowed
        if ($current_password !== '' || $new_password !== '' || $confirm_password !== '') {
            // Current password required
            if ($current_password === '') {
                $_SESSION['error'] = 'Current password is required to change your password.';
                header('Location: subadmin_dashboard.php');
                exit();
            }

            // Verify current password
            if (!password_verify($current_password, $user['password'])) {
                $_SESSION['error'] = 'Current password is incorrect.';
                header('Location: subadmin_dashboard.php');
                exit();
            }

            // Validate new password fields
            if ($new_password === '' || $confirm_password === '') {
                $_SESSION['error'] = 'Please provide the new password and confirmation.';
                header('Location: subadmin_dashboard.php');
                exit();
            }

            if ($new_password !== $confirm_password) {
                $_SESSION['error'] = 'New passwords do not match.';
                header('Location: subadmin_dashboard.php');
                exit();
            }

            if (strlen($new_password) < 8) {
                $_SESSION['error'] = 'New password must be at least 8 characters long.';
                header('Location: subadmin_dashboard.php');
                exit();
            }

            $changePassword = true;
            $new_hashed = password_hash($new_password, PASSWORD_DEFAULT);
        }

        // Perform update for self (allow profile_pic change) on employees
        // Split fullname
        $firstVal = $fullname;
        $lastVal = '';
        if ($hasFirstCol || $hasLastCol) {
            $parts = preg_split('/\s+/', trim($fullname));
            if (count($parts) > 1) {
                $lastVal = array_pop($parts);
                $firstVal = trim(implode(' ', $parts));
            }
        }
        $sets = [];
        $params = [];
        if ($firstColName) {
            $sets[] = "$firstColName = ?";
            $params[] = $firstVal;
        }
        if ($lastColName) {
            $sets[] = "$lastColName = ?";
            $params[] = $lastVal;
        }
        if ($changePassword) {
            $sets[] = "password = ?";
            $params[] = $new_hashed;
        }
        if ($hasProfilePicCol) {
            if ($newProfilePicName === false) {
                $sets[] = "profile_pic = NULL";
            } elseif (is_string($newProfilePicName)) {
                $sets[] = "profile_pic = ?";
                $params[] = $newProfilePicName;
            }
        }
        $sql = "UPDATE employees SET " . implode(', ', $sets) . " WHERE employee_id = ?";
        $params[] = $target_id;
        $stmt = $conn->prepare($sql);
        $ok = $stmt->execute($params);

        if ($ok) {
            // Remove old file if replaced or removed
            if ($hasProfilePicCol && $oldProfilePic && ($newProfilePicName === false || is_string($newProfilePicName))) {
                $oldPath = __DIR__ . '/images/' . $oldProfilePic;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
            // Refresh session display name
            if (isset($_SESSION['subadmin_id']) && (string)$_SESSION['subadmin_id'] === (string)$target_id) {
                $_SESSION['subadmin_name'] = $fullname;
            }
            if ($changePassword) {
                $_SESSION['success'] = 'Password changed successfully.';
            } else {
                $_SESSION['success'] = 'Profile updated successfully.';
            }
            header('Location: subadmin_dashboard.php');
            exit();
        } else {
            $_SESSION['error'] = 'No changes were saved.';
            header('Location: subadmin_dashboard.php');
            exit();
        }
    }
} catch (PDOException $e) {
    error_log('update_subadmin.php error: ' . $e->getMessage());
    $_SESSION['error'] = 'Failed to update profile: ' . $e->getMessage();
    header('Location: ' . ($is_admin ? 'manage_subadmins.php' : 'subadmin_dashboard.php'));
    exit();
}
