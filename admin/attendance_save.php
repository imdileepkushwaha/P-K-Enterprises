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
$date = trim($_POST['attendance_date'] ?? '');
$status = trim($_POST['status'] ?? '');
$leave_type = trim($_POST['leave_type'] ?? '');
$overtime_hours = (float) ($_POST['overtime_hours'] ?? 0);
$action = $_POST['attendance_action'] ?? 'save';
$month = (int) ($_POST['month'] ?? date('n'));
$year = (int) ($_POST['year'] ?? date('Y'));
$redirect = 'employee_view.php?emp_id=' . urlencode($emp_id) . '&month=' . $month . '&year=' . $year;

if ($emp_id === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $_SESSION['flash_message'] = 'Invalid employee or date.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

if (is_payroll_period_locked($conn, $year, $month)) {
    $_SESSION['flash_message'] = 'This payroll period is locked. Unlock it before editing attendance.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

require_employee_branch_access($conn, $emp_id, $redirect);

$existing = $conn->prepare('SELECT status, leave_type, overtime_hours FROM attendance WHERE emp_id = ? AND attendance_date = ?');
$existing->bind_param('ss', $emp_id, $date);
$existing->execute();
$old = $existing->get_result()->fetch_assoc();

if ($action === 'delete') {
    if ($old) {
        $del = $conn->prepare('DELETE FROM attendance WHERE emp_id = ? AND attendance_date = ?');
        $del->bind_param('ss', $emp_id, $date);
        $del->execute();
    }
    $_SESSION['flash_message'] = 'Attendance record removed.';
    $_SESSION['flash_success'] = true;
    header('Location: ' . $redirect);
    exit;
}

$allowed = ['Present', 'Absent', 'Half day', 'Leave', 'Week off'];
if (!in_array($status, $allowed, true)) {
    $_SESSION['flash_message'] = 'Invalid status.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

if ($status === 'Leave') {
    $types = get_leave_types($conn);
    if ($leave_type === '' || !isset($types[$leave_type])) {
        $leave_type = 'CL';
    }
} else {
    $leave_type = null;
}

$overtime_hours = max(0, min(24, $overtime_hours));

$stmt = $conn->prepare('
    INSERT INTO attendance (emp_id, attendance_date, status, leave_type, overtime_hours)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE status = VALUES(status), leave_type = VALUES(leave_type), overtime_hours = VALUES(overtime_hours)
');
$stmt->bind_param('ssssd', $emp_id, $date, $status, $leave_type, $overtime_hours);

if ($stmt->execute()) {
    $_SESSION['flash_message'] = 'Attendance saved for ' . date('d M Y', strtotime($date)) . '.';
    $_SESSION['flash_success'] = true;
} else {
    $_SESSION['flash_message'] = 'Could not save attendance.';
    $_SESSION['flash_success'] = false;
}

header('Location: ' . $redirect);
exit;
