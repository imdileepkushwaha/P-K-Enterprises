<?php
require 'config.php';
require_once 'includes/csrf_helper.php';
require_once 'includes/settings_helper.php';

$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
$month_start = sprintf('%d-%02d-01', $year, $month);
$month_end = sprintf('%d-%02d-%d', $year, $month, $days_in_month);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_or_redirect('holidays.php?year=' . $year . '&month=' . $month);
    $action = $_POST['holiday_action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $branch_id = require_branch_context_for_write();
            $stmt = $conn->prepare('DELETE FROM holidays WHERE id = ? AND branch_id = ?');
            $stmt->bind_param('ii', $id, $branch_id);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Non-working day removed.';
            $_SESSION['flash_success'] = true;
        }
    } else {
        $date = trim($_POST['calendar_date'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $kind = ($_POST['kind'] ?? '') === 'weekoff' ? 'weekoff' : 'holiday';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $_SESSION['flash_message'] = 'Please choose a valid date.';
            $_SESSION['flash_success'] = false;
        } elseif ($date < $month_start || $date > $month_end) {
            $_SESSION['flash_message'] = 'Date must fall within the selected month.';
            $_SESSION['flash_success'] = false;
        } elseif ($name === '') {
            $_SESSION['flash_message'] = 'Please enter a name for this day.';
            $_SESSION['flash_success'] = false;
        } else {
            $branch_id = require_branch_context_for_write();
            $stmt = $conn->prepare('INSERT INTO holidays (branch_id, calendar_date, name, kind) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), kind = VALUES(kind)');
            $stmt->bind_param('isss', $branch_id, $date, $name, $kind);
            $stmt->execute();
            $label = $kind === 'weekoff' ? 'Week off' : 'Public holiday';
            $_SESSION['flash_message'] = $label . ' saved for ' . date('j M Y', strtotime($date)) . '.';
            $_SESSION['flash_success'] = true;
        }
    }

    header('Location: holidays.php?year=' . $year . '&month=' . $month);
    exit;
}

require 'includes/header.php';

$active_branch_label = get_branch_label($conn, get_active_branch_id());
$branch_for_holidays = get_active_branch_id() ?? 1;
$holidays = get_holidays_for_month($conn, $year, $month, $branch_for_holidays);
$period_label = get_period_label($year, $month);
$settings = get_all_settings($conn);
$working_days = (int) get_working_days_per_month($settings);

$holiday_count = 0;
$weekoff_count = 0;
foreach ($holidays as $h) {
    if (($h['kind'] ?? '') === 'weekoff') {
        $weekoff_count++;
    } else {
        $holiday_count++;
    }
}
$marked_total = count($holidays);

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

