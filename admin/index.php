<?php
require_once 'includes/session_auth.php';
init_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';

if (!empty($_SESSION['admin_logged_in'])) {
    if (is_admin_session_expired()) {
        expire_admin_session();
    } else {
        header('Location: dashboard.php');
        exit;
    }
}

$branches = get_branches($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Payroll System</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-auth">
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-brand">P</div>
            <h2>Welcome back</h2>
            <p class="login-subtitle">Sign in to manage payroll & attendance</p>
            <p class="login-hint">Sessions end after 30 minutes of inactivity.</p>
            <?php
            if (isset($_SESSION['login_error'])) {
                echo "<div class='alert alert-error'>" . htmlspecialchars($_SESSION['login_error']) . "</div>";
                unset($_SESSION['login_error']);
            }
            ?>
            <form action="authenticate.php" method="POST">
                <?php echo csrf_field(); ?>
                <?php if (!SHOW_BRANCH_SELECTOR): ?>
                    <input type="hidden" name="branch_id" value="<?php echo (int) DEFAULT_BRANCH_ID; ?>">
                <?php endif; ?>
                <div class="form-group login-branch-field<?php echo SHOW_BRANCH_SELECTOR ? '' : ' is-ui-hidden'; ?>"<?php echo SHOW_BRANCH_SELECTOR ? '' : ' aria-hidden="true"'; ?>>
                    <label for="branch_id">Branch</label>
                    <select name="branch_id" id="branch_id"<?php echo SHOW_BRANCH_SELECTOR ? ' required' : ' disabled tabindex="-1"'; ?>>
                        <option value="">Select branch</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo (int) $branch['id']; ?>"<?php echo (int) $branch['id'] === (int) DEFAULT_BRANCH_ID ? ' selected' : ''; ?>><?php echo htmlspecialchars($branch['name']); ?></option>
                        <?php endforeach; ?>
                        <option value="0">All Branches (Head Office)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" placeholder="Enter your username" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" placeholder="Enter your password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-block">Sign in</button>
            </form>
            <p class="login-footer">First time here? <a href="setup.php">Run database setup</a> · <a href="emp/login.php">Employee portal</a></p>
        </div>
    </div>
</body>
</html>
