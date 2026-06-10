<?php

function get_working_days_per_month($settings)
{
    $days = (int) ($settings['working_days_per_month'] ?? 26);
    return $days > 0 ? $days : 26;
}

function get_leave_day_credit($settings)
{
    $v = (float) ($settings['leave_day_credit'] ?? 1);
    return max(0, min(1, $v));
}

function get_half_day_credit($settings)
{
    $v = (float) ($settings['half_day_credit'] ?? 0.5);
    return max(0, min(1, $v));
}

function normalize_status_bucket($status)
{
    $s = strtolower(trim((string) $status));
    $s = preg_replace('/\s+/', ' ', $s);

    if (in_array($s, ['present', 'p'], true)) {
        return 'present';
    }
    if (in_array($s, ['absent', 'a'], true)) {
        return 'absent';
    }
    if (in_array($s, ['half day', 'half-day', 'halfday', 'hd', 'h'], true) || str_starts_with($s, 'half')) {
        return 'half';
    }
    if (in_array($s, ['leave', 'l', 'on leave'], true)) {
        return 'leave';
    }
    if (in_array($s, ['week off', 'weekoff', 'week-off', 'wo', 'w/o', 'off'], true)) {
        return 'weekoff';
    }

    return 'other';
}

function get_attendance_stats_for_period($conn, $emp_id, $year, $month)
{
    $stmt = $conn->prepare("
        SELECT status FROM attendance
        WHERE emp_id = ?
          AND YEAR(attendance_date) = ?
          AND MONTH(attendance_date) = ?
    ");
    $stmt->bind_param('sii', $emp_id, $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();

    $present_days = 0;
    $absent_days = 0;
    $half_days = 0;
    $leave_days = 0;
    $other_days = 0;

    while ($row = $result->fetch_assoc()) {
        $bucket = normalize_status_bucket($row['status']);
        switch ($bucket) {
            case 'present':
                $present_days++;
                break;
            case 'absent':
                $absent_days++;
                break;
            case 'half':
                $half_days++;
                break;
            case 'leave':
                $leave_days++;
                break;
            default:
                $other_days++;
        }
    }

    $total_records = $present_days + $absent_days + $half_days + $leave_days + $other_days;

    return [
        'present_days' => $present_days,
        'absent_days' => $absent_days,
        'half_days' => $half_days,
        'leave_days' => $leave_days,
        'other_days' => $other_days,
        'total_records' => $total_records,
    ];
}

function calculate_paid_days(array $stats, $settings)
{
    $half_credit = get_half_day_credit($settings);
    $leave_credit = get_leave_day_credit($settings);

    return round(
        (float) $stats['present_days']
        + (float) $stats['half_days'] * $half_credit
        + (float) $stats['leave_days'] * $leave_credit,
        2
    );
}

function get_salary_earning_components($settings = [])
{
    $components = [
        ['id' => 'basic', 'label' => 'Basic Salary', 'key' => 'pct_basic', 'default' => 50.0, 'hint' => 'Core component'],
        ['id' => 'hra', 'label' => 'HRA (House Rent Allowance)', 'key' => 'pct_hra', 'default' => 20.0, 'hint' => '40% of Basic'],
        ['id' => 'conveyance', 'label' => 'Conveyance Allowance', 'key' => 'pct_conveyance', 'default' => 5.0, 'hint' => 'Transport'],
        ['id' => 'medical', 'label' => 'Medical Allowance', 'key' => 'pct_medical', 'default' => 5.0, 'hint' => 'Health benefit'],
        ['id' => 'special', 'label' => 'Special Allowance', 'key' => 'pct_special', 'default' => 20.0, 'hint' => 'Flexible pay'],
    ];

    $out = [];
    foreach ($components as $c) {
        $pct = (float) ($settings[$c['key']] ?? $c['default']);
        $out[] = [
            'id' => $c['id'],
            'label' => $c['label'],
            'hint' => $c['hint'],
            'percent' => $pct,
        ];
    }

    return $out;
}

function calculate_employee_salary($employee, $stats, $settings)
{
    $base_salary = (float) ($employee['base_salary'] ?? 0);
    $working_days = get_working_days_per_month($settings);
    $paid_days = calculate_paid_days($stats, $settings);

    $daily_rate = $working_days > 0 ? $base_salary / $working_days : 0;
    $earned_salary = round($daily_rate * $paid_days, 2);
    $deduction = round($daily_rate * (float) $stats['absent_days'], 2);

    $salary = [
        'base_salary' => $base_salary,
        'working_days' => $working_days,
        'daily_rate' => round($daily_rate, 2),
        'present_days' => (int) $stats['present_days'],
        'absent_days' => (int) $stats['absent_days'],
        'half_days' => (int) $stats['half_days'],
        'leave_days' => (int) $stats['leave_days'],
        'paid_days' => $paid_days,
        'earned_salary' => $earned_salary,
        'deduction' => $deduction,
    ];

    $breakdown = build_salary_component_breakdown($salary, $settings);
    $salary['breakdown'] = $breakdown;
    $salary['net_salary'] = $breakdown['net_period'];

    return $salary;
}

function build_salary_component_breakdown(array $salary, $settings = [])
{
    $gross_monthly = (float) ($salary['base_salary'] ?? 0);
    $working_days = max(1, (int) ($salary['working_days'] ?? 26));
    $paid_days = (float) ($salary['paid_days'] ?? $salary['present_days'] ?? 0);
    $period_gross = (float) ($salary['earned_salary'] ?? 0);
    $attendance_ratio = $working_days > 0 ? min(1, $paid_days / $working_days) : 0;
    if ($gross_monthly > 0 && $period_gross > 0) {
        $attendance_ratio = min(1, $period_gross / $gross_monthly);
    }

    $earnings = [];
    $basic_monthly = 0.0;

    foreach (get_salary_earning_components($settings) as $component) {
        $monthly = round($gross_monthly * $component['percent'] / 100, 2);
        $period = round($period_gross * $component['percent'] / 100, 2);
        if ($component['id'] === 'basic') {
            $basic_monthly = $monthly;
        }
        $earnings[] = [
            'id' => $component['id'],
            'label' => $component['label'],
            'hint' => $component['hint'],
            'percent' => $component['percent'],
            'percent_label' => rtrim(rtrim(number_format($component['percent'], 1), '0'), '.') . '%',
            'monthly' => $monthly,
            'period' => $period,
        ];
    }

    $pf_pct = (float) ($settings['pf_percent'] ?? 12);
    $pt = (float) ($settings['professional_tax'] ?? 200);
    $esi_pct = (float) ($settings['esi_percent'] ?? 0.75);
    $esi_limit = (float) ($settings['esi_gross_limit'] ?? 21000);

    $deduction_defs = [
        [
            'id' => 'pf',
            'label' => 'Provident Fund (PF)',
            'hint' => $pf_pct . '% of Basic',
            'percent_label' => $pf_pct . '% of Basic',
            'monthly' => round($basic_monthly * $pf_pct / 100, 2),
        ],
        [
            'id' => 'pt',
            'label' => 'Professional Tax',
            'hint' => 'Statutory',
            'percent_label' => 'Fixed',
            'monthly' => $pt,
        ],
    ];

    if ($gross_monthly > 0 && $gross_monthly <= $esi_limit) {
        $deduction_defs[] = [
            'id' => 'esi',
            'label' => 'ESI (Employee)',
            'hint' => $esi_pct . '% of gross',
            'percent_label' => $esi_pct . '%',
            'monthly' => round($gross_monthly * $esi_pct / 100, 2),
        ];
    }

    $deductions = [];
    foreach ($deduction_defs as $def) {
        $monthly = (float) $def['monthly'];
        $period = round($monthly * $attendance_ratio, 2);
        $deductions[] = array_merge($def, [
            'monthly' => $monthly,
            'period' => $period,
        ]);
    }

    $earnings_monthly_total = round(array_sum(array_column($earnings, 'monthly')), 2);
    $earnings_period_total = round(array_sum(array_column($earnings, 'period')), 2);
    $deductions_monthly_total = round(array_sum(array_column($deductions, 'monthly')), 2);
    $deductions_period_total = round(array_sum(array_column($deductions, 'period')), 2);
    $net_monthly = max(0, round($earnings_monthly_total - $deductions_monthly_total, 2));
    $net_period = max(0, round($earnings_period_total - $deductions_period_total, 2));

    return [
        'gross_monthly' => $gross_monthly,
        'period_gross' => $period_gross,
        'earnings' => $earnings,
        'deductions' => $deductions,
        'earnings_monthly_total' => $earnings_monthly_total,
        'earnings_period_total' => $earnings_period_total,
        'deductions_monthly_total' => $deductions_monthly_total,
        'deductions_period_total' => $deductions_period_total,
        'net_monthly' => $net_monthly,
        'net_period' => $net_period,
        'attendance_ratio' => $attendance_ratio,
        'attendance_percent' => round($attendance_ratio * 100, 1),
        'paid_days' => $paid_days,
        'working_days' => $working_days,
        'daily_rate' => (float) ($salary['daily_rate'] ?? 0),
    ];
}

function get_employee_payroll_bundle($conn, $employee, $year, $month, $settings)
{
    $stats = get_attendance_stats_extended($conn, $employee['emp_id'], $year, $month, $settings);
    $salary = calculate_employee_salary_full($conn, $employee, $year, $month, $settings);

    return [
        'stats' => $stats,
        'salary' => $salary,
        'breakdown' => $salary['breakdown'],
    ];
}

function format_money($amount)
{
    return number_format((float) $amount, 2);
}

function get_period_label($year, $month)
{
    return date('F Y', mktime(0, 0, 0, $month, 1, $year));
}

function render_salary_slip_html($employee, $salary, $settings, $year, $month)
{
    $breakdown = $salary['breakdown'] ?? build_salary_component_breakdown($salary, $settings);
    $company = htmlspecialchars($settings['company_name'] ?? 'Company');
    $period = htmlspecialchars(get_period_label($year, $month));
    $name = htmlspecialchars($employee['name']);
    $emp_id = htmlspecialchars($employee['emp_id']);
    $department = htmlspecialchars($employee['department'] ?? '-');
    $designation = htmlspecialchars($employee['designation'] ?? '-');
    $pan = htmlspecialchars($employee['pan'] ?? '-');
    $bank = htmlspecialchars(trim(($employee['bank_name'] ?? '') . ' ' . ($employee['bank_account'] ?? '')) ?: '-');
    $generated = date('d M Y');

    $rows = '';
    foreach ($breakdown['earnings'] as $line) {
        $rows .= '<tr><td style="padding:9px 14px;border-top:1px solid #e2e8f0;">' . htmlspecialchars($line['label'])
            . ' <span style="color:#94a3b8;font-size:11px;">(' . htmlspecialchars($line['percent_label']) . ')</span></td>'
            . '<td style="padding:9px 14px;border-top:1px solid #e2e8f0;text-align:right;">' . format_money($line['period']) . '</td></tr>';
    }
    $rows .= '<tr style="background:#f8fafc;"><td style="padding:10px 14px;border-top:1px solid #e2e8f0;font-weight:700;">Gross earnings</td>'
        . '<td style="padding:10px 14px;border-top:1px solid #e2e8f0;text-align:right;font-weight:700;">' . format_money($breakdown['earnings_period_total']) . '</td></tr>';

    foreach ($breakdown['deductions'] as $line) {
        $rows .= '<tr><td style="padding:9px 14px;border-top:1px solid #e2e8f0;color:#b91c1c;">' . htmlspecialchars($line['label'])
            . ' <span style="color:#94a3b8;font-size:11px;">(' . htmlspecialchars($line['percent_label']) . ')</span></td>'
            . '<td style="padding:9px 14px;border-top:1px solid #e2e8f0;text-align:right;color:#b91c1c;">-' . format_money($line['period']) . '</td></tr>';
    }

    $att_note = 'Paid days: ' . format_money($salary['paid_days'] ?? 0) . ' / ' . (int) $salary['working_days']
        . ' (P:' . (int) $salary['present_days'] . ', HD:' . (int) ($salary['half_days'] ?? 0) . ', L:' . (int) ($salary['leave_days'] ?? 0) . ')';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:20px;font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9;color:#1e293b;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
        <tr><td style="background:linear-gradient(135deg,#4f46e5,#4338ca);padding:24px 28px;color:#fff;">
            <div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85;margin-bottom:6px;">Official Salary Slip</div>
            <div style="font-size:22px;font-weight:700;">' . $company . '</div>
            <div style="font-size:14px;margin-top:6px;opacity:.9;">Pay Period: ' . $period . '</div>
        </td></tr>
        <tr><td style="padding:24px 28px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;border:1px solid #e2e8f0;border-radius:6px;">
                <tr><td colspan="2" style="background:#f8fafc;padding:10px 14px;font-size:12px;font-weight:700;color:#64748b;">EMPLOYEE</td></tr>
                <tr><td style="padding:8px 14px;border-top:1px solid #e2e8f0;width:35%;color:#64748b;">Name</td><td style="padding:8px 14px;border-top:1px solid #e2e8f0;font-weight:600;">' . $name . '</td></tr>
                <tr><td style="padding:8px 14px;border-top:1px solid #e2e8f0;color:#64748b;">ID</td><td style="padding:8px 14px;border-top:1px solid #e2e8f0;">' . $emp_id . '</td></tr>
                <tr><td style="padding:8px 14px;border-top:1px solid #e2e8f0;color:#64748b;">Dept / Role</td><td style="padding:8px 14px;border-top:1px solid #e2e8f0;">' . $department . ' / ' . $designation . '</td></tr>
                <tr><td style="padding:8px 14px;border-top:1px solid #e2e8f0;color:#64748b;">PAN</td><td style="padding:8px 14px;border-top:1px solid #e2e8f0;">' . $pan . '</td></tr>
                <tr><td style="padding:8px 14px;border-top:1px solid #e2e8f0;color:#64748b;">Bank</td><td style="padding:8px 14px;border-top:1px solid #e2e8f0;">' . $bank . '</td></tr>
            </table>
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;">
                <tr style="background:#f1f5f9;"><th style="padding:10px 14px;text-align:left;font-size:12px;color:#64748b;">Earnings / Deductions</th>
                <th style="padding:10px 14px;text-align:right;font-size:12px;color:#64748b;">This period (INR)</th></tr>
                ' . $rows . '
                <tr style="background:#ecfdf5;">
                    <td style="padding:14px;font-weight:700;color:#065f46;border-top:2px solid #10b981;">NET PAYABLE</td>
                    <td style="padding:14px;text-align:right;font-weight:700;font-size:16px;color:#065f46;border-top:2px solid #10b981;">Rs. ' . format_money($breakdown['net_period']) . '</td>
                </tr>
            </table>
            <p style="margin:16px 0 0;font-size:11px;color:#94a3b8;text-align:center;">' . htmlspecialchars($att_note) . ' · Generated ' . $generated . '</p>
        </td></tr>
    </table></body></html>';
}

function render_salary_slip_email_html($employee, $salary, $settings, $year, $month)
{
    $breakdown = $salary['breakdown'] ?? build_salary_component_breakdown($salary, $settings);
    $company = htmlspecialchars($settings['company_name'] ?? 'Company');
    $period = htmlspecialchars(get_period_label($year, $month));
    $name = htmlspecialchars($employee['name']);
    $emp_id = htmlspecialchars($employee['emp_id']);
    $dept = htmlspecialchars($employee['department'] ?? '—');
    $designation = htmlspecialchars($employee['designation'] ?? '—');
    $net = format_money($breakdown['net_period']);
    $gross = format_money($breakdown['earnings_period_total']);
    $ded = format_money($breakdown['deductions_period_total']);
    $paid = format_money($salary['paid_days'] ?? 0);
    $working = (int) $salary['working_days'];

    $earn_rows = '';
    foreach ($breakdown['earnings'] as $line) {
        $earn_rows .= '<tr>
            <td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:13px;">' . htmlspecialchars($line['label']) . '</td>
            <td style="padding:8px 8px;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:12px;text-align:center;">' . htmlspecialchars($line['percent_label']) . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:13px;text-align:right;font-weight:600;">&#8377;' . format_money($line['period']) . '</td>
        </tr>';
    }

    $ded_rows = '';
    foreach ($breakdown['deductions'] as $line) {
        $ded_rows .= '<tr>
            <td style="padding:8px 12px;border-bottom:1px solid #fee2e2;color:#334155;font-size:13px;">' . htmlspecialchars($line['label']) . '</td>
            <td style="padding:8px 8px;border-bottom:1px solid #fee2e2;color:#64748b;font-size:12px;text-align:center;">' . htmlspecialchars($line['percent_label']) . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #fee2e2;color:#b91c1c;font-size:13px;text-align:right;font-weight:600;">-&#8377;' . format_money($line['period']) . '</td>
        </tr>';
    }

    return '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Salary Slip — ' . $period . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f1f5f9" style="background-color:#f1f5f9;padding:20px 10px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="background-color:#ffffff;width:600px;max-width:600px;border:1px solid #e2e8f0;">

<!-- Header: dark text on light bar (visible when Gmail/Outlook strip dark backgrounds or use dark mode) -->
<tr>
<td height="5" bgcolor="#4f46e5" style="background-color:#4f46e5;font-size:5px;line-height:5px;mso-line-height-rule:exactly;">&nbsp;</td>
</tr>
<tr>
<td bgcolor="#eef2ff" style="background-color:#eef2ff;padding:22px 30px 24px;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr>
<td align="left" valign="top" style="font-size:11px;line-height:16px;color:#4338ca;font-weight:bold;text-transform:uppercase;letter-spacing:1px;">Official Salary Notification</td>
<td align="right" valign="top" style="font-size:13px;line-height:16px;color:#312e81;font-weight:bold;">' . $period . '</td>
</tr>
<tr>
<td colspan="2" align="left" style="padding:12px 0 0 0;font-size:26px;line-height:32px;color:#1e293b;font-weight:bold;">' . $company . '</td>
</tr>
<tr>
<td colspan="2" align="left" style="padding:8px 0 0 0;font-size:13px;line-height:18px;color:#475569;">Salary slip attached &middot; Payroll System</td>
</tr>
</table>
</td>
</tr>

<!-- Greeting -->
<tr><td style="padding:28px 32px 20px;">
    <p style="margin:0 0 6px;font-size:15px;color:#64748b;">Dear <strong style="color:#1e293b;">' . $name . '</strong>,</p>
    <p style="margin:0;font-size:15px;line-height:1.6;color:#475569;">Your salary for <strong style="color:#4f46e5;">' . $period . '</strong> has been processed. A detailed PDF payslip is attached to this email for your records.</p>
</td></tr>

<!-- Employee chip -->
<tr><td style="padding:0 32px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
    <tr><td style="padding:14px 18px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="font-size:12px;color:#64748b;padding:3px 0;">Employee ID</td>
            <td align="right" style="font-size:13px;font-weight:600;color:#1e293b;padding:3px 0;">' . $emp_id . '</td>
        </tr>
        <tr>
            <td style="font-size:12px;color:#64748b;padding:3px 0;">Department</td>
            <td align="right" style="font-size:13px;color:#1e293b;padding:3px 0;">' . $dept . ' · ' . $designation . '</td>
        </tr>
        <tr>
            <td style="font-size:12px;color:#64748b;padding:3px 0;">Paid days</td>
            <td align="right" style="font-size:13px;color:#1e293b;padding:3px 0;"><strong>' . $paid . '</strong> / ' . $working . ' (P' . (int) $salary['present_days'] . ' · HD' . (int) ($salary['half_days'] ?? 0) . ' · L' . (int) ($salary['leave_days'] ?? 0) . ')</td>
        </tr>
        </table>
    </td></tr>
    </table>
</td></tr>

<!-- Net highlight -->
<tr><td style="padding:0 32px 24px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ecfdf5" style="background-color:#ecfdf5;border:1px solid #6ee7b7;">
    <tr><td align="center" style="padding:22px 24px;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0 0 8px;font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#047857;">Net take-home pay</p>
        <p style="margin:0;font-size:34px;font-weight:bold;color:#065f46;line-height:1.2;">&#8377;' . $net . '</p>
        <p style="margin:10px 0 0;font-size:12px;color:#059669;">Gross &#8377;' . $gross . ' &nbsp;&middot;&nbsp; Deductions &#8377;' . $ded . '</p>
    </td></tr>
    </table>
</td></tr>

<!-- Breakdown summary -->
<tr><td style="padding:0 32px 8px;">
    <p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Earnings</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
    <tr style="background:#f1f5f9;">
        <th align="left" style="padding:8px 12px;font-size:11px;color:#64748b;font-weight:600;">Component</th>
        <th style="padding:8px;font-size:11px;color:#64748b;font-weight:600;">%</th>
        <th align="right" style="padding:8px 12px;font-size:11px;color:#64748b;font-weight:600;">Amount</th>
    </tr>
    ' . $earn_rows . '
    <tr style="background:#ecfdf5;">
        <td colspan="2" style="padding:10px 12px;font-size:13px;font-weight:700;color:#047857;">Gross earnings</td>
        <td align="right" style="padding:10px 12px;font-size:13px;font-weight:700;color:#047857;">&#8377;' . $gross . '</td>
    </tr>
    </table>
</td></tr>

<tr><td style="padding:16px 32px 24px;">
    <p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Deductions</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fecaca;border-radius:8px;overflow:hidden;">
    <tr style="background:#fef2f2;">
        <th align="left" style="padding:8px 12px;font-size:11px;color:#991b1b;font-weight:600;">Component</th>
        <th style="padding:8px;font-size:11px;color:#991b1b;font-weight:600;">Rate</th>
        <th align="right" style="padding:8px 12px;font-size:11px;color:#991b1b;font-weight:600;">Amount</th>
    </tr>
    ' . $ded_rows . '
    <tr style="background:#fef2f2;">
        <td colspan="2" style="padding:10px 12px;font-size:13px;font-weight:700;color:#991b1b;">Total deductions</td>
        <td align="right" style="padding:10px 12px;font-size:13px;font-weight:700;color:#991b1b;">-&#8377;' . $ded . '</td>
    </tr>
    </table>
</td></tr>

<!-- Attachment note -->
<tr><td style="padding:0 32px 28px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;">
    <tr><td style="padding:16px 18px;">
        <p style="margin:0;font-size:14px;line-height:1.5;color:#92400e;">
            <strong style="color:#b45309;">📎 PDF attached</strong> — Open the attachment <em>Salary_Slip_' . htmlspecialchars(preg_replace('/[^a-zA-Z0-9_-]/', '_', $employee['emp_id'])) . '_' . (int) $year . '_' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.pdf</em> for the full printable payslip with signature.
        </p>
    </td></tr>
    </table>
</td></tr>

<!-- Footer -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 32px;text-align:center;">
    <p style="margin:0 0 6px;font-size:12px;color:#64748b;">This is an automated message from the Payroll System.</p>
    <p style="margin:0;font-size:11px;color:#94a3b8;">For queries, contact your HR department. &nbsp;·&nbsp; ' . $company . ' &nbsp;·&nbsp; Confidential</p>
</td></tr>

</table>
</td></tr>
</table>
</body></html>';
}

/**
 * Latest successful send per period for one employee (newest periods first).
 *
 * @return array<int, array{period_month:int, period_year:int, net_salary:string, sent_at:string, sent_to?:string}>
 */
function get_employee_sent_slip_logs($conn, $emp_id, $limit = 24)
{
    $limit = max(1, min(48, (int) $limit));
    $stmt = $conn->prepare("
        SELECT l.period_month, l.period_year, l.net_salary, l.sent_at, l.sent_to
        FROM salary_slip_logs l
        INNER JOIN (
            SELECT period_month, period_year, MAX(id) AS max_id
            FROM salary_slip_logs
            WHERE emp_id = ? AND status = 'sent'
            GROUP BY period_year, period_month
        ) latest ON l.id = latest.max_id
        ORDER BY l.period_year DESC, l.period_month DESC
        LIMIT " . $limit . "
    ");
    $stmt->bind_param('s', $emp_id);
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function get_employee_recent_sent_slip_logs($conn, $emp_id, $limit = 6)
{
    return get_employee_sent_slip_logs($conn, $emp_id, $limit);
}

function get_slip_send_status_for_period($conn, $year, $month)
{
    $stmt = $conn->prepare("
        SELECT emp_id, status, sent_at
        FROM salary_slip_logs
        WHERE period_year = ? AND period_month = ?
        ORDER BY sent_at DESC
    ");
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $map = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!isset($map[$row['emp_id']])) {
            $map[$row['emp_id']] = $row;
        }
    }
    return $map;
}
