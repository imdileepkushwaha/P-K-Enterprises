<?php
require_once __DIR__ . '/../../includes/employee_portal_auth.php';
enforce_employee_session();
if (!isset($conn)) {
    require __DIR__ . '/../../config.php';
}
require_once __DIR__ . '/../../includes/settings_helper.php';
$employee = require_logged_in_employee($conn);
$branch_label = get_branch_label($conn, (int) $employee['branch_id']);
$portal_company = trim(get_all_settings($conn)['company_name'] ?? '') ?: 'Payroll Company';
$portal_logo_initial = strtoupper(substr($portal_company, 0, 1)) ?: 'P';
$initial = strtoupper(substr($employee['name'], 0, 1));
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(match ($current_page) {
        'attendance.php' => 'My Attendance',
        'leave.php' => 'Apply Leave',
        'salary_slips.php' => 'My Salary Slips',
        'details.php' => 'My Details',
        'dashboard.php' => 'Dashboard',
        default => 'Employee Portal',
    }); ?> — Employee Portal</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="page-emp-portal">
    <header class="emp-topbar">
        <div class="emp-topbar-inner">
            <a href="dashboard.php" class="emp-topbar-brand">
                <span class="emp-brand-logo" aria-hidden="true"><?php echo htmlspecialchars($portal_logo_initial); ?></span>
                <div class="emp-topbar-brand-text">
                    <strong><?php echo htmlspecialchars($portal_company); ?></strong>
                    <span>Employee Portal · <?php echo htmlspecialchars($branch_label); ?></span>
                </div>
            </a>
            <nav class="emp-topbar-nav" aria-label="Employee portal">
                <a href="dashboard.php" class="emp-topbar-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <span>Dashboard</span>
                </a>
                <a href="attendance.php" class="emp-topbar-link <?php echo $current_page === 'attendance.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Attendance</span>
                </a>
                <a href="leave.php" class="emp-topbar-link <?php echo $current_page === 'leave.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    <span>Leave</span>
                </a>
                <a href="salary_slips.php" class="emp-topbar-link <?php echo $current_page === 'salary_slips.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <span>Salary slips</span>
                </a>
                <a href="details.php" class="emp-topbar-link <?php echo $current_page === 'details.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>My details</span>
                </a>
            </nav>
            <div class="emp-topbar-user">
                <div class="emp-topbar-user-card">
                    <span class="emp-user-avatar" aria-hidden="true"><?php echo htmlspecialchars($initial); ?></span>
                    <div class="emp-topbar-user-text">
                        <strong><?php echo htmlspecialchars($employee['name']); ?></strong>
                        <span><?php echo htmlspecialchars($employee['emp_id']); ?></span>
                    </div>
                </div>
                <form method="POST" action="logout.php" class="emp-topbar-logout-form">
                    <?php require_once __DIR__ . '/../../includes/csrf_helper.php'; echo csrf_field(); ?>
                    <button type="submit" class="emp-topbar-logout" title="Logout">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span>Logout</span>
                    </button>
                </form>
            </div>
        </div>
    </header>
    <main class="emp-content">
