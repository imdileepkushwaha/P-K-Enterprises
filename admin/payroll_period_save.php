<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require_once 'includes/payroll_extensions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

require_csrf_or_redirect('dashboard.php');

$month = (int) ($_POST['month'] ?? date('n'));
$year = (int) ($_POST['year'] ?? date('Y'));
$action = $_POST['period_action'] ?? '';
$username = $_SESSION['admin_username'] ?? 'admin';
$redirect = 'dashboard.php?month=' . $month . '&year=' . $year;

$map = [
    'submit_review' => 'review',
    'approve' => 'approved',
    'lock' => 'locked',
    'reopen' => 'open',
];

if (!isset($map[$action])) {
    $_SESSION['flash_message'] = 'Invalid payroll period action.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

upsert_payroll_period($conn, $year, $month, $map[$action], $username);

$labels = [
    'review' => 'submitted for review',
    'approved' => 'approved',
    'locked' => 'locked',
    'open' => 'reopened',
];
$_SESSION['flash_message'] = get_period_label($year, $month) . ' payroll ' . $labels[$map[$action]] . '.';
$_SESSION['flash_success'] = true;
header('Location: ' . $redirect);
exit;
