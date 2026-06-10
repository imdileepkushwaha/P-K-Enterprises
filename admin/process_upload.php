<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require 'includes/attendance_import.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['attendance_file'])) {
    header('Location: upload_attendance.php');
    exit;
}

require_csrf_or_redirect('upload_attendance.php');

$file = $_FILES['attendance_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'File upload error.';
    header('Location: upload_attendance.php');
    exit;
}

$allowed = ['csv', 'xlsx', 'xls'];
$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($file_ext, $allowed, true)) {
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'Invalid file format. Use CSV or Excel (.csv, .xlsx, .xls).';
    header('Location: upload_attendance.php');
    exit;
}

$upload_month = (int) ($_POST['upload_month'] ?? date('n'));
$upload_year = (int) ($_POST['upload_year'] ?? date('Y'));

if ($upload_month < 1 || $upload_month > 12 || $upload_year < 2000) {
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'Please select a valid attendance month and year.';
    header('Location: upload_attendance.php');
    exit;
}

if (is_payroll_period_locked($conn, $upload_year, $upload_month)) {
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'This payroll period is locked. Reopen it from the dashboard before importing attendance.';
    header('Location: upload_attendance.php?month=' . $upload_month . '&year=' . $upload_year);
    exit;
}

$parsed = read_attendance_file_rows($file['tmp_name'], $file_ext);

if (isset($parsed['error'])) {
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = $parsed['error'];
    header('Location: upload_attendance.php');
    exit;
}

$upload_branch_id = branch_id_for_write();
if (SHOW_BRANCH_SELECTOR && get_active_branch_id() === null) {
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'Select a branch from the top bar before importing attendance.';
    header('Location: upload_attendance.php?month=' . $upload_month . '&year=' . $upload_year);
    exit;
}

$preview_only = !empty($_POST['preview_only']);
$result = process_attendance_upload($conn, $parsed['rows'], $upload_year, $upload_month, $preview_only, $upload_branch_id);
$period_label = date('F Y', mktime(0, 0, 0, $upload_month, 1, $upload_year));
$format_note = ($result['format'] ?? '') === 'wide' ? ' (grid)' : '';

if ($preview_only) {
    if (!empty($_SESSION['upload_pending']['token'])) {
        attendance_upload_delete_pending($_SESSION['upload_pending']['token']);
    }
    $pending_token = attendance_upload_save_pending($parsed['rows'], $upload_year, $upload_month, $upload_branch_id, [
        'result' => $result,
        'filename' => $file['name'],
    ]);
    $_SESSION['upload_pending'] = [
        'token' => $pending_token,
        'year' => $upload_year,
        'month' => $upload_month,
    ];
    $_SESSION['upload_success'] = true;
    $skip_note = $result['error_count'] + ($result['wrong_month_count'] ?? 0);
    $protected_note = (int) ($result['protected_skip_count'] ?? 0);
    $_SESSION['upload_message'] = sprintf(
        'Preview for %s%s: %d record(s) would be saved for %d employee(s).',
        $period_label,
        $format_note,
        $result['success_count'],
        (int) ($result['employee_count'] ?? 0)
    );
    if ($skip_note > 0) {
        $_SESSION['upload_message'] .= ' Skipped: ' . $skip_note . '.';
    }
    if ($protected_note > 0) {
        $_SESSION['upload_message'] .= ' Protected (holiday / WO / leave): ' . $protected_note . '.';
    }
    header('Location: upload_attendance.php?preview=1&month=' . $upload_month . '&year=' . $upload_year);
    exit;
}

$msg = sprintf(
    'Attendance for %s%s: %d processed, %d saved.',
    $period_label,
    $format_note,
    $result['row_count'],
    $result['success_count']
);
if (($result['protected_skip_count'] ?? 0) > 0) {
    $msg .= ' Protected (holiday / WO / approved leave kept): ' . $result['protected_skip_count'] . '.';
}
if ($result['error_count'] > 0) {
    $msg .= ' Skipped: ' . $result['error_count'] . '.';
}

$_SESSION['upload_success'] = $result['success_count'] > 0;
$_SESSION['upload_message'] = $msg;
header('Location: upload_attendance.php');
exit;
