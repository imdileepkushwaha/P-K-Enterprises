<?php
require 'includes/header.php';
require 'config.php';
require 'includes/settings_helper.php';
require_once 'includes/salary_helper.php';
require 'includes/employee_helper.php';
require_once 'includes/csrf_helper.php';

$settings = get_all_settings($conn);
$smtp_ready = is_smtp_configured($settings);
$emp_count = $conn->query("SELECT COUNT(*) as count FROM employees")->fetch_assoc()['count'];
$att_count = $conn->query("SELECT COUNT(*) as count FROM attendance")->fetch_assoc()['count'];
$present_count = $conn->query("SELECT COUNT(*) as count FROM attendance WHERE LOWER(status) = 'present'")->fetch_assoc()['count'];

$payroll_month = (int) ($_GET['month'] ?? date('n'));
$payroll_year = (int) ($_GET['year'] ?? date('Y'));
if ($payroll_month < 1 || $payroll_month > 12) {
    $payroll_month = (int) date('n');
}
if ($payroll_year < 2000 || $payroll_year > 2100) {
    $payroll_year = (int) date('Y');
}

$payroll_period_label = get_period_label($payroll_year, $payroll_month);
$slip_status_map = get_slip_send_status_for_period($conn, $payroll_year, $payroll_month);
$payroll_rows = [];
$total_net_payroll = 0.0;
$employees_with_attendance = 0;
$active_employee_count = 0;

$employees_result = $conn->query('SELECT * FROM employees ORDER BY is_active DESC, name ASC');
while ($emp = $employees_result->fetch_assoc()) {
    $is_active = employee_is_active($emp);
    if ($is_active) {
        $active_employee_count++;
    }

    $stats = get_attendance_stats_extended($conn, $emp['emp_id'], $payroll_year, $payroll_month, $settings);
    $salary = calculate_employee_salary_full($conn, $emp, $payroll_year, $payroll_month, $settings);
    $has_attendance = $stats['total_records'] > 0;

    if ($has_attendance) {
        $employees_with_attendance++;
    }
    if ($is_active && $has_attendance) {
        $total_net_payroll += $salary['net_salary'];
    }

    $payroll_rows[] = [
        'employee' => $emp,
        'is_active' => $is_active,
        'stats' => $stats,
        'salary' => $salary,
        'has_attendance' => $has_attendance,
    ];
}

$slip_eligible_rows = [];
$slips_sent_count = 0;
$slips_failed_count = 0;

foreach ($payroll_rows as $row) {
    $emp = $row['employee'];
    if (!$row['is_active'] || empty($emp['email']) || (float) $emp['base_salary'] <= 0 || !$row['has_attendance']) {
        continue;
    }
    $slip_eligible_rows[] = $row;
}

foreach ($slip_status_map as $entry) {
    if (($entry['status'] ?? '') === 'sent') {
        $slips_sent_count++;
    } elseif (($entry['status'] ?? '') === 'failed') {
        $slips_failed_count++;
    }
}

$slip_eligible_count = count($slip_eligible_rows);
$slip_pending_count = 0;
foreach ($slip_eligible_rows as $row) {
    $sent_info = $slip_status_map[$row['employee']['emp_id']] ?? null;
    if (($sent_info['status'] ?? '') !== 'sent') {
        $slip_pending_count++;
    }
}
$company_name = $settings['company_name'] ?? 'Company';
$working_days = (int) get_working_days_per_month($settings);
$payroll_period = get_payroll_period($conn, $payroll_year, $payroll_month);
$period_status = $payroll_period['status'] ?? 'open';
$period_locked = $period_status === 'locked';

?>
<div class="dashboard-page">
<div class="page-header page-header-row dashboard-page-header">
    <div class="page-header-main">
        <p class="page-eyebrow">Overview</p>
        <h2>Dashboard</h2>
        <p>Payroll for <strong><?php echo htmlspecialchars($payroll_period_label); ?></strong> · <?php echo htmlspecialchars($company_name); ?></p>
    </div>
    <div class="page-header-actions">
        <a href="employees.php" class="btn btn-outline">Employees</a>
        <a href="upload_attendance.php" class="btn btn-header">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Upload Attendance
        </a>
    </div>
</div>

<?php if (isset($_SESSION['flash_message'])): ?>
    <div class="alert <?php echo $_SESSION['flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page">
        <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_success']); ?>
    </div>
<?php endif; ?>

