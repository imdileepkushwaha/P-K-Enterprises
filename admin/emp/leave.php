<?php
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/attendance_helper.php';
require_once __DIR__ . '/../includes/payroll_extensions.php';
require_once __DIR__ . '/includes/period.php';

$settings = get_all_settings($conn);
$emp_id = $employee['emp_id'];
[$year, $month] = emp_parse_period();

$leave_balances = get_employee_leave_balances($conn, $emp_id, $settings);

$period_label = get_period_label($year, $month);
$leave_types = get_leave_types($conn);
$leave_requests = get_employee_leave_requests($conn, $emp_id, 25);
$month_start = sprintf('%d-%02d-01', $year, $month);
$month_end = sprintf('%d-%02d-%d', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));
$leave_requests_for_month = array_values(array_filter($leave_requests, static function ($req) use ($month_start, $month_end) {
    return ($req['from_date'] ?? '') <= $month_end && ($req['to_date'] ?? '') >= $month_start;
}));
$period_locked = is_payroll_period_locked($conn, $year, $month, (int) $employee['branch_id']);
$requests_limit = get_leave_requests_per_month_limit($settings);
$requests_remaining = employee_leave_request_remaining($conn, $emp_id, $year, $month, $settings);
$requests_used = max(0, $requests_limit - $requests_remaining);
$leave_form_disabled = $requests_remaining <= 0 || $period_locked;
$pending_leave = 0;
foreach ($leave_requests_for_month as $req) {
    if (($req['request_status'] ?? '') === 'pending') {
        $pending_leave++;
    }
}

sync_approved_leave_attendance_for_period($conn, $emp_id, $year, $month);
$approved_leave_rows = get_approved_leave_records_for_month($conn, $emp_id, $year, $month);

