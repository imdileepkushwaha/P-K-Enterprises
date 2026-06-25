<?php

function weekoff_roster_adjacent_period($month, $year, $delta_months)
{
    $ts = mktime(0, 0, 0, (int) $month + (int) $delta_months, 1, (int) $year);
    return [(int) date('n', $ts), (int) date('Y', $ts)];
}

function is_weekoff_roster_editable_period($year, $month)
{
    $period_ts = mktime(0, 0, 0, (int) $month, 1, (int) $year);
    $current_month_ts = mktime(0, 0, 0, (int) date('n'), 1, (int) date('Y'));
    return $period_ts >= $current_month_ts;
}

function is_weekoff_roster_past_period($year, $month)
{
    $period_ts = mktime(0, 0, 0, (int) $month, 1, (int) $year);
    $current_month_ts = mktime(0, 0, 0, (int) date('n'), 1, (int) date('Y'));
    return $period_ts < $current_month_ts;
}

function weekoff_roster_week_key($date)
{
    return date('o-W', strtotime($date));
}

function validate_employee_weekoff_dates(array $dates)
{
    $unique = [];
    foreach ($dates as $date) {
        $date = trim((string) $date);
        if ($date === '') {
            continue;
        }
        $unique[$date] = true;
    }
    $dates = array_keys($unique);
    sort($dates);

    $weeks = [];
    foreach ($dates as $date) {
        $week_key = weekoff_roster_week_key($date);
        if (isset($weeks[$week_key])) {
            return [
                'ok' => false,
                'message' => 'Only one weekoff day is allowed per calendar week (conflict on ' . date('d M Y', strtotime($date)) . ').',
            ];
        }
        $weeks[$week_key] = $date;
    }

    return ['ok' => true, 'dates' => $dates];
}

function dedupe_weekoff_dates_one_per_week(array $dates)
{
    sort($dates);
    $by_week = [];
    foreach ($dates as $date) {
        $date = trim((string) $date);
        if ($date === '') {
            continue;
        }
        $week_key = weekoff_roster_week_key($date);
        if (!isset($by_week[$week_key])) {
            $by_week[$week_key] = $date;
        }
    }
    return array_values($by_week);
}

function enforce_one_weekoff_per_week(array $dates)
{
    return dedupe_weekoff_dates_one_per_week($dates);
}

function get_weekoff_day_credit($settings)
{
    $v = (float) ($settings['weekoff_day_credit'] ?? 1);
    return max(0, min(1, $v));
}

function get_month_date_bounds($year, $month)
{
    $start = sprintf('%d-%02d-01', $year, $month);
    $end = sprintf('%d-%02d-%d', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));
    return [$start, $end];
}

function get_employee_weekoff_dates($conn, $emp_id, $year, $month)
{
    [$start, $end] = get_month_date_bounds($year, $month);
    $stmt = $conn->prepare('SELECT off_date FROM employee_weekoff_days WHERE emp_id = ? AND off_date BETWEEN ? AND ? ORDER BY off_date');
    $stmt->bind_param('sss', $emp_id, $start, $end);
    $stmt->execute();
    $dates = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $dates[] = $row['off_date'];
    }
    return $dates;
}

function get_branch_weekoff_roster_map($conn, $year, $month, $branch_id = null)
{
    [$start, $end] = get_month_date_bounds($year, $month);
    $sql = '
        SELECT w.emp_id, w.off_date
        FROM employee_weekoff_days w
        INNER JOIN employees e ON e.emp_id = w.emp_id
        WHERE w.off_date BETWEEN ? AND ?
    ';
    $types = 'ss';
    $params = [$start, $end];
    if ($branch_id !== null) {
        $sql .= ' AND e.branch_id = ?';
        $types .= 'i';
        $params[] = $branch_id;
    }
    $sql .= ' ORDER BY w.emp_id, w.off_date';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $map = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $map[$row['emp_id']][] = $row['off_date'];
    }
    return $map;
}

function save_employee_weekoff_roster($conn, $emp_id, $branch_id, $year, $month, array $dates)
{
    [$start, $end] = get_month_date_bounds($year, $month);
    $valid_dates = [];
    foreach ($dates as $date) {
        $date = trim((string) $date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= $start && $date <= $end) {
            $valid_dates[] = $date;
        }
    }

    $valid_dates = dedupe_weekoff_dates_one_per_week($valid_dates);

    $old_stmt = $conn->prepare('SELECT off_date FROM employee_weekoff_days WHERE emp_id = ? AND off_date BETWEEN ? AND ?');
    $old_stmt->bind_param('sss', $emp_id, $start, $end);
    $old_stmt->execute();
    $old_dates = [];
    $r = $old_stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $old_dates[] = $row['off_date'];
    }

    $del = $conn->prepare('DELETE FROM employee_weekoff_days WHERE emp_id = ? AND off_date BETWEEN ? AND ?');
    $del->bind_param('sss', $emp_id, $start, $end);
    $del->execute();

    if ($valid_dates !== []) {
        $ins = $conn->prepare('INSERT INTO employee_weekoff_days (emp_id, branch_id, off_date) VALUES (?, ?, ?)');
        foreach ($valid_dates as $date) {
            $ins->bind_param('sis', $emp_id, $branch_id, $date);
            $ins->execute();
        }
    }

    sync_weekoff_roster_attendance($conn, $emp_id, $valid_dates, $old_dates);

    return ['saved' => count($valid_dates), 'error' => null];
}

