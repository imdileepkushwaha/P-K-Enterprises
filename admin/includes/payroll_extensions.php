<?php

require_once __DIR__ . '/salary_helper.php';

function payroll_ext_table_exists($conn, $table)
{
    $table = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$table'");
    return $r && $r->num_rows > 0;
}

/* ---------- Payroll periods (approval / lock) ---------- */

function get_payroll_period($conn, $year, $month)
{
    $stmt = $conn->prepare('SELECT * FROM payroll_periods WHERE period_year = ? AND period_month = ?');
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return $row;
    }
    return [
        'period_year' => (int) $year,
        'period_month' => (int) $month,
        'status' => 'open',
        'approved_by' => null,
        'approved_at' => null,
        'locked_by' => null,
        'locked_at' => null,
        'notes' => null,
    ];
}

function upsert_payroll_period($conn, $year, $month, $status, $username = null, $notes = null)
{
    $existing = get_payroll_period($conn, $year, $month);
    $approved_at = null;
    $locked_at = null;
    $approved_by = $existing['approved_by'] ?? null;
    $locked_by = $existing['locked_by'] ?? null;

    if ($status === 'approved' || $status === 'locked') {
        $approved_by = $username;
        $approved_at = date('Y-m-d H:i:s');
    }
    if ($status === 'locked') {
        $locked_by = $username;
        $locked_at = date('Y-m-d H:i:s');
    }
    if ($status === 'open') {
        $approved_by = null;
        $approved_at = null;
        $locked_by = null;
        $locked_at = null;
    }

    $stmt = $conn->prepare("
        INSERT INTO payroll_periods (period_year, period_month, status, approved_by, approved_at, locked_by, locked_at, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            approved_by = VALUES(approved_by),
            approved_at = VALUES(approved_at),
            locked_by = VALUES(locked_by),
            locked_at = VALUES(locked_at),
            notes = VALUES(notes)
    ");
    $stmt->bind_param('iissssss', $year, $month, $status, $approved_by, $approved_at, $locked_by, $locked_at, $notes);
    $stmt->execute();
}

function is_payroll_period_locked($conn, $year, $month)
{
    $p = get_payroll_period($conn, $year, $month);
    return ($p['status'] ?? 'open') === 'locked';
}

function can_send_slips_for_period($conn, $year, $month)
{
    $p = get_payroll_period($conn, $year, $month);
    $status = $p['status'] ?? 'open';
    return in_array($status, ['approved', 'locked'], true);
}

function payroll_period_status_label($status)
{
    return match ($status) {
        'review' => 'Under review',
        'approved' => 'Approved',
        'locked' => 'Locked',
        default => 'Open',
    };
}

/* ---------- Holidays ---------- */

function get_holidays_for_month($conn, $year, $month)
{
    $start = sprintf('%d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $conn->prepare('SELECT * FROM holidays WHERE calendar_date BETWEEN ? AND ? ORDER BY calendar_date');
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $map = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $map[$row['calendar_date']] = $row;
    }
    return $map;
}

function get_holiday_dates_set($conn, $year, $month)
{
    return array_keys(get_holidays_for_month($conn, $year, $month));
}

/* ---------- Leave types ---------- */

function get_leave_types($conn)
{
    $types = [];
    $r = $conn->query('SELECT * FROM leave_types WHERE is_active = 1 ORDER BY code');
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $types[$row['code']] = $row;
        }
    }
    if ($types === []) {
        return [
            'CL' => ['code' => 'CL', 'name' => 'Casual Leave', 'paid_credit' => '1.00'],
            'SL' => ['code' => 'SL', 'name' => 'Sick Leave', 'paid_credit' => '1.00'],
            'LOP' => ['code' => 'LOP', 'name' => 'Loss of Pay', 'paid_credit' => '0.00'],
        ];
    }
    return $types;
}

function get_leave_type_credit($conn, $code, $settings)
{
    if ($code === null || $code === '') {
        return get_leave_day_credit($settings);
    }
    $types = get_leave_types($conn);
    if (isset($types[$code])) {
        return (float) $types[$code]['paid_credit'];
    }
    return get_leave_day_credit($settings);
}

/* ---------- Attendance audit ---------- */

