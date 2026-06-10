<?php

function normalize_attendance_date($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (is_numeric($value) && (float) $value > 0) {
        $serial = (float) $value;
        if ($serial > 59) {
            $unix = (int) round(($serial - 25569) * 86400);
            return gmdate('Y-m-d', $unix);
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function map_attendance_status_from_code($code)
{
    $c = strtoupper(trim((string) $code));
    if ($c === '' ) {
        return null;
    }
    if (in_array($c, ['WO', 'W/O', 'WEEK OFF', 'WEEKOFF', 'OFF'], true)) {
        return 'Week off';
    }

    return match ($c) {
        'P', 'PRESENT' => 'Present',
        'A', 'ABSENT' => 'Absent',
        'HD', 'H', 'HALF DAY', 'HALFDAY' => 'Half day',
        'L', 'LEAVE' => 'Leave',
        default => null,
    };
}

function attendance_import_holiday_dates_for_period($conn, int $year, int $month, int $branch_id): array
{
    $holidays = get_holidays_for_month($conn, $year, $month, $branch_id);
    $dates = [];
    foreach ($holidays as $date => $row) {
        if (($row['kind'] ?? 'holiday') === 'holiday') {
            $dates[$date] = true;
        }
    }

    return $dates;
}

function attendance_import_load_employee_context($conn, $emp_id, $year, $month, array $holiday_dates = [])
{
    [$start, $end] = get_month_date_bounds($year, $month);

    $attendance_by_date = [];
    $stmt = $conn->prepare('SELECT attendance_date, status FROM attendance WHERE emp_id = ? AND attendance_date BETWEEN ? AND ?');
    $stmt->bind_param('sss', $emp_id, $start, $end);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $attendance_by_date[$row['attendance_date']] = $row['status'];
    }

    $roster_dates = array_flip(get_employee_weekoff_dates($conn, $emp_id, $year, $month));

    $approved_leave_dates = [];
    $leave_stmt = $conn->prepare("
        SELECT from_date, to_date FROM employee_leave_requests
        WHERE emp_id = ? AND request_status = 'approved'
          AND from_date <= ? AND to_date >= ?
    ");
    $leave_stmt->bind_param('sss', $emp_id, $end, $start);
    $leave_stmt->execute();
    $leave_rows = $leave_stmt->get_result();
    while ($row = $leave_rows->fetch_assoc()) {
        foreach (leave_request_dates_in_range($row['from_date'], $row['to_date']) as $date) {
            if ($date >= $start && $date <= $end) {
                $approved_leave_dates[$date] = true;
            }
        }
    }

    return [
        'attendance' => $attendance_by_date,
        'roster' => $roster_dates,
        'approved_leave' => $approved_leave_dates,
        'holiday_dates' => $holiday_dates,
    ];
}

/**
 * Skip Excel P/A/HD on holidays, weekoff (roster or attendance), or approved leave.
 * Explicit WO / L in the file is still applied.
 */
function attendance_import_should_protect_cell($new_status, $date, array $ctx)
{
    $new_bucket = normalize_status_bucket($new_status);
    if (in_array($new_bucket, ['leave', 'weekoff'], true)) {
        return false;
    }

    if (isset($ctx['holiday_dates'][$date])) {
        return true;
    }

    $existing = $ctx['attendance'][$date] ?? null;
    $existing_bucket = $existing !== null ? normalize_status_bucket($existing) : null;

    if ($existing_bucket === 'leave' || isset($ctx['approved_leave'][$date])) {
        return true;
    }
    if ($existing_bucket === 'weekoff' || isset($ctx['roster'][$date])) {
        return true;
    }

    return false;
}

function is_attendance_row_empty(array $row)
{
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }
    return true;
}

function count_wide_sheet_day_columns(array $header)
{
    $day_cols = [];
    $started = false;
    for ($c = 0, $cMax = count($header); $c < $cMax; $c++) {
        $label = trim((string) ($header[$c] ?? ''));
        if (preg_match('/^\d{1,2}$/', $label)) {
            $day = (int) $label;
            if ($day >= 1 && $day <= 31) {
                $day_cols[$c] = $day;
                $started = true;
            }
        } elseif ($started) {
            break;
        }
    }
    return $day_cols;
}

function find_wide_sheet_layout(array $rows)
{
    $max_scan = min(12, count($rows));
    for ($r = 0; $r < $max_scan; $r++) {
        if (!is_array($rows[$r])) {
            continue;
        }

        $day_cols = count_wide_sheet_day_columns($rows[$r]);
        if (count($day_cols) < 5) {
            continue;
        }

        $first_day_col = min(array_keys($day_cols));
        $name_col = max(0, $first_day_col - 1);
        $emp_col = $first_day_col >= 2 ? $first_day_col - 2 : null;

        $data_start = $r + 1;
        while ($data_start < count($rows) && is_attendance_row_empty($rows[$data_start])) {
            $data_start++;
        }

        return [
            'header_row' => $r,
            'data_start_row' => $data_start,
            'day_columns' => $day_cols,
            'name_col' => $name_col,
            'emp_col' => $emp_col,
        ];
    }

    return null;
}

function is_wide_attendance_sheet(array $rows)
{
    return find_wide_sheet_layout($rows) !== null;
}

function resolve_emp_id_from_wide_row(array $data, array $layout)
{
    $name = trim((string) ($data[$layout['name_col']] ?? ''));
    if ($name === '' || preg_match('/^(total|grand total|summary)$/i', $name)) {
        return ['emp_id' => '', 'name' => ''];
    }

    $emp_id = '';
    if ($layout['emp_col'] !== null) {
        $raw = trim((string) ($data[$layout['emp_col']] ?? ''));
        if ($raw !== '' && preg_match('/^emp[\w-]+$/i', $raw)) {
            $emp_id = strtoupper($raw);
        } elseif ($raw !== '' && preg_match('/^\d+$/', $raw)) {
            $emp_id = 'EMP' . str_pad($raw, 3, '0', STR_PAD_LEFT);
        } elseif ($raw !== '' && !preg_match('/^(sr|s\.?\s*no|serial)$/i', $raw)) {
            $emp_id = $raw;
        }
    }

    if ($emp_id === '' && $name !== '') {
        $emp_id = 'EMP' . str_pad((string) (abs(crc32(strtolower($name))) % 100000), 5, '0', STR_PAD_LEFT);
    }

    return ['emp_id' => $emp_id, 'name' => $name];
}

function attendance_upload_pending_dir()
{
    $dir = dirname(__DIR__) . '/tmp/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function attendance_upload_save_pending(array $rows, int $year, int $month, int $branch_id, array $meta = []): string
{
    $token = bin2hex(random_bytes(16));
    $path = attendance_upload_pending_dir() . '/att_' . $token . '.json';
    file_put_contents($path, json_encode(array_merge([
        'rows' => $rows,
        'year' => $year,
        'month' => $month,
        'branch_id' => $branch_id,
    ], $meta), JSON_UNESCAPED_UNICODE));
    return $token;
}

function attendance_import_preview_per_page(): int
{
    return 25;
}

function attendance_import_pagination_pages(int $page, int $total_pages): array
{
    if ($total_pages <= 1) {
        return [];
    }
    if ($total_pages <= 7) {
        return range(1, $total_pages);
    }

    $pages = [1];
    if ($page > 3) {
        $pages[] = null;
    }
    for ($i = max(2, $page - 1); $i <= min($total_pages - 1, $page + 1); $i++) {
        $pages[] = $i;
    }
    if ($page < $total_pages - 2) {
        $pages[] = null;
    }
    $pages[] = $total_pages;

    return $pages;
}

function attendance_upload_preview_url(int $month, int $year, int $page = 1): string
{
    $query = [
        'preview' => 1,
        'month' => $month,
        'year' => $year,
    ];
    if ($page > 1) {
        $query['preview_page'] = $page;
    }

    return 'upload_attendance.php?' . http_build_query($query);
}

function attendance_import_preview_badge_class(string $code): string
{
    return match ($code) {
        'P' => 'badge-present',
        'A' => 'badge-absent',
        'HD' => 'badge-hd',
        'WO' => 'badge-wo',
        'L' => 'badge-leave',
        default => '',
    };
}

function attendance_upload_load_pending(string $token): ?array
{
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
    if ($token === '') {
        return null;
    }
    $path = attendance_upload_pending_dir() . '/att_' . $token . '.json';
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function attendance_upload_delete_pending(?string $token): void
{
    if ($token === null || $token === '') {
        return;
    }
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
    if ($token === '') {
        return;
    }
    $path = attendance_upload_pending_dir() . '/att_' . $token . '.json';
    if (is_file($path)) {
        unlink($path);
    }
}

function attendance_upload_clear_session_pending(): void
{
    $pending = $_SESSION['upload_pending'] ?? null;
    if (is_array($pending) && !empty($pending['token'])) {
        attendance_upload_delete_pending($pending['token']);
    }
    unset($_SESSION['upload_pending']);
}

function attendance_import_preview_push(array &$preview_items, int $limit, string $emp_id, string $name, string $date, string $status, string $code = '')
{
    if ($limit > 0 && count($preview_items) >= $limit) {
        return;
    }
    $preview_items[] = [
        'emp_id' => $emp_id,
        'name' => $name,
        'date' => $date,
        'status' => $status,
        'code' => $code !== '' ? $code : normalize_attendance_status_code($status),
    ];
}

function process_wide_attendance_rows($conn, array $rows, $year, $month, $dry_run = false, $branch_id = 1)
{
    $layout = find_wide_sheet_layout($rows);
    if ($layout === null) {
        return [
            'row_count' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'wrong_month_count' => 0,
            'format' => 'wide',
        ];
    }

    $day_columns = $layout['day_columns'];
    $max_day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

    $row_count = 0;
    $success_count = 0;
    $error_count = 0;
    $protected_skip_count = 0;
    $preview_items = [];
    $preview_limit = 0;
    $employee_ids = [];

    if (!$dry_run && $conn) {
        $stmt_emp = $conn->prepare('INSERT IGNORE INTO employees (emp_id, branch_id, name) VALUES (?, ?, ?)');
        $stmt_att = $conn->prepare(
            'INSERT INTO attendance (emp_id, attendance_date, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status=?'
        );
    }

    $employee_context = [];
    $holiday_dates = $conn
        ? attendance_import_holiday_dates_for_period($conn, $year, $month, $branch_id)
        : [];

    for ($r = $layout['data_start_row'], $rMax = count($rows); $r < $rMax; $r++) {
        $data = $rows[$r];
        if (!is_array($data) || is_attendance_row_empty($data)) {
            continue;
        }

        $resolved = resolve_emp_id_from_wide_row($data, $layout);
        $emp_id = $resolved['emp_id'];
        $name = $resolved['name'];
        if ($emp_id === '' || $name === '') {
            continue;
        }

        if (!$dry_run && $conn) {
            $stmt_emp->bind_param('sis', $emp_id, $branch_id, $name);
            $stmt_emp->execute();
        }

        if (!isset($employee_context[$emp_id])) {
            $employee_context[$emp_id] = $conn
                ? attendance_import_load_employee_context($conn, $emp_id, $year, $month, $holiday_dates)
                : ['attendance' => [], 'roster' => [], 'approved_leave' => [], 'holiday_dates' => $holiday_dates];
        }
        $ctx = $employee_context[$emp_id];

        foreach ($day_columns as $col => $day) {
            if ($day > $max_day) {
                continue;
            }

            $code = trim((string) ($data[$col] ?? ''));
            if ($code === '') {
                continue;
            }

            $status = map_attendance_status_from_code($code);
            if ($status === null) {
                continue;
            }

            $date = sprintf('%d-%02d-%02d', $year, $month, $day);
            $row_count++;

            if (attendance_import_should_protect_cell($status, $date, $ctx)) {
                $protected_skip_count++;
                continue;
            }

            if ($dry_run) {
                $success_count++;
                $employee_ids[$emp_id] = true;
                attendance_import_preview_push($preview_items, $preview_limit, $emp_id, $name, $date, $status, $code);
            } else {
                $stmt_att->bind_param('ssss', $emp_id, $date, $status, $status);
                if ($stmt_att->execute()) {
                    $success_count++;
                    $ctx['attendance'][$date] = $status;
                    $employee_ids[$emp_id] = true;
                } else {
                    $error_count++;
                }
            }
        }
        $employee_context[$emp_id] = $ctx;
    }

    return [
        'row_count' => $row_count,
        'success_count' => $success_count,
        'error_count' => $error_count,
        'protected_skip_count' => $protected_skip_count,
        'wrong_month_count' => 0,
        'format' => 'wide',
        'preview_items' => $preview_items,
        'employee_count' => count($employee_ids),
    ];
}

function resolve_attendance_date($value, $year, $month)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $year = (int) $year;
    $month = (int) $month;
    if ($month < 1 || $month > 12 || $year < 2000) {
        return null;
    }

    if (preg_match('/^\d{1,2}$/', $value)) {
        $day = (int) $value;
        $max_day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        if ($day >= 1 && $day <= $max_day) {
            return sprintf('%d-%02d-%02d', $year, $month, $day);
        }
        return null;
    }

    if (preg_match('/^[a-zA-Z\/]+$/', $value) && !preg_match('/\d/', $value)) {
        return null;
    }

    $date = normalize_attendance_date($value);
    if ($date === null) {
        return null;
    }

    if ((int) date('n', strtotime($date)) === $month && (int) date('Y', strtotime($date)) === $year) {
        return $date;
    }

    return false;
}

function process_attendance_rows($conn, array $rows, $skip_header = true, $year = null, $month = null, $dry_run = false, $branch_id = 1)
{
    $row_count = 0;
    $success_count = 0;
    $error_count = 0;
    $protected_skip_count = 0;
    $wrong_month_count = 0;
    $preview_items = [];
    $preview_limit = 0;
    $employee_ids = [];
    $is_first = true;
    $use_period = $year !== null && $month !== null;
    $employee_context = [];
    $stmt_att = null;
    $holiday_dates = ($conn && $use_period)
        ? attendance_import_holiday_dates_for_period($conn, (int) $year, (int) $month, $branch_id)
        : [];

    if (!$dry_run && $conn) {
        $stmt_att = $conn->prepare(
            'INSERT INTO attendance (emp_id, attendance_date, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status=?'
        );
    }

    foreach ($rows as $data) {
        if (!is_array($data)) {
            continue;
        }

        if ($skip_header && $is_first) {
            $is_first = false;
            continue;
        }
        $is_first = false;

        if (count($data) < 4) {
            $error_count++;
            continue;
        }

        $emp_id = trim((string) $data[0]);
        $name = trim((string) $data[1]);
        $status = trim((string) $data[3]);

        if ($use_period) {
            $date = resolve_attendance_date($data[2], $year, $month);
            if ($date === false) {
                $wrong_month_count++;
                continue;
            }
        } else {
            $date = normalize_attendance_date($data[2]);
        }

        if ($emp_id === '' || $date === null) {
            $error_count++;
            continue;
        }

        $row_count++;

        if (!isset($employee_context[$emp_id])) {
            $employee_context[$emp_id] = ($conn && $use_period)
                ? attendance_import_load_employee_context($conn, $emp_id, $year, $month, $holiday_dates)
                : ['attendance' => [], 'roster' => [], 'approved_leave' => [], 'holiday_dates' => $holiday_dates];
        }
        $ctx = $employee_context[$emp_id];

        if ($use_period && attendance_import_should_protect_cell($status, $date, $ctx)) {
            $protected_skip_count++;
            continue;
        }

        if (!$dry_run) {
            $stmt_emp = $conn->prepare('INSERT IGNORE INTO employees (emp_id, branch_id, name) VALUES (?, ?, ?)');
            $stmt_emp->bind_param('sis', $emp_id, $branch_id, $name);
            $stmt_emp->execute();

            $stmt_att->bind_param('ssss', $emp_id, $date, $status, $status);

            if ($stmt_att->execute()) {
                $success_count++;
                $ctx['attendance'][$date] = $status;
            } else {
                $error_count++;
            }
        } else {
            $success_count++;
            $employee_ids[$emp_id] = true;
            attendance_import_preview_push($preview_items, $preview_limit, $emp_id, $name, $date, $status);
        }

        $employee_context[$emp_id] = $ctx;
    }

    return [
        'row_count' => $row_count,
        'success_count' => $success_count,
        'error_count' => $error_count,
        'protected_skip_count' => $protected_skip_count,
        'wrong_month_count' => $wrong_month_count,
        'format' => 'list',
        'preview_items' => $preview_items,
        'employee_count' => count($employee_ids),
    ];
}

function process_attendance_upload($conn, array $rows, $year, $month, $dry_run = false, $branch_id = 1)
{
    if (is_wide_attendance_sheet($rows)) {
        return process_wide_attendance_rows($conn, $rows, $year, $month, $dry_run, $branch_id);
    }

    return process_attendance_rows($conn, $rows, true, $year, $month, $dry_run, $branch_id);
}

function read_attendance_file_rows($tmp_path, $extension)
{
    $extension = strtolower($extension);

    if ($extension === 'csv') {
        $handle = fopen($tmp_path, 'r');
        if ($handle === false) {
            return ['error' => 'Could not read the CSV file.'];
        }

        $rows = [];
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $rows[] = $data;
        }
        fclose($handle);

        return ['rows' => $rows];
    }

    if ($extension === 'xlsx') {
        require_once __DIR__ . '/../lib/SimpleXLSX.php';

        $xlsx = \Shuchkin\SimpleXLSX::parse($tmp_path);
        if (!$xlsx) {
            return ['error' => 'Could not read the Excel file: ' . \Shuchkin\SimpleXLSX::parseError()];
        }

        return ['rows' => $xlsx->rows()];
    }

    if ($extension === 'xls') {
        require_once __DIR__ . '/../lib/SimpleXLS.php';

        $xls = SimpleXLS::parse($tmp_path);
        if (!$xls) {
            return ['error' => 'Could not read the Excel file: ' . SimpleXLS::parseError()];
        }

        return ['rows' => $xls->rows()];
    }

    return ['error' => 'Invalid file format. Please upload a CSV or Excel file (.csv, .xlsx, .xls).'];
}
