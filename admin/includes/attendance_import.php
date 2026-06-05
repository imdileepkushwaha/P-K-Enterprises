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
    if ($c === '' || $c === 'WO' || $c === 'W/O' || $c === 'WEEK OFF' || $c === 'OFF') {
        return null;
    }

    return match ($c) {
        'P', 'PRESENT' => 'Present',
        'A', 'ABSENT' => 'Absent',
        'HD', 'H', 'HALF DAY', 'HALFDAY' => 'Half day',
        'L', 'LEAVE' => 'Leave',
        default => null,
    };
}

function is_wide_attendance_sheet(array $rows)
{
    if (count($rows) < 2) {
        return false;
    }

    $header = $rows[0];
    if (!is_array($header) || count($header) < 10) {
        return false;
    }

    $col0 = strtolower(trim((string) ($header[0] ?? '')));
    if (strpos($col0, 'emp') === false && $col0 !== 'id') {
        return false;
    }

    $day_cols = count_wide_sheet_day_columns($header);
    return count($day_cols) >= 5;
}

function count_wide_sheet_day_columns(array $header)
{
    $day_cols = [];
    for ($c = 2; $c < count($header); $c++) {
        $label = trim((string) ($header[$c] ?? ''));
        if (preg_match('/^\d{1,2}$/', $label)) {
            $day = (int) $label;
            if ($day >= 1 && $day <= 31) {
                $day_cols[$c] = $day;
            }
        } elseif ($day_cols !== []) {
            break;
        }
    }
    return $day_cols;
}

function process_wide_attendance_rows($conn, array $rows, $year, $month, $dry_run = false)
{
    $header = $rows[0];
    $day_columns = count_wide_sheet_day_columns($header);
    $max_day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

    $row_count = 0;
    $success_count = 0;
    $error_count = 0;
    $skipped_empty = 0;

    for ($r = 1, $rMax = count($rows); $r < $rMax; $r++) {
        $data = $rows[$r];
        if (!is_array($data) || count($data) < 3) {
            continue;
        }

        $emp_id = trim((string) ($data[0] ?? ''));
        $name = trim((string) ($data[1] ?? ''));
        if ($emp_id === '') {
            continue;
        }

        if (!$dry_run) {
            $stmt_emp = $conn->prepare('INSERT IGNORE INTO employees (emp_id, name) VALUES (?, ?)');
            $stmt_emp->bind_param('ss', $emp_id, $name);
            $stmt_emp->execute();

            $stmt_att = $conn->prepare(
                'INSERT INTO attendance (emp_id, attendance_date, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status=?'
            );
        }

        foreach ($day_columns as $col => $day) {
            if ($day > $max_day) {
                continue;
            }

            $code = trim((string) ($data[$col] ?? ''));
            if ($code === '') {
                $skipped_empty++;
                continue;
            }

            $status = map_attendance_status_from_code($code);
            if ($status === null) {
                $skipped_empty++;
                continue;
            }

            $date = sprintf('%d-%02d-%02d', $year, $month, $day);

            $row_count++;
            if ($dry_run) {
                $success_count++;
            } else {
                $stmt_att->bind_param('ssss', $emp_id, $date, $status, $status);
                if ($stmt_att->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
        }
    }

    return [
        'row_count' => $row_count,
        'success_count' => $success_count,
        'error_count' => $error_count,
        'wrong_month_count' => 0,
        'format' => 'wide',
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

function process_attendance_rows($conn, array $rows, $skip_header = true, $year = null, $month = null, $dry_run = false)
{
    $row_count = 0;
    $success_count = 0;
    $error_count = 0;
    $wrong_month_count = 0;
    $is_first = true;
    $use_period = $year !== null && $month !== null;

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

        if (!$dry_run) {
            $stmt_emp = $conn->prepare("INSERT IGNORE INTO employees (emp_id, name) VALUES (?, ?)");
            $stmt_emp->bind_param("ss", $emp_id, $name);
            $stmt_emp->execute();

            $stmt_att = $conn->prepare(
                "INSERT INTO attendance (emp_id, attendance_date, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status=?"
            );
            $stmt_att->bind_param("ssss", $emp_id, $date, $status, $status);

            if ($stmt_att->execute()) {
                $success_count++;
            } else {
                $error_count++;
            }
        } else {
            $success_count++;
        }

        $row_count++;
    }

    return [
        'row_count' => $row_count,
        'success_count' => $success_count,
        'error_count' => $error_count,
        'wrong_month_count' => $wrong_month_count,
        'format' => 'list',
    ];
}

function process_attendance_upload($conn, array $rows, $year, $month, $dry_run = false)
{
    if (is_wide_attendance_sheet($rows)) {
        return process_wide_attendance_rows($conn, $rows, $year, $month, $dry_run);
    }

    return process_attendance_rows($conn, $rows, true, $year, $month, $dry_run);
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
