<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require_once 'includes/attendance_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: upload_attendance.php');
    exit;
}

$month = (int) ($_POST['month'] ?? date('n'));
$year = (int) ($_POST['year'] ?? date('Y'));
$redirect = 'upload_attendance.php?month=' . $month . '&year=' . $year;

require_csrf_or_redirect($redirect);

if (SHOW_BRANCH_SELECTOR && get_active_branch_id() === null) {
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'Select a branch from the top bar before clearing attendance.';
    header('Location: ' . $redirect);
    exit;
}

if (is_payroll_period_locked($conn, $year, $month)) {
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'This payroll period is locked. Reopen it before clearing attendance.';
    header('Location: ' . $redirect);
    exit;
}

$branch_id = branch_id_for_write();
$result = restore_branch_attendance_baseline_for_month($conn, $branch_id, $year, $month);
$period_label = date('F Y', mktime(0, 0, 0, $month, 1, $year));

$_SESSION['upload_success'] = true;
$_SESSION['upload_message'] = sprintf(
    'Cleared %d attendance record(s) for %s. Restored %d weekoff and %d approved leave day(s). Re-upload the correct Excel file when ready.',
    $result['deleted'],
    $period_label,
    $result['wo_restored'],
    $result['leave_restored']
);
header('Location: ' . $redirect);
exit;
