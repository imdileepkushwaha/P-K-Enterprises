<?php

require_once __DIR__ . '/../lib/fpdf.php';
require_once __DIR__ . '/signature_helper.php';

function pdf_set_fill($pdf, $r, $g, $b)
{
    $pdf->SetFillColor($r, $g, $b);
}

function pdf_set_draw($pdf, $r, $g, $b)
{
    $pdf->SetDrawColor($r, $g, $b);
}

function pdf_set_text($pdf, $r, $g, $b)
{
    $pdf->SetTextColor($r, $g, $b);
}

function pdf_section_bar($pdf, $title, $width = 190)
{
    pdf_set_fill($pdf, 248, 250, 252);
    pdf_set_draw($pdf, 226, 232, 240);
    pdf_set_text($pdf, 51, 65, 85);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($width, 6, '  ' . strtoupper($title), 'LTR', 1, 'L', true);
}

function pdf_kv_row($pdf, $label, $value, $labelW = 42, $rowH = 6.5)
{
    pdf_set_draw($pdf, 226, 232, 240);
    pdf_set_fill($pdf, 248, 250, 252);
    pdf_set_text($pdf, 100, 116, 139);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell($labelW, $rowH, ' ' . $label, 1, 0, 'L', true);
    pdf_set_fill($pdf, 255, 255, 255);
    pdf_set_text($pdf, 30, 41, 59);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(190 - $labelW, $rowH, $value, 1, 1, 'L');
}

function pdf_money_row($pdf, $label, $value, $opts = [])
{
    $rowH = $opts['h'] ?? 6.5;
    $bold = !empty($opts['bold']);
    $accent = $opts['accent'] ?? null;

    if ($accent === 'earn') {
        pdf_set_fill($pdf, 240, 253, 244);
    } elseif ($accent === 'ded') {
        pdf_set_fill($pdf, 254, 242, 242);
    } elseif ($bold) {
        pdf_set_fill($pdf, 248, 250, 252);
    } else {
        pdf_set_fill($pdf, 255, 255, 255);
    }

    pdf_set_draw($pdf, 226, 232, 240);
    pdf_set_text($pdf, 51, 65, 85);
    $pdf->SetFont('Arial', $bold ? 'B' : '', $bold ? 8 : 7);
    $pdf->Cell(115, $rowH, '  ' . $label, 1, 0, 'L', true);

    if ($accent === 'ded') {
        pdf_set_text($pdf, 185, 28, 28);
    } else {
        pdf_set_text($pdf, 30, 41, 59);
    }
    $pdf->SetFont('Arial', $bold ? 'B' : '', $bold ? 8 : 7);
    $pdf->Cell(75, $rowH, $value, 1, 1, 'R', true);
}

