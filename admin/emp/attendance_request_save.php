<?php
require_once __DIR__ . '/../includes/employee_portal_auth.php';
require_once __DIR__ . '/../includes/csrf_helper.php';
require_once __DIR__ . '/../includes/settings_helper.php';
init_employee_session();
require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

enforce_employee_session();
require_csrf_or_redirect('attendance.php');

$employee = require_logged_in_employee($conn);
$settings = get_all_settings($conn);
$emp_id = $employee['emp_id'];
$branch_id = (int) $employee['branch_id'];

$result = create_employee_attendance_request(
    $conn,
    $emp_id,
    $branch_id,
    trim($_POST['attendance_date'] ?? ''),
    trim($_POST['status'] ?? ''),
    trim($_POST['leave_type'] ?? ''),
    $_POST['employee_note'] ?? '',
    $settings
);

$_SESSION['emp_flash_message'] = $result['message'];
$_SESSION['emp_flash_success'] = $result['ok'];
$redirect = trim($_POST['redirect'] ?? 'attendance.php');
if (!preg_match('/^(dashboard|attendance|details)\.php(\?[\w=&.-]*)?$/', $redirect)) {
    $redirect = 'attendance.php';
}
header('Location: ' . $redirect);
exit;
