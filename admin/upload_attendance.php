<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require 'config.php';
require_once 'includes/attendance_import.php';

if (!empty($_GET['cancel_preview'])) {
    attendance_upload_clear_session_pending();
    $cancel_month = (int) ($_GET['month'] ?? date('n'));
    $cancel_year = (int) ($_GET['year'] ?? date('Y'));
    header('Location: upload_attendance.php?month=' . $cancel_month . '&year=' . $cancel_year);
    exit;
}

require 'includes/header.php';

$upload_month = (int) ($_GET['month'] ?? date('n'));
$upload_year = (int) ($_GET['year'] ?? date('Y'));
if ($upload_month < 1 || $upload_month > 12) {
    $upload_month = (int) date('n');
}
$upload_period_label = date('F Y', mktime(0, 0, 0, $upload_month, 1, $upload_year));
$upload_payroll_period = get_payroll_period($conn, $upload_year, $upload_month);
$upload_period_status = $upload_payroll_period['status'] ?? 'open';
$upload_period_locked = $upload_period_status === 'locked';
require_once 'includes/csrf_helper.php';

$pending = $_SESSION['upload_pending'] ?? null;
$stored_pending = null;
if (is_array($pending) && !empty($pending['token'])) {
    $stored_pending = attendance_upload_load_pending($pending['token']);
}
$has_preview = $stored_pending !== null;
$open_preview_modal = $has_preview && !empty($_GET['preview']);
$preview_result = [];
$preview_items_all = [];
$preview_page = 1;
$preview_total_pages = 1;
$preview_page_items = [];
$preview_range_start = 0;
$preview_range_end = 0;
$preview_total_items = 0;
$preview_filename = '';
$preview_month_val = $upload_month;
$preview_year_val = $upload_year;
$preview_period_label = '';
$preview_format_label = '';

if ($has_preview) {
    $preview_result = $stored_pending['result'] ?? ($pending['result'] ?? []);
    $preview_items_all = $preview_result['preview_items'] ?? [];
    $preview_filename = $stored_pending['filename'] ?? ($pending['filename'] ?? 'File');
    $preview_month_val = (int) ($pending['month'] ?? $stored_pending['month'] ?? $upload_month);
    $preview_year_val = (int) ($pending['year'] ?? $stored_pending['year'] ?? $upload_year);
    $preview_period_label = date('F Y', mktime(0, 0, 0, $preview_month_val, 1, $preview_year_val));
    $preview_format_label = ($preview_result['format'] ?? '') === 'wide' ? 'Monthly grid' : 'Simple list';

    $preview_per_page = attendance_import_preview_per_page();
    $preview_total_items = count($preview_items_all);
    $preview_total_pages = max(1, (int) ceil($preview_total_items / $preview_per_page));
    $preview_page = max(1, (int) ($_GET['preview_page'] ?? 1));
    if ($preview_page > $preview_total_pages) {
        $preview_page = $preview_total_pages;
    }
    $preview_offset = ($preview_page - 1) * $preview_per_page;
    $preview_page_items = array_slice($preview_items_all, $preview_offset, $preview_per_page);
    $preview_range_start = $preview_total_items > 0 ? $preview_offset + 1 : 0;
    $preview_range_end = min($preview_offset + $preview_per_page, $preview_total_items);
}
?>

<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">Import data</p>
        <h2>Upload Attendance</h2>
        <p>Bulk import employee attendance from CSV or Excel files.</p>
    </div>
    <div class="page-header-actions">
        <a href="holidays.php?month=<?php echo $upload_month; ?>&year=<?php echo $upload_year; ?>" class="btn btn-outline">Holidays</a>
    </div>
</div>

<?php if ($upload_period_locked): ?>
    <div class="alert alert-error alert-page upload-lock-alert">
        <div>
            <strong>Period locked.</strong>
            <?php echo htmlspecialchars($upload_period_label); ?> payroll is finalized, so attendance import and edits are disabled.
        </div>
        <form method="POST" action="payroll_period_save.php" class="upload-reopen-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="month" value="<?php echo $upload_month; ?>">
            <input type="hidden" name="year" value="<?php echo $upload_year; ?>">
            <input type="hidden" name="return_to" value="upload_attendance.php">
            <button type="submit" name="period_action" value="reopen" class="btn btn-sm">Reopen <?php echo htmlspecialchars($upload_period_label); ?></button>
        </form>
    </div>
<?php elseif (get_active_branch_id() === null): ?>
    <div class="alert alert-page" style="background:#fff7ed;border-color:#fdba74;color:#9a3412">
        <strong>Select a branch.</strong> Choose <strong>Indra Nagar</strong> or <strong>Alambagh</strong> from the top bar before uploading attendance.
    </div>