<div class="settings-status dashboard-status">
    <div class="settings-status-chip <?php echo $smtp_ready ? 'ok' : 'warn'; ?>">
        <span class="status-dot"></span>
        <div>
            <strong><?php echo $smtp_ready ? 'SMTP ready' : 'SMTP not configured'; ?></strong>
            <span><?php echo $smtp_ready ? 'Email delivery enabled' : 'Set up in Settings → SMTP'; ?></span>
        </div>
    </div>
    <div class="settings-status-chip neutral">
        <span class="status-dot"></span>
        <div>
            <strong><?php echo htmlspecialchars($payroll_period_label); ?></strong>
            <span><?php echo (int) $employees_with_attendance; ?> with attendance · <?php echo $working_days; ?> working days</span>
        </div>
    </div>
    <div class="settings-status-chip <?php echo $slip_eligible_count > 0 && $slips_sent_count >= $slip_eligible_count ? 'ok' : 'neutral'; ?>">
        <span class="status-dot"></span>
        <div>
            <strong><?php echo (int) $slips_sent_count; ?> / <?php echo (int) $slip_eligible_count; ?> slips sent</strong>
            <span><?php echo $slips_failed_count > 0 ? (int) $slips_failed_count . ' failed · ' : ''; ?>Eligible active employees</span>
        </div>
    </div>
    <div class="settings-status-chip <?php echo $period_status === 'approved' || $period_status === 'locked' ? 'ok' : ($period_status === 'review' ? 'warn' : 'neutral'); ?>">
        <span class="status-dot"></span>
        <div>
            <strong>Payroll: <?php echo htmlspecialchars(payroll_period_status_label($period_status)); ?></strong>
            <span><?php echo $period_locked ? 'Attendance locked' : 'Approve before bulk send'; ?></span>
        </div>
    </div>
</div>

<div class="card-container dashboard-stats">
    <div class="stat-card stat-card-primary">
        <div class="stat-icon-wrap employees">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="stat-body">
            <h3>Total employees</h3>
            <p class="stat-value"><?php echo (int) $emp_count; ?></p>
            <span class="stat-meta"><?php echo (int) $active_employee_count; ?> active</span>
        </div>
    </div>
    <div class="stat-card stat-card-accent">
        <div class="stat-icon-wrap attendance">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div class="stat-body">
            <h3>Attendance records</h3>
            <p class="stat-value"><?php echo (int) $att_count; ?></p>
            <span class="stat-meta"><?php echo (int) $present_count; ?> present marks (all time)</span>
        </div>
    </div>
    <div class="stat-card stat-card-success">
        <div class="stat-icon-wrap present">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="stat-body">
            <h3>With attendance</h3>
            <p class="stat-value"><?php echo (int) $employees_with_attendance; ?></p>
            <span class="stat-meta">For <?php echo htmlspecialchars($payroll_period_label); ?></span>
        </div>
    </div>
    <div class="stat-card stat-card-payroll">
        <div class="stat-icon-wrap payroll">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="stat-body">
            <h3>Net payroll</h3>
            <p class="stat-value stat-value-money">₹<?php echo format_money($total_net_payroll); ?></p>
            <span class="stat-meta">Active employees with attendance</span>
        </div>
    </div>
</div>

