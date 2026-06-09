<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require_once 'includes/payroll_extensions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: employees.php');
    exit;
}

require_csrf_or_redirect('employees.php');

$emp_id = trim($_POST['emp_id'] ?? '');
$month = (int) ($_POST['month'] ?? date('n'));
$year = (int) ($_POST['year'] ?? date('Y'));
$redirect = 'employee_view.php?emp_id=' . urlencode($emp_id) . '&month=' . $month . '&year=' . $year;

require_employee_branch_access($conn, $emp_id, $redirect);

if (is_payroll_period_locked($conn, $year, $month)) {
    $_SESSION['flash_message'] = 'Period is locked.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

$action = $_POST['adj_action'] ?? 'add';
if ($action === 'delete') {
    $id = (int) ($_POST['adj_id'] ?? 0);
    $stmt = $conn->prepare('DELETE FROM payroll_adjustments WHERE id = ? AND emp_id = ?');
    $stmt->bind_param('is', $id, $emp_id);
    $stmt->execute();
    $_SESSION['flash_message'] = 'Adjustment removed.';
    $_SESSION['flash_success'] = true;
    header('Location: ' . $redirect);
    exit;
}

$adj_type = $_POST['adj_type'] ?? 'bonus';
$label = trim($_POST['label'] ?? '');
$amount = (float) ($_POST['amount'] ?? 0);
$allowed = ['bonus', 'incentive', 'deduction'];

if (!in_array($adj_type, $allowed, true) || $label === '' || $amount <= 0) {
    $_SESSION['flash_message'] = 'Enter valid adjustment type, label, and amount.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

$stmt = $conn->prepare('INSERT INTO payroll_adjustments (emp_id, period_year, period_month, adj_type, label, amount) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->bind_param('siissd', $emp_id, $year, $month, $adj_type, $label, $amount);
$stmt->execute();

$_SESSION['flash_message'] = 'Payroll adjustment added.';
$_SESSION['flash_success'] = true;
header('Location: ' . $redirect);
exit;
