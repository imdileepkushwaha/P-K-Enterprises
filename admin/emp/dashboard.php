<?php
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/salary_helper.php';
require_once __DIR__ . '/includes/period.php';

$settings = get_all_settings($conn);
$emp_id = $employee['emp_id'];
[$year, $month] = emp_parse_period();

$period_label = get_period_label($year, $month);
$stats = get_attendance_stats_extended($conn, $emp_id, $year, $month, $settings);
$has_pending_profile = employee_has_pending_profile_request($conn, $emp_id);
$requests_limit = get_attendance_requests_per_month_limit($settings);
$requests_remaining = employee_attendance_request_remaining($conn, $emp_id, $year, $month, $settings);

$attendance_requests = get_employee_attendance_requests($conn, $emp_id, 10);
$leave_requests = get_employee_leave_requests($conn, $emp_id, 10);
$pending_att_requests = 0;
foreach ($attendance_requests as $req) {
    if (($req['request_status'] ?? '') === 'pending') {
        $pending_att_requests++;
    }
}
$pending_leave_requests = 0;
foreach ($leave_requests as $req) {
    if (($req['request_status'] ?? '') === 'pending') {
        $pending_leave_requests++;
    }
}

$dept = $employee['department'] ?: 'General';
$designation = $employee['designation'] ?: 'Staff';
$period_query = 'year=' . $year . '&month=' . $month;
$portal_company = trim($settings['company_name'] ?? '') ?: 'Payroll Company';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$today_label = date('l, j M Y');
$sent_slip_count = count(get_employee_sent_slip_logs($conn, $emp_id, 36));
?>
<div class="emp-page emp-page-dashboard">
    <?php require __DIR__ . '/includes/flash.php'; ?>

    <header class="emp-dash-banner">
        <div class="emp-dash-banner-decor" aria-hidden="true">
            <span class="emp-dash-banner-orb emp-dash-banner-orb-1"></span>
            <span class="emp-dash-banner-orb emp-dash-banner-orb-2"></span>
        </div>
        <div class="emp-dash-banner-grid">
            <div class="emp-dash-banner-main">
                <div class="emp-dash-banner-profile">
                    <span class="emp-avatar emp-avatar-xl emp-dash-avatar" aria-hidden="true"><?php echo htmlspecialchars($initial); ?></span>
                    <div class="emp-dash-banner-text">
                        <p class="emp-dash-eyebrow"><?php echo htmlspecialchars($greeting); ?></p>
                        <h1><?php echo htmlspecialchars($employee['name']); ?></h1>
                        <p class="emp-dash-id"><?php echo htmlspecialchars($employee['emp_id']); ?> · <?php echo htmlspecialchars($portal_company); ?></p>
                        <div class="emp-dash-tags">
                            <span class="emp-dash-tag"><?php echo htmlspecialchars($designation); ?></span>
                            <span class="emp-dash-tag"><?php echo htmlspecialchars($dept); ?></span>
                            <span class="emp-dash-tag emp-dash-tag-branch"><?php echo htmlspecialchars($branch_label); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="emp-dash-banner-aside">
                <div class="emp-dash-banner-meta">
                    <span class="emp-dash-today-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php echo htmlspecialchars($today_label); ?>
                    </span>
                    <a href="attendance.php?<?php echo $period_query; ?>" class="emp-dash-period-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
                        <span><?php echo htmlspecialchars($period_label); ?></span>
                    </a>
                </div>
                <div class="emp-dash-banner-stats">
                    <div class="emp-dash-banner-stat">
                        <span>Paid days</span>
                        <strong><?php echo format_money($stats['paid_days']); ?></strong>
                    </div>
                    <div class="emp-dash-banner-stat">
                        <span>Present</span>
                        <strong><?php echo (int) $stats['present_days']; ?></strong>
                    </div>
                    <div class="emp-dash-banner-stat">
                        <span>Requests left</span>
                        <strong><?php echo $requests_remaining; ?><small>/<?php echo $requests_limit; ?></small></strong>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="emp-dash-stats">
        <div class="emp-dash-stat emp-dash-stat-paid">
            <div class="emp-dash-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <span class="emp-dash-stat-label">Paid days</span>
                <strong class="emp-dash-stat-value"><?php echo format_money($stats['paid_days']); ?></strong>
                <span class="emp-dash-stat-hint"><?php echo htmlspecialchars($period_label); ?></span>
            </div>
        </div>
        <div class="emp-dash-stat emp-dash-stat-present">
            <div class="emp-dash-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            </div>
            <div>
                <span class="emp-dash-stat-label">Present</span>
                <strong class="emp-dash-stat-value"><?php echo (int) $stats['present_days']; ?></strong>
                <span class="emp-dash-stat-hint">HD <?php echo (int) $stats['half_days']; ?> · L <?php echo (int) $stats['leave_days']; ?></span>
            </div>
        </div>
        <div class="emp-dash-stat emp-dash-stat-absent">
            <div class="emp-dash-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div>
                <span class="emp-dash-stat-label">Absent</span>
                <strong class="emp-dash-stat-value"><?php echo (int) $stats['absent_days']; ?></strong>
                <span class="emp-dash-stat-hint">WO <?php echo (int) ($stats['weekoff_days'] ?? 0); ?></span>
            </div>
        </div>
        <div class="emp-dash-stat emp-dash-stat-quota">
            <div class="emp-dash-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <span class="emp-dash-stat-label">Requests left</span>
                <strong class="emp-dash-stat-value"><?php echo $requests_remaining; ?><small>/<?php echo $requests_limit; ?></small></strong>
                <span class="emp-dash-stat-hint">Manual attendance</span>
            </div>
        </div>
    </div>

    <?php if ($has_pending_profile || $pending_att_requests > 0 || $pending_leave_requests > 0): ?>
    <div class="emp-dash-alerts">
        <?php if ($has_pending_profile): ?>
            <a href="details.php" class="emp-dash-alert emp-dash-alert-info">Profile update pending approval →</a>
        <?php endif; ?>
        <?php if ($pending_leave_requests > 0): ?>
            <a href="leave.php?<?php echo $period_query; ?>" class="emp-dash-alert emp-dash-alert-warn"><?php echo $pending_leave_requests; ?> leave request<?php echo $pending_leave_requests === 1 ? '' : 's'; ?> pending →</a>
        <?php endif; ?>
        <?php if ($pending_att_requests > 0): ?>
            <a href="attendance.php?<?php echo $period_query; ?>" class="emp-dash-alert emp-dash-alert-warn"><?php echo $pending_att_requests; ?> attendance request<?php echo $pending_att_requests === 1 ? '' : 's'; ?> pending →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="emp-quick-cards">
        <a href="attendance.php?<?php echo $period_query; ?>" class="emp-quick-card emp-quick-card-att">
            <span class="emp-quick-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
            </span>
            <div>
                <h2>My attendance</h2>
                <p>Calendar, paid days summary and manual attendance requests for <?php echo htmlspecialchars($period_label); ?>.</p>
                <span class="emp-quick-card-cta">Open attendance →</span>
            </div>
        </a>
        <a href="leave.php?<?php echo $period_query; ?>" class="emp-quick-card emp-quick-card-leave">
            <span class="emp-quick-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            </span>
            <div>
                <h2>Apply for leave</h2>
                <p>Submit CL, SL or LOP leave for admin approval. Approved leave shows on your calendar.</p>
                <span class="emp-quick-card-cta">Apply leave →</span>
            </div>
        </a>
        <a href="salary_slips.php" class="emp-quick-card emp-quick-card-slip">
            <span class="emp-quick-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </span>
            <div>
                <h2>Salary slips</h2>
                <p><?php echo $sent_slip_count > 0
                    ? $sent_slip_count . ' sent slip' . ($sent_slip_count === 1 ? '' : 's') . ' available — view or download PDF.'
                    : 'View salary slips after admin sends them from payroll.'; ?></p>
                <span class="emp-quick-card-cta">Open salary slips →</span>
            </div>
        </a>
        <a href="details.php" class="emp-quick-card emp-quick-card-profile">
            <span class="emp-quick-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <div>
                <h2>My details</h2>
                <p>View profile, bank info and request contact or bank detail updates.</p>
                <span class="emp-quick-card-cta">Open my details →</span>
            </div>
        </a>
        <button type="button" class="emp-quick-card emp-quick-card-security" id="openPasswordModalBtn" aria-haspopup="dialog">
            <span class="emp-quick-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <div>
                <h2>Change password</h2>
                <p>Update your employee portal login password securely.</p>
                <span class="emp-quick-card-cta">Change password →</span>
            </div>
        </button>
    </div>
