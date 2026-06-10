<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require 'includes/attendance_import.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: upload_attendance.php');
    exit;
}

require_csrf_or_redirect('upload_attendance.php');

$pending = $_SESSION['upload_pending'] ?? null;
$stored = null;
if (is_array($pending) && !empty($pending['token'])) {
    $stored = attendance_upload_load_pending($pending['token']);
}
if (!$pending || !$stored || empty($stored['rows'])) {
    attendance_upload_clear_session_pending();
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'Preview expired or not found. Please upload the file again.';
    header('Location: upload_attendance.php');
    exit;
}

$upload_year = (int) ($pending['year'] ?? $stored['year'] ?? date('Y'));
$upload_month = (int) ($pending['month'] ?? $stored['month'] ?? date('n'));
if (is_payroll_period_locked($conn, $upload_year, $upload_month)) {
    attendance_upload_clear_session_pending();
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'This payroll period is locked. Reopen it from the dashboard before importing attendance.';
    header('Location: upload_attendance.php?month=' . $upload_month . '&year=' . $upload_year);
    exit;
}

$upload_branch_id = (int) ($stored['branch_id'] ?? branch_id_for_write());
if (SHOW_BRANCH_SELECTOR && get_active_branch_id() === null && empty($stored['branch_id'])) {
    attendance_upload_clear_session_pending();
    $_SESSION['upload_success'] = false;
    $_SESSION['upload_message'] = 'Select a branch from the top bar before confirming import.';
    header('Location: upload_attendance.php');
    exit;
}
$result = process_attendance_upload($conn, $stored['rows'], $upload_year, $upload_month, false, $upload_branch_id);
attendance_upload_clear_session_pending();

$upload_month = (int) $pending['month'];
$upload_year = (int) $pending['year'];
$period_label = date('F Y', mktime(0, 0, 0, $upload_month, 1, $upload_year));
$format_note = ($result['format'] ?? '') === 'wide' ? ' (grid format)' : '';

$msg = sprintf(
    'Imported attendance for %s%s: %d records, %d saved.',
    $period_label,
    $format_note,
    $result['row_count'],
    $result['success_count']
);
if (($result['protected_skip_count'] ?? 0) > 0) {
    $msg .= ' Protected (holiday / WO / approved leave kept): ' . $result['protected_skip_count'] . '.';
}
if ($result['error_count'] > 0) {
    $msg .= ' Errors: ' . $result['error_count'] . '.';
}

$_SESSION['upload_success'] = $result['success_count'] > 0;
$_SESSION['upload_message'] = $msg;
header('Location: upload_attendance.php');
exit;
