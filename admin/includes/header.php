<?php
require_once __DIR__ . '/session_auth.php';
enforce_admin_session();
if (!isset($conn)) {
    require_once __DIR__ . '/../config.php';
}
$current_page = basename($_SERVER['PHP_SELF']);
$employees_active = in_array($current_page, ['employees.php', 'employee_view.php'], true);
$admin_initial = strtoupper(substr($_SESSION['admin_username'], 0, 1));
$active_branch_label = get_branch_label($conn, get_active_branch_id());
$branch_switch_query = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
$all_branches = get_branches($conn);
$pending_approvals_count = count_pending_approvals_for_branch($conn, get_active_branch_id());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Payroll</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-panel">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="sidebar-logo">P</div>
                <div>
                    <h2>Payroll</h2>
                    <span>Admin Panel</span>
                </div>
            </div>
        </div>
        <ul class="sidebar-menu">
            <li>
                <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="upload_attendance.php" class="<?php echo $current_page === 'upload_attendance.php' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span>Upload Attendance</span>
                </a>
            </li>
            <li>
                <a href="holidays.php" class="<?php echo $current_page === 'holidays.php' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/></svg>
                    <span>Holidays</span>
                </a>
            </li>
            <li>
                <a href="weekoff_roster.php" class="<?php echo $current_page === 'weekoff_roster.php' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><path d="M9 16h6M12 13v6"/></svg>
                    <span>Weekoff roster</span>
                </a>
            </li>
            <li>
                <a href="slip_logs.php" class="<?php echo $current_page === 'slip_logs.php' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <span>Slip history</span>
                </a>
            </li>
            <li>
                <a href="employees.php" class="<?php echo $employees_active ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Employees</span>
                </a>
            </li>
            <li>
                <a href="approvals.php" class="<?php echo $current_page === 'approvals.php' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    <span>Approvals<?php if ($pending_approvals_count > 0): ?> <em class="nav-badge"><?php echo (int) $pending_approvals_count; ?></em><?php endif; ?></span>
                </a>
            </li>
            <li>
                <a href="settings.php" class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span>Settings</span>
                </a>
            </li>
            <li class="nav-logout">
                <a href="logout.php">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </aside>
    <div class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <span class="topbar-title">Payroll Management System</span>
                <?php if (is_super_admin()): ?>
                    <form method="GET" action="branch_switch.php" class="branch-switcher">
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($branch_switch_query); ?>">
                        <label class="sr-only" for="topbar-branch">Branch</label>
                        <select name="branch_id" id="topbar-branch" onchange="this.form.submit()">
                            <option value="0" <?php echo get_active_branch_id() === null ? 'selected' : ''; ?>>All Branches</option>
                            <?php foreach ($all_branches as $branch): ?>
                                <option value="<?php echo (int) $branch['id']; ?>" <?php echo get_active_branch_id() === (int) $branch['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($branch['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <span class="branch-pill"><?php echo htmlspecialchars($active_branch_label); ?></span>
                <?php endif; ?>
            </div>
            <div class="topbar-user">
                <div class="user-info">
                    <span class="name"><?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                    <span class="role"><?php echo is_super_admin() ? 'Head Office' : htmlspecialchars($active_branch_label); ?></span>
                </div>
                <div class="user-avatar"><?php echo htmlspecialchars($admin_initial); ?></div>
            </div>
        </header>
        <main class="content">