$all_att_stmt = $conn->prepare("
    SELECT * FROM attendance
    WHERE emp_id = ? AND YEAR(attendance_date) = ? AND MONTH(attendance_date) = ?
    ORDER BY attendance_date ASC
");
$all_att_stmt->bind_param('sii', $emp_id, $year, $month);
$all_att_stmt->execute();
$month_attendance = $all_att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$attendance_by_date = [];
$attendance_detail = [];
foreach ($month_attendance as $row) {
    $attendance_by_date[$row['attendance_date']] = $row['status'];
    $attendance_detail[$row['attendance_date']] = $row;
}
$holidays_map = get_holidays_for_month($conn, $year, $month, (int) $employee['branch_id']);
$roster_weekoff_dates = get_employee_weekoff_dates($conn, $emp_id, $year, $month);
$attendance_codes = count_calendar_display_codes($year, $month, $attendance_by_date, $roster_weekoff_dates, $holidays_map);

[$prev_month, $prev_year] = get_adjacent_period($month, $year, -1);
[$next_month, $next_year] = get_adjacent_period($month, $year, 1);
$is_current_month = ((int) date('n') === $month && (int) date('Y') === $year);
$today_day = $is_current_month ? (int) date('j') : 0;
$period_query = 'year=' . $year . '&month=' . $month;
$default_from = $is_current_month ? date('Y-m-d') : $month_start;
?>
<div class="emp-page emp-page-leave">
    <?php require __DIR__ . '/includes/flash.php'; ?>

    <div class="emp-page-hero">
        <div class="emp-page-hero-main">
            <p class="page-eyebrow">Leave</p>
            <h1>Apply for leave</h1>
            <p>Submit a leave request for admin approval. Approved leave will appear on your attendance calendar and monthly record.</p>
        </div>
        <div class="emp-period-nav">
            <a href="leave.php?<?php echo 'year=' . $prev_year . '&month=' . $prev_month; ?>" class="emp-period-nav-btn" aria-label="Previous month">&larr;</a>
            <span class="emp-period-nav-label"><?php echo htmlspecialchars($period_label); ?></span>
            <a href="leave.php?<?php echo 'year=' . $next_year . '&month=' . $next_month; ?>" class="emp-period-nav-btn" aria-label="Next month">&rarr;</a>
        </div>
    </div>

    <div class="emp-section-grid emp-section-grid-leave">
        <aside class="emp-request-panel emp-request-panel-leave">
            <div class="emp-request-panel-header">
                <span class="emp-request-panel-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <div>
                    <h3>New leave request</h3>
                    <p>Select date range and leave type. Branch admin will approve before it is recorded.</p>
                </div>
            </div>
            <div class="emp-request-panel-body">
                <div class="emp-quota-meter" style="margin-bottom: 24px;">
                    <h4 style="margin: 0 0 12px; font-size: 14px;">Leave Balances</h4>
                    <div style="display: flex; gap: 12px; font-size: 13px;">
                        <div style="flex:1; background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                            <div style="color: #64748b; font-weight: 600; margin-bottom: 4px;">PL</div>
                            <div style="font-size: 16px; font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($leave_balances['PL'] ?? '0'); ?></div>
                        </div>
                        <div style="flex:1; background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                            <div style="color: #64748b; font-weight: 600; margin-bottom: 4px;">SL</div>
                            <div style="font-size: 16px; font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($leave_balances['SL'] ?? '0'); ?></div>
                        </div>
                        <div style="flex:1; background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                            <div style="color: #64748b; font-weight: 600; margin-bottom: 4px;">CL</div>
                            <div style="font-size: 16px; font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($leave_balances['CL'] ?? '0'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="emp-quota-meter">
                    <div class="emp-quota-meter-top">
                        <span>Applications for <?php echo htmlspecialchars($period_label); ?></span>
                        <strong><?php echo $requests_used; ?> / <?php echo $requests_limit; ?> used</strong>
                    </div>
                    <p class="emp-quota-hint">
                        <?php if ($requests_remaining > 0): ?>
                            You can submit <strong><?php echo $requests_remaining; ?></strong> more leave application<?php echo $requests_remaining === 1 ? '' : 's'; ?> this month.
                        <?php else: ?>
                            Monthly leave application limit reached for <?php echo htmlspecialchars($period_label); ?>.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($period_locked): ?>
                    <div class="emp-inline-alert emp-inline-alert-warn">
                        <strong>Period locked</strong>
                        <span><?php echo htmlspecialchars($period_label); ?> payroll is locked.</span>
                    </div>
                <?php elseif ($requests_remaining <= 0): ?>
                    <div class="emp-inline-alert emp-inline-alert-warn">
                        <strong>Monthly limit reached</strong>
                        <span>Maximum <?php echo $requests_limit; ?> leave applications per month.</span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="leave_request_save.php" class="emp-request-form<?php echo $leave_form_disabled ? ' is-disabled' : ''; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="redirect" value="leave.php?<?php echo $period_query; ?>">
                    <div class="emp-request-fields">
                        <div class="form-group">
                            <label for="empLeaveFrom">From date</label>
                            <input type="date" id="empLeaveFrom" name="from_date" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($default_from); ?>" <?php echo $leave_form_disabled ? 'disabled' : ''; ?>>
                        </div>
                        <div class="form-group">
                            <label for="empLeaveTo">To date</label>
                            <input type="date" id="empLeaveTo" name="to_date" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($default_from); ?>" <?php echo $leave_form_disabled ? 'disabled' : ''; ?>>
                            <span class="form-hint" id="empLeaveDayCount"></span>
                        </div>
                        <div class="form-group">
                            <label for="empLeaveTypePick">Leave type</label>
                            <select name="leave_type" id="empLeaveTypePick" required <?php echo $leave_form_disabled ? 'disabled' : ''; ?>>
                                <?php foreach ($leave_types as $lt): ?>
                                    <option value="<?php echo htmlspecialchars($lt['code']); ?>"><?php echo htmlspecialchars($lt['code'] . ' — ' . $lt['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="empLeaveNote">Reason</label>
                            <textarea id="empLeaveNote" name="employee_note" rows="3" placeholder="Brief reason for leave" required <?php echo $leave_form_disabled ? 'disabled' : ''; ?>></textarea>
                        </div>
                    </div>
                    <div class="emp-request-submit">
                        <button type="submit" class="btn btn-block" <?php echo $leave_form_disabled ? 'disabled' : ''; ?>>Submit leave request</button>
                    </div>
                </form>
            </div>
        </aside>

        <div class="emp-leave-main">
            <div class="emp-card emp-card-calendar">
                <div class="emp-card-toolbar">
                    <h3 class="emp-card-title">Leave calendar — <?php echo htmlspecialchars($period_label); ?></h3>
                    <div class="emp-att-legend-chips emp-att-legend-chips-inline">
                        <span class="emp-att-legend-chip emp-att-legend-chip-l">
                            <span class="emp-att-legend-code">L</span>
                            <span class="emp-att-legend-meta">
                                <strong class="emp-att-legend-count"><?php echo (int) ($attendance_codes['L'] ?? 0); ?></strong>
                                <span class="emp-att-legend-name">Leave days</span>
                            </span>
                        </span>
                    </div>
                </div>
                <div class="emp-cal-wrap">
                    <?php echo render_attendance_calendar($year, $month, $attendance_by_date, $today_day, $holidays_map, false, $attendance_detail, $roster_weekoff_dates); ?>
                </div>
                <div class="emp-cal-legend-foot">
                    <span class="emp-cal-legend-key">Legend</span>
                    <div class="emp-cal-legend-items">
                        <span class="emp-cal-legend-item"><span class="emp-cal-legend-swatch att-code-l">CL</span> Leave (CL/SL/LOP)</span>
                        <span class="emp-cal-legend-item"><span class="emp-cal-legend-swatch emp-cal-legend-p">P</span> Present</span>
                        <span class="emp-cal-legend-item"><span class="emp-cal-legend-swatch emp-cal-legend-a">A</span> Absent</span>
                    </div>
                </div>
            </div>

            <div class="emp-card emp-leave-table-card">
                <div class="emp-card-toolbar">
                    <h3 class="emp-card-title">Approved leave — <?php echo htmlspecialchars($period_label); ?></h3>
                </div>
                <?php if ($approved_leave_rows === []): ?>
                    <p class="emp-leave-empty">No approved leave recorded for this month yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="emp-req-table emp-leave-records-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Leave type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approved_leave_rows as $row): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($row['attendance_date'])); ?></td>
                                        <td><?php echo date('l', strtotime($row['attendance_date'])); ?></td>
                                        <td><span class="att-legend-item att-code-l"><?php echo htmlspecialchars($row['leave_type'] ?: 'CL'); ?></span></td>
                                        <td>Approved</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="emp-card emp-leave-table-card">
                <div class="emp-card-toolbar">
                    <h3 class="emp-card-title">Leave requests — <?php echo htmlspecialchars($period_label); ?></h3>
                    <?php if ($pending_leave > 0): ?>
                        <span class="emp-badge pending"><?php echo $pending_leave; ?> pending</span>
                    <?php endif; ?>
                </div>
                <?php if ($leave_requests_for_month === []): ?>
                    <p class="emp-leave-empty">No leave requests for this month.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="emp-req-table emp-leave-requests-table">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th>Days</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leave_requests_for_month as $req): ?>
                                    <?php $days = leave_request_day_count($req['from_date'], $req['to_date']); ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo date('d M', strtotime($req['from_date'])); ?></strong>
                                            <?php if ($req['from_date'] !== $req['to_date']): ?>
                                                – <?php echo date('d M Y', strtotime($req['to_date'])); ?>
                                            <?php else: ?>
                                                <?php echo date(' Y', strtotime($req['from_date'])); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $days; ?></td>
                                        <td><span class="att-legend-item att-code-l"><?php echo htmlspecialchars($req['leave_type']); ?></span></td>
                                        <td><span class="emp-req-status emp-req-<?php echo htmlspecialchars($req['request_status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $req['request_status']))); ?></span></td>
                                        <td><?php echo date('d M Y, h:i A', strtotime($req['created_at'])); ?></td>
                                        <td>
                                            <?php if ($req['request_status'] === 'pending' || $req['request_status'] === 'approved'): ?>
                                                <form method="POST" action="leave_cancel_save.php" style="margin:0; display:inline;" onsubmit="event.preventDefault(); openCancelModal(this);">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                                    <input type="hidden" name="redirect" value="leave.php?<?php echo $period_query; ?>">
                                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 12px; background: #fee2e2; color: #ef4444; border: 1px solid #fca5a5;">Cancel</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if (!empty($req['employee_note'])): ?>
                                    <tr class="emp-leave-note-row">
                                        <td colspan="6"><em><?php echo htmlspecialchars($req['employee_note']); ?></em></td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Leave Modal -->
<div id="cancelLeaveModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 9999;">
    <div style="background: #fff; padding: 24px; border-radius: 12px; width: 400px; max-width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.2); text-align: center; animation: modalPop 0.2s ease-out;">
        <div style="width: 56px; height: 56px; border-radius: 50%; background: #fee2e2; color: #ef4444; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h3 style="margin: 0 0 8px; font-size: 1.25rem; font-weight: 600; color: #111827;">Cancel Leave Request?</h3>
        <p style="margin: 0 0 24px; color: #4b5563; font-size: 0.95rem; line-height: 1.5;">Are you sure you want to cancel this leave request? This action cannot be undone.</p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <button type="button" class="btn btn-outline" onclick="closeCancelModal()" style="flex: 1; padding: 10px; font-weight: 500;">No, keep it</button>
            <button type="button" class="btn" id="confirmCancelBtn" style="flex: 1; padding: 10px; font-weight: 500; background: #ef4444; color: white; border: none; border-radius: 6px; cursor: pointer;">Yes, cancel it</button>
        </div>
    </div>
</div>
<style>
@keyframes modalPop {
    0% { transform: scale(0.95); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}
#confirmCancelBtn:hover { background: #dc2626 !important; }
</style>

<script>
var cancelFormToSubmit = null;
function openCancelModal(formElement) {
    cancelFormToSubmit = formElement;
    document.getElementById('cancelLeaveModal').style.display = 'flex';
}
function closeCancelModal() {
    document.getElementById('cancelLeaveModal').style.display = 'none';
    cancelFormToSubmit = null;
}
document.getElementById('confirmCancelBtn').addEventListener('click', function() {
    if (cancelFormToSubmit) {
        cancelFormToSubmit.submit();
    }
});

(function () {
    var from = document.getElementById('empLeaveFrom');
    var to = document.getElementById('empLeaveTo');
    var hint = document.getElementById('empLeaveDayCount');
    if (!from || !to || !hint) return;
    function sync() {
        if (!from.value || !to.value) {
            hint.textContent = '';
            return;
        }
        var a = new Date(from.value + 'T00:00:00');
        var b = new Date(to.value + 'T00:00:00');
        if (b < a) {
            hint.textContent = 'To date must be on or after from date.';
            return;
        }
        var days = Math.round((b - a) / 86400000) + 1;
        hint.textContent = days + ' day' + (days === 1 ? '' : 's') + ' selected';
    }
    from.addEventListener('change', function () {
        if (!to.value || to.value < from.value) to.value = from.value;
        sync();
    });
    to.addEventListener('change', sync);
    sync();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
