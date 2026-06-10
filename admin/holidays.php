<?php
require 'config.php';
require_once 'includes/session_auth.php';
require_once 'includes/csrf_helper.php';
require_once 'includes/settings_helper.php';

enforce_admin_session();

$year = (int) ($_REQUEST['year'] ?? date('Y'));
$filter_month = isset($_REQUEST['month']) ? (int) $_REQUEST['month'] : 0;
if ($filter_month < 0 || $filter_month > 12) {
    $filter_month = 0;
}
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$year_start = sprintf('%d-01-01', $year);
$year_end = sprintf('%d-12-31', $year);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect = 'holidays.php?year=' . $year;
    if ($filter_month >= 1 && $filter_month <= 12) {
        $redirect .= '&month=' . $filter_month;
    }
    require_csrf_or_redirect($redirect);
    $action = $_POST['holiday_action'] ?? '';

    $branch_id = branch_id_for_write();
    if (SHOW_BRANCH_SELECTOR && get_active_branch_id() === null) {
        $_SESSION['flash_message'] = 'Select a branch from the top bar before saving holidays.';
        $_SESSION['flash_success'] = false;
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('DELETE FROM holidays WHERE id = ? AND branch_id = ?');
            $stmt->bind_param('ii', $id, $branch_id);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Non-working day removed.';
            $_SESSION['flash_success'] = true;
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $date = trim($_POST['calendar_date'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $kind = ($_POST['kind'] ?? '') === 'weekoff' ? 'weekoff' : 'holiday';
        $edit_year = (int) date('Y', strtotime($date !== '' ? $date : $year_start));
        $edit_year_start = sprintf('%d-01-01', $edit_year);
        $edit_year_end = sprintf('%d-12-31', $edit_year);

        if ($id <= 0) {
            $_SESSION['flash_message'] = 'Invalid holiday selected.';
            $_SESSION['flash_success'] = false;
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $_SESSION['flash_message'] = 'Please choose a valid date.';
            $_SESSION['flash_success'] = false;
        } elseif ($date < $edit_year_start || $date > $edit_year_end) {
            $_SESSION['flash_message'] = 'Date must fall within ' . $edit_year . '.';
            $_SESSION['flash_success'] = false;
        } elseif ($name === '') {
            $_SESSION['flash_message'] = 'Please enter a name for this day.';
            $_SESSION['flash_success'] = false;
        } else {
            $dup = $conn->prepare('SELECT id FROM holidays WHERE branch_id = ? AND calendar_date = ? AND id != ? LIMIT 1');
            $dup->bind_param('isi', $branch_id, $date, $id);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                $_SESSION['flash_message'] = 'Another holiday already exists on ' . date('j M Y', strtotime($date)) . '.';
                $_SESSION['flash_success'] = false;
            } else {
                $stmt = $conn->prepare('UPDATE holidays SET calendar_date = ?, name = ?, kind = ? WHERE id = ? AND branch_id = ?');
                $stmt->bind_param('sssii', $date, $name, $kind, $id, $branch_id);
                if ($stmt->execute()) {
                    $label = $kind === 'weekoff' ? 'Week off' : 'Public holiday';
                    $_SESSION['flash_message'] = $label . ' updated for ' . date('j M Y', strtotime($date)) . '.';
                    $_SESSION['flash_success'] = true;
                    $redirect = 'holidays.php?year=' . $edit_year . '&month=0';
                } else {
                    $_SESSION['flash_message'] = 'Could not update holiday. Please try again.';
                    $_SESSION['flash_success'] = false;
                }
            }
        }
    } else {
        $date = trim($_POST['calendar_date'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $kind = ($_POST['kind'] ?? '') === 'weekoff' ? 'weekoff' : 'holiday';
        $saved_year = (int) date('Y', strtotime($date !== '' ? $date : $year_start));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $_SESSION['flash_message'] = 'Please choose a valid date.';
            $_SESSION['flash_success'] = false;
        } elseif ($date < $year_start || $date > $year_end) {
            $_SESSION['flash_message'] = 'Date must fall within ' . $year . '.';
            $_SESSION['flash_success'] = false;
        } elseif ($name === '') {
            $_SESSION['flash_message'] = 'Please enter a name for this day.';
            $_SESSION['flash_success'] = false;
        } else {
            $stmt = $conn->prepare('INSERT INTO holidays (branch_id, calendar_date, name, kind) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), kind = VALUES(kind)');
            $stmt->bind_param('isss', $branch_id, $date, $name, $kind);
            if ($stmt->execute()) {
                $label = $kind === 'weekoff' ? 'Week off' : 'Public holiday';
                $_SESSION['flash_message'] = $label . ' saved for ' . date('j M Y', strtotime($date)) . '.';
                $_SESSION['flash_success'] = true;
                $redirect = 'holidays.php?year=' . $saved_year . '&month=0';
            } else {
                $_SESSION['flash_message'] = 'Could not save holiday. Please try again.';
                $_SESSION['flash_success'] = false;
            }
        }
    }

    header('Location: ' . $redirect);
    exit;
}

require 'includes/header.php';

$active_branch_label = get_branch_label($conn, get_active_branch_id());
$branch_for_holidays = branch_id_for_write();
$holidays_branch_required = SHOW_BRANCH_SELECTOR && get_active_branch_id() === null;
$year_holidays = get_holidays_for_year($conn, $year, $branch_for_holidays);
$holidays = $year_holidays;
if ($filter_month >= 1 && $filter_month <= 12) {
    $holidays = array_values(array_filter($year_holidays, static function (array $row) use ($filter_month) {
        return (int) date('n', strtotime($row['calendar_date'])) === $filter_month;
    }));
}

$settings = get_all_settings($conn);
$working_days = (int) get_working_days_per_month($settings);

$holiday_count = 0;
$weekoff_count = 0;
foreach ($year_holidays as $h) {
    if (($h['kind'] ?? '') === 'weekoff') {
        $weekoff_count++;
    } else {
        $holiday_count++;
    }
}
$marked_total = count($year_holidays);
$list_total = count($holidays);

$prev_year = $year - 1;
$next_year = $year + 1;
$prev_month = $filter_month - 1;
$prev_month_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_month_year--;
}
$next_month = $filter_month + 1;
$next_month_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_month_year++;
}

