<?php
require 'includes/header.php';
require 'config.php';
require_once 'includes/attendance_helper.php';

$branch_filter = get_active_branch_id();
$active_branch_label = get_branch_label($conn, $branch_filter);
$pending_profile = get_pending_profile_requests($conn, $branch_filter);
$pending_attendance = get_pending_attendance_requests($conn, $branch_filter);
$pending_total = count($pending_profile) + count($pending_attendance);

$employee_cache = [];
function approvals_employee($conn, $emp_id, &$cache)
{
    if (!isset($cache[$emp_id])) {
        $stmt = $conn->prepare('SELECT * FROM employees WHERE emp_id = ?');
        $stmt->bind_param('s', $emp_id);
        $stmt->execute();
        $cache[$emp_id] = $stmt->get_result()->fetch_assoc() ?: [];
    }
    return $cache[$emp_id];
}
?>
<div class="approvals-page">
    <div class="page-header page-header-row">
        <div class="page-header-main">
            <p class="page-eyebrow">Employee portal</p>
            <h2>Approval requests</h2>
            <p>Review profile updates and manual attendance submitted by employees<?php echo $branch_filter !== null ? ' at <strong>' . htmlspecialchars($active_branch_label) . '</strong>' : ''; ?>.</p>
        </div>
    </div>

    <?php if ($branch_filter === null): ?>
        <div class="alert alert-page approvals-branch-alert">
            <strong>Select a branch.</strong> Choose a branch from the top bar to review employee requests for that location.
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert <?php echo $_SESSION['flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page">
            <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_success']); ?>
        </div>
    <?php endif; ?>

    <div class="approvals-stats">
        <div class="approvals-stat <?php echo $pending_total > 0 ? 'approvals-stat-warn' : 'approvals-stat-ok'; ?>">
            <span class="approvals-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </span>
            <div>
                <span class="approvals-stat-label">Total pending</span>
                <strong class="approvals-stat-value"><?php echo $pending_total; ?></strong>
                <span class="approvals-stat-hint"><?php echo $pending_total > 0 ? 'Awaiting your decision' : 'All caught up'; ?></span>
            </div>
        </div>
        <div class="approvals-stat approvals-stat-profile">
            <span class="approvals-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <div>
                <span class="approvals-stat-label">Profile updates</span>
                <strong class="approvals-stat-value"><?php echo count($pending_profile); ?></strong>
                <span class="approvals-stat-hint">Contact &amp; bank changes</span>
            </div>
        </div>
        <div class="approvals-stat approvals-stat-attendance">
            <span class="approvals-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </span>
            <div>
                <span class="approvals-stat-label">Attendance</span>
                <strong class="approvals-stat-value"><?php echo count($pending_attendance); ?></strong>
                <span class="approvals-stat-hint">Manual mark requests</span>
            </div>
        </div>
    </div>

    <div class="approvals-layout">
        <section class="panel panel-elevated approvals-panel">
            <div class="panel-header">
                <div class="panel-title-group approvals-panel-head">
                    <span class="approvals-panel-icon approvals-panel-icon-profile" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    <div>
                        <h3>Profile update requests</h3>
                        <p class="approvals-panel-desc">Employees request changes to contact and bank details.</p>
                    </div>
                    <span class="panel-badge"><?php echo count($pending_profile); ?> pending</span>
                </div>
            </div>
            <div class="panel-body approvals-panel-body">
                <?php if ($pending_profile === []): ?>
                    <div class="empty-state compact approvals-empty">
                        <div class="empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                        <h4>No profile requests</h4>
                        <p>When employees submit profile updates, they will appear here for review.</p>
                    </div>
                <?php else: ?>
                    <div class="approvals-card-list">
                        <?php foreach ($pending_profile as $req): ?>
                            <?php
                            $emp = approvals_employee($conn, $req['emp_id'], $employee_cache);
                            $diffs = profile_request_field_diffs($emp, $req);
                            $emp_initial = strtoupper(substr($req['employee_name'], 0, 1));
                            ?>
                            <article class="approval-card approval-card-profile">
                                <header class="approval-card-top">
                                    <div class="approval-card-employee">
                                        <span class="approval-card-avatar" aria-hidden="true"><?php echo htmlspecialchars($emp_initial); ?></span>
                                        <div>
                                            <a href="employee_view.php?emp_id=<?php echo urlencode($req['emp_id']); ?>" class="approval-card-name"><?php echo htmlspecialchars($req['employee_name']); ?></a>
                                            <span class="approval-card-meta"><?php echo htmlspecialchars($req['emp_id']); ?> · <?php echo htmlspecialchars(get_branch_label($conn, (int) $req['branch_id'])); ?></span>
                                        </div>
                                    </div>
                                    <time class="approval-card-time" datetime="<?php echo htmlspecialchars($req['created_at']); ?>"><?php echo date('d M Y, h:i A', strtotime($req['created_at'])); ?></time>
                                </header>

                                <?php if ($diffs !== []): ?>
                                    <div class="approval-diff-list">
                                        <?php foreach ($diffs as $diff): ?>
                                            <div class="approval-diff-item">
                                                <span class="approval-diff-label"><?php echo htmlspecialchars($diff['label']); ?></span>
                                                <div class="approval-diff-values">
                                                    <span class="approval-diff-old" title="Current value"><?php echo htmlspecialchars($diff['old']); ?></span>
                                                    <span class="approval-diff-arrow" aria-hidden="true">→</span>
                                                    <span class="approval-diff-new" title="Requested value"><?php echo htmlspecialchars($diff['new']); ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($req['employee_note'])): ?>
                                    <div class="approval-employee-note">
                                        <strong>Employee note</strong>
                                        <p><?php echo htmlspecialchars($req['employee_note']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <footer class="approval-card-footer">
                                    <form method="POST" action="approval_save.php" class="approval-actions">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="request_type" value="profile">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $req['id']; ?>">
                                        <label class="approval-note-field">
                                            <span class="sr-only">Note to employee</span>
                                            <input type="text" name="review_note" placeholder="Optional note to employee" class="approval-note-input">
                                        </label>
                                        <div class="approval-action-buttons">
                                            <button type="submit" name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                                            <button type="submit" name="action" value="reject" class="btn btn-outline btn-sm btn-danger-outline" onclick="return confirm('Reject this profile request?');">Reject</button>
                                        </div>
                                    </form>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel panel-elevated approvals-panel">
            <div class="panel-header">
                <div class="panel-title-group approvals-panel-head">
                    <span class="approvals-panel-icon approvals-panel-icon-attendance" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </span>
                    <div>
                        <h3>Attendance requests</h3>
                        <p class="approvals-panel-desc">Manual attendance marks submitted by employees.</p>
                    </div>
                    <span class="panel-badge"><?php echo count($pending_attendance); ?> pending</span>
                </div>
            </div>
            <div class="panel-body approvals-panel-body">
                <?php if ($pending_attendance === []): ?>
                    <div class="empty-state compact approvals-empty">
                        <div class="empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <h4>No attendance requests</h4>
                        <p>When employees request manual attendance, they will appear here for review.</p>
                    </div>
                <?php else: ?>
                    <div class="approvals-card-list">
                        <?php foreach ($pending_attendance as $req): ?>
                            <?php
                            $emp_initial = strtoupper(substr($req['employee_name'], 0, 1));
                            $att_code = normalize_attendance_status_code($req['status']);
                            $att_class = attendance_code_css_class($att_code);
                            $att_label = attendance_code_label($att_code);
                            if ($att_code === '') {
                                $att_label = ucfirst(trim((string) $req['status']));
                            }
                            ?>
                            <article class="approval-card approval-card-attendance">
                                <header class="approval-card-top">
                                    <div class="approval-card-employee">
                                        <span class="approval-card-avatar" aria-hidden="true"><?php echo htmlspecialchars($emp_initial); ?></span>
                                        <div>
                                            <a href="employee_view.php?emp_id=<?php echo urlencode($req['emp_id']); ?>" class="approval-card-name"><?php echo htmlspecialchars($req['employee_name']); ?></a>
                                            <span class="approval-card-meta"><?php echo htmlspecialchars($req['emp_id']); ?> · <?php echo htmlspecialchars(get_branch_label($conn, (int) $req['branch_id'])); ?></span>
                                        </div>
                                    </div>
                                    <time class="approval-card-time" datetime="<?php echo htmlspecialchars($req['created_at']); ?>"><?php echo date('d M Y, h:i A', strtotime($req['created_at'])); ?></time>
                                </header>

                                <div class="approval-att-box">
                                    <div class="approval-att-date">
                                        <span class="approval-att-date-label">Attendance date</span>
                                        <strong><?php echo date('l, d M Y', strtotime($req['attendance_date'])); ?></strong>
                                    </div>
                                    <div class="approval-att-status-wrap">
                                        <span class="approval-att-status-label">Requested status</span>
                                        <span class="att-legend-item <?php echo htmlspecialchars($att_class); ?> approval-att-status"><?php echo htmlspecialchars($att_label); ?><?php if ($req['leave_type']): ?> <em>(<?php echo htmlspecialchars($req['leave_type']); ?>)</em><?php endif; ?></span>
                                    </div>
                                </div>

                                <?php if (!empty($req['employee_note'])): ?>
                                    <div class="approval-employee-note">
                                        <strong>Employee note</strong>
                                        <p><?php echo htmlspecialchars($req['employee_note']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <footer class="approval-card-footer">
                                    <form method="POST" action="approval_save.php" class="approval-actions">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="request_type" value="attendance">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $req['id']; ?>">
                                        <label class="approval-note-field">
                                            <span class="sr-only">Note to employee</span>
                                            <input type="text" name="review_note" placeholder="Optional note to employee" class="approval-note-input">
                                        </label>
                                        <div class="approval-action-buttons">
                                            <button type="submit" name="action" value="approve" class="btn btn-sm btn-success">Approve &amp; save</button>
                                            <button type="submit" name="action" value="reject" class="btn btn-outline btn-sm btn-danger-outline" onclick="return confirm('Reject this attendance request?');">Reject</button>
                                        </div>
                                    </form>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
