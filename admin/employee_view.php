<?php
require 'includes/header.php';
require 'config.php';
require 'includes/settings_helper.php';
require_once 'includes/salary_helper.php';
require 'includes/employee_helper.php';
require_once 'includes/attendance_helper.php';

$emp_id = trim($_GET['emp_id'] ?? '');
if ($emp_id === '') {
    header('Location: employees.php');
    exit;
}

$employee = require_employee_branch_access($conn, $emp_id);

$settings = get_all_settings($conn);
$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
$period_label = get_period_label($year, $month);

$stats = get_attendance_stats_extended($conn, $emp_id, $year, $month, $settings);
$salary = calculate_employee_salary_full($conn, $employee, $year, $month, $settings);
$salary_breakdown = $salary['breakdown'];
$recent_sent_slips = get_employee_recent_sent_slip_logs($conn, $emp_id, 6);
$payroll_profile = get_employee_payroll_profile($conn, $emp_id);
$payroll_adjustments = get_payroll_adjustments_for_period($conn, $emp_id, $year, $month);
$adj_bonus_total = (float) ($salary['bonus_total'] ?? 0);
$adj_incentive_total = (float) ($salary['incentive_total'] ?? 0);
$adj_deduction_total = (float) ($salary['extra_deductions'] ?? 0);
$adj_net_impact = round($adj_bonus_total + $adj_incentive_total - $adj_deduction_total, 2);
$holidays_map = get_holidays_for_month($conn, $year, $month);
$roster_weekoff_dates = get_employee_weekoff_dates($conn, $emp_id, $year, $month);
$period_locked = is_payroll_period_locked($conn, $year, $month);
$can_send_period = can_send_slips_for_period($conn, $year, $month);
$already_sent_slip = employee_slip_already_sent($conn, $emp_id, $year, $month);

sync_approved_leave_attendance_for_period($conn, $emp_id, $year, $month);

