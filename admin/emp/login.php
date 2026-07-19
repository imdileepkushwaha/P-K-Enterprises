<?php
require_once __DIR__ . '/../includes/employee_portal_auth.php';
init_employee_session();
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf_helper.php';
require_once __DIR__ . '/../includes/settings_helper.php';
$settings = get_all_settings($conn);
$login_company = trim($settings['company_name'] ?? '') ?: 'Payroll Company';
$company_initial = strtoupper(mb_substr($login_company, 0, 1));

if (!empty($_SESSION['employee_logged_in'])) {
    if (is_employee_session_expired()) {
        expire_employee_session();
    } else {
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Login — <?php echo htmlspecialchars($login_company); ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="page-auth page-auth-emp">
    <div class="login-wrapper">
        <div class="login-card login-card-v2 login-card-v2-emp">
            <header class="login-card-header login-card-header-emp">
                <div class="login-header-row">
                    <div class="login-header-logo"><?php echo htmlspecialchars($company_initial); ?></div>
                    <div class="login-header-text">
                        <span class="login-header-eyebrow"><?php echo htmlspecialchars(strtoupper($login_company)); ?></span>
                        <h1>Employee portal</h1>
                    </div>
                </div>
                <p class="login-header-sub">Attendance, leave requests &amp; salary slips</p>
            </header>

            <div class="login-card-body">
                <?php
                if (isset($_SESSION['employee_login_error'])) {
                    echo '<div class="alert alert-error login-alert">' . htmlspecialchars($_SESSION['employee_login_error']) . '</div>';
                    unset($_SESSION['employee_login_error']);
                }
                ?>

                <form action="authenticate.php" method="POST" class="login-form-v2">
                    <?php echo csrf_field(); ?>

                    <div class="form-group">
                        <label for="emp_id">Employee ID</label>
                        <div class="login-field">
                            <span class="login-field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                            </span>
                            <input type="text" name="emp_id" id="emp_id" class="login-input" placeholder="e.g. EMP001" required autocomplete="username">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="login-field">
                            <span class="login-field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </span>
                            <input type="password" name="password" id="password" class="login-input" placeholder="Enter your password" required autocomplete="current-password">
                        </div>
                    </div>

                    <button type="submit" class="login-submit login-submit-emp">
                        Sign in to portal
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </form>

                <div class="login-card-actions login-card-actions-single">
                    <a href="../index.php" class="login-action-btn login-action-outline">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Admin login
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
