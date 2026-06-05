<?php
require 'includes/header.php';
require 'config.php';

$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$start = sprintf('%d-%02d-01', $year, $month);
$end = date('Y-m-t', strtotime($start));

$stmt = $conn->prepare('SELECT * FROM attendance_audit WHERE attendance_date BETWEEN ? AND ? ORDER BY changed_at DESC LIMIT 300');
$stmt->bind_param('ss', $start, $end);
$stmt->execute();
$logs = $stmt->get_result();
?>
<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">Attendance</p>
        <h2>Attendance change audit</h2>
        <p>Who changed attendance records for <?php echo htmlspecialchars(get_period_label($year, $month)); ?>.</p>
    </div>
    <form method="GET" class="inline-filter">
        <select name="month" onchange="this.form.submit()"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo $m; ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option><?php endfor; ?></select>
        <select name="year" onchange="this.form.submit()"><?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--): ?><option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option><?php endfor; ?></select>
    </form>
</div>

<div class="panel panel-elevated">
    <div class="panel-body padded">
        <?php if ($logs && $logs->num_rows > 0): ?>
        <div class="table-wrap">
            <table class="data-table data-table-compact">
                <thead><tr><th>When</th><th>Employee</th><th>Date</th><th>Action</th><th>Change</th><th>By</th></tr></thead>
                <tbody>
                    <?php while ($row = $logs->fetch_assoc()): ?>
                    <tr>
                        <td class="slip-log-date"><span class="slip-log-date-main"><?php echo htmlspecialchars(date('j M Y g:i A', strtotime($row['changed_at']))); ?></span></td>
                        <td><a href="employee_view.php?emp_id=<?php echo urlencode($row['emp_id']); ?>&month=<?php echo $month; ?>&year=<?php echo $year; ?>"><?php echo htmlspecialchars($row['emp_id']); ?></a></td>
                        <td><?php echo htmlspecialchars(date('j M Y', strtotime($row['attendance_date']))); ?></td>
                        <td><span class="badge badge-present"><?php echo htmlspecialchars($row['action']); ?></span></td>
                        <td class="payroll-phl">
                            <?php echo htmlspecialchars(($row['old_status'] ?? '—') . ' → ' . ($row['new_status'] ?? '—')); ?>
                            <?php if ($row['new_leave_type']): ?> (<?php echo htmlspecialchars($row['new_leave_type']); ?>)<?php endif; ?>
                            <?php if ((float) ($row['overtime_hours'] ?? 0) > 0): ?> · OT <?php echo format_money($row['overtime_hours']); ?>h<?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['changed_by']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state compact"><p>No attendance changes logged for this period.</p></div>
        <?php endif; ?>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
