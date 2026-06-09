<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require 'config.php';
require 'includes/settings_helper.php';
require_once 'includes/salary_helper.php';
require 'includes/pdf_slip.php';

$emp_id = trim($_GET['emp_id'] ?? '');
$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));

if ($emp_id === '') {
    http_response_code(400);
    echo 'Missing employee ID';
    exit;
}

$employee = require_employee_branch_access($conn, $emp_id, 'employees.php');
if (!$employee) {
    http_response_code(404);
    echo 'Employee not found';
    exit;
}

$settings = get_all_settings($conn);
$salary = calculate_employee_salary_full($conn, $employee, $year, $month, $settings);
$pdf = generate_salary_slip_pdf($employee, $salary, $settings, $year, $month);
$filename = salary_slip_pdf_filename($employee, $year, $month);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
