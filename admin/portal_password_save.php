<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: employees.php');
    exit;
}

require_csrf_or_redirect('employees.php');

$emp_id = trim($_POST['emp_id'] ?? '');
$password = trim($_POST['portal_password'] ?? '');
$redirect = trim($_POST['redirect'] ?? 'employees.php');

if ($emp_id === '') {
    $_SESSION['flash_message'] = 'Invalid employee.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

require_employee_branch_access($conn, $emp_id, $redirect);

if (strlen($password) < 6) {
    $_SESSION['flash_message'] = 'Portal password must be at least 6 characters.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

set_employee_portal_password($conn, $emp_id, $password);
$_SESSION['flash_message'] = 'Employee portal password updated for ' . $emp_id . '.';
$_SESSION['flash_success'] = true;
header('Location: ' . $redirect);
exit;
