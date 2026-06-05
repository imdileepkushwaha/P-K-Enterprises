<?php
require 'config.php';
require_once 'includes/csrf_helper.php';

$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_or_redirect('holidays.php');
    $action = $_POST['holiday_action'] ?? '';
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $conn->query('DELETE FROM holidays WHERE id = ' . $id);
        $_SESSION['flash_message'] = 'Holiday removed.';
        $_SESSION['flash_success'] = true;
    } else {
        $date = trim($_POST['calendar_date'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $kind = $_POST['kind'] === 'weekoff' ? 'weekoff' : 'holiday';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $name !== '') {
            $stmt = $conn->prepare('INSERT INTO holidays (calendar_date, name, kind) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), kind = VALUES(kind)');
            $stmt->bind_param('sss', $date, $name, $kind);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Holiday saved.';
            $_SESSION['flash_success'] = true;
        }
    }
    header('Location: holidays.php?year=' . $year . '&month=' . $month);
    exit;
}

require 'includes/header.php';

$holidays = get_holidays_for_month($conn, $year, $month);
$period_label = get_period_label($year, $month);
?>
<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">Attendance</p>
        <h2>Holiday &amp; week-off calendar</h2>
        <p>Mark non-working days for <?php echo htmlspecialchars($period_label); ?>.</p>
    </div>
    <form method="GET" class="inline-filter">
        <select name="month" onchange="this.form.submit()"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option><?php endfor; ?></select>
        <select name="year" onchange="this.form.submit()"><?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--): ?><option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option><?php endfor; ?></select>
    </form>
</div>

<?php if (isset($_SESSION['flash_message'])): ?>
<div class="alert <?php echo $_SESSION['flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page"><?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_success']); ?></div>
<?php endif; ?>

<div class="dashboard-grid">
    <div class="panel panel-elevated">
        <div class="panel-body padded">
            <form method="POST" class="stack-form">
                <?php echo csrf_field(); ?>
                <div class="form-row">
                    <div class="form-group"><label>Date</label><input type="date" name="calendar_date" required min="<?php echo sprintf('%d-%02d-01', $year, $month); ?>" max="<?php echo sprintf('%d-%02d-%d', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year))); ?>"></div>
                    <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="e.g. Diwali / Sunday off"></div>
                    <div class="form-group"><label>Type</label><select name="kind"><option value="holiday">Public holiday</option><option value="weekoff">Week off</option></select></div>
                    <div class="form-group form-group-btn"><label>&nbsp;</label><button type="submit" class="btn btn-sm">Add</button></div>
                </div>
            </form>
            <?php if (count($holidays) > 0): ?>
            <div class="table-wrap" style="margin-top:20px">
                <table class="data-table data-table-compact">
                    <thead><tr><th>Date</th><th>Name</th><th>Type</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($holidays as $h): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('j M Y', strtotime($h['calendar_date']))); ?></td>
                            <td><?php echo htmlspecialchars($h['name']); ?></td>
                            <td><span class="badge badge-present"><?php echo htmlspecialchars($h['kind']); ?></span></td>
                            <td>
                                <form method="POST" class="inline-delete-form" onsubmit="return confirm('Remove this day?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="holiday_action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $h['id']; ?>">
                                    <button type="submit" class="btn-link" style="color:#dc2626">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="form-hint" style="margin-top:16px">No holidays defined for this month.</p>
            <?php endif; ?>
        </div>
    </div>
    <aside class="dashboard-aside-card" style="padding:18px">
        <h4>Used for</h4>
        <p class="dashboard-aside-desc">Missing attendance report excludes these dates from expected working days.</p>
        <a href="missing_attendance.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-outline btn-sm">Missing days report</a>
    </aside>
</div>
<?php require 'includes/footer.php'; ?>
