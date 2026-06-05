<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require 'config.php';
require 'includes/settings_helper.php';
require_once __DIR__ . '/lib/fpdf.php';

$emp_id = trim($_GET['emp_id'] ?? '');
$fy_year = (int) ($_GET['fy'] ?? (date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1));
if ($emp_id === '') {
    http_response_code(400);
    exit('Missing employee');
}

$stmt = $conn->prepare('SELECT * FROM employees WHERE emp_id = ?');
$stmt->bind_param('s', $emp_id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
if (!$employee) {
    http_response_code(404);
    exit('Not found');
}

$settings = get_all_settings($conn);
$profile = get_employee_payroll_profile($conn, $emp_id);
$fy_label = $fy_year . '-' . substr((string) ($fy_year + 1), -2);
$tds_ytd = get_tds_ytd_for_fy($conn, $emp_id, $fy_label);
$company = $settings['company_name'] ?? 'Company';

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Form 16 - Annual TDS Summary', 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'Employer: ' . $company, 0, 1);
$pdf->Cell(0, 6, 'Financial Year: ' . $fy_label, 0, 1);
$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 8, 'Part B - Employee details', 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'Name: ' . $employee['name'], 0, 1);
$pdf->Cell(0, 6, 'Employee ID: ' . $employee['emp_id'], 0, 1);
$pdf->Cell(0, 6, 'PAN: ' . ($employee['pan'] ?? 'N/A'), 0, 1);
$pdf->Cell(0, 6, 'Tax regime: ' . ($profile['tax_regime'] ?? 'new'), 0, 1);
$pdf->Ln(4);
$pdf->Cell(0, 6, 'Total TDS deducted (FY): Rs. ' . number_format($tds_ytd, 2), 0, 1);
$pdf->Cell(0, 6, 'Section 80C declared: Rs. ' . number_format((float) ($profile['section_80c'] ?? 0), 2), 0, 1);
$pdf->Ln(8);
$pdf->SetFont('Arial', 'I', 9);
$pdf->MultiCell(0, 5, 'This is a system-generated summary from payroll TDS ledger. For statutory filing, verify with your CA and full Form 16 Part A (TRACES) records.');

$pdf->Output('I', 'Form16_' . $emp_id . '_' . $fy_label . '.pdf');
exit;