<?php elseif ($upload_period_status !== 'open'): ?>
    <div class="alert alert-page" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af">
        <strong>Payroll <?php echo htmlspecialchars(payroll_period_status_label($upload_period_status)); ?>.</strong>
        You can still import attendance for <?php echo htmlspecialchars($upload_period_label); ?> until the period is locked.
    </div>
<?php endif; ?>

<?php
if (isset($_SESSION['upload_message'])) {
    $alert_class = !empty($_SESSION['upload_success']) ? 'alert-success' : 'alert-error';
    echo "<div class='alert " . $alert_class . " alert-page'>" . htmlspecialchars($_SESSION['upload_message']) . "</div>";
    unset($_SESSION['upload_message']);
    unset($_SESSION['upload_success']);
}
?>

<div class="upload-layout">
    <section class="upload-card">
        <div class="upload-card-head">
            <div class="upload-card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <div>
                <h3>Upload file</h3>
                <p>Drag & drop or choose a file from your computer</p>
            </div>
        </div>

        <?php if (!empty($_GET['preview']) && !$has_preview): ?>
        <div class="alert alert-error alert-page">
            <strong>Preview not available.</strong> The preview may have expired. Please choose the file again and click <strong>Preview import</strong>.
        </div>
        <?php endif; ?>

        <?php if ($has_preview && !$open_preview_modal): ?>
        <div class="upload-preview-ready">
            <div>
                <strong>Import preview ready</strong>
                <span><?php echo htmlspecialchars($preview_period_label); ?> · <?php echo (int) ($preview_result['success_count'] ?? 0); ?> record(s)</span>
            </div>
            <div class="upload-preview-ready-actions">
                <a href="<?php echo htmlspecialchars(attendance_upload_preview_url($preview_month_val, $preview_year_val)); ?>" class="btn btn-sm">View preview</a>
                <form action="upload_confirm.php" method="POST" class="upload-preview-ready-form">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="btn btn-sm">Confirm import</button>
                </form>
                <a href="upload_attendance.php?cancel_preview=1&amp;month=<?php echo $preview_month_val; ?>&amp;year=<?php echo $preview_year_val; ?>" class="btn btn-outline btn-sm">Cancel</a>
            </div>
        </div>
        <?php endif; ?>

        <form action="process_upload.php" method="POST" enctype="multipart/form-data" class="upload-form" id="uploadForm"<?php echo $upload_period_locked ? ' data-locked="1"' : ''; ?>>
            <?php require_once 'includes/csrf_helper.php'; echo csrf_field(); ?>
            <?php if ($upload_period_locked): ?><fieldset disabled><?php endif; ?>
            <div class="upload-period-picker">
                <div class="upload-period-label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <div>
                        <strong>Attendance month</strong>
                        <span>Select which month this file belongs to</span>
                    </div>
                </div>
                <div class="upload-period-fields">
                    <div class="form-group">
                        <label for="upload_month">Month</label>
                        <select name="upload_month" id="upload_month" required>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m === $upload_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="upload_year">Year</label>
                        <select name="upload_year" id="upload_year" required>
                            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y === $upload_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <p class="upload-period-hint">Only rows for <strong><?php echo htmlspecialchars($upload_period_label); ?></strong> will be imported. Date column can be full date (<code>2026-04-05</code>) or day only (<code>5</code>).</p>
            </div>

            <label class="dropzone" id="dropzone" for="attendance_file">
                <input type="file" name="attendance_file" id="attendance_file" accept=".csv,.xlsx,.xls" required hidden>
                <div class="dropzone-inner">
                    <div class="dropzone-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <p class="dropzone-title"><span id="fileLabel">Choose file</span> or drag here</p>
                    <p class="dropzone-hint">CSV, XLSX, or XLS — max recommended 5MB</p>
                    <span class="dropzone-btn">Browse files</span>
                </div>
            </label>

            <div class="format-tags">
                <span class="format-tag">.csv</span>
                <span class="format-tag">.xlsx</span>
                <span class="format-tag">.xls</span>
            </div>

            <div class="upload-actions-row">
                <button type="submit" name="preview_only" value="1" class="btn btn-outline btn-block">Preview import</button>
                <button type="submit" class="btn btn-block btn-upload">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Upload &amp; import now
                </button>
            </div>
            <?php if ($upload_period_locked): ?></fieldset><?php endif; ?>
        </form>
    </section>

    <aside class="upload-guide">
        <div class="guide-card">
            <h3>File format</h3>
            <p class="guide-intro">Supports two Excel layouts for the month you select:</p>
            <p class="guide-intro"><strong>A) Monthly grid</strong> (like Attendance_Payroll_Demo.xlsx)</p>
            <ol class="guide-steps">
                <li>Row 1: <strong>Emp ID</strong>, <strong>Name</strong>, days <strong>1–31</strong>, then totals</li>
                <li>Each row: status per day — <strong>P</strong> Present, <strong>A</strong> Absent, <strong>HD</strong> Half day, <strong>L</strong> Leave, <strong>WO</strong> week off</li>
                <li><strong>Holidays</strong>, <strong>weekoff roster</strong> days, and <strong>approved leave</strong> are not overwritten by P/A/HD — use <strong>WO</strong> or <strong>L</strong> in Excel to change those days</li>
            </ol>
            <p class="guide-intro"><strong>B) Simple list</strong></p>
            <ol class="guide-steps">
                <li><strong>Emp ID</strong>, <strong>Name</strong>, <strong>Date</strong> (day 1–31 or YYYY-MM-DD), <strong>Status</strong></li>
            </ol>
        </div>

        <div class="upload-undo-card">
            <h3>Undo wrong import</h3>
            <p>Clear all attendance for <strong><?php echo htmlspecialchars($upload_period_label); ?></strong> at this branch, then re-upload the correct Excel file.</p>
            <ul class="upload-undo-notes">
                <li>Weekoff roster days are restored automatically</li>
                <li>Approved leave days are restored automatically</li>
                <li>Holidays are not affected</li>
            </ul>
            <?php if ($upload_period_locked || (SHOW_BRANCH_SELECTOR && get_active_branch_id() === null)): ?>
                <button type="button" class="btn btn-outline btn-block btn-delete" disabled>Clear month attendance</button>
            <?php else: ?>
                <form method="POST" action="attendance_clear_month.php" class="upload-undo-form" onsubmit="return confirm('Clear all attendance for <?php echo htmlspecialchars($upload_period_label); ?>? Weekoff and approved leave will be restored.');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="month" value="<?php echo $upload_month; ?>">
                    <input type="hidden" name="year" value="<?php echo $upload_year; ?>">
                    <button type="submit" class="btn btn-outline btn-block btn-delete">Clear <?php echo htmlspecialchars($upload_period_label); ?> attendance</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="panel panel-compact">
            <div class="panel-header">
                <h3>Example preview</h3>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="data-table data-table-compact">
                        <thead>
                            <tr>
                                <th>Emp ID</th>
                                <th>Name</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>EMP001</code></td>
                                <td>John Doe</td>
                                <td>5</td>
                                <td><span class="badge badge-present">Present</span></td>
                            </tr>
                            <tr>
                                <td><code>EMP002</code></td>
                                <td>Jane Smith</td>
                                <td>6</td>
                                <td><span class="badge badge-absent">Absent</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </aside>
