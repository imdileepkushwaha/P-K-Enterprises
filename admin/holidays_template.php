<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require 'config.php';

$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$filename = 'holidays_template_' . $year . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Label', 'Type']);
fputcsv($out, [$year . '-01-26', 'Republic Day', 'holiday']);
fputcsv($out, [$year . '-08-15', 'Independence Day', 'holiday']);
fputcsv($out, [$year . '-10-02', 'Gandhi Jayanti', 'holiday']);
fputcsv($out, [$year . '-12-25', 'Christmas', 'holiday']);
fclose($out);
exit;