function generate_salary_slip_pdf($employee, $salary, $settings, $year, $month)
{
    $company = $settings['company_name'] ?? 'Company';
    $period = get_period_label($year, $month);
    $generated = date('d M Y');
    $breakdown = $salary['breakdown'] ?? build_salary_component_breakdown($salary, $settings);

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $x = 10;
    $w = 190;

    // Header
    pdf_set_fill($pdf, 30, 41, 59);
    $pdf->Rect($x, 10, $w, 28, 'F');
    pdf_set_fill($pdf, 79, 70, 229);
    $pdf->Rect($x, 10, 5, 28, 'F');

    pdf_set_text($pdf, 255, 255, 255);
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->SetXY($x + 10, 14);
    $pdf->Cell(115, 8, $company, 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'SALARY SLIP', 0, 1, 'R');
    $pdf->SetX($x + 10);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(115, 5, 'Official Pay Statement', 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(60, 5, $period, 0, 1, 'R');
    $pdf->SetX($x + 10);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(175, 4, 'Generated: ' . $generated, 0, 1, 'R');

    $pdf->SetY(40);

    // Employee details (2 columns)
    pdf_section_bar($pdf, 'Employee Details', $w);

    $pairs = [
        ['Full Name', $employee['name'], 'Employee ID', $employee['emp_id']],
        ['Department', $employee['department'] ?: '-', 'Designation', $employee['designation'] ?: '-'],
        ['PAN', trim($employee['pan'] ?? '') ?: '-', 'Bank Account', trim($employee['bank_account'] ?? '') ?: '-'],
    ];

    foreach ($pairs as $pair) {
        $y = $pdf->GetY();
        pdf_set_draw($pdf, 226, 232, 240);
        pdf_set_fill($pdf, 248, 250, 252);
        pdf_set_text($pdf, 100, 116, 139);
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(28, 6.5, ' ' . $pair[0], 1, 0, 'L', true);
        pdf_set_fill($pdf, 255, 255, 255);
        pdf_set_text($pdf, 30, 41, 59);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(67, 6.5, $pair[1], 1, 0, 'L');
        pdf_set_fill($pdf, 248, 250, 252);
        pdf_set_text($pdf, 100, 116, 139);
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(28, 6.5, ' ' . $pair[2], 1, 0, 'L', true);
        pdf_set_fill($pdf, 255, 255, 255);
        pdf_set_text($pdf, 30, 41, 59);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(67, 6.5, $pair[3], 1, 1, 'L');
        if ($pdf->GetY() <= $y) {
            $pdf->SetY($y + 6.5);
        }
    }

    $pdf->Ln(3);

    // Attendance summary (single row, 4 cells)
    pdf_section_bar($pdf, 'Attendance Summary', $w);
    $cols = [
        ['Paid Days', format_money($salary['paid_days'] ?? 0) . ' / ' . (int) $salary['working_days']],
        ['Present', (string) (int) $salary['present_days']],
        ['Half / Leave', (int) ($salary['half_days'] ?? 0) . ' / ' . (int) ($salary['leave_days'] ?? 0)],
        ['Absent', (string) (int) $salary['absent_days']],
    ];
    $cw = 47.5;
    pdf_set_draw($pdf, 226, 232, 240);
    foreach ($cols as $i => $col) {
        pdf_set_fill($pdf, 241, 245, 249);
        pdf_set_text($pdf, 100, 116, 139);
        $pdf->SetFont('Arial', '', 6);
        $pdf->Cell($cw, 5, ' ' . strtoupper($col[0]), 'LTR', 0, 'L', true);
    }
    $pdf->Ln(5);
    $pdf->SetX($x);
    foreach ($cols as $i => $col) {
        pdf_set_fill($pdf, 255, 255, 255);
        pdf_set_text($pdf, 30, 41, 59);
        $pdf->SetFont('Arial', 'B', 11);
        $border = $i === 3 ? 'LBR' : 'LTR';
        $pdf->Cell($cw, 8, $col[1], $border, 0, 'C', true);
    }
    $pdf->Ln(8);

    // Earnings
    pdf_section_bar($pdf, 'Earnings', $w);
    pdf_set_fill($pdf, 30, 41, 59);
    pdf_set_text($pdf, 255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7);
    pdf_set_draw($pdf, 30, 41, 59);
    $pdf->Cell(115, 6, '  Component', 1, 0, 'L', true);
    $pdf->Cell(25, 6, '%', 1, 0, 'C', true);
    $pdf->Cell(50, 6, 'Amount (INR)', 1, 1, 'R', true);

    foreach ($breakdown['earnings'] as $line) {
        pdf_money_row($pdf, $line['label'], format_money($line['period']) . '   (' . $line['percent_label'] . ')');
    }
    pdf_money_row($pdf, 'Gross Earnings', format_money($breakdown['earnings_period_total']), ['bold' => true, 'accent' => 'earn']);

    $pdf->Ln(2);

    // Deductions
    pdf_section_bar($pdf, 'Deductions', $w);
    pdf_set_fill($pdf, 127, 29, 29);
    pdf_set_text($pdf, 255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7);
    pdf_set_draw($pdf, 127, 29, 29);
    $pdf->Cell(140, 6, '  Component', 1, 0, 'L', true);
    $pdf->Cell(50, 6, 'Amount (INR)', 1, 1, 'R', true);

    foreach ($breakdown['deductions'] as $line) {
        pdf_money_row($pdf, $line['label'] . ' (' . $line['percent_label'] . ')', '-' . format_money($line['period']), ['accent' => 'ded']);
    }
    pdf_money_row($pdf, 'Total Deductions', '-' . format_money($breakdown['deductions_period_total']), ['bold' => true, 'accent' => 'ded']);

    $pdf->Ln(3);

    // Net payable
    pdf_set_fill($pdf, 79, 70, 229);
    pdf_set_draw($pdf, 67, 56, 202);
    pdf_set_text($pdf, 255, 255, 255);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(125, 11, '  NET PAYABLE SALARY', 1, 0, 'L', true);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(65, 11, 'Rs. ' . format_money($breakdown['net_period']), 1, 1, 'R', true);

    $pdf->Ln(2);
    pdf_set_text($pdf, 148, 163, 184);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(
        $w,
        3.5,
        'Calculation: Gross = (Monthly base Rs.' . format_money($salary['base_salary']) . ' / ' . (int) $salary['working_days']
        . ' working days) x paid days; net after PF, PT and other deductions. Amounts in INR.',
        0,
        'L'
    );

    // Signature + footer
    $sigPath = payslip_signature_absolute_path($settings);
    $authorityName = trim($settings['signature_authority_name'] ?? 'Authorized Signatory');
    $blockY = max($pdf->GetY() + 8, 232);

    if ($sigPath) {
        $imgW = 44;
        $imgX = $x + $w - $imgW;
        $pdf->Image($sigPath, $imgX, $blockY, $imgW);
        pdf_set_draw($pdf, 203, 213, 225);
        $pdf->Line($imgX, $blockY + 15, $imgX + $imgW, $blockY + 15);
        $pdf->SetXY($imgX, $blockY + 16);
        pdf_set_text($pdf, 51, 65, 85);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($imgW, 4, $authorityName, 0, 1, 'C');
        $pdf->SetX($imgX);
        $pdf->SetFont('Arial', '', 7);
        pdf_set_text($pdf, 148, 163, 184);
        $pdf->Cell($imgW, 3, 'Authorized Signatory', 0, 1, 'C');

        $pdf->SetXY($x, $blockY);
        pdf_set_text($pdf, 148, 163, 184);
        $pdf->SetFont('Arial', '', 7);
        $pdf->MultiCell(130, 3.5, 'This is a system-generated payslip. Please retain for your records.', 0, 'L');
    }

    $pdf->SetY(285);
    pdf_set_draw($pdf, 226, 232, 240);
    $pdf->Line($x, 285, $x + $w, 285);
    pdf_set_text($pdf, 148, 163, 184);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetX($x);
    $pdf->Cell($w, 4, $company . '  |  Confidential  |  Payroll System', 0, 1, 'C');

    return $pdf->Output('S');
}

function salary_slip_pdf_filename($employee, $year, $month)
{
    $safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '_', $employee['emp_id']);
    return 'Salary_Slip_' . $safe_id . '_' . $year . '_' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.pdf';
}