</div>

<?php if ($has_preview): ?>
<dialog class="modal modal-upload-preview" id="importPreviewModal">
    <div class="modal-head">
        <div class="modal-head-content">
            <div class="modal-head-icon" style="background:rgba(255,255,255,0.2)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <div>
                <h3>Import preview</h3>
                <p>
                    <?php echo htmlspecialchars($preview_period_label); ?>
                    · <?php echo htmlspecialchars($preview_filename); ?>
                    · <?php echo htmlspecialchars($preview_format_label); ?>
                </p>
            </div>
        </div>
        <button type="button" class="modal-close" id="importPreviewClose" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="modal-body modal-upload-preview-body">
        <div class="upload-preview-stats">
            <div class="upload-preview-stat">
                <span>To import</span>
                <strong><?php echo (int) ($preview_result['success_count'] ?? 0); ?></strong>
            </div>
            <div class="upload-preview-stat">
                <span>Employees</span>
                <strong><?php echo (int) ($preview_result['employee_count'] ?? 0); ?></strong>
            </div>
            <div class="upload-preview-stat">
                <span>Skipped</span>
                <strong><?php echo (int) (($preview_result['error_count'] ?? 0) + ($preview_result['wrong_month_count'] ?? 0)); ?></strong>
            </div>
            <div class="upload-preview-stat">
                <span>Protected</span>
                <strong><?php echo (int) ($preview_result['protected_skip_count'] ?? 0); ?></strong>
            </div>
        </div>

        <?php if ($preview_page_items !== []): ?>
        <div class="table-wrap upload-preview-table-wrap">
            <table class="data-table data-table-compact upload-preview-table">
                <thead>
                    <tr>
                        <th>Emp ID</th>
                        <th>Name</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_page_items as $item): ?>
                    <?php $code = $item['code'] ?? ''; ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($item['emp_id']); ?></code></td>
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td><?php echo htmlspecialchars(date('d M Y', strtotime($item['date']))); ?></td>
                        <td>
                            <span class="badge <?php echo htmlspecialchars(attendance_import_preview_badge_class($code)); ?>"><?php echo htmlspecialchars($code !== '' ? $code : $item['status']); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="upload-preview-empty">No valid attendance cells found in this file for <?php echo htmlspecialchars($preview_period_label); ?>. Check month selection and file format.</p>
        <?php endif; ?>
    </div>
    <div class="modal-foot modal-upload-preview-foot">
        <?php if ($preview_page_items !== []): ?>
        <div class="upload-preview-foot-pagination">
            <p class="emp-pagination-info">
                Showing <strong><?php echo $preview_range_start; ?>–<?php echo $preview_range_end; ?></strong>
                of <strong><?php echo $preview_total_items; ?></strong> records
                <span class="emp-pagination-page">· Page <?php echo $preview_page; ?> of <?php echo $preview_total_pages; ?></span>
            </p>
            <?php if ($preview_total_pages > 1): ?>
            <nav class="emp-pagination-nav" aria-label="Preview pages">
                <?php if ($preview_page > 1): ?>
                    <a href="<?php echo htmlspecialchars(attendance_upload_preview_url($preview_month_val, $preview_year_val, $preview_page - 1)); ?>" class="emp-page-btn" aria-label="Previous page">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                        <span>Prev</span>
                    </a>
                <?php else: ?>
                    <span class="emp-page-btn is-disabled" aria-disabled="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                        <span>Prev</span>
                    </span>
                <?php endif; ?>

                <div class="emp-page-links">
                    <?php foreach (attendance_import_pagination_pages($preview_page, $preview_total_pages) as $p): ?>
                        <?php if ($p === null): ?>
                            <span class="emp-page-ellipsis" aria-hidden="true">…</span>
                        <?php elseif ($p === $preview_page): ?>
                            <span class="emp-page-link is-active" aria-current="page"><?php echo $p; ?></span>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars(attendance_upload_preview_url($preview_month_val, $preview_year_val, $p)); ?>" class="emp-page-link"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($preview_page < $preview_total_pages): ?>
                    <a href="<?php echo htmlspecialchars(attendance_upload_preview_url($preview_month_val, $preview_year_val, $preview_page + 1)); ?>" class="emp-page-btn" aria-label="Next page">
                        <span>Next</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                <?php else: ?>
                    <span class="emp-page-btn is-disabled" aria-disabled="true">
                        <span>Next</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    </span>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="upload-preview-foot-actions">
            <a href="upload_attendance.php?cancel_preview=1&amp;month=<?php echo $preview_month_val; ?>&amp;year=<?php echo $preview_year_val; ?>" class="btn btn-outline">Cancel</a>
            <form action="upload_confirm.php" method="POST" class="upload-preview-confirm-form">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn">Confirm import</button>
            </form>
        </div>
    </div>