$att_stmt = $conn->prepare("
    SELECT * FROM attendance
    WHERE emp_id = ?
      AND YEAR(attendance_date) = ?
      AND MONTH(attendance_date) = ?
    ORDER BY attendance_date DESC
");
$att_stmt->bind_param('sii', $emp_id, $year, $month);
$att_stmt->execute();
$att_result = $att_stmt->get_result();
$attendance_by_date = [];
$attendance_detail = [];
while ($row = $att_result->fetch_assoc()) {
    $attendance_by_date[$row['attendance_date']] = $row['status'];
    $attendance_detail[$row['attendance_date']] = $row;
}
$attendance_count = count($attendance_by_date);
$attendance_codes = count_calendar_display_codes($year, $month, $attendance_by_date, $roster_weekoff_dates, $holidays_map);
$is_current_month = ((int) date('n') === $month && (int) date('Y') === $year);
$today_day = $is_current_month ? (int) date('j') : 0;
[$prev_month, $prev_year] = get_adjacent_period($month, $year, -1);
[$next_month, $next_year] = get_adjacent_period($month, $year, 1);

$initial = strtoupper(substr($employee['name'], 0, 1));
$is_active = employee_is_active($employee);
$has_email = !empty($employee['email']);
$has_salary = (float) $employee['base_salary'] > 0;
$has_attendance = $stats['total_records'] > 0;
$view_redirect = 'employee_view.php?emp_id=' . urlencode($emp_id);
$dept = $employee['department'] ?: 'General';
$designation = $employee['designation'] ?: 'Staff';
$joined_date_value = normalize_joined_date_for_input($employee['joined_date'] ?? null);
$joined_date_display = format_joined_date_display($employee['joined_date'] ?? null);
?>
<div class="employee-view-page">
    <div class="page-header page-header-profile">
        <div class="page-header-row">
            <div class="page-header-main">
                <nav class="page-breadcrumb" aria-label="Breadcrumb">
                    <a href="employees.php">Employees</a>
                    <span class="page-breadcrumb-sep" aria-hidden="true">/</span>
                    <span class="page-breadcrumb-current">Profile</span>
                </nav>
                <p class="page-eyebrow">Employee profile</p>
                <h2><?php echo htmlspecialchars($employee['name']); ?></h2>
                <p class="page-header-desc">
                    <span class="page-header-id"><?php echo htmlspecialchars($employee['emp_id']); ?></span>
                    <?php echo htmlspecialchars($designation); ?> · <?php echo htmlspecialchars($dept); ?>
                </p>
            </div>
            <div class="page-header-actions">
                <div class="page-header-btn-group">
                    <form method="POST" action="employee_toggle_active.php" class="page-header-inline-form" onsubmit="return confirmToggleStatus(<?php echo json_encode($employee['name']); ?>, <?php echo $is_active ? 'true' : 'false'; ?>);">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($employee['emp_id']); ?>">
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($view_redirect); ?>">
                        <button type="submit" class="btn <?php echo $is_active ? 'btn-status-deactivate' : 'btn-status-activate'; ?>">
                            <?php if ($is_active): ?>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                            <?php else: ?>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <?php endif; ?>
                            <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                    <a href="preview_slip.php?emp_id=<?php echo urlencode($emp_id); ?>&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-action-back" target="_blank" rel="noopener">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        Preview PDF
                    </a>
                    <?php if ($has_email && $can_send_period && !$period_locked): ?>
                    <form method="POST" action="resend_slip.php" class="page-header-inline-form" onsubmit="return confirm('Resend salary slip email to <?php echo htmlspecialchars($employee['email'], ENT_QUOTES); ?>?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($emp_id); ?>">
                        <input type="hidden" name="month" value="<?php echo $month; ?>">
                        <input type="hidden" name="year" value="<?php echo $year; ?>">
                        <button type="submit" class="btn btn-action-back"><?php echo $already_sent_slip ? 'Resend slip' : 'Send slip'; ?></button>
                    </form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-action-edit" onclick="document.getElementById('editEmployeeModal').showModal()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Edit profile
                    </button>
                    <a href="employees.php" class="btn btn-action-back">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        Back to list
                    </a>
                    <form method="POST" action="employee_delete.php" class="page-header-inline-form" onsubmit="return confirm('Delete ' + <?php echo json_encode($employee['name']); ?> + ' and all attendance records? This cannot be undone.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($employee['emp_id']); ?>">
                        <button type="submit" class="btn btn-action-delete">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert <?php echo $_SESSION['flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page">
            <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_success']); ?>
        </div>
    <?php endif; ?>

    <div class="ev-profile-banner">
        <div class="ev-profile-banner-main">
            <span class="emp-avatar emp-avatar-xl"><?php echo htmlspecialchars($initial); ?></span>
            <div class="ev-profile-banner-text">
                <h3><?php echo htmlspecialchars($employee['name']); ?></h3>
                <p><?php echo htmlspecialchars($employee['emp_id']); ?></p>
                <div class="ev-profile-tags">
                    <span class="dept-badge"><?php echo htmlspecialchars($dept); ?></span>
                    <span class="ev-role-tag"><?php echo htmlspecialchars($designation); ?></span>
                </div>
            </div>
        </div>
        <div class="ev-readiness">
            <span class="ev-readiness-chip <?php echo $is_active ? 'ok' : 'warn'; ?>">
                <?php echo $is_active ? 'Active — receives emails' : 'Inactive — no slip emails'; ?>
            </span>
            <span class="ev-readiness-chip <?php echo $has_email ? 'ok' : 'warn'; ?>">
                <?php echo $has_email ? 'Email set' : 'No email'; ?>
            </span>
            <span class="ev-readiness-chip <?php echo $has_salary ? 'ok' : 'warn'; ?>">
                <?php echo $has_salary ? 'Salary configured' : 'Salary missing'; ?>
            </span>
            <span class="ev-readiness-chip <?php echo $has_attendance ? 'ok' : 'warn'; ?>">
                <?php echo $has_attendance ? $period_label . ' attendance' : 'No attendance this period'; ?>
            </span>
        </div>
    </div>

    <div class="ev-quick-stats">
        <div class="ev-quick-stat">
            <span class="ev-quick-stat-label">Base salary</span>
            <strong>₹<?php echo number_format((float) $employee['base_salary'], 0); ?></strong>
            <span class="ev-quick-stat-sub">per month</span>
        </div>
        <div class="ev-quick-stat ev-quick-stat-present">
            <span class="ev-quick-stat-label">Paid days</span>
            <strong><?php echo format_money($salary['paid_days']); ?></strong>
            <span class="ev-quick-stat-sub">P <?php echo (int) $salary['present_days']; ?> · HD <?php echo (int) $salary['half_days']; ?> · L <?php echo (int) $salary['leave_days']; ?> · WO <?php echo (int) ($salary['weekoff_days'] ?? 0); ?></span>
        </div>
        <div class="ev-quick-stat ev-quick-stat-absent">
            <span class="ev-quick-stat-label">Absent days</span>
            <strong><?php echo (int) $salary['absent_days']; ?></strong>
            <span class="ev-quick-stat-sub"><?php echo htmlspecialchars($period_label); ?></span>
        </div>
        <div class="ev-quick-stat ev-quick-stat-net">
            <span class="ev-quick-stat-label">Net take-home</span>
            <strong>₹<?php echo format_money($salary_breakdown['net_period']); ?></strong>
            <span class="ev-quick-stat-sub"><?php echo htmlspecialchars($period_label); ?></span>
        </div>
    </div>

    <div class="ev-layout">
        <aside class="ev-sidebar">
            <div class="ev-info-card">
                <h4 class="ev-info-card-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Contact
                </h4>
                <ul class="ev-info-list">
                    <li>
                        <span class="ev-info-label">Email</span>
                        <span class="ev-info-value"><?php echo $has_email ? htmlspecialchars($employee['email']) : '—'; ?></span>
                    </li>
                    <li>
                        <span class="ev-info-label">Phone</span>
                        <span class="ev-info-value"><?php echo htmlspecialchars($employee['phone'] ?: '—'); ?></span>
                    </li>
                </ul>
            </div>
            <div class="ev-info-card">
                <h4 class="ev-info-card-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                    Employment
                </h4>
                <ul class="ev-info-list">
                    <li>
                        <span class="ev-info-label">Department</span>
                        <span class="ev-info-value"><?php echo htmlspecialchars($employee['department'] ?: '—'); ?></span>
                    </li>
                    <li>
                        <span class="ev-info-label">Designation</span>
                        <span class="ev-info-value"><?php echo htmlspecialchars($employee['designation'] ?: '—'); ?></span>
                    </li>
                    <li>
                        <span class="ev-info-label">Joined</span>
                        <span class="ev-info-value"><?php echo htmlspecialchars($joined_date_display); ?></span>
                    </li>
                    <li>
                        <span class="ev-info-label">PAN</span>
                        <span class="ev-info-value"><?php echo htmlspecialchars($employee['pan'] ?: '—'); ?></span>
                    </li>
                    <li>
                        <span class="ev-info-label">Bank</span>
                        <span class="ev-info-value"><?php echo htmlspecialchars(trim(($employee['bank_name'] ?? '') . ' ' . ($employee['bank_account'] ?? '')) ?: '—'); ?></span>
                    </li>
                    <?php if (!empty($employee['bank_ifsc'])): ?>
                    <li>
                        <span class="ev-info-label">IFSC</span>
                        <span class="ev-info-value"><?php echo htmlspecialchars($employee['bank_ifsc']); ?></span>
                    </li>
                    <?php endif; ?>
                </ul>
                <div class="ev-slip-history">
                    <p class="ev-slip-history-heading">Sent salary slips</p>
                    <p class="ev-slip-history-sub">Up to last 6 periods with email sent</p>
                    <?php if (count($recent_sent_slips) > 0): ?>
                        <ul class="ev-slip-history-list">
                            <?php foreach ($recent_sent_slips as $slip):
                                $slip_month = (int) $slip['period_month'];
                                $slip_year = (int) $slip['period_year'];
                                $slip_label = get_period_label($slip_year, $slip_month);
                                $slip_view_url = 'employee_view.php?emp_id=' . urlencode($emp_id) . '&month=' . $slip_month . '&year=' . $slip_year;
                                $slip_pdf_url = 'preview_slip.php?emp_id=' . urlencode($emp_id) . '&month=' . $slip_month . '&year=' . $slip_year;
                                $is_current_period = ($slip_month === $month && $slip_year === $year);
                                $sent_display = date('j M Y', strtotime($slip['sent_at']));
                                ?>
                            <li class="ev-slip-history-item<?php echo $is_current_period ? ' is-active-period' : ''; ?>">
                                <a href="<?php echo htmlspecialchars($slip_view_url); ?>" class="ev-slip-history-main">
                                    <span class="ev-slip-history-period"><?php echo htmlspecialchars($slip_label); ?></span>
                                    <span class="ev-slip-history-net">₹<?php echo format_money($slip['net_salary']); ?></span>
                                </a>
                                <a href="<?php echo htmlspecialchars($slip_pdf_url); ?>" class="ev-slip-history-pdf" target="_blank" rel="noopener" title="Open PDF for <?php echo htmlspecialchars($slip_label); ?>">PDF</a>
                                <span class="ev-slip-history-date">Sent <?php echo htmlspecialchars($sent_display); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="ev-slip-history-empty">No salary slips sent yet. Use <a href="dashboard.php">Dashboard → Send Salary Slips</a>.</p>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <div class="ev-main">
            <div class="panel panel-elevated ev-salary-panel">
                <div class="panel-header ev-panel-header-split">
                    <div class="panel-title-group">
                        <h3>Salary breakdown</h3>
                        <span class="panel-badge"><?php echo htmlspecialchars($period_label); ?></span>
                    </div>
                    <form method="GET" class="ev-period-filter">
                        <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($emp_id); ?>">
                        <label class="ev-filter-label">Period</label>
                        <select name="month" aria-label="Month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="year" aria-label="Year">
                            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="btn btn-sm">Update</button>
                    </form>
                </div>
                <div class="panel-body padded ev-salary-breakdown">
                    <div class="ev-salary-meta">
                        <div class="ev-salary-meta-item">
                            <span>Gross salary (monthly)</span>
                            <strong>₹<?php echo format_money($salary_breakdown['gross_monthly']); ?></strong>
                        </div>
                        <div class="ev-salary-meta-item">
                            <span>Working days</span>
                            <strong><?php echo (int) $salary['working_days']; ?> days</strong>
                        </div>
                        <div class="ev-salary-meta-item">
                            <span>Daily rate</span>
                            <strong>₹<?php echo format_money($salary['daily_rate']); ?></strong>
                        </div>
                        <div class="ev-salary-meta-item ev-salary-meta-highlight">
                            <span>Paid days (<?php echo htmlspecialchars($period_label); ?>)</span>
                            <strong><?php echo format_money($salary['paid_days']); ?> / <?php echo (int) $salary['working_days']; ?> · <?php echo format_money($salary_breakdown['attendance_percent']); ?>%</strong>
                            <span class="ev-salary-meta-sub">P <?php echo (int) $salary['present_days']; ?> · HD <?php echo (int) $salary['half_days']; ?> · L <?php echo (int) $salary['leave_days']; ?> · WO <?php echo (int) ($salary['weekoff_days'] ?? 0); ?> · Abs <?php echo (int) $salary['absent_days']; ?></span>
                        </div>
                    </div>

                    <div class="ev-salary-table-section">
                        <h4 class="ev-salary-section-title">
                            <span class="ev-salary-section-icon earnings">+</span>
                            Earnings
                        </h4>
                        <div class="table-wrap">
                            <table class="ev-salary-table">
                                <thead>
                                    <tr>
                                        <th>Component</th>
                                        <th class="col-pct">%</th>
                                        <th class="col-amt">Monthly (₹)</th>
                                        <th class="col-amt">This period (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($salary_breakdown['earnings'] as $line): ?>
                                        <tr>
                                            <td>
                                                <span class="ev-comp-label"><?php echo htmlspecialchars($line['label']); ?></span>
                                                <?php if (!empty($line['hint'])): ?>
                                                    <span class="ev-comp-hint"><?php echo htmlspecialchars($line['hint']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-pct"><span class="ev-pct-badge"><?php echo htmlspecialchars($line['percent_label']); ?></span></td>
                                            <td class="col-amt"><?php echo format_money($line['monthly']); ?></td>
                                            <td class="col-amt strong"><?php echo format_money($line['period']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="ev-salary-tfoot">
                                        <td><strong>Gross earnings</strong></td>
                                        <td class="col-pct"><span class="ev-pct-badge">100%</span></td>
                                        <td class="col-amt"><strong>₹<?php echo format_money($salary_breakdown['earnings_monthly_total']); ?></strong></td>
                                        <td class="col-amt strong"><strong>₹<?php echo format_money($salary_breakdown['earnings_period_total']); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="ev-salary-table-section">
                        <h4 class="ev-salary-section-title">
                            <span class="ev-salary-section-icon deductions">−</span>
                            Deductions
                        </h4>
                        <div class="table-wrap">
                            <table class="ev-salary-table">
                                <thead>
                                    <tr>
                                        <th>Component</th>
                                        <th class="col-pct">Rate</th>
                                        <th class="col-amt">Monthly (₹)</th>
                                        <th class="col-amt">This period (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($salary_breakdown['deductions'] as $line): ?>
                                        <tr>
                                            <td>
                                                <span class="ev-comp-label"><?php echo htmlspecialchars($line['label']); ?></span>
                                                <?php if (!empty($line['hint'])): ?>
                                                    <span class="ev-comp-hint"><?php echo htmlspecialchars($line['hint']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-pct"><span class="ev-pct-badge ev-pct-ded"><?php echo htmlspecialchars($line['percent_label']); ?></span></td>
                                            <td class="col-amt ded">₹<?php echo format_money($line['monthly']); ?></td>
                                            <td class="col-amt ded strong">₹<?php echo format_money($line['period']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="ev-salary-tfoot ev-salary-tfoot-ded">
                                        <td><strong>Total deductions</strong></td>
                                        <td></td>
                                        <td class="col-amt"><strong>₹<?php echo format_money($salary_breakdown['deductions_monthly_total']); ?></strong></td>
                                        <td class="col-amt strong"><strong>₹<?php echo format_money($salary_breakdown['deductions_period_total']); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="ev-salary-summary-rows">
                        <div class="ev-salary-summary-row">
                            <span>Gross this period <small>(<?php echo format_money($salary['paid_days']); ?> paid days × ₹<?php echo format_money($salary['daily_rate']); ?>/day)</small></span>
                            <strong>₹<?php echo format_money($salary_breakdown['period_gross']); ?></strong>
                        </div>
                        <div class="ev-salary-summary-row">
                            <span>Less: statutory deductions (this period)</span>
                            <strong class="ded">− ₹<?php echo format_money($salary_breakdown['deductions_period_total']); ?></strong>
                        </div>
                    </div>

                    <div class="ev-net-box">
                        <div>
                            <span class="ev-net-label">Net take-home (this period)</span>
                            <p class="ev-net-formula">Gross earnings − PF, Professional Tax<?php echo $salary_breakdown['gross_monthly'] <= 21000 ? ', ESI' : ''; ?> · prorated for <?php echo format_money($salary['paid_days']); ?> paid days</p>
                        </div>
                        <strong class="ev-net-amount">₹<?php echo format_money($salary_breakdown['net_period']); ?></strong>
                    </div>
                    <p class="ev-salary-footnote">Percentages configurable in Settings → Payroll. Paid days: <?php echo format_money($salary['paid_days']); ?> of <?php echo (int) $salary['working_days']; ?> working days<?php if (!empty($salary['overtime_hours'])): ?> · OT <?php echo format_money($salary['overtime_hours']); ?>h<?php endif; ?>.</p>
                </div>
            </div>

            <div class="panel panel-elevated ev-adj-panel">
                <div class="panel-header ev-panel-header-split">
                    <div class="panel-title-group">
                        <h3>Payroll adjustments</h3>
                        <span class="panel-badge"><?php echo htmlspecialchars($period_label); ?></span>
                    </div>
                    <?php if (count($payroll_adjustments) > 0): ?>
                        <span class="ev-adj-count-badge"><?php echo count($payroll_adjustments); ?> item<?php echo count($payroll_adjustments) === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                </div>
                <div class="panel-body padded ev-adj-body">
                    <p class="ev-adj-intro">One-time bonus, incentive, or extra deductions for this pay period. Amounts are added to the salary breakdown above.</p>

                    <div class="ev-adj-summary">
                        <div class="ev-adj-summary-chip ev-adj-chip-bonus">
                            <span class="ev-adj-chip-label">Bonus</span>
                            <strong class="ev-adj-chip-value">+ ₹<?php echo format_money($adj_bonus_total); ?></strong>
                        </div>
                        <div class="ev-adj-summary-chip ev-adj-chip-incentive">
                            <span class="ev-adj-chip-label">Incentive</span>
                            <strong class="ev-adj-chip-value">+ ₹<?php echo format_money($adj_incentive_total); ?></strong>
                        </div>
                        <div class="ev-adj-summary-chip ev-adj-chip-deduction">
                            <span class="ev-adj-chip-label">Deductions</span>
                            <strong class="ev-adj-chip-value">− ₹<?php echo format_money($adj_deduction_total); ?></strong>
                        </div>
                        <div class="ev-adj-summary-chip ev-adj-chip-net<?php echo $adj_net_impact >= 0 ? ' is-positive' : ' is-negative'; ?>">
                            <span class="ev-adj-chip-label">Net impact</span>
                            <strong class="ev-adj-chip-value"><?php echo $adj_net_impact >= 0 ? '+' : '−'; ?> ₹<?php echo format_money(abs($adj_net_impact)); ?></strong>
                        </div>
                    </div>

                    <?php if (count($payroll_adjustments) > 0): ?>
                        <ul class="ev-adj-cards">
                            <?php foreach ($payroll_adjustments as $adj):
                                $adj_type = $adj['adj_type'] ?? 'deduction';
                                $is_deduction = ($adj_type === 'deduction');
                                $type_label = match ($adj_type) {
                                    'bonus' => 'Bonus',
                                    'incentive' => 'Incentive',
                                    default => 'Deduction',
                                };
                                ?>
                            <li class="ev-adj-card ev-adj-card-<?php echo htmlspecialchars($adj_type); ?>">
                                <div class="ev-adj-card-icon" aria-hidden="true">
                                    <?php if ($is_deduction): ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                                    <?php elseif ($adj_type === 'incentive'): ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                    <?php endif; ?>
                                </div>
                                <div class="ev-adj-card-main">
                                    <span class="ev-adj-card-type"><?php echo htmlspecialchars($type_label); ?></span>
                                    <strong class="ev-adj-card-label"><?php echo htmlspecialchars($adj['label']); ?></strong>
                                </div>
                                <div class="ev-adj-card-actions">
                                    <span class="ev-adj-card-amount<?php echo $is_deduction ? ' is-deduction' : ' is-credit'; ?>">
                                        <?php echo $is_deduction ? '−' : '+'; ?> ₹<?php echo format_money($adj['amount']); ?>
                                    </span>
                                    <?php if (!$period_locked): ?>
                                    <form method="POST" action="adjustment_save.php" class="ev-adj-remove-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($emp_id); ?>">
                                        <input type="hidden" name="month" value="<?php echo $month; ?>">
                                        <input type="hidden" name="year" value="<?php echo $year; ?>">
                                        <input type="hidden" name="adj_action" value="delete">
                                        <input type="hidden" name="adj_id" value="<?php echo (int) $adj['id']; ?>">
                                        <button type="submit" class="ev-adj-remove-btn" title="Remove adjustment" aria-label="Remove <?php echo htmlspecialchars($adj['label']); ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="ev-adj-empty">
                            <div class="ev-adj-empty-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 12h8M12 8v8"/></svg>
                            </div>
                            <p><strong>No adjustments yet</strong></p>
                            <p>Add a bonus, incentive, or one-time deduction for <?php echo htmlspecialchars($period_label); ?>.</p>
                        </div>
                    <?php endif; ?>

                    <?php if ($period_locked): ?>
                        <p class="ev-adj-lock-note">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            This payroll period is locked. Reopen it from Upload Attendance to add or remove adjustments.
                        </p>
                    <?php else: ?>
                        <div class="ev-adj-form-card">
                            <h4 class="ev-adj-form-title">Add adjustment</h4>
                            <form method="POST" action="adjustment_save.php" class="ev-adj-form">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($emp_id); ?>">
                                <input type="hidden" name="month" value="<?php echo $month; ?>">
                                <input type="hidden" name="year" value="<?php echo $year; ?>">
                                <div class="ev-adj-form-grid">
                                    <div class="form-group">
                                        <label for="adj_type">Type</label>
                                        <select name="adj_type" id="adj_type" class="ev-adj-type-select">
                                            <option value="bonus">Bonus — added to earnings</option>
                                            <option value="incentive">Incentive — added to earnings</option>
                                            <option value="deduction">Deduction — subtracted from net</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="adj_label">Label</label>
                                        <input type="text" name="label" id="adj_label" required placeholder="e.g. Festival bonus, Sales incentive">
                                    </div>
                                    <div class="form-group">
                                        <label for="adj_amount">Amount (₹)</label>
                                        <input type="number" name="amount" id="adj_amount" step="0.01" min="0.01" required placeholder="0.00">
                                    </div>
                                    <div class="form-group ev-adj-form-submit">
                                        <label>&nbsp;</label>
                                        <button type="submit" class="btn btn-sm">Add adjustment</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel panel-elevated">
                <div class="panel-header"><h3>Custom salary profile</h3></div>
                <div class="panel-body padded">
                    <form method="POST" action="profile_save.php" class="stack-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($emp_id); ?>">
                        <input type="hidden" name="month" value="<?php echo $month; ?>">
                        <input type="hidden" name="year" value="<?php echo $year; ?>">
                        <label><input type="checkbox" name="use_custom" value="1" <?php echo ($payroll_profile['use_custom'] ?? 0) ? 'checked' : ''; ?>> Use custom salary % (overrides global settings)</label>
                        <div class="form-row" style="margin-top:12px">
                            <div class="form-group"><label>Basic %</label><input type="number" step="0.1" name="pct_basic" value="<?php echo htmlspecialchars($payroll_profile['pct_basic'] ?? ''); ?>"></div>
                            <div class="form-group"><label>HRA %</label><input type="number" step="0.1" name="pct_hra" value="<?php echo htmlspecialchars($payroll_profile['pct_hra'] ?? ''); ?>"></div>
                        </div>
                        <button type="submit" class="btn btn-sm">Save profile</button>
                    </form>
                </div>
            </div>

            <div class="panel panel-elevated ev-att-panel">
                <div class="panel-header ev-att-cal-header">
                    <div class="panel-title-group">
                        <h3>Attendance calendar</h3>
                    </div>
                    <form method="GET" class="att-cal-month-nav" id="attCalMonthForm">
                        <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($emp_id); ?>">
                        <a href="<?php echo htmlspecialchars(employee_view_period_url($emp_id, $prev_month, $prev_year)); ?>" class="att-cal-nav-btn" title="Previous month" aria-label="Previous month">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                        </a>
                        <select name="month" class="att-cal-select" aria-label="Month" onchange="this.form.submit()">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo date('M', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="year" class="att-cal-select" aria-label="Year" onchange="this.form.submit()">
                            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <a href="<?php echo htmlspecialchars(employee_view_period_url($emp_id, $next_month, $next_year)); ?>" class="att-cal-nav-btn" title="Next month" aria-label="Next month">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                    </form>
                    <div class="att-cal-summary">
                        <span class="att-legend-item att-code-p"><strong>P</strong> <?php echo (int) $attendance_codes['P']; ?></span>
                        <span class="att-legend-item att-code-a"><strong>A</strong> <?php echo (int) $attendance_codes['A']; ?></span>
                        <span class="att-legend-item att-code-hd"><strong>HD</strong> <?php echo (int) $attendance_codes['HD']; ?></span>
                        <span class="att-legend-item att-code-wo"><strong>WO</strong> <?php echo (int) ($attendance_codes['WO'] ?? 0); ?></span>
                        <?php if ($attendance_codes['other'] > 0): ?>
                            <span class="att-legend-item att-code-unknown"><strong>?</strong> <?php echo (int) $attendance_codes['other']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="panel-body att-cal-panel-body">
                    <p class="att-cal-month-title"><?php echo htmlspecialchars($period_label); ?></p>
                    <?php echo render_attendance_calendar($year, $month, $attendance_by_date, $today_day, $holidays_map, false, $attendance_detail, $roster_weekoff_dates); ?>
                    <?php if ($attendance_count === 0): ?>
                        <p class="att-cal-empty-note">No attendance uploaded for this month. <a href="upload_attendance.php">Upload attendance</a></p>
                    <?php endif; ?>
                    <div class="att-cal-legend att-cal-legend-compact">
                        <span class="att-legend-item"><span class="att-legend-swatch att-code-p">P</span> Present</span>
                        <span class="att-legend-item"><span class="att-legend-swatch att-code-a">A</span> Absent</span>
                        <span class="att-legend-item"><span class="att-legend-swatch att-code-hd">HD</span> Half day</span>
                        <span class="att-legend-item"><span class="att-legend-swatch att-code-wo">WO</span> Week off</span>
                        <span class="att-legend-item"><span class="att-legend-swatch att-cal-dash">—</span> None</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<dialog class="modal modal-employee" id="editEmployeeModal">
    <form method="POST" action="employee_save.php" class="modal-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($employee['emp_id']); ?>">
        <input type="hidden" name="return_month" value="<?php echo (int) $month; ?>">
        <input type="hidden" name="return_year" value="<?php echo (int) $year; ?>">
        <div class="modal-head">
            <div class="modal-head-content">
                <div class="modal-head-icon" style="background:linear-gradient(135deg,#0891b2,#06b6d4)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div>
                    <h3>Edit Employee</h3>
                    <p>Update profile, salary &amp; joining date</p>
                </div>
            </div>
            <button type="button" class="modal-close" aria-label="Close" onclick="document.getElementById('editEmployeeModal').close()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="modal-section">
                <h4 class="modal-section-title">Basic information</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Employee ID</label>
                        <input type="text" value="<?php echo htmlspecialchars($employee['emp_id']); ?>" disabled class="input-disabled">
                    </div>
                    <div class="form-group">
                        <label for="ev_edit_name">Full Name <span class="req">*</span></label>
                        <input type="text" name="name" id="ev_edit_name" value="<?php echo htmlspecialchars($employee['name']); ?>" required>
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <h4 class="modal-section-title">Contact</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ev_edit_email">Email</label>
                        <input type="email" name="email" id="ev_edit_email" value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="ev_edit_phone">Phone</label>
                        <input type="tel" name="phone" id="ev_edit_phone" value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>">
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <h4 class="modal-section-title">Job &amp; compensation</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ev_edit_department">Department</label>
                        <input type="text" name="department" id="ev_edit_department" value="<?php echo htmlspecialchars($employee['department'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="ev_edit_designation">Designation</label>
                        <input type="text" name="designation" id="ev_edit_designation" value="<?php echo htmlspecialchars($employee['designation'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ev_edit_grade">Grade</label>
                        <input type="text" name="grade" id="ev_edit_grade" value="<?php echo htmlspecialchars($employee['grade'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="ev_edit_pf_no">P.F. No.</label>
                        <input type="text" name="pf_no" id="ev_edit_pf_no" value="<?php echo htmlspecialchars($employee['pf_no'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ev_edit_esic_no">E.S.I.C. No.</label>
                        <input type="text" name="esic_no" id="ev_edit_esic_no" value="<?php echo htmlspecialchars($employee['esic_no'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="ev_edit_uan_no">U.A.N. No.</label>
                        <input type="text" name="uan_no" id="ev_edit_uan_no" value="<?php echo htmlspecialchars($employee['uan_no'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ev_edit_joined_date">Joined Date</label>
                        <input type="date" name="joined_date" id="ev_edit_joined_date" value="<?php echo htmlspecialchars($joined_date_value); ?>">
                    </div>
                    <div class="form-group">
                        <label for="ev_edit_base_salary">Base Salary (₹) <span class="req">*</span></label>
                        <div class="input-prefix-wrap">
                            <span class="input-prefix">₹</span>
                            <input type="number" name="base_salary" id="ev_edit_base_salary" min="0" step="0.01" value="<?php echo htmlspecialchars($employee['base_salary']); ?>" required>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <h4 class="modal-section-title">Bank &amp; tax</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ev_edit_pan">PAN</label>
                        <input type="text" name="pan" id="ev_edit_pan" value="<?php echo htmlspecialchars($employee['pan'] ?? ''); ?>" maxlength="20">
                    </div>
                    <div class="form-group">
                        <label for="ev_edit_bank_name">Bank name</label>
                        <input type="text" name="bank_name" id="ev_edit_bank_name" value="<?php echo htmlspecialchars($employee['bank_name'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ev_edit_bank_account">Account number</label>
                        <input type="text" name="bank_account" id="ev_edit_bank_account" value="<?php echo htmlspecialchars($employee['bank_account'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="ev_edit_bank_ifsc">IFSC</label>
                        <input type="text" name="bank_ifsc" id="ev_edit_bank_ifsc" value="<?php echo htmlspecialchars($employee['bank_ifsc'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('editEmployeeModal').close()">Cancel</button>
            <button type="submit" class="btn">Save Changes</button>
        </div>
    </form>
</dialog>

<script>
function confirmToggleStatus(name, isActive) {
    if (isActive) {
        return confirm('Deactivate "' + name + '"?\n\nThey will not receive salary slip emails while inactive.');
    }
    return confirm('Activate "' + name + '"?\n\nThey can receive salary slip emails again if email and salary are set.');
}

document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('editEmployeeModal');
    if (modal && modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
});
</script>

<?php require 'includes/footer.php'; ?>