function log_attendance_audit($conn, $emp_id, $date, $action, $old_status, $new_status, $old_leave_type, $new_leave_type, $overtime_hours, $changed_by)
{
    $stmt = $conn->prepare("
        INSERT INTO attendance_audit (emp_id, attendance_date, action, old_status, new_status, old_leave_type, new_leave_type, overtime_hours, changed_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ot = $overtime_hours !== null ? (float) $overtime_hours : 0;
    $stmt->bind_param('sssssssds', $emp_id, $date, $action, $old_status, $new_status, $old_leave_type, $new_leave_type, $ot, $changed_by);
    $stmt->execute();
}

/* ---------- Employee payroll profile ---------- */

function get_employee_payroll_profile($conn, $emp_id)
{
    $stmt = $conn->prepare('SELECT * FROM employee_payroll_profiles WHERE emp_id = ?');
    $stmt->bind_param('s', $emp_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function merge_settings_with_employee_profile($settings, $profile)
{
    if (!$profile || !(int) ($profile['use_custom'] ?? 0)) {
        return $settings;
    }
    $keys = ['pct_basic', 'pct_hra', 'pct_conveyance', 'pct_medical', 'pct_special', 'pf_percent', 'professional_tax', 'esi_percent', 'esi_gross_limit'];
    $merged = $settings;
    foreach ($keys as $key) {
        if (isset($profile[$key]) && $profile[$key] !== null && $profile[$key] !== '') {
            $merged[$key] = $profile[$key];
        }
    }
    return $merged;
}

/* ---------- Payroll adjustments ---------- */

function get_payroll_adjustments_for_period($conn, $emp_id, $year, $month)
{
    $stmt = $conn->prepare("
        SELECT * FROM payroll_adjustments
        WHERE emp_id = ? AND period_year = ? AND period_month = ?
        ORDER BY id
    ");
    $stmt->bind_param('sii', $emp_id, $year, $month);
    $stmt->execute();
    $rows = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function sum_adjustments_by_kind(array $adjustments)
{
    $bonus = 0.0;
    $incentive = 0.0;
    $deduction = 0.0;
    foreach ($adjustments as $a) {
        $amt = (float) $a['amount'];
        if ($a['adj_type'] === 'bonus') {
            $bonus += $amt;
        } elseif ($a['adj_type'] === 'incentive') {
            $incentive += $amt;
        } else {
            $deduction += $amt;
        }
    }
    return ['bonus' => $bonus, 'incentive' => $incentive, 'deduction' => $deduction];
}

/* ---------- Extended attendance stats ---------- */

function get_attendance_stats_extended($conn, $emp_id, $year, $month, $settings = [])
{
    $stmt = $conn->prepare("
        SELECT status, leave_type, overtime_hours FROM attendance
        WHERE emp_id = ? AND YEAR(attendance_date) = ? AND MONTH(attendance_date) = ?
    ");
    $stmt->bind_param('sii', $emp_id, $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();

    $present_days = 0;
    $absent_days = 0;
    $half_days = 0;
    $leave_days = 0;
    $leave_by_type = [];
    $other_days = 0;
    $overtime_hours = 0.0;

    while ($row = $result->fetch_assoc()) {
        $bucket = normalize_status_bucket($row['status']);
        $overtime_hours += (float) ($row['overtime_hours'] ?? 0);
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
                $lt = $row['leave_type'] ?: 'CL';
                $leave_by_type[$lt] = ($leave_by_type[$lt] ?? 0) + 1;
                break;
            default:
                $other_days++;
        }
    }

    $paid_leave_credit = 0.0;
    foreach ($leave_by_type as $code => $count) {
        $paid_leave_credit += $count * get_leave_type_credit($conn, $code, $settings);
    }
    if ($leave_days > 0 && $paid_leave_credit === 0.0) {
        $paid_leave_credit = $leave_days * get_leave_day_credit($settings);
    }

    $half_credit = get_half_day_credit($settings);
    $paid_days = round(
        (float) $present_days
        + (float) $half_days * $half_credit
        + $paid_leave_credit,
        2
    );

    return [
        'present_days' => $present_days,
        'absent_days' => $absent_days,
        'half_days' => $half_days,
        'leave_days' => $leave_days,
        'leave_by_type' => $leave_by_type,
        'other_days' => $other_days,
        'total_records' => $present_days + $absent_days + $half_days + $leave_days + $other_days,
        'overtime_hours' => round($overtime_hours, 2),
        'paid_days' => $paid_days,
    ];
}

/* ---------- Overtime pay ---------- */

function calculate_overtime_pay($employee, $stats, $settings)
{
    $hours = (float) ($stats['overtime_hours'] ?? 0);
    if ($hours <= 0) {
        return 0.0;
    }
    $working_days = get_working_days_per_month($settings);
    $base = (float) ($employee['base_salary'] ?? 0);
    $daily = $working_days > 0 ? $base / $working_days : 0;
    $hours_per_day = max(1, (float) ($settings['overtime_hours_per_day'] ?? 8));
    $multiplier = max(1, (float) ($settings['overtime_multiplier'] ?? 1.5));
    $hourly = $daily / $hours_per_day;
    return round($hours * $hourly * $multiplier, 2);
}

/* ---------- Missing attendance report ---------- */

function get_missing_attendance_report($conn, $year, $month, $settings)
{
    $working_days = get_working_days_per_month($settings);
    $holidays = get_holiday_dates_set($conn, $year, $month);
    $days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

    $employees = $conn->query('SELECT * FROM employees WHERE is_active = 1 ORDER BY name');
    $rows = [];

    while ($emp = $employees->fetch_assoc()) {
        $stats = get_attendance_stats_extended($conn, $emp['emp_id'], $year, $month, $settings);
        $recorded = (int) $stats['total_records'];
        $expected = max(0, $working_days - count($holidays));
        $missing = max(0, $expected - $recorded);
        $rows[] = [
            'employee' => $emp,
            'stats' => $stats,
            'recorded' => $recorded,
            'expected' => $expected,
            'missing' => $missing,
            'complete' => $missing === 0 && $recorded > 0,
        ];
    }

    return $rows;
}

/* ---------- TDS engine (simplified FY) ---------- */

function get_financial_year_for_month($year, $month)
{
    if ($month >= 4) {
        return $year . '-' . substr((string) ($year + 1), -2);
    }
    return ($year - 1) . '-' . substr((string) $year, -2);
}

function get_tds_slabs_old_regime()
{
    return [
        ['upto' => 250000, 'rate' => 0],
        ['upto' => 500000, 'rate' => 5],
        ['upto' => 1000000, 'rate' => 20],
        ['upto' => PHP_INT_MAX, 'rate' => 30],
    ];
}

function get_tds_slabs_new_regime()
{
    return [
        ['upto' => 300000, 'rate' => 0],
        ['upto' => 600000, 'rate' => 5],
        ['upto' => 900000, 'rate' => 10],
        ['upto' => 1200000, 'rate' => 15],
        ['upto' => 1500000, 'rate' => 20],
        ['upto' => PHP_INT_MAX, 'rate' => 30],
    ];
}

function calculate_annual_tax_from_slabs($taxable_income, array $slabs)
{
    $taxable_income = max(0, $taxable_income);
    $prev = 0;
    $tax = 0.0;
    foreach ($slabs as $band) {
        $limit = (float) $band['upto'];
        $rate = (float) $band['rate'];
        if ($taxable_income <= $prev) {
            break;
        }
        $chunk = min($taxable_income, $limit) - $prev;
        if ($chunk > 0) {
            $tax += $chunk * $rate / 100;
        }
        $prev = $limit;
    }
    if ($taxable_income > 5000000) {
        $tax *= 1.10;
    } elseif ($taxable_income > 1000000) {
        $tax *= 1.04;
    }
    return round($tax, 2);
}

function get_employee_annual_taxable_income($employee, $profile, $settings, $monthly_gross)
{
    $annual_gross = $monthly_gross * 12;
    $std_ded = (float) ($settings['tds_standard_deduction'] ?? 75000);
    $sec80c = (float) ($profile['section_80c'] ?? 0);
    $other = (float) ($profile['other_exemptions'] ?? 0);
    $taxable = max(0, $annual_gross - $std_ded - min($sec80c, 150000) - $other);
    return $taxable;
}

function calculate_monthly_tds($conn, $employee, $profile, $settings, $monthly_gross, $year, $month)
{
    if (!((int) ($settings['tds_enabled'] ?? 0))) {
        return 0.0;
    }
    $regime = $profile['tax_regime'] ?? 'new';
    $slabs = $regime === 'old' ? get_tds_slabs_old_regime() : get_tds_slabs_new_regime();
    $taxable = get_employee_annual_taxable_income($employee, $profile, $settings, $monthly_gross);
    $annual_tax = calculate_annual_tax_from_slabs($taxable, $slabs);

    $fy_start_year = $month >= 4 ? $year : $year - 1;
    $fy_start = sprintf('%d-04-01', $fy_start_year);
    $emp_id = $employee['emp_id'];

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(tds_amount), 0) AS paid
        FROM tds_ledger
        WHERE emp_id = ? AND deducted_on >= ?
    ");
    $stmt->bind_param('ss', $emp_id, $fy_start);
    $stmt->execute();
    $paid_ytd = (float) ($stmt->get_result()->fetch_assoc()['paid'] ?? 0);

    $months_left = 12;
    if ($month >= 4) {
        $months_left = 12 - ($month - 4);
    } else {
        $months_left = 4 - $month;
    }
    $months_left = max(1, $months_left);
    $remaining = max(0, $annual_tax - $paid_ytd);
    return round($remaining / $months_left, 2);
}

function record_tds_ledger($conn, $emp_id, $year, $month, $amount)
{
    $date = sprintf('%d-%02d-01', $year, $month);
    $stmt = $conn->prepare("
        INSERT INTO tds_ledger (emp_id, period_year, period_month, deducted_on, tds_amount)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE tds_amount = VALUES(tds_amount)
    ");
    $stmt->bind_param('siisd', $emp_id, $year, $month, $date, $amount);
    $stmt->execute();
}

function get_tds_ytd_for_fy($conn, $emp_id, $fy_label)
{
    $parts = explode('-', $fy_label);
    $start_year = (int) $parts[0];
    $fy_start = sprintf('%d-04-01', $start_year);
    $fy_end = sprintf('%d-03-31', $start_year + 1);
    $stmt = $conn->prepare('SELECT COALESCE(SUM(tds_amount), 0) AS total FROM tds_ledger WHERE emp_id = ? AND deducted_on BETWEEN ? AND ?');
    $stmt->bind_param('sss', $emp_id, $fy_start, $fy_end);
    $stmt->execute();
    return (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
}

/* ---------- Full salary with extensions ---------- */

function calculate_employee_salary_full($conn, $employee, $year, $month, $settings)
{
    $profile = get_employee_payroll_profile($conn, $employee['emp_id']);
    $effective_settings = merge_settings_with_employee_profile($settings, $profile);
    $stats = get_attendance_stats_extended($conn, $employee['emp_id'], $year, $month, $effective_settings);

    $base_salary = (float) ($employee['base_salary'] ?? 0);
    $working_days = get_working_days_per_month($effective_settings);
    $daily_rate = $working_days > 0 ? $base_salary / $working_days : 0;
    $paid_days = (float) $stats['paid_days'];
    $earned_salary = round($daily_rate * $paid_days, 2);
    $ot_pay = calculate_overtime_pay($employee, $stats, $effective_settings);
    $adjustments = get_payroll_adjustments_for_period($conn, $employee['emp_id'], $year, $month);
    $adj_sums = sum_adjustments_by_kind($adjustments);

    $salary = [
        'base_salary' => $base_salary,
        'working_days' => $working_days,
        'daily_rate' => round($daily_rate, 2),
        'present_days' => (int) $stats['present_days'],
        'absent_days' => (int) $stats['absent_days'],
        'half_days' => (int) $stats['half_days'],
        'leave_days' => (int) $stats['leave_days'],
        'leave_by_type' => $stats['leave_by_type'],
        'paid_days' => $paid_days,
        'overtime_hours' => $stats['overtime_hours'],
        'overtime_pay' => $ot_pay,
        'earned_salary' => $earned_salary,
        'deduction' => round($daily_rate * (float) $stats['absent_days'], 2),
        'adjustments' => $adjustments,
        'bonus_total' => $adj_sums['bonus'],
        'incentive_total' => $adj_sums['incentive'],
        'extra_deductions' => $adj_sums['deduction'],
        'uses_custom_profile' => $profile && (int) ($profile['use_custom'] ?? 0),
    ];

    $period_gross_base = $earned_salary + $ot_pay + $adj_sums['bonus'] + $adj_sums['incentive'];
    $salary['earned_salary'] = $period_gross_base;

    $breakdown = build_salary_component_breakdown($salary, $effective_settings);

    if ($adj_sums['bonus'] > 0) {
        $breakdown['earnings'][] = [
            'id' => 'bonus', 'label' => 'Bonus', 'hint' => 'One-time', 'percent' => 0,
            'percent_label' => '—', 'monthly' => 0, 'period' => round($adj_sums['bonus'], 2),
        ];
        $breakdown['earnings_period_total'] += round($adj_sums['bonus'], 2);
    }
    if ($adj_sums['incentive'] > 0) {
        $breakdown['earnings'][] = [
            'id' => 'incentive', 'label' => 'Incentive', 'hint' => 'One-time', 'percent' => 0,
            'percent_label' => '—', 'monthly' => 0, 'period' => round($adj_sums['incentive'], 2),
        ];
        $breakdown['earnings_period_total'] += round($adj_sums['incentive'], 2);
    }
    if ($ot_pay > 0) {
        $breakdown['earnings'][] = [
            'id' => 'overtime', 'label' => 'Overtime pay', 'hint' => $stats['overtime_hours'] . ' hrs', 'percent' => 0,
            'percent_label' => '—', 'monthly' => 0, 'period' => $ot_pay,
        ];
        $breakdown['earnings_period_total'] += $ot_pay;
    }
    if ($adj_sums['deduction'] > 0) {
        $breakdown['deductions'][] = [
            'id' => 'adj_ded', 'label' => 'Other deductions', 'hint' => 'Adjustments', 'percent_label' => '—',
            'monthly' => 0, 'period' => round($adj_sums['deduction'], 2),
        ];
        $breakdown['deductions_period_total'] += round($adj_sums['deduction'], 2);
    }

    $monthly_gross = (float) ($employee['base_salary'] ?? 0);
    $tds = calculate_monthly_tds($conn, $employee, $profile ?: [], $effective_settings, $monthly_gross, $year, $month);
    if ($tds > 0) {
        $breakdown['deductions'][] = [
            'id' => 'tds', 'label' => 'TDS (Income Tax)', 'hint' => 'Monthly withholding', 'percent_label' => 'TDS',
            'monthly' => 0, 'period' => $tds,
        ];
        $breakdown['deductions_period_total'] += $tds;
        $salary['tds_amount'] = $tds;
        record_tds_ledger($conn, $employee['emp_id'], $year, $month, $tds);
    }

    $breakdown['earnings_period_total'] = round($breakdown['earnings_period_total'], 2);
    $breakdown['deductions_period_total'] = round($breakdown['deductions_period_total'], 2);
    $breakdown['net_period'] = max(0, round($breakdown['earnings_period_total'] - $breakdown['deductions_period_total'], 2));

    $salary['breakdown'] = $breakdown;
    $salary['net_salary'] = $breakdown['net_period'];
    return $salary;
}

/* ---------- Slip send helpers ---------- */

function employee_slip_already_sent($conn, $emp_id, $year, $month)
{
    $stmt = $conn->prepare("
        SELECT id FROM salary_slip_logs
        WHERE emp_id = ? AND period_year = ? AND period_month = ? AND status = 'sent'
        ORDER BY sent_at DESC LIMIT 1
    ");
    $stmt->bind_param('sii', $emp_id, $year, $month);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function send_single_salary_slip($conn, $employee, $year, $month, $settings, $mailer)
{
    require_once __DIR__ . '/pdf_slip.php';
    $period = get_period_label($year, $month);
    $salary = calculate_employee_salary_full($conn, $employee, $year, $month, $settings);
    $subject = 'Salary Slip - ' . $period . ' - ' . $employee['name'];
    $email_html = render_salary_slip_email_html($employee, $salary, $settings, $year, $month);
    $pdf_binary = generate_salary_slip_pdf($employee, $salary, $settings, $year, $month);
    $pdf_filename = salary_slip_pdf_filename($employee, $year, $month);

    $ok = $mailer->send($employee['email'], $employee['name'], $subject, $email_html, $pdf_binary, $pdf_filename);
    $status = $ok ? 'sent' : 'failed';
    $log = $conn->prepare("
        INSERT INTO salary_slip_logs (emp_id, period_month, period_year, net_salary, sent_to, status, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            net_salary = VALUES(net_salary),
            sent_to = VALUES(sent_to),
            status = VALUES(status),
            sent_at = CURRENT_TIMESTAMP
    ");
    $net = $salary['net_salary'];
    $email = $employee['email'];
    $log->bind_param('siidss', $employee['emp_id'], $month, $year, $net, $email, $status);
    $log->execute();

    return ['success' => $ok, 'salary' => $salary, 'error' => $ok ? null : $mailer->getLastError()];
}
