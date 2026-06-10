<?php
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/salary_helper.php';

$emp_id = $employee['emp_id'];
$slip_logs = get_employee_sent_slip_logs($conn, $emp_id, 36);
$slip_count = count($slip_logs);
$latest_slip = $slip_logs[0] ?? null;
$has_email = trim((string) ($employee['email'] ?? '')) !== '';
?>
<div class="emp-page emp-page-slips">
    <?php require __DIR__ . '/includes/flash.php'; ?>

    <div class="emp-page-hero emp-page-hero-slips">
        <div class="emp-page-hero-main">
            <p class="page-eyebrow">Payroll</p>
            <h1>My salary slips</h1>
            <p>View and download salary slips that admin has sent for your account. Slips also go to your registered email when payroll is processed.</p>
        </div>
        <?php if ($slip_count > 0): ?>
            <div class="emp-slips-hero-stats">
                <div class="emp-slips-hero-stat">
                    <span>Total slips</span>
                    <strong><?php echo $slip_count; ?></strong>
                </div>
                <?php if ($latest_slip): ?>
                    <div class="emp-slips-hero-stat emp-slips-hero-stat-highlight">
                        <span>Latest net pay</span>
                        <strong>₹<?php echo format_money($latest_slip['net_salary']); ?></strong>
                        <small><?php echo htmlspecialchars(get_period_label((int) $latest_slip['period_year'], (int) $latest_slip['period_month'])); ?></small>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$has_email): ?>
        <div class="emp-slips-notice">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <div>
                <strong>No email on file</strong>
                <p>Ask admin to add your email in employee profile so future slips can be sent to your inbox.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="emp-card emp-slips-card">
        <div class="emp-card-toolbar">
            <h3 class="emp-card-title">Sent salary slips</h3>
            <?php if ($slip_count > 0): ?>
                <span class="emp-badge ok"><?php echo $slip_count; ?> available</span>
            <?php endif; ?>
        </div>

        <?php if ($slip_logs === []): ?>
            <div class="emp-slips-empty">
                <div class="emp-slips-empty-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </div>
                <p><strong>No salary slips yet</strong></p>
                <p>When admin sends your salary slip from the payroll dashboard, it will appear here for download.</p>
            </div>
        <?php else: ?>
            <ul class="emp-slip-list">
                <?php foreach ($slip_logs as $slip):
                    $slip_month = (int) $slip['period_month'];
                    $slip_year = (int) $slip['period_year'];
                    $slip_label = get_period_label($slip_year, $slip_month);
                    $sent_at = $slip['sent_at'] ?? '';
                    $sent_display = $sent_at !== '' ? date('j M Y, h:i A', strtotime($sent_at)) : '—';
                    $sent_to = trim((string) ($slip['sent_to'] ?? ''));
                    $pdf_url = 'slip_view.php?month=' . $slip_month . '&year=' . $slip_year;
                    ?>
                <li class="emp-slip-item">
                    <div class="emp-slip-item-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="emp-slip-item-main">
                        <strong class="emp-slip-period"><?php echo htmlspecialchars($slip_label); ?></strong>
                        <span class="emp-slip-meta">Sent <?php echo htmlspecialchars($sent_display); ?><?php if ($sent_to !== ''): ?> · <?php echo htmlspecialchars($sent_to); ?><?php endif; ?></span>
                    </div>
                    <div class="emp-slip-item-pay">
                        <span class="emp-slip-pay-label">Net salary</span>
                        <strong class="emp-slip-net">₹<?php echo format_money($slip['net_salary']); ?></strong>
                    </div>
                    <a href="<?php echo htmlspecialchars($pdf_url); ?>" class="emp-slip-download" target="_blank" rel="noopener">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        View PDF
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
