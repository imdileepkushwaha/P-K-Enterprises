<?php
/** CLI only: php run_seed_cli.php */
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}
require 'config.php';
require 'includes/settings_helper.php';

$year = (int) date('Y');
$month = (int) date('n');
$days_in_month = (int) date('t');

$departments = ['Engineering', 'Human Resources', 'Sales', 'Finance', 'Operations', 'Marketing', 'Support'];
$designations = [
    'Software Developer', 'Senior Developer', 'Team Lead', 'Project Manager',
    'HR Executive', 'Sales Manager', 'Accountant', 'Business Analyst',
    'Customer Support', 'QA Engineer', 'DevOps Engineer', 'UI Designer',
];
$first_names = ['Rahul', 'Priya', 'Amit', 'Sneha', 'Vikram', 'Anita', 'Karan', 'Pooja', 'Arjun', 'Neha'];
$last_names = ['Sharma', 'Patel', 'Singh', 'Kumar', 'Gupta', 'Reddy', 'Nair', 'Mehta', 'Joshi'];

function slug_email($name, $emp_id)
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $name));
    return trim($slug, '.') . '.' . strtolower($emp_id) . '@payroll-demo.local';
}

$result = $conn->query('SELECT * FROM employees ORDER BY id');
$employees = [];
while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

if (count($employees) === 0) {
    for ($i = 1; $i <= 10; $i++) {
        $emp_id = 'EMP' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $name = $first_names[$i - 1] . ' ' . $last_names[$i - 1];
        $stmt = $conn->prepare('INSERT INTO employees (emp_id, name) VALUES (?, ?)');
        $stmt->bind_param('ss', $emp_id, $name);
        $stmt->execute();
    }
    $result = $conn->query('SELECT * FROM employees ORDER BY id');
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    echo "Created 10 employees.\n";
}

$update = $conn->prepare('
    UPDATE employees SET name=?, email=?, phone=?, department=?, designation=?, base_salary=?, joined_date=?
    WHERE emp_id=?
');

$idx = 0;
foreach ($employees as $emp) {
    $idx++;
    $name = !empty($emp['name']) && strlen($emp['name']) > 2 ? $emp['name'] : ($first_names[($idx - 1) % 10] . ' ' . $last_names[($idx - 1) % 9]);
    $email = slug_email($name, $emp['emp_id']);
    $phone = '+91 98' . str_pad((string) (10000000 + $idx * 137421), 8, '0', STR_PAD_LEFT);
    $department = $departments[$idx % count($departments)];
    $designation = $designations[$idx % count($designations)];
    $base_salary = 28000 + ($idx * 4500);
    $joined = sprintf('%d-%02d-15', $year - ($idx % 3 + 1), ($idx % 12) + 1);
    $update->bind_param('sssssdss', $name, $email, $phone, $department, $designation, $base_salary, $joined, $emp['emp_id']);
    $update->execute();
}
echo 'Updated ' . count($employees) . " employees.\n";

set_setting($conn, 'company_name', 'Payroll Demo Pvt Ltd');
set_setting($conn, 'working_days_per_month', '26');

$att_stmt = $conn->prepare('INSERT INTO attendance (emp_id, attendance_date, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status=VALUES(status)');
$att_count = 0;
foreach ($employees as $emp) {
    for ($day = 1; $day <= $days_in_month; $day++) {
        if ((int) date('w', strtotime("$year-$month-$day")) === 0) {
            continue;
        }
        $date = sprintf('%d-%02d-%02d', $year, $month, $day);
        $status = mt_rand(1, 100) <= 88 ? 'Present' : 'Absent';
        $att_stmt->bind_param('sss', $emp['emp_id'], $date, $status);
        $att_stmt->execute();
        $att_count++;
    }
}
echo "Attendance rows: $att_count for " . date('F Y') . "\n";
echo "Done.\n";
