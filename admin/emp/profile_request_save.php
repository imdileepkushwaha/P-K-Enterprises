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
require_csrf_or_redirect('details.php');

$employee = require_logged_in_employee($conn);
$emp_id = $employee['emp_id'];
$branch_id = (int) $employee['branch_id'];

$proposed = [
    'email' => $_POST['email'] ?? '',
    'phone' => $_POST['phone'] ?? '',
    'pan' => $_POST['pan'] ?? '',
    'bank_account' => $_POST['bank_account'] ?? '',
    'bank_ifsc' => $_POST['bank_ifsc'] ?? '',
    'bank_name' => $_POST['bank_name'] ?? '',
];
$note = $_POST['employee_note'] ?? '';

$result = create_employee_profile_request($conn, $emp_id, $branch_id, $proposed, $note);
$_SESSION['emp_flash_message'] = $result['message'];
$_SESSION['emp_flash_success'] = $result['ok'];
$redirect = trim($_POST['redirect'] ?? 'details.php');
if (!preg_match('/^(dashboard|attendance|details)\.php(\?[\w=&.-]*)?$/', $redirect)) {
    $redirect = 'details.php';
}
header('Location: ' . $redirect);
exit;
