<?php
require 'includes/header.php';
require 'config.php';
require_once 'includes/salary_helper.php';

$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$period_label = get_period_label($year, $month);

$stmt = $conn->prepare("
    SELECT l.*, e.name
    FROM salary_slip_logs l
    LEFT JOIN employees e ON e.emp_id = l.emp_id
    WHERE l.period_month = ? AND l.period_year = ?
    ORDER BY l.sent_at DESC
    LIMIT 500
");
$stmt->bind_param('ii', $month, $year);
$stmt->execute();
$result = $stmt->get_result();

$log_rows = [];
$sent_count = 0;
$failed_count = 0;
$total_net_sent = 0.0;
$unique_employees = [];

while ($row = $result->fetch_assoc()) {
    $log_rows[] = $row;
    $unique_employees[$row['emp_id']] = true;
    if (($row['status'] ?? '') === 'sent') {
        $sent_count++;
        $total_net_sent += (float) $row['net_salary'];
    } elseif (($row['status'] ?? '') === 'failed') {
        $failed_count++;
    }
}

$entry_count = count($log_rows);
$employee_count = count($unique_employees);
?>
<div class="slip-logs-page">
    <div class="page-header page-header-row">
        <div class="page-header-main">
            <p class="page-eyebrow">Payroll</p>
            <h2>Salary slip history</h2>
            <p>Email delivery log for <strong><?php echo htmlspecialchars($period_label); ?></strong> — sent and failed attempts.</p>
        </div>
        <div class="page-header-actions">
            <a href="dashboard.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-outline">Dashboard</a>
            <a href="dashboard.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>#payroll-overview" class="btn btn-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Send slips
            </a>
        </div>
    </div>

    <div class="settings-status slip-logs-status">
        <div class="settings-status-chip neutral">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo htmlspecialchars($period_label); ?></strong>
                <span><?php echo (int) $entry_count; ?> log <?php echo $entry_count === 1 ? 'entry' : 'entries'; ?></span>
            </div>
        </div>
        <div class="settings-status-chip <?php echo $sent_count > 0 ? 'ok' : 'neutral'; ?>">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo (int) $sent_count; ?> sent</strong>
                <span><?php echo (int) $employee_count; ?> unique employee<?php echo $employee_count === 1 ? '' : 's'; ?></span>
            </div>
        </div>
        <div class="settings-status-chip <?php echo $failed_count > 0 ? 'warn' : 'neutral'; ?>">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo (int) $failed_count; ?> failed</strong>
                <span><?php echo $failed_count > 0 ? 'Retry from dashboard' : 'No failures this period'; ?></span>
            </div>
        </div>
    </div>

    <div class="slip-logs-layout">
        <div class="panel panel-elevated slip-logs-panel">
            <div class="dashboard-panel-head dashboard-panel-head-table">
                <div class="dashboard-panel-icon send">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <div>
                    <h3>Delivery log</h3>
                    <p>Newest attempts first · up to 500 records</p>
                </div>
                <div class="dashboard-panel-head-actions">
                    
                    <form method="GET" class="dashboard-panel-period-filter dashboard-period-form">
                        <div class="form-group">
                            <label for="slip-log-month">Month</label>
                            <select name="month" id="slip-log-month" onchange="this.form.submit()">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="slip-log-year">Year</label>
                            <select name="year" id="slip-log-year" onchange="this.form.submit()">
                                <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
            <div class="panel-body padded">
                <?php if ($entry_count > 0): ?>
                    <div class="slip-logs-stats">
                        <div class="slip-logs-stat">
                            <span>Total entries</span>
                            <strong><?php echo (int) $entry_count; ?></strong>
                        </div>
                        <div class="slip-logs-stat ok">
                            <span>Successful</span>
                            <strong><?php echo (int) $sent_count; ?></strong>
                        </div>
                        <div class="slip-logs-stat <?php echo $failed_count > 0 ? 'warn' : ''; ?>">
                            <span>Failed</span>
                            <strong><?php echo (int) $failed_count; ?></strong>
                        </div>
                        <div class="slip-logs-stat highlight">
                            <span>Net sent (successful)</span>
                            <strong>₹<?php echo format_money($total_net_sent); ?></strong>
                        </div>
                    </div>
                    <div class="table-wrap slip-logs-table-wrap">
                        <table class="data-table data-table-compact slip-logs-table">
                            <thead>
                                <tr>
                                    <th>Sent at</th>
                                    <th>Employee</th>
                                    <th>Email</th>
                                    <th class="col-money">Net (₹)</th>
                                    <th>Status</th>
                                    <th class="col-action">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($log_rows as $row):
                                    $emp_name = $row['name'] ?? $row['emp_id'];
                                    $initial = strtoupper(substr($emp_name, 0, 1));
                                    $is_sent = ($row['status'] ?? '') === 'sent';
                                    $sent_ts = strtotime($row['sent_at']);
                                    $sent_date = $sent_ts ? date('j M Y', $sent_ts) : '—';
                                    $sent_time = $sent_ts ? date('g:i A', $sent_ts) : '';
                                    $view_url = 'employee_view.php?emp_id=' . urlencode($row['emp_id']) . '&month=' . $month . '&year=' . $year;
                                    $pdf_url = 'preview_slip.php?emp_id=' . urlencode($row['emp_id']) . '&month=' . $month . '&year=' . $year;
                                    ?>
                                <tr class="slip-log-row <?php echo $is_sent ? 'is-sent' : 'is-failed'; ?>">
                                    <td class="slip-log-date">
                                        <span class="slip-log-date-main"><?php echo htmlspecialchars($sent_date); ?></span>
                                        <?php if ($sent_time !== ''): ?>
                                            <span class="slip-log-date-sub"><?php echo htmlspecialchars($sent_time); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="cell-employee">
                                            <span class="emp-avatar"><?php echo htmlspecialchars($initial); ?></span>
                                            <div>
                                                <a href="<?php echo htmlspecialchars($view_url); ?>" class="emp-name emp-name-link"><?php echo htmlspecialchars($emp_name); ?></a>
                                                <span class="emp-id"><?php echo htmlspecialchars($row['emp_id']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="slip-log-email"><?php echo htmlspecialchars($row['sent_to'] ?? '—'); ?></td>
                                    <td class="col-money">
                                        <strong class="net-pay-cell">₹<?php echo format_money($row['net_salary']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $is_sent ? 'badge-present' : 'badge-absent'; ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                                    </td>
                                    <td class="col-action">
                                        <div class="action-btns">
                                            <a href="<?php echo htmlspecialchars($view_url); ?>" class="btn-action btn-view" title="View employee">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </a>
                                            <a href="<?php echo htmlspecialchars($pdf_url); ?>" class="btn-action btn-pdf" title="Preview PDF slip" target="_blank" rel="noopener">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="slip-logs-footnote">Each send creates a new log row. Resending the same employee appears as a separate entry.</p>
                <?php else: ?>
                    <div class="slip-logs-empty">
                        <div class="slip-logs-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        </div>
                        <h4>No slips logged for <?php echo htmlspecialchars($period_label); ?></h4>
                        <p>Send salary slips from the dashboard once attendance and SMTP are ready.</p>
                        <div class="slip-logs-empty-actions">
                            <a href="dashboard.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn">Go to dashboard</a>
                            <a href="settings.php?tab=smtp" class="btn btn-outline">SMTP settings</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
