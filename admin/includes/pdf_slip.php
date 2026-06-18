<?php

require_once __DIR__ . '/../lib/fpdf.php';
require_once __DIR__ . '/signature_helper.php';

function numberToWordsIndian($number) {
    $no = floor($number);
    $point = round($number - $no, 2) * 100;
    $hundred = null;
    $digits_1 = strlen($no);
    $i = 0;
    $str = array();
    $words = array('0' => '', '1' => 'One', '2' => 'Two',
        '3' => 'Three', '4' => 'Four', '5' => 'Five', '6' => 'Six',
        '7' => 'Seven', '8' => 'Eight', '9' => 'Nine',
        '10' => 'Ten', '11' => 'Eleven', '12' => 'Twelve',
        '13' => 'Thirteen', '14' => 'Fourteen',
        '15' => 'Fifteen', '16' => 'Sixteen', '17' => 'Seventeen',
        '18' => 'Eighteen', '19' => 'Nineteen', '20' => 'Twenty',
        '30' => 'Thirty', '40' => 'Forty', '50' => 'Fifty',
        '60' => 'Sixty', '70' => 'Seventy',
        '80' => 'Eighty', '90' => 'Ninety');
    $digits = array('', 'Hundred', 'Thousand', 'Lakh', 'Crore');
    while ($i < $digits_1) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($number) {
            $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
            $str [] = ($number < 21) ? $words[$number] .
                " " . $digits[$counter] . $plural . " " . $hundred
                :
                $words[floor($number / 10) * 10]
                . " " . $words[$number % 10] . " "
                . $digits[$counter] . $plural . " " . $hundred;
        } else {
            $str[] = null;
        }
    }
    $str = array_reverse($str);
    $result = implode('', $str);
    $points = ($point) ?
        " and " . $words[$point / 10] . " " .
        $words[$point = $point % 10] . " Paise" : "";
    return trim($result) . $points . " Only";
}

