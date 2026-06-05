<?php
require 'includes/header.php';
require 'config.php';
$upload_month = (int) ($_GET['month'] ?? date('n'));
$upload_year = (int) ($_GET['year'] ?? date('Y'));
if ($upload_month < 1 || $upload_month > 12) {
    $upload_month = (int) date('n');
}
$upload_period_label = date('F Y', mktime(0, 0, 0, $upload_month, 1, $upload_year));
$upload_period_locked = is_payroll_period_locked($conn, $upload_year, $upload_month);
?>

<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">Import data</p>
        <h2>Upload Attendance</h2>
        <p>Bulk import employee attendance from CSV or Excel files.</p>
    </div>
    <div class="page-header-actions">
        <a href="holidays.php?month=<?php echo $upload_month; ?>&year=<?php echo $upload_year; ?>" class="btn btn-outline">Holidays</a>
        <a href="attendance_audit.php" class="btn btn-outline">Audit log</a>
    </div>
</div>

<?php if ($upload_period_locked): ?>
    <div class="alert alert-error alert-page">
        <strong>Period locked.</strong> <?php echo htmlspecialchars($upload_period_label); ?> payroll is finalized. Reopen the period from the dashboard to import or edit attendance.
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

        <?php
        $show_preview = !empty($_GET['preview']) && !empty($_SESSION['upload_pending']);
        $pending = $_SESSION['upload_pending'] ?? null;
        if ($show_preview && $pending):
            $pr = $pending['result'] ?? [];
        ?>
        <div class="alert alert-success alert-page">
            <strong>Preview ready</strong> — <?php echo (int) ($pr['success_count'] ?? 0); ?> records for <?php echo htmlspecialchars(date('F Y', mktime(0, 0, 0, (int) $pending['month'], 1, (int) $pending['year']))); ?>.
            File: <?php echo htmlspecialchars($pending['filename'] ?? ''); ?>
        </div>
        <form action="upload_confirm.php" method="POST" style="margin-bottom:16px">
            <?php require_once 'includes/csrf_helper.php'; echo csrf_field(); ?>
            <button type="submit" class="btn">Confirm import</button>
            <a href="upload_attendance.php" class="btn btn-outline" style="margin-left:8px">Cancel</a>
        </form>
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
                <li>Each row: status per day — <strong>P</strong> Present, <strong>A</strong> Absent, <strong>HD</strong> Half day, <strong>L</strong> Leave, <strong>WO</strong> week off (skipped)</li>
            </ol>
            <p class="guide-intro"><strong>B) Simple list</strong></p>
            <ol class="guide-steps">
                <li><strong>Emp ID</strong>, <strong>Name</strong>, <strong>Date</strong> (day 1–31 or YYYY-MM-DD), <strong>Status</strong></li>
            </ol>
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
    var periodHint = document.querySelector('.upload-period-hint strong');
    var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    function updatePeriodHint() {
        if (!periodHint || !monthSel || !yearSel) return;
        var m = parseInt(monthSel.value, 10) - 1;
        periodHint.textContent = monthNames[m] + ' ' + yearSel.value;
    }

    if (monthSel) monthSel.addEventListener('change', updatePeriodHint);
    if (yearSel) yearSel.addEventListener('change', updatePeriodHint);
})();
</script>

<?php require 'includes/footer.php'; ?>
