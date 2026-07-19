<?php
require_once 'includes/session_auth.php';
init_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require_once 'includes/settings_helper.php';

if (!empty($_SESSION['admin_logged_in'])) {
    if (is_admin_session_expired()) {
        expire_admin_session();
    } else {
        header('Location: dashboard.php');
        exit;
    }
}

$branches = get_branches($conn);
$settings = get_all_settings($conn);
$company_name = trim($settings['company_name'] ?? '') ?: 'Payroll Company';
$company_initial = strtoupper(mb_substr($company_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — <?php echo htmlspecialchars($company_name); ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-auth page-auth-admin">
    <div class="login-wrapper">
        <div class="login-card login-card-v2">
            <header class="login-card-header">
                <div class="login-header-row">
                    <div class="login-header-logo"><?php echo htmlspecialchars($company_initial); ?></div>
                    <div class="login-header-text">
                        <span class="login-header-eyebrow"><?php echo htmlspecialchars(strtoupper($company_name)); ?></span>
                        <h1>Admin portal</h1>
                    </div>
                </div>
                <p class="login-header-sub">Payroll, attendance, employees &amp; reports</p>
            </header>

            <div class="login-card-body">
                <div class="login-notice">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Sessions end after 30 minutes of inactivity
                </div>

                <?php
                if (isset($_SESSION['login_error'])) {
                    echo '<div class="alert alert-error login-alert">' . htmlspecialchars($_SESSION['login_error']) . '</div>';
                    unset($_SESSION['login_error']);
                }
                ?>

                <form action="authenticate.php" method="POST" class="login-form-v2">
                    <?php echo csrf_field(); ?>
                    <?php if (!SHOW_BRANCH_SELECTOR): ?>
                        <input type="hidden" name="branch_id" value="<?php echo (int) DEFAULT_BRANCH_ID; ?>">
                    <?php endif; ?>

                    <div class="form-group login-branch-field<?php echo SHOW_BRANCH_SELECTOR ? '' : ' is-ui-hidden'; ?>"<?php echo SHOW_BRANCH_SELECTOR ? '' : ' aria-hidden="true"'; ?>>
                        <label for="branch_id">Branch</label>
                        <div class="login-field">
                            <span class="login-field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            </span>
                            <select name="branch_id" id="branch_id" class="login-input login-select"<?php echo SHOW_BRANCH_SELECTOR ? ' required' : ' disabled tabindex="-1"'; ?>>
                                <option value="">Select branch</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo (int) $branch['id']; ?>"<?php echo (int) $branch['id'] === (int) DEFAULT_BRANCH_ID ? ' selected' : ''; ?>><?php echo htmlspecialchars($branch['name']); ?></option>
                                <?php endforeach; ?>
                                <option value="0">All Branches (Head Office)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="login-field">
                            <span class="login-field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                            <input type="text" name="username" id="username" class="login-input" placeholder="Enter your username" required autocomplete="username">
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

                    <button type="submit" class="login-submit">
                        Sign in to admin
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </form>

                <div class="login-card-actions">
                    <a href="setup.php" class="login-action-btn login-action-outline">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                        Database setup
                    </a>
                    <a href="emp/login.php" class="login-action-btn login-action-emp">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Employee portal
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
