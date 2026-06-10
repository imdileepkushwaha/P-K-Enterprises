<?php

require_once __DIR__ . '/attendance_import.php';

function holiday_import_normalize_kind(string $value): string
{
    $kind = strtolower(trim($value));
    if (in_array($kind, ['weekoff', 'week off', 'week-off', 'wo', 'w/o', 'off'], true)) {
        return 'weekoff';
    }

    return 'holiday';
}

function holiday_import_is_header_row(array $row): bool
{
    $joined = strtolower(implode(' ', array_map(static fn($cell) => trim((string) $cell), $row)));
    return str_contains($joined, 'date')
        || str_contains($joined, 'label')
        || str_contains($joined, 'name')
        || str_contains($joined, 'type')
        || str_contains($joined, 'holiday');
}

function holiday_import_resolve_columns(array $header): array
{
    $columns = ['date' => 0, 'name' => 1, 'kind' => 2];
    foreach ($header as $index => $cell) {
        $label = strtolower(trim((string) $cell));
        if ($label === '') {
            continue;
        }
        if (str_contains($label, 'date')) {
            $columns['date'] = $index;
        } elseif (str_contains($label, 'type') || str_contains($label, 'kind')) {
            $columns['kind'] = $index;
        } elseif (str_contains($label, 'label') || str_contains($label, 'name') || str_contains($label, 'holiday')) {
            $columns['name'] = $index;
        }
    }

    return $columns;
}

function holiday_import_parse_row(array $row, array $columns): ?array
{
    $date_raw = trim((string) ($row[$columns['date']] ?? ''));
    $name = trim((string) ($row[$columns['name']] ?? ''));
    $kind_raw = trim((string) ($row[$columns['kind']] ?? ''));

    if ($date_raw === '' && $name === '') {
        return null;
    }

    $date = normalize_attendance_date($date_raw);
    if ($date === null) {
        return ['error' => 'invalid_date'];
    }
    if ($name === '') {
        return ['error' => 'missing_name'];
    }

    return [
        'date' => $date,
        'name' => mb_substr($name, 0, 120),
        'kind' => $kind_raw === '' ? 'holiday' : holiday_import_normalize_kind($kind_raw),
    ];
}

function holiday_import_process_rows($conn, array $rows, int $year, int $branch_id): array
{
    $result = [
        'row_count' => 0,
        'saved_count' => 0,
        'invalid_date_count' => 0,
        'missing_name_count' => 0,
        'wrong_year_count' => 0,
    ];

    if ($rows === []) {
        return $result;
    }

    $start = 0;
    $columns = ['date' => 0, 'name' => 1, 'kind' => 2];
    if (holiday_import_is_header_row($rows[0])) {
        $columns = holiday_import_resolve_columns($rows[0]);
        $start = 1;
    }

    $year_start = sprintf('%d-01-01', $year);
    $year_end = sprintf('%d-12-31', $year);
    $stmt = $conn->prepare('INSERT INTO holidays (branch_id, calendar_date, name, kind) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), kind = VALUES(kind)');

    for ($i = $start, $iMax = count($rows); $i < $iMax; $i++) {
        $row = $rows[$i];
        if (!is_array($row)) {
            continue;
        }

        $parsed = holiday_import_parse_row($row, $columns);
        if ($parsed === null) {
            continue;
        }

        $result['row_count']++;

        if (($parsed['error'] ?? '') === 'invalid_date') {
            $result['invalid_date_count']++;
            continue;
        }
        if (($parsed['error'] ?? '') === 'missing_name') {
            $result['missing_name_count']++;
            continue;
        }

        $date = $parsed['date'];
        if ($date < $year_start || $date > $year_end) {
            $result['wrong_year_count']++;
            continue;
        }

        $name = $parsed['name'];
        $kind = $parsed['kind'];
        $stmt->bind_param('isss', $branch_id, $date, $name, $kind);
        if ($stmt->execute()) {
            $result['saved_count']++;
        }
    }

    return $result;
}

function holiday_import_build_message(array $result, int $year): string
{
    $parts = [];
    if ($result['saved_count'] > 0) {
        $parts[] = $result['saved_count'] . ' holiday day(s) saved for ' . $year;
    }
    $skipped = $result['invalid_date_count'] + $result['missing_name_count'] + $result['wrong_year_count'];
    if ($skipped > 0) {
        $parts[] = $skipped . ' row(s) skipped';
    }
    if ($parts === []) {
        return 'No valid holiday rows found in the file. Check the format and year.';
    }

    return implode('. ', $parts) . '.';
}
