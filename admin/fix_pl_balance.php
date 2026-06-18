<?php
require_once 'config.php';

// Truncate the log and insert only the current month
$conn->query("TRUNCATE TABLE leave_accruals_log");

$current_y = (int)date('Y');
$current_m = (int)date('n');

$stmt = $conn->prepare("INSERT INTO leave_accruals_log (period_year, period_month) VALUES (?, ?)");
$stmt->bind_param('ii', $current_y, $current_m);
$stmt->execute();

// Reset PL balance to exactly 1.08 for everyone
$pl_monthly = 1.08;
$conn->query("UPDATE employee_leave_balances SET balance = {$pl_monthly} WHERE leave_type = 'PL'");

echo "PL balance reset to {$pl_monthly} and accrual log cleaned to only have {$current_y}-{$current_m}.";