<div class="dashboard-grid">
<section class="panel panel-elevated dashboard-send-panel">
    <div class="dashboard-panel-head">
        <div class="dashboard-panel-icon send">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <div>
            <h3>Send salary slips</h3>
            <p>Email PDF payslips to selected employees for the chosen period.</p>
        </div>
        <span class="dashboard-panel-badge <?php echo $smtp_ready ? 'ok' : 'warn'; ?>"><?php echo $smtp_ready ? 'SMTP ready' : 'SMTP off'; ?></span>
    </div>
    <div class="panel-body padded">
        <form method="POST" action="send_slips.php" class="slip-form" id="sendSlipsForm">
            <?php echo csrf_field(); ?>
            <div class="dashboard-send-controls">
                <div class="form-row slip-form-row">
                    <div class="form-group">
                        <label>Month</label>
                        <select name="month" id="slipMonth" onchange="window.location.href='dashboard.php?month='+this.value+'&year='+document.getElementById('slipYear').value">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m === $payroll_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Year</label>
                        <select name="year" id="slipYear" onchange="window.location.href='dashboard.php?month='+document.getElementById('slipMonth').value+'&year='+this.value">
                            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 2; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y === $payroll_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group form-group-btn">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-send-slips" id="sendSlipsBtn" <?php echo $smtp_ready ? '' : 'disabled title="Configure SMTP in Settings"'; ?>>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            <span class="btn-send-slips-label">Send slips</span>
                            <span class="btn-send-slips-loader" aria-hidden="true">
                                <span class="btn-spinner"></span>
                                Sending…
                            </span>
                        </button>
                    </div>
                </div>
            </div>
            <div id="slipSendStatus" class="slip-send-status" role="status" aria-live="polite" hidden></div>
            <div class="dashboard-send-options">
                <label class="slip-select-all-label"><input type="checkbox" name="include_already_sent" value="1"> Include already sent</label>
                <label class="slip-select-all-label"><input type="checkbox" name="resend_failed_only" value="1"> Resend failed only</label>
            </div>
            <div class="slip-select-toolbar">
                <label class="slip-select-all-label"><input type="checkbox" id="slipSelectAll" checked> Select all pending (<?php echo (int) $slip_pending_count; ?>)</label>
                <a href="slip_logs.php?month=<?php echo $payroll_month; ?>&year=<?php echo $payroll_year; ?>" class="btn-link">Slip history</a>
            </div>
            <?php if ($slip_eligible_count > 0): ?>
                <div class="slip-recipient-list">
                    <?php foreach ($slip_eligible_rows as $row):
                        $emp = $row['employee'];
                        $sent = $slip_status_map[$emp['emp_id']] ?? null;
                        $already_sent = ($sent['status'] ?? '') === 'sent';
                        $initial = strtoupper(substr($emp['name'], 0, 1));
                        ?>
                    <label class="slip-recipient-item<?php echo $already_sent ? ' slip-recipient-sent' : ''; ?>"<?php echo $already_sent ? ' hidden' : ''; ?>>
                        <input type="checkbox" name="emp_ids[]" value="<?php echo htmlspecialchars($emp['emp_id']); ?>"<?php echo $already_sent ? '' : ' checked'; ?>>
                        <span class="slip-recipient-avatar" aria-hidden="true"><?php echo htmlspecialchars($initial); ?></span>
                        <span class="slip-recipient-info">
                            <span class="slip-recipient-name"><?php echo htmlspecialchars($emp['name']); ?></span>
                            <span class="slip-recipient-meta"><?php echo htmlspecialchars($emp['emp_id']); ?> · ₹<?php echo format_money($row['salary']['net_salary']); ?> net</span>
                        </span>
                        <?php if ($sent): ?>
                            <span class="badge <?php echo $sent['status'] === 'sent' ? 'badge-present' : 'badge-absent'; ?>"><?php echo htmlspecialchars($sent['status']); ?></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dashboard-send-empty">
                    <p>No eligible employees for <?php echo htmlspecialchars($payroll_period_label); ?>.</p>
                    <span>Need active staff with email, salary, and attendance.</span>
                </div>
            <?php endif; ?>
            <p class="dashboard-send-hint">Uncheck all boxes to email every eligible employee. Failed sends are logged; others continue.</p>
        </form>
        <?php if (!$smtp_ready): ?>
            <div class="dashboard-inline-alert warn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <p><a href="settings.php?tab=smtp">Configure SMTP</a> before sending.<?php if (PAYROLL_ALLOW_SETUP_TOOLS): ?> Or <a href="seed_demo_data.php">fill demo data</a>.<?php endif; ?></p>
            </div>
        <?php endif; ?>
    </div>
</section>

<aside class="dashboard-aside">
    <div class="dashboard-aside-card">
        <h4>Period filter</h4>
        <p class="dashboard-aside-desc">Switch month for payroll table and slip list.</p>
        <form method="GET" action="dashboard.php" class="dashboard-period-form">
            <div class="form-group">
                <label for="payroll-month">Month</label>
                <select name="month" id="payroll-month" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m === $payroll_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="payroll-year">Year</label>
                <select name="year" id="payroll-year" onchange="this.form.submit()">
                    <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y === $payroll_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
    </div>
    <div class="dashboard-aside-card">
        <h4>Payroll period</h4>
        <p class="dashboard-aside-desc">Approve or lock this month before sending slips.</p>
        <form method="POST" action="payroll_period_save.php" class="dashboard-period-actions">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="month" value="<?php echo $payroll_month; ?>">
            <input type="hidden" name="year" value="<?php echo $payroll_year; ?>">
            <?php if ($period_status === 'open'): ?>
                <button type="submit" name="period_action" value="submit_review" class="btn btn-outline btn-sm btn-block">Submit for review</button>
            <?php elseif ($period_status === 'review'): ?>
                <button type="submit" name="period_action" value="approve" class="btn btn-sm btn-block">Approve payroll</button>
            <?php elseif ($period_status === 'approved'): ?>
                <button type="submit" name="period_action" value="lock" class="btn btn-outline btn-sm btn-block">Lock period</button>
            <?php else: ?>
                <button type="submit" name="period_action" value="reopen" class="btn btn-outline btn-sm btn-block">Reopen period</button>
            <?php endif; ?>
        </form>
    </div>
