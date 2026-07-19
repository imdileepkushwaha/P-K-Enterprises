<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require_once 'includes/settings_helper.php';
require_once 'includes/employee_portal_helper.php';
require 'includes/employee_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: employees.php');
    exit;
}

$emp_id = trim($_POST['emp_id'] ?? '');
$redirect = trim($_POST['redirect'] ?? '');
$action = trim($_POST['action'] ?? 'set');

if ($emp_id === '') {
    $_SESSION['flash_message'] = 'Invalid employee.';
    $_SESSION['flash_success'] = false;
    header('Location: employees.php');
    exit;
}

$allowed_redirect = ($redirect !== '' && strpos($redirect, 'employee_view.php?emp_id=') === 0);
$fallback_redirect = 'employee_view.php?emp_id=' . urlencode($emp_id);
$redirect = $allowed_redirect ? $redirect : $fallback_redirect;

require_csrf_or_redirect($redirect);

$employee = require_employee_branch_access($conn, $emp_id);
$settings = get_all_settings($conn);

if ($action === 'reset_default') {
    $result = admin_reset_employee_portal_password_to_default($conn, $emp_id, $settings);
} else {
    $result = admin_set_employee_portal_password(
        $conn,
        $emp_id,
        $_POST['new_password'] ?? '',
        $_POST['confirm_password'] ?? ''
    );
}

$_SESSION['flash_message'] = $result['message'];
$_SESSION['flash_success'] = $result['ok'];
if (!$result['ok']) {
    $separator = strpos($redirect, '?') === false ? '?' : '&';
    $redirect .= $separator . 'open_portal_password=1';
}

header('Location: ' . $redirect);
exit;