</dialog>
<?php endif; ?>

<script>
(function () {
    var input = document.getElementById('attendance_file');
    var label = document.getElementById('fileLabel');
    var dropzone = document.getElementById('dropzone');

    function setFileName(file) {
        label.textContent = file ? file.name : 'Choose file';
        dropzone.classList.toggle('has-file', !!file);
    }

    input.addEventListener('change', function () {
        setFileName(input.files[0]);
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
            e.preventDefault();
            dropzone.classList.add('drag-over');
        });
    });

    ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
            e.preventDefault();
            dropzone.classList.remove('drag-over');
        });
    });

    dropzone.addEventListener('drop', function (e) {
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            setFileName(input.files[0]);
        }
    });

    var monthSel = document.getElementById('upload_month');
    var yearSel = document.getElementById('upload_year');

    function goToSelectedPeriod() {
        if (!monthSel || !yearSel) return;
        window.location = 'upload_attendance.php?month=' + encodeURIComponent(monthSel.value) + '&year=' + encodeURIComponent(yearSel.value);
    }

    if (monthSel) monthSel.addEventListener('change', goToSelectedPeriod);
    if (yearSel) yearSel.addEventListener('change', goToSelectedPeriod);

    var previewModal = document.getElementById('importPreviewModal');
    var previewClose = document.getElementById('importPreviewClose');

    function stripPreviewParams() {
        var url = new URL(window.location.href);
        url.searchParams.delete('preview');
        url.searchParams.delete('preview_page');
        window.history.replaceState({}, '', url);
    }

    if (previewModal) {
        if (new URLSearchParams(window.location.search).has('preview')) {
            previewModal.showModal();
        }

        if (previewClose) {
            previewClose.addEventListener('click', function () {
                previewModal.close();
                stripPreviewParams();
            });
        }

        previewModal.addEventListener('cancel', function () {
            stripPreviewParams();
        });

        previewModal.addEventListener('click', function (event) {
            if (event.target === previewModal) {
                previewModal.close();
                stripPreviewParams();
            }
        });
    }
})();
</script>

<?php require 'includes/footer.php'; ?>