$today = date('Y-m-d');
$default_date = ($today >= $month_start && $today <= $month_end) ? $today : $month_start;
?>
<div class="holidays-page">
    <div class="page-header page-header-row">
        <div class="page-header-main">
            <p class="page-eyebrow">Attendance</p>
            <h2>Holiday calendar</h2>
            <p>Mark public holidays and weekly offs for <strong><?php echo htmlspecialchars($period_label); ?></strong> at <strong><?php echo htmlspecialchars($active_branch_label === 'All Branches' ? get_branch_label($conn, $branch_for_holidays) : $active_branch_label); ?></strong>.</p>
        </div>
        <div class="page-header-actions">
            <a href="weekoff_roster.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-outline">Weekoff roster</a>
            <a href="upload_attendance.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-outline">Upload attendance</a>
        </div>
    </div>

    <?php if (get_active_branch_id() === null): ?>
        <div class="alert alert-page" style="background:#fff7ed;border-color:#fdba74;color:#9a3412">
            <strong>Select a branch.</strong> Choose <strong>Indra Nagar</strong> or <strong>Alambagh</strong> from the top bar to manage holidays.
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert <?php echo $_SESSION['flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page">
            <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_success']); ?>
        </div>
    <?php endif; ?>

    <div class="settings-status holidays-status">
        <div class="settings-status-chip neutral">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo htmlspecialchars($period_label); ?></strong>
                <span><?php echo $days_in_month; ?> days in month</span>
            </div>
        </div>
        <div class="settings-status-chip <?php echo $marked_total > 0 ? 'ok' : 'neutral'; ?>">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo $marked_total; ?> marked</strong>
                <span>Non-working days added</span>
            </div>
        </div>
        <div class="settings-status-chip neutral">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo $holiday_count; ?> public</strong>
                <span>Public holidays</span>
            </div>
        </div>
        <div class="settings-status-chip neutral">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo $weekoff_count; ?> week offs</strong>
                <span>Recurring or planned offs</span>
            </div>
        </div>
    </div>

    <div class="holidays-layout">
        <aside class="holidays-add-card">
            <div class="holidays-add-head">
                <div class="holidays-add-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                </div>
                <div>
                    <h3>Add non-working day</h3>
                    <p>Pick a date in <?php echo htmlspecialchars($period_label); ?> and save it once.</p>
                </div>
            </div>

            <form method="POST" class="holidays-add-form stack-form">
                <?php echo csrf_field(); ?>
                <?php if (get_active_branch_id() === null): ?><fieldset disabled><?php endif; ?>
                <div class="form-group">
                    <label for="holiday-date">Date</label>
                    <input type="date" id="holiday-date" name="calendar_date" required min="<?php echo $month_start; ?>" max="<?php echo $month_end; ?>" value="<?php echo htmlspecialchars($default_date); ?>">
                </div>
                <div class="form-group">
                    <label for="holiday-name">Label</label>
                    <input type="text" id="holiday-name" name="name" required maxlength="80" placeholder="e.g. Diwali, Republic Day, Sunday off">
                </div>
                <div class="form-group">
                    <label for="holiday-kind">Type</label>
                    <select id="holiday-kind" name="kind">
                        <option value="holiday">Public holiday</option>
                        <option value="weekoff">Week off</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-block">Save day</button>
                <?php if (get_active_branch_id() === null): ?></fieldset><?php endif; ?>
            </form>

            <div class="holidays-tip">
                <strong>Payroll impact</strong>
                <p>Marked days are treated as non-working when calculating paid days. Your payroll settings use <strong><?php echo $working_days; ?> working days</strong> per month as the baseline.</p>
            </div>
        </aside>

        <section class="panel panel-elevated holidays-panel">
            <div class="dashboard-panel-head dashboard-panel-head-table">
                <div class="dashboard-panel-icon payroll">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <h3><?php echo htmlspecialchars($period_label); ?> calendar</h3>
                    <p><?php echo $marked_total > 0 ? 'Sorted by date · click remove to delete a day' : 'No days marked yet — add your first holiday on the left'; ?></p>
                </div>
                <div class="dashboard-panel-head-actions">
                    <nav class="att-cal-month-nav" aria-label="Change month">
                        <a href="holidays.php?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="att-cal-nav-btn" title="Previous month" aria-label="Previous month">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                        </a>
                        <form method="GET" class="dashboard-panel-period-filter holidays-period-form">
                            <div class="form-group">
                                <label for="holiday-month">Month</label>
                                <select name="month" id="holiday-month" onchange="this.form.submit()">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="holiday-year">Year</label>
                                <select name="year" id="holiday-year" onchange="this.form.submit()">
                                    <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 3; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </form>
                        <a href="holidays.php?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="att-cal-nav-btn" title="Next month" aria-label="Next month">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                    </nav>
                    <?php if ($marked_total > 0): ?>
                        <span class="dashboard-panel-badge ok"><?php echo $marked_total; ?> day<?php echo $marked_total === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel-body">
                <?php if ($marked_total > 0): ?>
                    <div class="table-wrap">
                        <table class="data-table holidays-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Label</th>
                                    <th>Type</th>
                                    <th class="th-actions">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($holidays as $h): ?>
                                    <?php
                                    $ts = strtotime($h['calendar_date']);
                                    $is_weekoff = ($h['kind'] ?? '') === 'weekoff';
                                    $is_today = $h['calendar_date'] === $today;
                                    ?>
                                    <tr class="<?php echo $is_today ? 'holidays-row-today' : ''; ?>">
                                        <td>
                                            <span class="holidays-date-main"><?php echo htmlspecialchars(date('j M Y', $ts)); ?></span>
                                            <?php if ($is_today): ?><span class="holidays-today-tag">Today</span><?php endif; ?>
                                        </td>
                                        <td class="holidays-day-name"><?php echo htmlspecialchars(date('l', $ts)); ?></td>
                                        <td><?php echo htmlspecialchars($h['name']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $is_weekoff ? 'badge-weekoff' : 'badge-holiday'; ?>">
                                                <?php echo $is_weekoff ? 'Week off' : 'Public holiday'; ?>
                                            </span>
                                        </td>
                                        <td class="td-actions">
                                            <form method="POST" class="inline-delete-form" onsubmit="return confirm('Remove this non-working day?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="holiday_action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int) $h['id']; ?>">
                                                <button type="submit" class="btn-action btn-delete" title="Remove day">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state compact">
                        <div class="empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <h4>No holidays for <?php echo htmlspecialchars($period_label); ?></h4>
                        <p>Add public holidays or weekly offs using the form. You can also jump to another month with the arrows above.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
