<?php
require 'includes/header.php';
require 'config.php';
require 'includes/settings_helper.php';

$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$settings = get_all_settings($conn);
$period_label = get_period_label($year, $month);
$rows = get_missing_attendance_report($conn, $year, $month, $settings);
$incomplete = array_filter($rows, fn($r) => !$r['complete']);
?>
<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">Attendance</p>
        <h2>Missing attendance report</h2>
        <p>Employees with fewer records than expected working days — <?php echo htmlspecialchars($period_label); ?>.</p>
    </div>
    <form method="GET" class="inline-filter">
        <select name="month" onchange="this.form.submit()"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option><?php endfor; ?></select>
        <select name="year" onchange="this.form.submit()"><?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--): ?><option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option><?php endfor; ?></select>
    </form>
</div>

<div class="settings-status dashboard-status" style="margin-bottom:20px">
    <div class="settings-status-chip <?php echo count($incomplete) === 0 ? 'ok' : 'warn'; ?>">
        <span class="status-dot"></span>
        <div>
            <strong><?php echo count($incomplete); ?> incomplete</strong>
            <span>of <?php echo count($rows); ?> active employees</span>
        </div>
    </div>
</div>

<div class="panel panel-elevated">
    <div class="panel-body padded">
        <div class="table-wrap">
            <table class="data-table data-table-compact">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th class="col-num">Recorded</th>
                        <th class="col-num">Expected</th>
                        <th class="col-num">Missing</th>
                        <th>P / HD / L</th>
                        <th class="col-action">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row):
                        $emp = $row['employee'];
                        $stats = $row['stats'];
                        $initial = strtoupper(substr($emp['name'], 0, 1));
                        $view_url = 'employee_view.php?emp_id=' . urlencode($emp['emp_id']) . '&month=' . $month . '&year=' . $year;
                        ?>
                    <tr class="<?php echo $row['complete'] ? '' : 'slip-log-row is-failed'; ?>">
                        <td>
                            <div class="cell-employee">
                                <span class="emp-avatar"><?php echo htmlspecialchars($initial); ?></span>
                                <div>
                                    <span class="emp-name"><?php echo htmlspecialchars($emp['name']); ?></span>
                                    <span class="emp-id"><?php echo htmlspecialchars($emp['emp_id']); ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="col-num"><strong><?php echo (int) $row['recorded']; ?></strong></td>
                        <td class="col-num"><?php echo (int) $row['expected']; ?></td>
                        <td class="col-num"><strong class="<?php echo $row['missing'] > 0 ? 'net-pay-cell' : ''; ?>" style="<?php echo $row['missing'] > 0 ? 'color:#b45309' : ''; ?>"><?php echo (int) $row['missing']; ?></strong></td>
                        <td class="payroll-phl"><?php echo (int) $stats['present_days']; ?> / <?php echo (int) $stats['half_days']; ?> / <?php echo (int) $stats['leave_days']; ?></td>
                        <td class="col-action">
                            <div class="action-btns">
                                <a href="<?php echo htmlspecialchars($view_url); ?>" class="btn-action btn-view" title="Fix attendance"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="dashboard-payroll-footnote">Expected days = working days per month (<?php echo (int) get_working_days_per_month($settings); ?>) minus holidays in <a href="holidays.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>">holiday calendar</a>.</p>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
