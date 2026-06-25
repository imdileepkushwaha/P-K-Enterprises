<?php
require 'config.php';
require_once 'includes/session_auth.php';
require_once 'includes/csrf_helper.php';
require_once 'includes/settings_helper.php';
require_once 'includes/weekoff_roster_helper.php';

enforce_admin_session();

$year = (int) ($_REQUEST['year'] ?? date('Y'));
$month = (int) ($_REQUEST['month'] ?? date('n'));
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
$month_start = sprintf('%d-%02d-01', $year, $month);
$month_end = sprintf('%d-%02d-%d', $year, $month, $days_in_month);
$redirect_base = 'weekoff_roster.php?year=' . $year . '&month=' . $month;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_or_redirect($redirect_base);

    if (!is_weekoff_roster_editable_period($year, $month)) {
        $_SESSION['flash_message'] = 'Past months are view-only. Weekoff roster can be set for ' . get_period_label((int) date('Y'), (int) date('n')) . ' and upcoming months.';
        $_SESSION['flash_success'] = false;
        header('Location: ' . $redirect_base);
        exit;
    }

    if (is_payroll_period_locked($conn, $year, $month)) {
        $_SESSION['flash_message'] = 'This payroll period is locked. Reopen it before editing weekoff roster.';
        $_SESSION['flash_success'] = false;
        header('Location: ' . $redirect_base);
        exit;
    }

    $action = $_POST['roster_action'] ?? 'save';

    if (SHOW_BRANCH_SELECTOR && get_active_branch_id() === null) {
        $_SESSION['flash_message'] = 'Select a branch from the top bar before saving weekoff roster.';
        $_SESSION['flash_success'] = false;
        header('Location: ' . $redirect_base);
        exit;
    }
    $branch_id = branch_id_for_write();

    if ($action === 'copy_prev') {
        $copied = copy_branch_weekoff_roster($conn, $branch_id, $year, $month);
        $_SESSION['flash_message'] = $copied > 0
            ? 'Copied ' . $copied . ' weekoff day(s) from previous month (same day numbers).'
            : 'No weekoff roster found in the previous month for this branch.';
        $_SESSION['flash_success'] = $copied > 0;
    } elseif ($action === 'apply_sundays') {
        $applied = apply_sunday_weekoffs_for_branch($conn, $branch_id, $year, $month);
        $_SESSION['flash_message'] = 'Marked ' . $applied . ' weekoff day(s) — all Sundays added for every employee.';
        $_SESSION['flash_success'] = true;
    } else {
        $roster = $_POST['roster'] ?? [];
        if (!is_array($roster)) {
            $roster = [];
        }

        $emp_stmt = $conn->prepare('SELECT emp_id, branch_id FROM employees WHERE branch_id = ? AND is_active = 1 ORDER BY name');
        $emp_stmt->bind_param('i', $branch_id);
        $emp_stmt->execute();
        $employees = $emp_stmt->get_result();
        $saved_total = 0;
        while ($emp = $employees->fetch_assoc()) {
            $emp_id = $emp['emp_id'];
            $dates = $roster[$emp_id] ?? [];
            if (!is_array($dates)) {
                $dates = [];
            }
            $save_result = save_employee_weekoff_roster($conn, $emp_id, (int) $emp['branch_id'], $year, $month, $dates);
            $saved_total += (int) ($save_result['saved'] ?? 0);
        }

        $_SESSION['flash_message'] = 'Weekoff roster saved — ' . $saved_total . ' day(s) across all employees. Attendance synced as Week off.';
        $_SESSION['flash_success'] = true;
    }

    header('Location: ' . $redirect_base);
    exit;
}

require 'includes/header.php';

$active_branch_label = get_branch_label($conn, get_active_branch_id());
$branch_for_roster = get_active_branch_id() ?? 1;
$period_label = get_period_label($year, $month);
$period_locked = is_payroll_period_locked($conn, $year, $month);
$roster_editable = is_weekoff_roster_editable_period($year, $month);
$roster_read_only = !$roster_editable || $period_locked || get_active_branch_id() === null;
$current_period_label = get_period_label((int) date('Y'), (int) date('n'));
$roster_map = get_branch_weekoff_roster_map($conn, $year, $month, $branch_for_roster);

