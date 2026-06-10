<?php
require_once __DIR__ . '/../includes/employee_portal_auth.php';
require_once __DIR__ . '/../includes/csrf_helper.php';
require_once __DIR__ . '/../includes/settings_helper.php';
init_employee_session();
require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: leave.php');
    exit;
}

enforce_employee_session();
require_csrf_or_redirect('leave.php');

$employee = require_logged_in_employee($conn);
$settings = get_all_settings($conn);

$result = create_employee_leave_request(
    $conn,
    $employee['emp_id'],
    (int) $employee['branch_id'],
    trim($_POST['from_date'] ?? ''),
    trim($_POST['to_date'] ?? ''),
    trim($_POST['leave_type'] ?? ''),
    $_POST['employee_note'] ?? '',
    $settings
);

$_SESSION['emp_flash_message'] = $result['message'];
$_SESSION['emp_flash_success'] = $result['ok'];
$redirect = trim($_POST['redirect'] ?? 'leave.php');
if (!preg_match('/^leave\.php(\?[\w=&.-]*)?$/', $redirect)) {
    $redirect = 'leave.php';
}
header('Location: ' . $redirect);
exit;
