<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require_once 'includes/holiday_import.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['holiday_file'])) {
    header('Location: holidays.php');
    exit;
}

$upload_year = (int) ($_POST['upload_year'] ?? date('Y'));
$filter_month = (int) ($_POST['filter_month'] ?? 0);
if ($upload_year < 2000 || $upload_year > 2100) {
    $upload_year = (int) date('Y');
}

$redirect = 'holidays.php?year=' . $upload_year;
if ($filter_month >= 1 && $filter_month <= 12) {
    $redirect .= '&month=' . $filter_month;
}

require_csrf_or_redirect($redirect);

if (SHOW_BRANCH_SELECTOR && get_active_branch_id() === null) {
    $_SESSION['flash_message'] = 'Select a branch before uploading holidays.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

$file = $_FILES['holiday_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash_message'] = 'File upload failed. Please try again.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

$allowed = ['csv', 'xlsx', 'xls'];
$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($file_ext, $allowed, true)) {
    $_SESSION['flash_message'] = 'Invalid file format. Use CSV or Excel (.csv, .xlsx, .xls).';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

$parsed = read_attendance_file_rows($file['tmp_name'], $file_ext);
if (isset($parsed['error'])) {
    $_SESSION['flash_message'] = $parsed['error'];
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

$branch_id = branch_id_for_write();
$result = holiday_import_process_rows($conn, $parsed['rows'], $upload_year, $branch_id);

$_SESSION['flash_success'] = $result['saved_count'] > 0;
$_SESSION['flash_message'] = holiday_import_build_message($result, $upload_year);
header('Location: ' . $redirect);
exit;
