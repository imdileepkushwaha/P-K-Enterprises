<?php
require 'includes/header.php';
require 'config.php';
require_once 'includes/employee_portal_helper.php';

$branch_filter = get_active_branch_id();
$active_branch_label = get_branch_label($conn, $branch_filter);
$all_leaves = get_all_leave_requests_with_names($conn, $branch_filter);

// Optional: Map status to colors
function get_status_class($status) {
    switch ($status) {
        case 'approved': return 'approved';
        case 'rejected': return 'rejected';
        case 'cancelled': return 'cancelled';
        case 'pending':
        case 'cancellation_pending':
            return 'pending';
        default: return '';
    }
}
function format_status_label($status) {
    if ($status === 'cancellation_pending') return 'Cancel Req.';
    return ucfirst($status);
}
?>
<div class="approvals-page">
    <div class="page-header page-header-row">
        <div class="page-header-main">
            <p class="page-eyebrow">Employee portal</p>
            <h2>Leave History</h2>
            <p>Complete record of all leave applications across <?php echo $branch_filter !== null ? '<strong>' . htmlspecialchars($active_branch_label) . '</strong>' : 'all branches'; ?>.</p>
        </div>
    </div>

    <?php if ($branch_filter === null): ?>
        <div class="alert alert-page approvals-branch-alert" style="margin-bottom:20px;">
            <strong>Viewing all branches.</strong> Choose a branch from the top bar to filter leave records.
        </div>
    <?php endif; ?>

    <div class="panel panel-elevated">
        <div class="panel-header">
            <h3>Leave Requests</h3>
        </div>
        <div class="panel-body" style="padding: 0;">
            <?php if (empty($all_leaves)): ?>
                <div class="empty-state compact approvals-empty">
                    <h4>No leave records</h4>
                    <p>No leave requests found for this branch.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="emp-req-table emp-leave-requests-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Period</th>
                                <th>Days</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Submitted On</th>
                                <th>Review Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_leaves as $req): ?>
                                <?php 
                                // Calculate days
                                $from = new DateTime($req['from_date']);
                                $to = new DateTime($req['to_date']);
                                $diff = $from->diff($to)->days + 1;
                                $status_class = get_status_class($req['request_status']);
                                $status_label = format_status_label($req['request_status']);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($req['employee_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($req['emp_id']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo date('d M', strtotime($req['from_date'])); ?>
                                        <?php if ($req['from_date'] !== $req['to_date']): ?>
                                            - <?php echo date('d M Y', strtotime($req['to_date'])); ?>
                                        <?php else: ?>
                                            <?php echo date('Y', strtotime($req['from_date'])); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $diff; ?> day<?php echo $diff > 1 ? 's' : ''; ?></td>
                                    <td><strong><?php echo htmlspecialchars($req['leave_type']); ?></strong></td>
                                    <td><span class="emp-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($status_label); ?></span></td>
                                    <td><?php echo date('d M Y', strtotime($req['created_at'])); ?></td>
                                    <td><small><?php echo htmlspecialchars($req['reviewer_note'] ?? '-'); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