$today = date('Y-m-d');
$default_date = ($today >= $year_start && $today <= $year_end) ? $today : $year_start;
$list_label = $filter_month >= 1
    ? date('F Y', mktime(0, 0, 0, $filter_month, 1, $year))
    : (string) $year;
?>
<div class="holidays-page">
    <div class="page-header page-header-row">
        <div class="page-header-main">
            <p class="page-eyebrow">Attendance</p>
            <h2>Holiday calendar</h2>
            <p>Set the full-year holiday list for <strong><?php echo htmlspecialchars($active_branch_label === 'All Branches' ? get_branch_label($conn, $branch_for_holidays) : $active_branch_label); ?></strong>. Add days one by-one or upload an Excel/CSV list for <?php echo $year; ?>.</p>
        </div>
        <div class="page-header-actions">
            <a href="weekoff_roster.php?month=<?php echo $filter_month > 0 ? $filter_month : (int) date('n'); ?>&year=<?php echo $year; ?>" class="btn btn-outline">Weekoff roster</a>
            <a href="upload_attendance.php?year=<?php echo $year; ?>" class="btn btn-outline">Upload attendance</a>
        </div>
    </div>

    <?php if ($holidays_branch_required): ?>
        <div class="alert alert-page" style="background:#fff7ed;border-color:#fdba74;color:#9a3412">
            <strong>Select a branch.</strong> Choose <strong>Indra Nagar</strong> or <strong>Alambagh</strong> from the top bar to manage holidays.
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert <?php echo $_SESSION['flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page">
            <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_success']); ?>
        </div>
    <?php endif; ?>

    <div class="holidays-workflow">
        <div class="holidays-workflow-step">
            <span class="holidays-workflow-num">1</span>
            <div>
                <strong>Select year &amp; branch</strong>
                <span>Pick <?php echo $year; ?> and the branch you are managing.</span>
            </div>
        </div>
        <div class="holidays-workflow-step">
            <span class="holidays-workflow-num">2</span>
            <div>
                <strong>Add holidays</strong>
                <span>Add any date in the year manually, or upload the full Excel/CSV list.</span>
            </div>
        </div>
        <div class="holidays-workflow-step">
            <span class="holidays-workflow-num">3</span>
            <div>
                <strong>Import attendance safely</strong>
                <span>Public holidays are protected — Excel P/A/HD will not overwrite them.</span>
            </div>
        </div>
    </div>

    <div class="settings-status holidays-status">
        <div class="settings-status-chip neutral">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo $year; ?> calendar</strong>
                <span>Full-year holiday setup</span>
            </div>
        </div>
        <div class="settings-status-chip <?php echo $marked_total > 0 ? 'ok' : 'neutral'; ?>">
            <span class="status-dot"></span>
            <div>
                <strong><?php echo $marked_total; ?> marked</strong>
                <span>Days added this year</span>
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
                <span>Branch week-off days</span>
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
                    <h3>Add one day</h3>
                    <p>Any date in <?php echo $year; ?> — January to December.</p>
                </div>
            </div>

            <form method="POST" class="holidays-add-form stack-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="year" value="<?php echo $year; ?>">
                <input type="hidden" name="month" value="<?php echo $filter_month; ?>">
                <?php if ($holidays_branch_required): ?><fieldset disabled><?php endif; ?>
                <div class="form-group">
                    <label for="holiday-date">Date</label>
                    <input type="date" id="holiday-date" name="calendar_date" required min="<?php echo $year_start; ?>" max="<?php echo $year_end; ?>" value="<?php echo htmlspecialchars($default_date); ?>">
                </div>
                <div class="form-group">
                    <label for="holiday-name">Label</label>
                    <input type="text" id="holiday-name" name="name" required maxlength="120" placeholder="e.g. Diwali, Republic Day">
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

            <div class="holidays-sidebar-divider"></div>

            <div class="holidays-add-head holidays-upload-head">
                <div class="holidays-add-icon holidays-upload-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </div>
                <div>
                    <h3>Upload full-year list</h3>
                    <p>Excel or CSV with all holidays for <?php echo $year; ?>.</p>
                </div>
            </div>

            <form method="POST" action="holidays_upload.php" enctype="multipart/form-data" class="holidays-upload-form stack-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="upload_year" value="<?php echo $year; ?>">
                <input type="hidden" name="filter_month" value="<?php echo $filter_month; ?>">
                <?php if ($holidays_branch_required): ?><fieldset disabled><?php endif; ?>
                <div class="form-group">
                    <label for="holiday-file">File</label>
                    <input type="file" id="holiday-file" name="holiday_file" accept=".csv,.xlsx,.xls" required>
                </div>
                <button type="submit" class="btn btn-block">Upload holidays</button>
                <a href="holidays_template.php?year=<?php echo $year; ?>" class="btn btn-outline btn-block holidays-template-btn">Download Excel template</a>
                <?php if ($holidays_branch_required): ?></fieldset><?php endif; ?>
            </form>

            <div class="holidays-format-guide">
                <strong>Excel format</strong>
                <div class="table-wrap">
                    <table class="data-table data-table-compact">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Label</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code><?php echo $year; ?>-01-26</code></td>
                                <td>Republic Day</td>
                                <td>holiday</td>
                            </tr>
                            <tr>
                                <td><code>15-08-<?php echo $year; ?></code></td>
                                <td>Independence Day</td>
                                <td>holiday</td>
                            </tr>
                            <tr>
                                <td><code><?php echo $year; ?>-10-02</code></td>
                                <td>Gandhi Jayanti</td>
                                <td>weekoff</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p>Type is optional. Use <strong>holiday</strong> or <strong>weekoff</strong>. Same date updates the existing entry.</p>
            </div>

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
                    <h3><?php echo htmlspecialchars($list_label); ?> holidays</h3>
                    <p><?php echo $list_total > 0 ? 'Sorted by date · remove to delete a day' : 'No days in this view — add manually or upload the full-year file'; ?></p>
                </div>
                <div class="dashboard-panel-head-actions">
                    <nav class="att-cal-month-nav" aria-label="Change period">
                        <?php if ($filter_month >= 1): ?>
                            <a href="holidays.php?year=<?php echo $prev_month_year; ?>&month=<?php echo $prev_month; ?>" class="att-cal-nav-btn" title="Previous month" aria-label="Previous month">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                            </a>
                        <?php else: ?>
                            <a href="holidays.php?year=<?php echo $prev_year; ?>" class="att-cal-nav-btn" title="Previous year" aria-label="Previous year">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                            </a>
                        <?php endif; ?>
                        <form method="GET" class="dashboard-panel-period-filter holidays-period-form">
                            <div class="form-group">
                                <label for="holiday-month">Month</label>
                                <select name="month" id="holiday-month" onchange="this.form.submit()">
                                    <option value="0" <?php echo $filter_month === 0 ? 'selected' : ''; ?>>All months</option>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m === $filter_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="holiday-year">Year</label>
                                <select name="year" id="holiday-year" onchange="this.form.submit()">
                                    <?php for ($y = (int) date('Y') + 2; $y >= (int) date('Y') - 3; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </form>
                        <?php if ($filter_month >= 1): ?>
                            <a href="holidays.php?year=<?php echo $next_month_year; ?>&month=<?php echo $next_month; ?>" class="att-cal-nav-btn" title="Next month" aria-label="Next month">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                            </a>
                        <?php else: ?>
                            <a href="holidays.php?year=<?php echo $next_year; ?>" class="att-cal-nav-btn" title="Next year" aria-label="Next year">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                            </a>
                        <?php endif; ?>
                    </nav>
                    <?php if ($list_total > 0): ?>
                        <span class="dashboard-panel-badge ok"><?php echo $list_total; ?> day<?php echo $list_total === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel-body">
                <?php if ($list_total > 0): ?>
                    <div class="table-wrap">
                        <table class="data-table holidays-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <?php if ($filter_month === 0): ?><th>Month</th><?php endif; ?>
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
                                        <?php if ($filter_month === 0): ?>
                                            <td><?php echo htmlspecialchars(date('F', $ts)); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($h['name']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $is_weekoff ? 'badge-weekoff' : 'badge-holiday'; ?>">
                                                <?php echo $is_weekoff ? 'Week off' : 'Public holiday'; ?>
                                            </span>
                                        </td>
                                        <td class="td-actions">
                                            <div class="action-btns">
                                                <?php if (!$holidays_branch_required): ?>
                                                <button
                                                    type="button"
                                                    class="btn-action btn-edit js-holiday-edit"
                                                    title="Edit holiday"
                                                    data-id="<?php echo (int) $h['id']; ?>"
                                                    data-date="<?php echo htmlspecialchars($h['calendar_date']); ?>"
                                                    data-name="<?php echo htmlspecialchars($h['name']); ?>"
                                                    data-kind="<?php echo htmlspecialchars($h['kind'] ?? 'holiday'); ?>"
                                                >
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                </button>
                                                <?php endif; ?>
                                                <form method="POST" class="action-delete-form" onsubmit="return confirm('Remove this non-working day?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="holiday_action" value="delete">
                                                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                                                    <input type="hidden" name="month" value="<?php echo $filter_month; ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int) $h['id']; ?>">
                                                    <button type="submit" class="btn-action btn-delete" title="Remove day"<?php echo $holidays_branch_required ? ' disabled' : ''; ?>>
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                    </button>
                                                </form>
                                            </div>
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
                        <h4>No holidays for <?php echo htmlspecialchars($list_label); ?></h4>
                        <p>Add days manually for any month in <?php echo $year; ?>, or upload one Excel/CSV file with the full-year list.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<dialog class="modal modal-holiday-edit" id="editHolidayModal">
    <form method="POST" class="modal-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="holiday_action" value="update">
        <input type="hidden" name="id" id="editHolidayId" value="">
        <input type="hidden" name="year" value="<?php echo $year; ?>">
        <input type="hidden" name="month" value="<?php echo $filter_month; ?>">
        <div class="modal-head">
            <div class="modal-head-content">
                <div class="modal-head-icon" style="background:rgba(255,255,255,0.2)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div>
                    <h3>Edit holiday</h3>
                    <p>Update date, label, or type for this non-working day.</p>
                </div>
            </div>
            <button type="button" class="modal-close" id="editHolidayClose" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="edit-holiday-date">Date</label>
                <input type="date" id="edit-holiday-date" name="calendar_date" required min="<?php echo $year_start; ?>" max="<?php echo $year_end; ?>">
            </div>
            <div class="form-group">
                <label for="edit-holiday-name">Label</label>
                <input type="text" id="edit-holiday-name" name="name" required maxlength="120" placeholder="e.g. Diwali, Republic Day">
            </div>
            <div class="form-group">
                <label for="edit-holiday-kind">Type</label>
                <select id="edit-holiday-kind" name="kind">
                    <option value="holiday">Public holiday</option>
                    <option value="weekoff">Week off</option>
                </select>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" id="editHolidayCancel">Cancel</button>
            <button type="submit" class="btn">Save changes</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var modal = document.getElementById('editHolidayModal');
    if (!modal) return;

    var idInput = document.getElementById('editHolidayId');
    var dateInput = document.getElementById('edit-holiday-date');
    var nameInput = document.getElementById('edit-holiday-name');
    var kindInput = document.getElementById('edit-holiday-kind');
    var closeBtn = document.getElementById('editHolidayClose');
    var cancelBtn = document.getElementById('editHolidayCancel');

    function closeModal() {
        modal.close();
    }

    document.querySelectorAll('.js-holiday-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            idInput.value = btn.getAttribute('data-id') || '';
            dateInput.value = btn.getAttribute('data-date') || '';
            nameInput.value = btn.getAttribute('data-name') || '';
            kindInput.value = btn.getAttribute('data-kind') === 'weekoff' ? 'weekoff' : 'holiday';
            modal.showModal();
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('cancel', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
})();
</script>

<?php require 'includes/footer.php'; ?>
