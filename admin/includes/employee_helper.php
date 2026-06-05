<?php

function employee_is_active(array $employee)
{
    return !isset($employee['is_active']) || (int) $employee['is_active'] === 1;
}

function is_invalid_joined_date($date)
{
    if ($date === null) {
        return true;
    }

    $s = trim((string) $date);
    if ($s === '' || $s === '0000-00-00' || str_starts_with($s, '0000-00-00')) {
        return true;
    }

    return false;
}

function normalize_joined_date_for_input($date)
{
    if (is_invalid_joined_date($date)) {
        return '';
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $date, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if ($y >= 1900 && $y <= 2100 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        return '';
    }

    $ts = strtotime((string) $date);
    if ($ts === false) {
        return '';
    }

    $y = (int) date('Y', $ts);
    if ($y < 1900 || $y > 2100) {
        return '';
    }

    return date('Y-m-d', $ts);
}

function format_joined_date_display($date)
{
    $normalized = normalize_joined_date_for_input($date);
    if ($normalized === '') {
        return '—';
    }

    return date('d M Y', strtotime($normalized));
}

function parse_joined_date_from_post($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === '0000-00-00') {
        return null;
    }

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        return null;
    }

    $y = (int) $m[1];
    $mo = (int) $m[2];
    $d = (int) $m[3];
    if ($y < 1900 || $y > 2100 || !checkdate($mo, $d, $y)) {
        return null;
    }

    return $raw;
}
