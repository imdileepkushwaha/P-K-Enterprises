<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require 'includes/settings_helper.php';
require 'includes/mailer.php';
require_once 'includes/payroll_extensions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$emp_id = trim($_POST['emp_id'] ?? '');
$month = (int) ($_POST['month'] ?? date('n'));
$year = (int) ($_POST['year'] ?? date('Y'));
$redirect = 'employee_view.php?emp_id=' . urlencode($emp_id) . '&month=' . $month . '&year=' . $year;

require_csrf_or_redirect($redirect);

$settings = get_all_settings($conn);

if (!is_smtp_configured($settings) || empty($settings['smtp_password'])) {
    $_SESSION['flash_message'] = 'Configure SMTP before resending slips.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

if (!empty($settings['require_payroll_approval']) && (int) $settings['require_payroll_approval'] === 1 && !can_send_slips_for_period($conn, $year, $month)) {
    $_SESSION['flash_message'] = 'Payroll must be approved before sending slips.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

$stmt = $conn->prepare('SELECT * FROM employees WHERE emp_id = ?');
$stmt->bind_param('s', $emp_id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();

if (!$employee || empty($employee['email'])) {
    $_SESSION['flash_message'] = 'Employee not found or has no email.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

$mailer = new SmtpMailer($settings);
if (!$mailer->connect()) {
    $_SESSION['flash_message'] = 'SMTP connection failed.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

$result = send_single_salary_slip($conn, $employee, $year, $month, $settings, $mailer);
$mailer->disconnect();

$_SESSION['flash_message'] = $result['success']
    ? 'Salary slip resent to ' . $employee['email'] . '.'
    : 'Resend failed: ' . ($result['error'] ?? 'Unknown error');
$_SESSION['flash_success'] = $result['success'];
header('Location: ' . $redirect);
exit;