</div>

<dialog class="modal modal-password" id="empPasswordModal">
    <form method="POST" action="password_change_save.php" class="modal-form" id="empPasswordForm">
        <?php echo csrf_field(); ?>
        <div class="modal-head modal-head-password">
            <div class="modal-head-content">
                <div class="modal-head-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <div>
                    <h3>Change portal password</h3>
                    <p>Enter your current password, then choose a new one (min 6 characters).</p>
                </div>
            </div>
            <button type="button" class="modal-close" aria-label="Close" id="closePasswordModalBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="empCurrentPassword">Current password</label>
                <input type="password" id="empCurrentPassword" name="current_password" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="empNewPassword">New password</label>
                <input type="password" id="empNewPassword" name="new_password" required minlength="6" autocomplete="new-password" placeholder="Min 6 characters">
            </div>
            <div class="form-group">
                <label for="empConfirmPassword">Confirm new password</label>
                <input type="password" id="empConfirmPassword" name="confirm_password" required minlength="6" autocomplete="new-password">
                <span class="form-hint" id="empPasswordMatchHint" hidden>Passwords do not match.</span>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" id="cancelPasswordModalBtn">Cancel</button>
            <button type="submit" class="btn" id="empPasswordSubmit">Update password</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var modal = document.getElementById('empPasswordModal');
    var openBtn = document.getElementById('openPasswordModalBtn');
    var closeBtn = document.getElementById('closePasswordModalBtn');
    var cancelBtn = document.getElementById('cancelPasswordModalBtn');
    var form = document.getElementById('empPasswordForm');
    var newPw = document.getElementById('empNewPassword');
    var confirmPw = document.getElementById('empConfirmPassword');
    var hint = document.getElementById('empPasswordMatchHint');
    var submit = document.getElementById('empPasswordSubmit');

    function openModal() {
        if (modal) modal.showModal();
    }
    function closeModal() {
        if (modal) modal.close();
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    if (new URLSearchParams(location.search).get('open_password') === '1') {
        openModal();
        history.replaceState(null, '', 'dashboard.php');
    }

    if (!form || !newPw || !confirmPw) return;

    function sync() {
        var mismatch = confirmPw.value !== '' && newPw.value !== confirmPw.value;
        if (hint) hint.hidden = !mismatch;
        if (submit) submit.disabled = mismatch;
    }

    newPw.addEventListener('input', sync);
    confirmPw.addEventListener('input', sync);
    form.addEventListener('submit', function (e) {
        if (newPw.value !== confirmPw.value) {
            e.preventDefault();
            if (hint) hint.hidden = false;
        }
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
