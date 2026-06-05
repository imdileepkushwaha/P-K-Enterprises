<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require 'config.php';
require 'includes/settings_helper.php';
require_once 'includes/salary_helper.php';
require 'includes/employee_helper.php';

$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$settings = get_all_settings($conn);
$period = get_period_label($year, $month);
$filename = 'payroll_' . $year . '_' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Payroll Export', $period]);
fputcsv($out, ['Emp ID', 'Name', 'Department', 'Active', 'Present', 'Half', 'Leave', 'Absent', 'Paid Days', 'Base', 'Gross Period', 'Deductions', 'Net Payable', 'Email']);

$employees = $conn->query('SELECT * FROM employees ORDER BY name');
while ($emp = $employees->fetch_assoc()) {
    $stats = get_attendance_stats_extended($conn, $emp['emp_id'], $year, $month, $settings);
    $salary = calculate_employee_salary_full($conn, $emp, $year, $month, $settings);
    $bd = $salary['breakdown'];
    fputcsv($out, [
        $emp['emp_id'],
        $emp['name'],
        $emp['department'] ?? '',
        employee_is_active($emp) ? 'Yes' : 'No',
        $stats['present_days'],
        $stats['half_days'],
        $stats['leave_days'],
        $stats['absent_days'],
        $salary['paid_days'],
        $salary['base_salary'],
        $bd['earnings_period_total'],
        $bd['deductions_period_total'],
        $salary['net_salary'],
        $emp['email'] ?? '',
    ]);
}
fclose($out);
exit;
