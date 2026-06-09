<?php
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/attendance_helper.php';
require_once __DIR__ . '/includes/period.php';

$settings = get_all_settings($conn);
$emp_id = $employee['emp_id'];
[$year, $month] = emp_parse_period();

$period_label = get_period_label($year, $month);
$stats = get_attendance_stats_extended($conn, $emp_id, $year, $month, $settings);
$holidays_map = get_holidays_for_month($conn, $year, $month, (int) $employee['branch_id']);
$roster_weekoff_dates = get_employee_weekoff_dates($conn, $emp_id, $year, $month);
$leave_types = get_leave_types($conn);

$att_stmt = $conn->prepare("
    SELECT * FROM attendance
    WHERE emp_id = ? AND YEAR(attendance_date) = ? AND MONTH(attendance_date) = ?
    ORDER BY attendance_date
");
$att_stmt->bind_param('sii', $emp_id, $year, $month);
$att_stmt->execute();
$att_result = $att_stmt->get_result();
$attendance_by_date = [];
while ($row = $att_result->fetch_assoc()) {
    $attendance_by_date[$row['attendance_date']] = $row['status'];
}
$attendance_codes = count_attendance_codes($attendance_by_date);

$attendance_requests = get_employee_attendance_requests($conn, $emp_id, 15);
$period_locked = is_payroll_period_locked($conn, $year, $month, (int) $employee['branch_id']);
$requests_limit = get_attendance_requests_per_month_limit($settings);
$requests_remaining = employee_attendance_request_remaining($conn, $emp_id, $year, $month, $settings);
$requests_used = max(0, $requests_limit - $requests_remaining);
$quota_percent = $requests_limit > 0 ? min(100, (int) round(($requests_used / $requests_limit) * 100)) : 0;
$attendance_form_disabled = $requests_remaining <= 0 || $period_locked;
$attendance_requests_month = array_values(array_filter($attendance_requests, static function ($req) use ($year, $month) {
    return (int) date('Y', strtotime($req['attendance_date'])) === $year
        && (int) date('n', strtotime($req['attendance_date'])) === $month;
}));

[$prev_month, $prev_year] = get_adjacent_period($month, $year, -1);
[$next_month, $next_year] = get_adjacent_period($month, $year, 1);
$is_current_month = ((int) date('n') === $month && (int) date('Y') === $year);
$today_day = $is_current_month ? (int) date('j') : 0;
$month_start = sprintf('%d-%02d-01', $year, $month);
$month_end = sprintf('%d-%02d-%d', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));
$period_query = 'year=' . $year . '&month=' . $month;
?>
<div class="emp-page emp-page-attendance">
    <?php require __DIR__ . '/includes/flash.php'; ?>

    <div class="emp-page-hero">
        <div class="emp-page-hero-main">
            <p class="page-eyebrow">Attendance</p>
            <h1>My attendance</h1>
            <p>View your calendar for <strong><?php echo htmlspecialchars($period_label); ?></strong> and submit manual correction requests to your branch admin.</p>
        </div>
        <div class="emp-period-nav">
            <a href="attendance.php?<?php echo 'year=' . $prev_year . '&month=' . $prev_month; ?>" class="emp-period-nav-btn" aria-label="Previous month">&larr;</a>
            <span class="emp-period-nav-label"><?php echo htmlspecialchars($period_label); ?></span>
            <a href="attendance.php?<?php echo 'year=' . $next_year . '&month=' . $next_month; ?>" class="emp-period-nav-btn" aria-label="Next month">&rarr;</a>
        </div>
    </div>

    <div class="emp-page-stats emp-page-stats-4">
        <div class="emp-dash-stat emp-dash-stat-paid">
            <div class="emp-dash-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <span class="emp-dash-stat-label">Paid days</span>
                <strong class="emp-dash-stat-value"><?php echo format_money($stats['paid_days']); ?></strong>
            </div>
        </div>
        <div class="emp-dash-stat emp-dash-stat-present">
            <div class="emp-dash-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            </div>
            <div>
                <span class="emp-dash-stat-label">Present</span>
                <strong class="emp-dash-stat-value"><?php echo (int) $stats['present_days']; ?></strong>
            </div>
        </div>
        <div class="emp-dash-stat emp-dash-stat-absent">
            <div class="emp-dash-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div>
                <span class="emp-dash-stat-label">Absent</span>
                <strong class="emp-dash-stat-value"><?php echo (int) $stats['absent_days']; ?></strong>
            </div>
        </div>
        <div class="emp-dash-stat emp-dash-stat-quota">
            <div class="emp-dash-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <span class="emp-dash-stat-label">Requests left</span>
                <strong class="emp-dash-stat-value"><?php echo $requests_remaining; ?><small>/<?php echo $requests_limit; ?></small></strong>
            </div>
        </div>
    </div>

    <div class="emp-section-grid emp-section-grid-att">
        <div class="emp-card emp-card-calendar">
            <div class="emp-card-toolbar">
                <div class="emp-att-legend" aria-label="Attendance summary for <?php echo htmlspecialchars($period_label); ?>">
                    <span class="emp-att-legend-heading">Month summary</span>
                    <div class="emp-att-legend-chips">
                        <span class="emp-att-legend-chip emp-att-legend-chip-p">
                            <span class="emp-att-legend-code">P</span>
                            <span class="emp-att-legend-meta">
                                <strong class="emp-att-legend-count"><?php echo (int) $attendance_codes['P']; ?></strong>
                                <span class="emp-att-legend-name">Present</span>
                            </span>
                        </span>
                        <span class="emp-att-legend-chip emp-att-legend-chip-a">
                            <span class="emp-att-legend-code">A</span>
                            <span class="emp-att-legend-meta">
                                <strong class="emp-att-legend-count"><?php echo (int) $attendance_codes['A']; ?></strong>
                                <span class="emp-att-legend-name">Absent</span>
                            </span>
                        </span>
                        <span class="emp-att-legend-chip emp-att-legend-chip-hd">
                            <span class="emp-att-legend-code">HD</span>
                            <span class="emp-att-legend-meta">
                                <strong class="emp-att-legend-count"><?php echo (int) $attendance_codes['HD']; ?></strong>
                                <span class="emp-att-legend-name">Half day</span>
                            </span>
                        </span>
                        <span class="emp-att-legend-chip emp-att-legend-chip-wo">
                            <span class="emp-att-legend-code">WO</span>
                            <span class="emp-att-legend-meta">
                                <strong class="emp-att-legend-count"><?php echo (int) ($attendance_codes['WO'] ?? 0); ?></strong>
                                <span class="emp-att-legend-name">Week off</span>
                            </span>
                        </span>
                    </div>
                </div>
                <?php if ($period_locked): ?>
                    <span class="emp-page-lock-badge">Period locked</span>
                <?php endif; ?>
            </div>
            <div class="emp-cal-wrap">
                <?php echo render_attendance_calendar($year, $month, $attendance_by_date, $today_day, $holidays_map, false, [], $roster_weekoff_dates); ?>
            </div>
            <div class="emp-cal-legend-foot">
                <span class="emp-cal-legend-key">Legend</span>
                <div class="emp-cal-legend-items">
                    <span class="emp-cal-legend-item"><span class="emp-cal-legend-swatch emp-cal-legend-p">P</span> Present</span>
                    <span class="emp-cal-legend-item"><span class="emp-cal-legend-swatch emp-cal-legend-a">A</span> Absent</span>
                    <span class="emp-cal-legend-item"><span class="emp-cal-legend-swatch emp-cal-legend-hd">HD</span> Half day</span>
                    <span class="emp-cal-legend-item"><span class="emp-cal-legend-swatch emp-cal-legend-wo">WO</span> Week off</span>
                </div>
            </div>
        </div>

        <aside class="emp-request-panel emp-request-panel-att">
            <div class="emp-request-panel-header">
                <span class="emp-request-panel-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                </span>
                <div>
                    <h3>Request manual attendance</h3>
                    <p>Pick a date in <strong><?php echo htmlspecialchars($period_label); ?></strong>. Branch admin will approve before it appears on your calendar.</p>
                </div>
            </div>
            <div class="emp-request-panel-body">
                <div class="emp-quota-meter">
                    <div class="emp-quota-meter-top">
                        <span>Quota for <?php echo htmlspecialchars($period_label); ?></span>
                        <strong><?php echo $requests_used; ?> / <?php echo $requests_limit; ?> used</strong>
                    </div>
                    <div class="emp-quota-bar" role="progressbar" aria-valuenow="<?php echo $quota_percent; ?>" aria-valuemin="0" aria-valuemax="100">
                        <span class="emp-quota-fill<?php echo $requests_remaining <= 0 ? ' is-full' : ''; ?>" style="width:<?php echo $quota_percent; ?>%"></span>
                    </div>
                    <p class="emp-quota-hint">
                        <?php if ($requests_remaining > 0): ?>
                            You can submit <strong><?php echo $requests_remaining; ?></strong> more request<?php echo $requests_remaining === 1 ? '' : 's'; ?> for this month.
                        <?php else: ?>
                            All <?php echo $requests_limit; ?> requests used for <?php echo htmlspecialchars($period_label); ?>.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($period_locked): ?>
                    <div class="emp-inline-alert emp-inline-alert-warn">
                        <strong>Period locked</strong>
                        <span><?php echo htmlspecialchars($period_label); ?> payroll is locked. Ask your branch admin to reopen it.</span>
                    </div>
                <?php elseif ($requests_remaining <= 0): ?>
                    <div class="emp-inline-alert emp-inline-alert-warn">
                        <strong>Monthly limit reached</strong>
                        <span>Maximum <?php echo $requests_limit; ?> manual requests allowed per month.</span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="attendance_request_save.php" class="emp-request-form<?php echo $attendance_form_disabled ? ' is-disabled' : ''; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="redirect" value="attendance.php?<?php echo $period_query; ?>">
                    <div class="emp-request-fields">
                        <div class="form-group">
                            <label for="empAttDate">Attendance date</label>
                            <input type="date" id="empAttDate" name="attendance_date" required min="<?php echo $month_start; ?>" max="<?php echo $month_end; ?>" value="<?php echo $is_current_month ? date('Y-m-d') : $month_start; ?>" <?php echo $attendance_form_disabled ? 'disabled' : ''; ?>>
                            <span class="form-hint">Only dates in <?php echo htmlspecialchars($period_label); ?></span>
                        </div>
                        <div class="form-group">
                            <label for="empAttStatus">Mark as</label>
                            <select name="status" id="empAttStatus" required <?php echo $attendance_form_disabled ? 'disabled' : ''; ?>>
                                <option value="Present">Present (P)</option>
                                <option value="Absent">Absent (A)</option>
                                <option value="Half day">Half day (HD)</option>
                                <option value="Leave">Leave (L)</option>
                                <option value="Week off">Week off (WO)</option>
                            </select>
                        </div>
                        <div class="form-group" id="empLeaveWrap" hidden>
                            <label for="empLeaveType">Leave type</label>
                            <select name="leave_type" id="empLeaveType" <?php echo $attendance_form_disabled ? 'disabled' : ''; ?>>
                                <?php foreach ($leave_types as $lt): ?>
                                    <option value="<?php echo htmlspecialchars($lt['code']); ?>"><?php echo htmlspecialchars($lt['code'] . ' — ' . $lt['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="empAttNote">Reason / note</label>
                            <textarea id="empAttNote" name="employee_note" rows="3" placeholder="e.g. Forgot to punch — was on client visit" <?php echo $attendance_form_disabled ? 'disabled' : ''; ?>></textarea>
                        </div>
                    </div>
                    <div class="emp-request-submit">
                        <button type="submit" class="btn btn-block" <?php echo $attendance_form_disabled ? 'disabled' : ''; ?>>Send to branch admin</button>
                    </div>
                </form>

                <?php if ($attendance_requests_month !== []): ?>
                <div class="emp-timeline">
                    <h4>Requests for <?php echo htmlspecialchars($period_label); ?></h4>
                    <?php foreach ($attendance_requests_month as $req): ?>
                        <article class="emp-timeline-item">
                            <div class="emp-timeline-dot emp-timeline-<?php echo htmlspecialchars($req['request_status']); ?>"></div>
                            <div class="emp-timeline-body">
                                <div class="emp-timeline-top">
                                    <strong><?php echo date('d M Y', strtotime($req['attendance_date'])); ?></strong>
                                    <span class="emp-req-status emp-req-<?php echo htmlspecialchars($req['request_status']); ?>"><?php echo htmlspecialchars(ucfirst($req['request_status'])); ?></span>
                                </div>
                                <p><?php echo htmlspecialchars($req['status']); ?><?php if ($req['leave_type']): ?> · <?php echo htmlspecialchars($req['leave_type']); ?><?php endif; ?></p>
                                <time>Submitted <?php echo date('d M, h:i A', strtotime($req['created_at'])); ?></time>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>
<script>
(function () {
    var status = document.getElementById('empAttStatus');
    var wrap = document.getElementById('empLeaveWrap');
    if (!status || !wrap) return;
    function sync() { wrap.hidden = status.value !== 'Leave'; }
    status.addEventListener('change', sync);
    sync();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
