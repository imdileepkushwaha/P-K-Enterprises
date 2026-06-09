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

if ($emp_id === '') {
    $_SESSION['flash_message'] = 'Invalid employee.';
    $_SESSION['flash_success'] = false;
    header('Location: employees.php');
    exit;
}

$row = require_employee_branch_access($conn, $emp_id);

$name = $row['name'];

$att = $conn->prepare('DELETE FROM attendance WHERE emp_id = ?');
$att->bind_param('s', $emp_id);
$att->execute();

$logs = $conn->prepare('DELETE FROM salary_slip_logs WHERE emp_id = ?');
$logs->bind_param('s', $emp_id);
$logs->execute();

$del = $conn->prepare('DELETE FROM employees WHERE emp_id = ?');
$del->bind_param('s', $emp_id);

if ($del->execute() && $del->affected_rows > 0) {
    $_SESSION['flash_message'] = 'Employee "' . $name . '" (' . $emp_id . ') deleted successfully.';
    $_SESSION['flash_success'] = true;
} else {
    $_SESSION['flash_message'] = 'Could not delete employee.';
    $_SESSION['flash_success'] = false;
}

header('Location: employees.php');
exit;