$emp_sql = 'SELECT emp_id, name, designation FROM employees WHERE is_active = 1';
$types = '';
$params = [];
if (get_active_branch_id() !== null) {
    $emp_sql .= ' AND branch_id = ?';
    $types = 'i';
    $params[] = get_active_branch_id();
} else {
    $emp_sql .= ' AND branch_id = ?';
    $types = 'i';
    $params[] = $branch_for_roster;
}
$emp_sql .= ' ORDER BY name ASC';
$emp_stmt = $conn->prepare($emp_sql);
$emp_stmt->bind_param($types, ...$params);
$emp_stmt->execute();
$employees = $emp_stmt->get_result();

$employee_rows = [];
$total_wo_days = 0;
$employees_with_roster = 0;
while ($emp = $employees->fetch_assoc()) {
    $dates = dedupe_weekoff_dates_one_per_week($roster_map[$emp['emp_id']] ?? []);
    $total_wo_days += count($dates);
    if ($dates !== []) {
        $employees_with_roster++;
    }
    $employee_rows[] = [
        'emp' => $emp,
        'dates' => array_flip($dates),
        'count' => count($dates),
    ];
}

$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}
$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

$weekday_labels = [];
for ($day = 1; $day <= $days_in_month; $day++) {
    $date = sprintf('%d-%02d-%02d', $year, $month, $day);
    $weekday_labels[$day] = date('D', strtotime($date));
}
?>
<div class="weekoff-roster-page">
    <div class="page-header page-header-row">
        <div class="page-header-main">
            <p class="page-eyebrow">Attendance</p>
            <h2>Weekoff roster</h2>
            <p>Set each employee's weekly off days for <strong><?php echo htmlspecialchars($period_label); ?></strong> at <strong><?php echo htmlspecialchars($active_branch_label === 'All Branches' ? get_branch_label($conn, $branch_for_roster) : $active_branch_label); ?></strong>. Roster days count as paid weekoffs in payroll.</p>
        </div>
        <div class="page-header-actions">
            <a href="holidays.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-outline">Holidays</a>
            <a href="upload_attendance.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-outline">Upload attendance</a>
        </div>
    </div>

    <?php if (get_active_branch_id() === null): ?>
        <div class="alert alert-page" style="background:#fff7ed;border-color:#fdba74;color:#9a3412">
            <strong>Select a branch.</strong> Choose <strong>Indra Nagar</strong> or <strong>Alambagh</strong> from the top bar to edit weekoff roster.
        </div>
    <?php endif; ?>

    <?php if (!$roster_editable): ?>
        <div class="alert alert-page" style="background:#eff6ff;border-color:#93c5fd;color:#1e40af">
            <strong>View only.</strong> Past months cannot be changed. You can set weekoff roster for <?php echo htmlspecialchars($current_period_label); ?> and later months. You are viewing <?php echo htmlspecialchars($period_label); ?>.
        </div>
    <?php endif; ?>

    <?php if ($period_locked): ?>
        <div class="alert alert-page" style="background:#fef3c7;border-color:#fcd34d;color:#92400e">
            <strong>Period locked.</strong> Reopen payroll period from Upload Attendance before changing roster.
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert <?php echo $_SESSION['flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page">
            <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_success']); ?>
        </div>
    <?php endif; ?>

    <div class="settings-status weekoff-roster-status">
        <div class="settings-status-chip neutral">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo htmlspecialchars($period_label); ?></strong>
                <span><?php echo $days_in_month; ?> days in month</span>
            </div>
        </div>
        <div class="settings-status-chip <?php echo count($employee_rows) > 0 ? 'ok' : 'neutral'; ?>">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo count($employee_rows); ?> employees</strong>
                <span>Active in branch</span>
            </div>
        </div>
        <div class="settings-status-chip <?php echo $employees_with_roster > 0 ? 'ok' : 'neutral'; ?>">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo $employees_with_roster; ?> with roster</strong>
                <span>Employees with weekoffs set</span>
            </div>
        </div>
        <div class="settings-status-chip neutral">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo $total_wo_days; ?> weekoff days</strong>
                <span>Total marked this month</span>
            </div>
        </div>
    </div>

    <div class="weekoff-roster-layout">
        <aside class="weekoff-roster-sidebar">
            <div class="weekoff-roster-side-card">
                <h3>Quick actions</h3>
                <p>Apply common patterns, then fine-tune in the grid.</p>
                <form method="POST" class="stack-form weekoff-roster-actions">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <?php if ($roster_read_only): ?><fieldset disabled><?php endif; ?>
                    <input type="hidden" name="roster_action" value="copy_prev">
                    <button type="submit" class="btn btn-outline btn-block" onclick="return confirm('Copy last month roster (same day numbers) for all employees?');">Copy from last month</button>
                    <?php if ($roster_read_only): ?></fieldset><?php endif; ?>
                </form>
                <form method="POST" class="stack-form weekoff-roster-actions">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <?php if ($roster_read_only): ?><fieldset disabled><?php endif; ?>
                    <input type="hidden" name="roster_action" value="apply_sundays">
                    <button type="submit" class="btn btn-outline btn-block" onclick="return confirm('Add all Sundays as weekoffs for every employee? Existing selections are kept.');">Add all Sundays</button>
                    <?php if ($roster_read_only): ?></fieldset><?php endif; ?>
                </form>
            </div>
            <div class="weekoff-roster-side-card weekoff-roster-tip">
                <strong>How it works</strong>
                <p>Click day cells to toggle <span class="att-cal-code att-code-wo inline-code">WO</span>. Each employee can have <strong>one weekoff per calendar week</strong>. Save updates payroll paid days and writes <em>Week off</em> in attendance (unless another status exists).</p>
                <p>Excel upload <strong>WO</strong> codes are also saved as Week off.</p>
            </div>
        </aside>

        <div class="weekoff-roster-main">
            <div class="panel panel-elevated weekoff-roster-panel">
                <div class="panel-header weekoff-roster-panel-head">
                    <div class="holidays-period-nav">
                        <a href="weekoff_roster.php?year=<?php echo $prev_year; ?>&month=<?php echo $prev_month; ?>" class="btn btn-sm btn-outline" aria-label="Previous month">&larr;</a>
                        <span class="holidays-period-label"><?php echo htmlspecialchars($period_label); ?></span>
                        <a href="weekoff_roster.php?year=<?php echo $next_year; ?>&month=<?php echo $next_month; ?>" class="btn btn-sm btn-outline" aria-label="Next month">&rarr;</a>
                    </div>
                    <?php if (!$roster_read_only): ?>
                        <button type="submit" form="weekoffRosterForm" class="btn btn-sm">Save roster</button>
                    <?php endif; ?>
                </div>
                <div class="panel-body weekoff-roster-panel-body">
                    <?php if ($employee_rows === []): ?>
                        <p class="weekoff-roster-empty">No active employees in this branch. <a href="employees.php">Add employees</a> first.</p>
                    <?php else: ?>
                        <form method="POST" id="weekoffRosterForm" class="weekoff-roster-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="year" value="<?php echo $year; ?>">
                            <input type="hidden" name="month" value="<?php echo $month; ?>">
                            <input type="hidden" name="roster_action" value="save">
                            <div class="weekoff-roster-scroll">
                                <table class="weekoff-roster-grid">
                                    <thead>
                                        <tr>
                                            <th class="wo-col-employee">Employee</th>
                                            <th class="wo-col-total">WO</th>
                                            <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                                <?php
                                                $is_sun = ($weekday_labels[$day] ?? '') === 'Sun';
                                                $th_class = $is_sun ? 'wo-col-day wo-col-sun' : 'wo-col-day';
                                                ?>
                                                <th class="<?php echo $th_class; ?>" title="<?php echo htmlspecialchars($weekday_labels[$day] ?? ''); ?>">
                                                    <span class="wo-day-num"><?php echo $day; ?></span>
                                                    <span class="wo-day-dow"><?php echo htmlspecialchars(substr($weekday_labels[$day] ?? '', 0, 1)); ?></span>
                                                </th>
                                            <?php endfor; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employee_rows as $row): ?>
                                            <?php
                                            $emp = $row['emp'];
                                            $date_set = $row['dates'];
                                            ?>
                                            <tr>
                                                <td class="wo-col-employee">
                                                    <a href="employee_view.php?emp_id=<?php echo urlencode($emp['emp_id']); ?>&year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="wo-emp-link">
                                                        <strong><?php echo htmlspecialchars($emp['name']); ?></strong>
                                                        <span><?php echo htmlspecialchars($emp['emp_id']); ?></span>
                                                    </a>
                                                </td>
                                                <td class="wo-col-total">
                                                    <span class="wo-count-badge" data-emp-count="<?php echo htmlspecialchars($emp['emp_id']); ?>"><?php echo (int) $row['count']; ?></span>
                                                </td>
                                                <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                                    <?php
                                                    $date_key = sprintf('%d-%02d-%02d', $year, $month, $day);
                                                    $week_key = weekoff_roster_week_key($date_key);
                                                    $is_on = isset($date_set[$date_key]);
                                                    $is_sun = ($weekday_labels[$day] ?? '') === 'Sun';
                                                    $cell_class = 'wo-day-cell';
                                                    if ($is_on) {
                                                        $cell_class .= ' wo-day-on';
                                                    }
                                                    if ($is_sun) {
                                                        $cell_class .= ' wo-day-sun';
                                                    }
                                                    $disabled = $roster_read_only;
                                                    ?>
                                                    <td class="<?php echo $cell_class; ?>">
                                                        <button type="button"
                                                            class="wo-day-toggle"
                                                            data-emp="<?php echo htmlspecialchars($emp['emp_id']); ?>"
                                                            data-date="<?php echo htmlspecialchars($date_key); ?>"
                                                            data-week-key="<?php echo htmlspecialchars($week_key); ?>"
                                                            aria-pressed="<?php echo $is_on ? 'true' : 'false'; ?>"
                                                            title="<?php echo htmlspecialchars($date_key); ?>"
                                                            <?php echo $disabled ? 'disabled' : ''; ?>>WO</button>
                                                        <input type="checkbox"
                                                            class="wo-day-checkbox"
                                                            name="roster[<?php echo htmlspecialchars($emp['emp_id']); ?>][]"
                                                            value="<?php echo htmlspecialchars($date_key); ?>"
                                                            data-week-key="<?php echo htmlspecialchars($week_key); ?>"
                                                            <?php echo $is_on ? 'checked' : ''; ?>
                                                            hidden>
                                                    </td>
                                                <?php endfor; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (!$roster_read_only): ?>
                                <div class="weekoff-roster-form-foot">
                                    <button type="submit" class="btn">Save weekoff roster</button>
                                </div>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    function syncCell(cb, on) {
        var cell = cb.closest('.wo-day-cell');
        var btn = cell ? cell.querySelector('.wo-day-toggle') : null;
        cb.checked = on;
        if (cell) cell.classList.toggle('wo-day-on', on);
        if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    }

    function updateCount(empId) {
        var badges = document.querySelectorAll('[data-emp-count="' + empId + '"]');
        var count = document.querySelectorAll('input.wo-day-checkbox[name="roster[' + empId + '][]"]:checked').length;
        badges.forEach(function (el) { el.textContent = String(count); });
    }

    function clearOtherWeekoffs(empId, weekKeyVal, keepCb) {
        document.querySelectorAll('input.wo-day-checkbox[name="roster[' + empId + '][]"]:checked').forEach(function (cb) {
            if (cb === keepCb) return;
            if (cb.getAttribute('data-week-key') !== weekKeyVal) return;
            syncCell(cb, false);
        });
    }

    function normalizeEmployeeWeekoffs(empId) {
        var seen = {};
        var boxes = Array.prototype.slice.call(
            document.querySelectorAll('input.wo-day-checkbox[name="roster[' + empId + '][]"]:checked')
        );
        boxes.sort(function (a, b) { return a.value.localeCompare(b.value); });
        boxes.forEach(function (cb) {
            var wk = cb.getAttribute('data-week-key');
            if (!wk || seen[wk]) {
                syncCell(cb, false);
                return;
            }
            seen[wk] = true;
        });
        updateCount(empId);
    }

    var empIds = [];
    document.querySelectorAll('.wo-day-toggle').forEach(function (btn) {
        var empId = btn.getAttribute('data-emp');
        if (empId && empIds.indexOf(empId) === -1) {
            empIds.push(empId);
        }
        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            var cell = btn.closest('.wo-day-cell');
            var cb = cell.querySelector('input.wo-day-checkbox');
            var weekKeyVal = cb.getAttribute('data-week-key');
            var on = !cb.checked;
            if (on) {
                clearOtherWeekoffs(empId, weekKeyVal, cb);
            }
            syncCell(cb, on);
            updateCount(empId);
        });
    });

    empIds.forEach(normalizeEmployeeWeekoffs);

    var form = document.getElementById('weekoffRosterForm');
    if (form) {
        form.addEventListener('submit', function () {
            empIds.forEach(normalizeEmployeeWeekoffs);
        });
    }
})();
</script>
<?php require 'includes/footer.php'; ?>
