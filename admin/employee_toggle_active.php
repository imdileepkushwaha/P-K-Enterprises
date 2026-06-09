<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require 'includes/employee_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: employees.php');
    exit;
}

require_csrf_or_redirect('employees.php');

$emp_id = trim($_POST['emp_id'] ?? '');
if ($emp_id === '') {
    $_SESSION['flash_message'] = 'Invalid employee.';
    $_SESSION['flash_success'] = false;
    header('Location: employees.php');
    exit;
}

$row = require_employee_branch_access($conn, $emp_id);

$new_active = employee_is_active($row) ? 0 : 1;
$update = $conn->prepare('UPDATE employees SET is_active = ? WHERE emp_id = ?');
$update->bind_param('is', $new_active, $emp_id);

if ($update->execute()) {
    $label = $new_active ? 'activated' : 'deactivated';
    $_SESSION['flash_message'] = $row['name'] . ' (' . $emp_id . ') ' . $label . '.';
    if (!$new_active) {
        $_SESSION['flash_message'] .= ' Salary slip emails will not be sent while inactive.';
    }
    $_SESSION['flash_success'] = true;
} else {
    $_SESSION['flash_message'] = 'Could not update employee status.';
    $_SESSION['flash_success'] = false;
}

$redirect = $_POST['redirect'] ?? 'employees.php';
$allowed = ($redirect === 'employees.php') || (strpos($redirect, 'employee_view.php?emp_id=') === 0);
if (!$allowed) {
    $redirect = 'employees.php';
}

header('Location: ' . $redirect);
exit;