function generate_salary_slip_pdf($conn, $employee, $salary, $settings, $year, $month)
{
    $company = $settings['company_name'] ?? 'Company';
    $period = strtoupper(date('F Y', mktime(0, 0, 0, $month, 1, $year)));
    $breakdown = $salary['breakdown'] ?? build_salary_component_breakdown($salary, $settings);

    // Get leave balances
    $leave_bals = ['CL' => 0, 'SL' => 0, 'PL' => 0];
    if ($conn) {
        $stmt = $conn->prepare("SELECT leave_type, balance FROM employee_leave_balances WHERE emp_id = ?");
        $stmt->bind_param('s', $employee['emp_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $leave_bals[$row['leave_type']] = $row['balance'];
        }
    }

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(190, 8, $company, 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(190, 6, 'PAY SLIP FOR THE MONTH OF ' . $period, 0, 1, 'C');
    $pdf->Ln(2);

    $pdf->SetFont('Arial', '', 8);
    $cw1 = 25; $cw2 = 45; $cw3 = 20; $cw4 = 40; $cw5 = 20; $cw6 = 40;

    // Row 1
    $pdf->Cell($cw1, 6, 'Emp. Code', 1, 0, 'L');
    $pdf->Cell($cw2, 6, ' ' . $employee['emp_id'], 1, 0, 'L');
    $pdf->Cell($cw3, 6, 'D.O.J.', 1, 0, 'L');
    $pdf->Cell($cw4, 6, ' ' . ($employee['joined_date'] ? date('d-m-Y', strtotime($employee['joined_date'])) : '-'), 1, 0, 'L');
    $pdf->Cell($cw5, 6, 'PAN NO.', 1, 0, 'L');
    $pdf->Cell($cw6, 6, ' ' . ($employee['pan'] ?? '-'), 1, 1, 'L');

    // Row 2
    $pdf->Cell($cw1, 6, 'Emp. Name', 1, 0, 'L');
    $pdf->Cell($cw2, 6, ' ' . $employee['name'], 1, 0, 'L');
    $pdf->Cell($cw3, 6, 'Grade', 1, 0, 'L');
    $pdf->Cell($cw4, 6, ' ' . ($employee['grade'] ?? '-'), 1, 0, 'L');
    $pdf->Cell($cw5, 6, 'P.F. NO.', 1, 0, 'L');
    $pdf->Cell($cw6, 6, ' ' . ($employee['pf_no'] ?? '-'), 1, 1, 'L');

    // Row 3
    $pdf->Cell($cw1, 6, 'Designation', 1, 0, 'L');
    $pdf->Cell($cw2, 6, ' ' . ($employee['designation'] ?? '-'), 1, 0, 'L');
    $pdf->Cell($cw3, 6, 'E.S.I.C. No.', 1, 0, 'L');
    $pdf->Cell($cw4, 6, ' ' . ($employee['esic_no'] ?? '-'), 1, 0, 'L');
    $pdf->Cell($cw5, 6, 'Bank A/c No', 1, 0, 'L');
    $pdf->Cell($cw6, 6, ' ' . ($employee['bank_account'] ?? '-'), 1, 1, 'L');

    // Row 4
    $pdf->Cell($cw1, 6, 'Dept.', 1, 0, 'L');
    $pdf->Cell($cw2, 6, ' ' . ($employee['department'] ?? '-'), 1, 0, 'L');
    $pdf->Cell($cw3, 6, 'U.A.N. No.', 1, 0, 'L');
    $pdf->Cell($cw4, 6, ' ' . ($employee['uan_no'] ?? '-'), 1, 0, 'L');
    $pdf->Cell($cw5, 6, '', 1, 0, 'L');
    $pdf->Cell($cw6, 6, '', 1, 1, 'L');

    // Attendance row
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(190, 6, 'ATTENDANCE', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 8);

    $aw = 190 / 6;
    $pdf->Cell($aw, 6, 'Present: ' . (int)$salary['present_days'], 1, 0, 'L');
    $pdf->Cell($aw, 6, 'Absent: ' . (int)$salary['absent_days'], 1, 0, 'L');
    $pdf->Cell($aw, 6, 'Leaves: ' . (int)($salary['leave_days'] ?? 0), 1, 0, 'L');
    $pdf->Cell($aw, 6, 'Bal. SL: ' . (float)($leave_bals['SL'] ?? 0), 1, 0, 'L');
    $pdf->Cell($aw, 6, 'Bal. PL: ' . (float)($leave_bals['PL'] ?? 0), 1, 0, 'L');
    $pdf->Cell($aw, 6, 'Bal. CL: ' . (float)($leave_bals['CL'] ?? 0), 1, 1, 'L');

    // Salary Table Header
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(63.33, 6, 'EARNINGS', 1, 0, 'C');
    $pdf->Cell(63.33, 6, 'DEDUCTIONS', 1, 0, 'C');
    $pdf->Cell(63.34, 6, 'ADD. BENEFIT', 1, 1, 'C');

    $pdf->SetFont('Arial', '', 8);
    $startY = $pdf->GetY();
    
    // Max rows for earnings and deductions
    $max_rows = max(count($breakdown['earnings']), count($breakdown['deductions']), 6);
    
    $y = $startY;
    for ($i = 0; $i < $max_rows; $i++) {
        $pdf->SetXY(10, $y);
        
        // Earnings
        if (isset($breakdown['earnings'][$i])) {
            $pdf->Cell(43.33, 5, ' ' . $breakdown['earnings'][$i]['label'], 'L', 0, 'L');
            $pdf->Cell(20, 5, format_money($breakdown['earnings'][$i]['period']) . ' ', 'R', 0, 'R');
        } else {
            $pdf->Cell(63.33, 5, '', 'LR', 0, 'L');
        }
        
        // Deductions
        if (isset($breakdown['deductions'][$i])) {
            $pdf->Cell(43.33, 5, ' ' . $breakdown['deductions'][$i]['label'], 'L', 0, 'L');
            $pdf->Cell(20, 5, format_money($breakdown['deductions'][$i]['period']) . ' ', 'R', 0, 'R');
        } else {
            $pdf->Cell(63.33, 5, '', 'LR', 0, 'L');
        }

        // Add benefit (Empty for now)
        $pdf->Cell(63.34, 5, '', 'LR', 1, 'L');
        
        $y += 5;
    }
    
    $pdf->SetXY(10, $y);
    $pdf->SetFont('Arial', 'B', 8);
    
    // Totals
    $pdf->Cell(43.33, 6, ' Total Earnings', 1, 0, 'L');
    $pdf->Cell(20, 6, format_money($breakdown['earnings_period_total']) . ' ', 1, 0, 'R');
    
    $pdf->Cell(43.33, 6, ' Total Deductions', 1, 0, 'L');
    $pdf->Cell(20, 6, format_money($breakdown['deductions_period_total']) . ' ', 1, 0, 'R');
    
    $pdf->Cell(63.34, 6, '', 1, 1, 'L');
    
    // Net Salary
    $net_period_num = (float) str_replace(',', '', $breakdown['net_period']);
    $pdf->Cell(190, 6, 'Net Salary : Rs. ' . format_money($breakdown['net_period']), 1, 1, 'L');
    $pdf->Cell(190, 6, 'In Words : ' . numberToWordsIndian($net_period_num), 1, 1, 'L');
    
    $pdf->Ln(10);
    $sigY = $pdf->GetY();
    $sigPath = payslip_signature_absolute_path($settings);
    if ($sigPath && file_exists($sigPath)) {
        $pdf->Image($sigPath, 42.5, $sigY, 30); // 42.5 is centered for the 95 width column (10 margin + 95/2 - 30/2)
    }

    $pdf->Ln(15);
    $pdf->Cell(95, 6, 'Employer Signature', 0, 0, 'C');
    $pdf->Cell(95, 6, 'Employee Signature', 0, 1, 'C');
    
    return $pdf->Output('S');
}

function salary_slip_pdf_filename($employee, $year, $month)
{
    $safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '_', $employee['emp_id']);
    return 'Salary_Slip_' . $safe_id . '_' . $year . '_' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.pdf';
}