</aside>
</div>

<div class="panel panel-elevated dashboard-payroll-panel" id="payroll-overview">
    <div class="dashboard-panel-head dashboard-panel-head-table">
        <div class="dashboard-panel-icon payroll">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div>
            <h3>Monthly payroll summary</h3>
            <p><?php echo htmlspecialchars($payroll_period_label); ?> · <?php echo count($payroll_rows); ?> employees listed</p>
        </div>
        <span class="dashboard-panel-total">₹<?php echo format_money($total_net_payroll); ?></span>
    </div>
    <div class="panel-body padded">
        <?php if (count($payroll_rows) > 0): ?>
            <div class="table-wrap dashboard-table-wrap">
                <table class="data-table data-table-compact dashboard-payroll-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th class="col-num">Paid days</th>
                            <th class="col-num">P / HD / L</th>
                            <th class="col-money">Net payable</th>
                            <th>Slip</th>
                            <th class="col-action">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payroll_rows as $row): ?>
                            <?php
                            $emp = $row['employee'];
                            $salary = $row['salary'];
                            $stats = $row['stats'];
                            $initial = strtoupper(substr($emp['name'], 0, 1));
                            $view_url = 'employee_view.php?emp_id=' . rawurlencode($emp['emp_id']) . '&month=' . $payroll_month . '&year=' . $payroll_year;
                            ?>
                            <tr class="<?php echo $row['is_active'] ? '' : 'emp-row-inactive'; ?>">
                                <td>
                                    <div class="cell-employee">
                                        <span class="emp-avatar"><?php echo htmlspecialchars($initial); ?></span>
                                        <div>
                                            <span class="emp-name"><?php echo htmlspecialchars($emp['name']); ?></span>
                                            <span class="emp-id"><?php echo htmlspecialchars($emp['emp_id']); ?><?php if (!$row['is_active']): ?> · Inactive<?php endif; ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="col-num">
                                    <?php if ($row['has_attendance']): ?>
                                        <strong><?php echo format_money($salary['paid_days']); ?></strong>
                                    <?php else: ?>
                                        <span class="payroll-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-num payroll-phl">
                                    <?php if ($row['has_attendance']): ?>
                                        <?php echo (int) $stats['present_days']; ?> / <?php echo (int) $stats['half_days']; ?> / <?php echo (int) $stats['leave_days']; ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td class="col-money">
                                    <?php if ($row['has_attendance']): ?>
                                        <strong class="net-pay-cell">₹<?php echo format_money($salary['net_salary']); ?></strong>
                                    <?php else: ?>
                                        <span class="payroll-muted">No attendance</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $sent = $slip_status_map[$emp['emp_id']] ?? null;
                                    if ($sent): ?>
                                        <span class="badge <?php echo $sent['status'] === 'sent' ? 'badge-present' : 'badge-absent'; ?>"><?php echo htmlspecialchars($sent['status']); ?></span>
                                    <?php else: ?>
                                        <span class="payroll-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-action">
                                    <div class="action-btns">
                                        <a href="<?php echo htmlspecialchars($view_url); ?>" class="btn-action btn-view" title="View employee">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                        <?php if ($row['has_attendance']): ?>
                                            <a href="preview_slip.php?emp_id=<?php echo urlencode($emp['emp_id']); ?>&month=<?php echo $payroll_month; ?>&year=<?php echo $payroll_year; ?>" class="btn-action btn-pdf" title="Preview PDF slip" target="_blank" rel="noopener">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($employees_with_attendance > 0): ?>
                        <tfoot>
                            <tr class="payroll-total-row">
                                <td colspan="3"><strong>Total (active, with attendance)</strong></td>
                                <td class="col-money"><strong class="net-pay-cell">₹<?php echo format_money($total_net_payroll); ?></strong></td>
                                <td></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <p class="dashboard-payroll-footnote">
                Net salary uses paid days (P + HD + L credits). <a href="upload_attendance.php">Upload attendance</a> if figures are missing.
            </p>
        <?php else: ?>
            <div class="empty-state compact dashboard-empty">
                <h4>No employees yet</h4>
                <p>Add employees and upload attendance to see monthly payroll here.</p>
                <a href="employees.php" class="btn btn-sm">Manage employees</a>
            </div>
        <?php endif; ?>
    </div>