function sync_weekoff_roster_attendance($conn, $emp_id, array $new_dates, array $old_dates)
{
    $new_set = array_flip($new_dates);
    $old_set = array_flip($old_dates);

    $ins = $conn->prepare("
        INSERT INTO attendance (emp_id, attendance_date, status, leave_type, overtime_hours)
        VALUES (?, ?, 'Week off', NULL, 0)
        ON DUPLICATE KEY UPDATE status = 'Week off', leave_type = NULL, overtime_hours = 0
    ");
    foreach ($new_dates as $date) {
        $ins->bind_param('ss', $emp_id, $date);
        $ins->execute();
    }

    $removed = array_diff($old_dates, array_keys($new_set));
    foreach ($removed as $date) {
        $stmt = $conn->prepare("DELETE FROM attendance WHERE emp_id = ? AND attendance_date = ? AND status = 'Week off'");
        $stmt->bind_param('ss', $emp_id, $date);
        $stmt->execute();
    }
}

function copy_branch_weekoff_roster($conn, $branch_id, $year, $month)
{
    [$prev_month, $prev_year] = weekoff_roster_adjacent_period($month, $year, -1);
    $prev_map = get_branch_weekoff_roster_map($conn, $prev_year, $prev_month, $branch_id);
    if ($prev_map === []) {
        return 0;
    }

    $days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    $copied = 0;

    $emp_stmt = $conn->prepare('SELECT emp_id, branch_id FROM employees WHERE branch_id = ? AND is_active = 1 ORDER BY name');
    $emp_stmt->bind_param('i', $branch_id);
    $emp_stmt->execute();
    $employees = $emp_stmt->get_result();

    while ($emp = $employees->fetch_assoc()) {
        $emp_id = $emp['emp_id'];
        $prev_dates = $prev_map[$emp_id] ?? [];
        if ($prev_dates === []) {
            continue;
        }
        $new_dates = [];
        foreach ($prev_dates as $prev_date) {
            $day = (int) date('j', strtotime($prev_date));
            if ($day <= $days_in_month) {
                $new_dates[] = sprintf('%d-%02d-%02d', $year, $month, $day);
            }
        }
        if ($new_dates !== []) {
            $save_result = save_employee_weekoff_roster($conn, $emp_id, (int) $emp['branch_id'], $year, $month, $new_dates);
            $copied += (int) ($save_result['saved'] ?? 0);
        }
    }

    return $copied;
}

function apply_sunday_weekoffs_for_branch($conn, $branch_id, $year, $month)
{
    $days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    $sundays = [];
    for ($day = 1; $day <= $days_in_month; $day++) {
        $date = sprintf('%d-%02d-%02d', $year, $month, $day);
        if ((int) date('w', strtotime($date)) === 0) {
            $sundays[] = $date;
        }
    }

    $total = 0;
    $emp_stmt = $conn->prepare('SELECT emp_id, branch_id FROM employees WHERE branch_id = ? AND is_active = 1 ORDER BY name');
    $emp_stmt->bind_param('i', $branch_id);
    $emp_stmt->execute();
    $employees = $emp_stmt->get_result();
    while ($emp = $employees->fetch_assoc()) {
        $existing = get_employee_weekoff_dates($conn, $emp['emp_id'], $year, $month);
        $merged = enforce_one_weekoff_per_week(array_values(array_unique(array_merge($existing, $sundays))));
        $save_result = save_employee_weekoff_roster($conn, $emp['emp_id'], (int) $emp['branch_id'], $year, $month, $merged);
        $total += (int) ($save_result['saved'] ?? 0);
    }

    return $total;
}

function count_roster_weekoff_paid_credit($conn, $emp_id, $year, $month, $settings, array $attendance_dates = null)
{
    $roster_dates = get_employee_weekoff_dates($conn, $emp_id, $year, $month);
    if ($roster_dates === []) {
        return ['weekoff_days' => 0, 'weekoff_paid_credit' => 0.0, 'roster_dates' => []];
    }

    if ($attendance_dates === null) {
        [$start, $end] = get_month_date_bounds($year, $month);
        $stmt = $conn->prepare('SELECT attendance_date, status FROM attendance WHERE emp_id = ? AND attendance_date BETWEEN ? AND ?');
        $stmt->bind_param('sss', $emp_id, $start, $end);
        $stmt->execute();
        $attendance_dates = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $attendance_dates[$row['attendance_date']] = $row['status'];
        }
    }

    $credit = get_weekoff_day_credit($settings);
    $weekoff_days = 0;
    $extra_paid = 0.0;

    foreach ($roster_dates as $date) {
        $weekoff_days++;
        $status = $attendance_dates[$date] ?? null;
        $bucket = $status !== null ? normalize_status_bucket($status) : null;
        if ($bucket === 'weekoff') {
            continue;
        }
        if ($bucket === 'present' || $bucket === 'half' || $bucket === 'leave') {
            continue;
        }
        $extra_paid += $credit;
    }

    return [
        'weekoff_days' => $weekoff_days,
        'weekoff_paid_credit' => round($extra_paid, 2),
        'roster_dates' => $roster_dates,
    ];
}
