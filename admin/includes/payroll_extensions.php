<?php

require_once __DIR__ . '/salary_helper.php';

function payroll_ext_table_exists($conn, $table)
{
    $table = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$table'");
    return $r && $r->num_rows > 0;
}

/* ---------- Payroll periods (approval / lock) ---------- */

function payroll_context_branch_id()
{
    if (function_exists('get_active_branch_id')) {
        $branch_id = get_active_branch_id();
        return $branch_id ?? 1;
    }
    return 1;
}

function get_payroll_period($conn, $year, $month, $branch_id = null)
{
    if ($branch_id === null) {
        $branch_id = payroll_context_branch_id();
    }
    $stmt = $conn->prepare('SELECT * FROM payroll_periods WHERE branch_id = ? AND period_year = ? AND period_month = ?');
    $stmt->bind_param('iii', $branch_id, $year, $month);
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

function upsert_payroll_period($conn, $year, $month, $status, $username = null, $notes = null, $branch_id = null)
{
    if ($branch_id === null) {
        $branch_id = payroll_context_branch_id();
    }
    $existing = get_payroll_period($conn, $year, $month, $branch_id);
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
        INSERT INTO payroll_periods (branch_id, period_year, period_month, status, approved_by, approved_at, locked_by, locked_at, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            approved_by = VALUES(approved_by),
            approved_at = VALUES(approved_at),
            locked_by = VALUES(locked_by),
            locked_at = VALUES(locked_at),
            notes = VALUES(notes)
    ");
    $stmt->bind_param('iiissssss', $branch_id, $year, $month, $status, $approved_by, $approved_at, $locked_by, $locked_at, $notes);
    $stmt->execute();
}

function is_payroll_period_locked($conn, $year, $month, $branch_id = null)
{
    $p = get_payroll_period($conn, $year, $month, $branch_id);
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

function get_holidays_for_month($conn, $year, $month, $branch_id = null)
{
    if ($branch_id === null) {
        $branch_id = payroll_context_branch_id();
    }
    $start = sprintf('%d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $conn->prepare('SELECT * FROM holidays WHERE branch_id = ? AND calendar_date BETWEEN ? AND ? ORDER BY calendar_date');
    $stmt->bind_param('iss', $branch_id, $start, $end);
    $stmt->execute();
    $map = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $map[$row['calendar_date']] = $row;
    }
    return $map;
}

function get_holidays_for_year($conn, $year, $branch_id = null)
{
    if ($branch_id === null) {
        $branch_id = payroll_context_branch_id();
    }
    $start = sprintf('%d-01-01', $year);
    $end = sprintf('%d-12-31', $year);
    $stmt = $conn->prepare('SELECT * FROM holidays WHERE branch_id = ? AND calendar_date BETWEEN ? AND ? ORDER BY calendar_date');
    $stmt->bind_param('iss', $branch_id, $start, $end);
    $stmt->execute();
    $rows = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
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
        SELECT attendance_date, status, leave_type, overtime_hours FROM attendance
        WHERE emp_id = ? AND YEAR(attendance_date) = ? AND MONTH(attendance_date) = ?
    ");
    $stmt->bind_param('sii', $emp_id, $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();

    $present_days = 0;
    $absent_days = 0;
    $half_days = 0;
    $leave_days = 0;
    $weekoff_days = 0;
    $leave_by_type = [];
    $other_days = 0;
    $overtime_hours = 0.0;
    $attendance_by_date = [];

    while ($row = $result->fetch_assoc()) {
        $attendance_by_date[$row['attendance_date'] ?? ''] = $row['status'];
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
            case 'weekoff':
                $weekoff_days++;
                break;
            default:
                $other_days++;
        }
    }

    $roster_info = count_roster_weekoff_paid_credit($conn, $emp_id, $year, $month, $settings, $attendance_by_date);
    $roster_weekoff_days = (int) ($roster_info['weekoff_days'] ?? 0);
    $roster_weekoff_credit = (float) ($roster_info['weekoff_paid_credit'] ?? 0);

    $paid_leave_credit = 0.0;
    foreach ($leave_by_type as $code => $count) {
        $paid_leave_credit += $count * get_leave_type_credit($conn, $code, $settings);
    }
    if ($leave_days > 0 && $paid_leave_credit === 0.0) {
        $paid_leave_credit = $leave_days * get_leave_day_credit($settings);
    }

    $half_credit = get_half_day_credit($settings);
    $weekoff_credit = get_weekoff_day_credit($settings);
    $paid_days = round(
        (float) $present_days
        + (float) $half_days * $half_credit
        + $paid_leave_credit
        + (float) $weekoff_days * $weekoff_credit
        + $roster_weekoff_credit,
        2
    );

    return [
        'present_days' => $present_days,
        'absent_days' => $absent_days,
        'half_days' => $half_days,
        'leave_days' => $leave_days,
        'weekoff_days' => $weekoff_days,
        'roster_weekoff_days' => $roster_weekoff_days,
        'leave_by_type' => $leave_by_type,
        'other_days' => $other_days,
        'total_records' => $present_days + $absent_days + $half_days + $leave_days + $weekoff_days + $other_days,
        'overtime_hours' => round($overtime_hours, 2),
        'paid_days' => $paid_days,
        'roster_weekoff_dates' => $roster_info['roster_dates'] ?? [],
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
        'weekoff_days' => (int) ($stats['weekoff_days'] ?? 0),
        'roster_weekoff_days' => (int) ($stats['roster_weekoff_days'] ?? 0),
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
