<?php
require_once __DIR__ . '/../includes/employee_portal_auth.php';
enforce_employee_session();
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/salary_helper.php';
require_once __DIR__ . '/../includes/pdf_slip.php';

$employee = require_logged_in_employee($conn);
$month = (int) ($_GET['month'] ?? 0);
$year = (int) ($_GET['year'] ?? 0);

if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
    header('Location: salary_slips.php');
    exit;
}

if (!employee_slip_already_sent($conn, $employee['emp_id'], $year, $month)) {
    $_SESSION['emp_flash_message'] = 'Salary slip is not available for this period yet.';
    $_SESSION['emp_flash_success'] = false;
    header('Location: salary_slips.php');
    exit;
}

$settings = get_all_settings($conn);
$salary = calculate_employee_salary_full($conn, $employee, $year, $month, $settings);
$pdf = generate_salary_slip_pdf($conn, $employee, $salary, $settings, $year, $month);
$filename = salary_slip_pdf_filename($employee, $year, $month);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
