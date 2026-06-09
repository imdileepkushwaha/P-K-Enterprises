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
require_employee_branch_access($conn, $emp_id);
$month = (int) ($_POST['month'] ?? date('n'));
$year = (int) ($_POST['year'] ?? date('Y'));
$redirect = 'employee_view.php?emp_id=' . urlencode($emp_id) . '&month=' . $month . '&year=' . $year;

$use_custom = !empty($_POST['use_custom']) ? 1 : 0;

$pct_basic = $_POST['pct_basic'] !== '' ? (float) $_POST['pct_basic'] : null;
$pct_hra = $_POST['pct_hra'] !== '' ? (float) $_POST['pct_hra'] : null;
$pct_conveyance = $_POST['pct_conveyance'] !== '' ? (float) $_POST['pct_conveyance'] : null;
$pct_medical = $_POST['pct_medical'] !== '' ? (float) $_POST['pct_medical'] : null;
$pct_special = $_POST['pct_special'] !== '' ? (float) $_POST['pct_special'] : null;
$pct_pf = $_POST['pf_percent'] !== '' ? (float) $_POST['pf_percent'] : null;
$professional_tax = $_POST['professional_tax'] !== '' ? (float) $_POST['professional_tax'] : null;

$stmt = $conn->prepare("
    INSERT INTO employee_payroll_profiles
        (emp_id, use_custom, pct_basic, pct_hra, pct_conveyance, pct_medical, pct_special, pf_percent, professional_tax)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        use_custom = VALUES(use_custom),
        pct_basic = VALUES(pct_basic),
        pct_hra = VALUES(pct_hra),
        pct_conveyance = VALUES(pct_conveyance),
        pct_medical = VALUES(pct_medical),
        pct_special = VALUES(pct_special),
        pf_percent = VALUES(pf_percent),
        professional_tax = VALUES(professional_tax)
");
$pct_basic = $pct_basic ?? 0;
$pct_hra = $pct_hra ?? 0;
$pct_conveyance = $pct_conveyance ?? 0;
$pct_medical = $pct_medical ?? 0;
$pct_special = $pct_special ?? 0;
$pct_pf = $pct_pf ?? 0;
$professional_tax = $professional_tax ?? 0;

$stmt->bind_param(
    'siddddddd',
    $emp_id,
    $use_custom,
    $pct_basic,
    $pct_hra,
    $pct_conveyance,
    $pct_medical,
    $pct_special,
    $pct_pf,
    $professional_tax
);
$stmt->execute();

$_SESSION['flash_message'] = 'Payroll profile saved.';
$_SESSION['flash_success'] = true;
header('Location: ' . $redirect);
exit;
