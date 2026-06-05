<?php
require 'includes/header.php';
require 'config.php';
require 'includes/settings_helper.php';
require_once 'includes/salary_helper.php';
require 'includes/employee_helper.php';

$settings = get_all_settings($conn);
$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$period_label = get_period_label($year, $month);

$by_dept = [];
$grand_net = 0.0;
$employees = $conn->query('SELECT * FROM employees ORDER BY department, name');

while ($emp = $employees->fetch_assoc()) {
    $dept = trim($emp['department'] ?? '') ?: 'Unassigned';
    $stats = get_attendance_stats_extended($conn, $emp['emp_id'], $year, $month, $settings);
    if ($stats['total_records'] === 0) {
        continue;
    }
    $salary = calculate_employee_salary_full($conn, $emp, $year, $month, $settings);
    if (!isset($by_dept[$dept])) {
        $by_dept[$dept] = ['count' => 0, 'net' => 0.0, 'gross' => 0.0];
    }
    $by_dept[$dept]['count']++;
    $by_dept[$dept]['net'] += $salary['net_salary'];
    $by_dept[$dept]['gross'] += $salary['earned_salary'];
    if (employee_is_active($emp)) {
        $grand_net += $salary['net_salary'];
    }
}
ksort($by_dept);
?>
<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">Analytics</p>
        <h2>Reports</h2>
        <p>Department payroll summary and export.</p>
    </div>
    <div class="page-header-actions">
        <a href="reports_export.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-header">Export CSV</a>
    </div>
</div>

<div class="panel panel-elevated">
    <div class="panel-header panel-header-split">
        <h3>Department summary — <?php echo htmlspecialchars($period_label); ?></h3>
        <form method="GET" class="inline-filter">
            <select name="month" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                <?php endfor; ?>
            </select>
            <select name="year" onchange="this.form.submit()">
                <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>
    <div class="panel-body padded">
        <div class="salary-summary">
            <div class="salary-stat highlight">
                <span>Total net (active)</span>
                <strong>₹<?php echo format_money($grand_net); ?></strong>
            </div>
            <div class="salary-stat">
                <span>Departments</span>
                <strong><?php echo count($by_dept); ?></strong>
            </div>
        </div>
        <?php if (count($by_dept) > 0): ?>
            <div class="table-wrap" style="margin-top:20px">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Employees</th>
                            <th class="col-money">Gross earned</th>
                            <th class="col-money">Net payable</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_dept as $dept => $row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dept); ?></strong></td>
                                <td><?php echo (int) $row['count']; ?></td>
                                <td class="col-money">₹<?php echo format_money($row['gross']); ?></td>
                                <td class="col-money"><strong>₹<?php echo format_money($row['net']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state compact" style="margin-top:16px">
                <p>No attendance data for this period.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
