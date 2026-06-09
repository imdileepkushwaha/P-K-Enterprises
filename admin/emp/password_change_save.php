<?php
require_once __DIR__ . '/../includes/employee_portal_auth.php';
require_once __DIR__ . '/../includes/csrf_helper.php';
init_employee_session();
require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

enforce_employee_session();
require_csrf_or_redirect('dashboard.php');

$employee = require_logged_in_employee($conn);
$result = change_employee_portal_password(
    $conn,
    $employee['emp_id'],
    $_POST['current_password'] ?? '',
    $_POST['new_password'] ?? '',
    $_POST['confirm_password'] ?? ''
);

$_SESSION['emp_flash_message'] = $result['message'];
$_SESSION['emp_flash_success'] = $result['ok'];
$redirect = 'dashboard.php';
if (!$result['ok']) {
    $redirect .= '?open_password=1';
}
header('Location: ' . $redirect);
exit;
