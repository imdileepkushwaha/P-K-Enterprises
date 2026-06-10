<?php
require_once __DIR__ . '/../includes/employee_portal_auth.php';
init_employee_session();
require_once __DIR__ . '/../includes/csrf_helper.php';
require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

require_csrf_or_redirect('login.php');

$emp_id = strtoupper(trim($_POST['emp_id'] ?? ''));
$password = $_POST['password'] ?? '';

if ($emp_id === '' || $password === '') {
    $_SESSION['employee_login_error'] = 'Please enter Employee ID and password.';
    header('Location: login.php');
    exit;
}

$employee = get_employee_portal_profile($conn, $emp_id);
if (!$employee) {
    $_SESSION['employee_login_error'] = 'Invalid Employee ID or account is inactive.';
    header('Location: login.php');
    exit;
}

$hash = get_employee_portal_password_hash($conn, $emp_id);
if (!$hash || !password_verify($password, $hash)) {
    $_SESSION['employee_login_error'] = 'Invalid Employee ID or password.';
    header('Location: login.php');
    exit;
}

set_employee_session_on_login($emp_id, (int) $employee['branch_id'], $employee['name']);
header('Location: dashboard.php');
exit;