</div>

</div>

<script>
(function () {
    var selectAll = document.getElementById('slipSelectAll');
    var form = document.getElementById('sendSlipsForm');
    var btn = document.getElementById('sendSlipsBtn');
    var statusEl = document.getElementById('slipSendStatus');
    if (!form) return;

    var includeSentCb = form.querySelector('input[name="include_already_sent"]');

    function visibleRecipientItems() {
        return Array.prototype.filter.call(form.querySelectorAll('.slip-recipient-item'), function (el) {
            return !el.hidden;
        });
    }

    function syncSentVisibility() {
        var showSent = includeSentCb && includeSentCb.checked;
        form.querySelectorAll('.slip-recipient-sent').forEach(function (el) {
            el.hidden = !showSent;
            if (!showSent) {
                var cb = el.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = false;
            }
        });
    }

    if (includeSentCb) {
        includeSentCb.addEventListener('change', syncSentVisibility);
    }
    syncSentVisibility();

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            visibleRecipientItems().forEach(function (el) {
                var cb = el.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = selectAll.checked;
            });
        });
    }

    function setSending(active) {
        if (!btn) return;
        btn.disabled = active;
        btn.classList.toggle('is-loading', active);
        btn.setAttribute('aria-busy', active ? 'true' : 'false');
        form.querySelectorAll('select, input[type="checkbox"]').forEach(function (el) {
            el.disabled = active;
        });
    }

    function showStatus(type, html) {
        if (!statusEl) return;
        statusEl.hidden = false;
        statusEl.className = 'slip-send-status alert alert-' + (type === 'success' ? 'success' : type === 'error' ? 'error' : 'info');
        statusEl.innerHTML = html;
        statusEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (btn && btn.disabled && btn.classList.contains('is-loading')) return;

        var checked = form.querySelectorAll('input[name="emp_ids[]"]:checked');
        var formData = new FormData(form);
        formData.append('ajax', '1');
        if (checked.length === 0) {
            form.querySelectorAll('input[name="emp_ids[]"]').forEach(function (cb) {
                formData.append('emp_ids[]', cb.value);
            });
        }

        var count = checked.length || form.querySelectorAll('input[name="emp_ids[]"]').length;
        setSending(true);
        showStatus('info', '<strong>Sending salary slips…</strong> Please wait. Emailing <span id="slipSendCount">' + count + '</span> employee(s). Do not close this page.');

        fetch('send_slips.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok && !data.message) {
                        throw new Error('Request failed');
                    }
                    return data;
                });
            })
            .then(function (data) {
                setSending(false);
                var icon = data.success ? '✓' : '⚠';
                var detail = '';
                if (data.sent !== undefined) {
                    detail = '<br><span class="slip-send-meta">' + data.sent + ' sent';
                    if (data.failed > 0) detail += ', ' + data.failed + ' failed';
                    detail += ' of ' + data.total + '</span>';
                }
                showStatus(data.success ? 'success' : 'error', '<strong>' + icon + ' ' + escapeHtml(data.message) + '</strong>' + detail);
                if (data.success || (data.sent && data.sent > 0)) {
                    setTimeout(function () {
                        var q = 'month=' + encodeURIComponent(data.month || '') + '&year=' + encodeURIComponent(data.year || '') + '&sent=1';
                        window.location.href = 'dashboard.php?' + q;
                    }, 2400);
                }
            })
            .catch(function () {
                setSending(false);
                showStatus('error', '<strong>Request failed.</strong> Check your connection or SMTP settings and try again.');
            });
    });

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    if (new URLSearchParams(window.location.search).get('sent') === '1') {
        var flash = document.querySelector('.alert-page');
        if (flash) flash.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
})();
</script>

<?php require 'includes/footer.php'; ?>
