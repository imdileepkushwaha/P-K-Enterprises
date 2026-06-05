<?php
/**
 * One-time demo data: fills employee details + attendance for salary slip testing.
 * Visit while logged in as admin, then delete this file in production.
 */
require_once 'includes/session_auth.php';
enforce_admin_session();
require 'config.php';

if (!PAYROLL_ALLOW_SETUP_TOOLS) {
    http_response_code(403);
    die('Demo tools are disabled. Set PAYROLL_ALLOW_SETUP_TOOLS to true in config.php for local use only.');
}
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
$first_names = ['Rahul', 'Priya', 'Amit', 'Sneha', 'Vikram', 'Anita', 'Karan', 'Pooja', 'Arjun', 'Neha', 'Rohan', 'Kavita'];
$last_names = ['Sharma', 'Patel', 'Singh', 'Kumar', 'Gupta', 'Reddy', 'Nair', 'Iyer', 'Mehta', 'Joshi'];

function slug_email($name, $emp_id)
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $name));
    $slug = trim($slug, '.');
    return $slug . '.' . strtolower($emp_id) . '@payroll-demo.local';
}

function random_phone($index)
{
    return '+91 98' . str_pad((string) (10000000 + $index * 137421), 8, '0', STR_PAD_LEFT);
}

$log = [];
$run = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']);

if ($run) {
    $result = $conn->query('SELECT * FROM employees ORDER BY id');
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }

    if (count($employees) === 0) {
        for ($i = 1; $i <= 10; $i++) {
            $emp_id = 'EMP' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $name = $first_names[($i - 1) % count($first_names)] . ' ' . $last_names[($i - 1) % count($last_names)];
            $stmt = $conn->prepare('INSERT INTO employees (emp_id, name) VALUES (?, ?)');
            $stmt->bind_param('ss', $emp_id, $name);
            $stmt->execute();
        }
        $log[] = 'Created 10 sample employees (none existed).';
        $result = $conn->query('SELECT * FROM employees ORDER BY id');
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
    }

    $update = $conn->prepare('
        UPDATE employees SET
            name = ?, email = ?, phone = ?, department = ?, designation = ?,
            base_salary = ?, joined_date = ?
        WHERE emp_id = ?
    ');

    $idx = 0;
    foreach ($employees as $emp) {
        $idx++;
        $name = $first_names[($idx - 1) % count($first_names)] . ' ' . $last_names[($idx - 1) % count($last_names)];
        if (!empty($emp['name']) && strlen($emp['name']) > 3) {
            $name = $emp['name'];
        }
        $email = slug_email($name, $emp['emp_id']);
        $phone = random_phone($idx);
        $department = $departments[$idx % count($departments)];
        $designation = $designations[$idx % count($designations)];
        $base_salary = 28000 + ($idx * 4500) + (($idx % 3) * 2000);
        $joined = sprintf('%d-%02d-%02d', $year - ($idx % 4 + 1), ($idx % 12) + 1, min($idx + 5, 28));

        $update->bind_param(
            'ssssssds',
            $name,
            $email,
            $phone,
            $department,
            $designation,
            $base_salary,
            $joined,
            $emp['emp_id']
        );
        $update->execute();
    }
    $log[] = 'Updated ' . count($employees) . ' employees (email, phone, dept, salary, etc.).';

    set_setting($conn, 'company_name', 'Payroll Demo Pvt Ltd');
    set_setting($conn, 'working_days_per_month', '26');
    $log[] = 'Company name & working days set in settings.';

    $att_stmt = $conn->prepare('
        INSERT INTO attendance (emp_id, attendance_date, status)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status)
    ');

    $att_count = 0;
    foreach ($employees as $emp) {
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = sprintf('%d-%02d-%02d', $year, $month, $day);
            $dow = (int) date('w', strtotime($date));
            if ($dow === 0) {
                continue;
            }
            $status = (mt_rand(1, 100) <= 88) ? 'Present' : 'Absent';
            $att_stmt->bind_param('sss', $emp['emp_id'], $date, $status);
            $att_stmt->execute();
            $att_count++;
        }
    }
    $log[] = "Added/updated {$att_count} attendance rows for " . date('F Y') . ' (Sundays skipped).';

    $_SESSION['flash_message'] = implode(' ', $log);
    $_SESSION['flash_success'] = true;
    header('Location: employees.php');
    exit;
}

require 'includes/header.php';
?>
<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">Demo data</p>
        <h2>Fill Employee Details</h2>
        <p>This will populate all employees with sample emails, salaries, departments, and <?php echo date('F Y'); ?> attendance so you can test salary slips.</p>
    </div>
</div>

<div class="settings-card">
    <h3>What will be filled?</h3>
    <ul class="seed-list">
        <li>Name, email (<code>@payroll-demo.local</code>), phone, department, designation</li>
        <li>Base salary (₹28,000 – ₹75,000 range)</li>
        <li>Joined date</li>
        <li>Attendance for current month (mostly Present, some Absent; Sundays off)</li>
        <li>Company name in Settings</li>
    </ul>
    <p class="form-hint warn-hint">Demo emails are fake — use only to test slip <strong>generation</strong> on screen, or configure SMTP to a test inbox.</p>
    <form method="POST" class="seed-actions">
        <input type="hidden" name="confirm" value="1">
        <button type="submit" class="btn">Fill all employee data</button>
        <a href="employees.php" class="btn btn-outline">Cancel</a>
    </form>
</div>

<?php require 'includes/footer.php'; ?>
