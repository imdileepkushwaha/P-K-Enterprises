<?php
if (!isset($conn)) {
    require __DIR__ . '/../../config.php';
}
require_once __DIR__ . '/../../includes/settings_helper.php';

$footer_settings = get_all_settings($conn);
$footer_company = trim($footer_settings['company_name'] ?? '') ?: 'Payroll Company';
$footer_logo_initial = strtoupper(substr($footer_company, 0, 1)) ?: 'P';
$footer_year = (int) date('Y');
$footer_page = $current_page ?? basename($_SERVER['PHP_SELF'] ?? '');
$footer_branch = $branch_label ?? '';
?>
    </main>
    <footer class="emp-site-footer">
        <div class="emp-site-footer-inner">
            <div class="emp-site-footer-brand">
                <span class="emp-brand-logo" aria-hidden="true"><?php echo htmlspecialchars($footer_logo_initial); ?></span>
                <div class="emp-site-footer-brand-text">
                    <strong><?php echo htmlspecialchars($footer_company); ?></strong>
                    <span>Employee Portal<?php echo $footer_branch !== '' ? ' · ' . htmlspecialchars($footer_branch) : ''; ?></span>
                </div>
            </div>
            <nav class="emp-site-footer-nav" aria-label="Footer navigation">
                <a href="dashboard.php" class="<?php echo $footer_page === 'dashboard.php' ? 'active' : ''; ?>">Dashboard</a>
                <a href="attendance.php" class="<?php echo $footer_page === 'attendance.php' ? 'active' : ''; ?>">My attendance</a>
                <a href="details.php" class="<?php echo $footer_page === 'details.php' ? 'active' : ''; ?>">My details</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
        <div class="emp-site-footer-bottom">
            <span>&copy; <?php echo $footer_year; ?> <?php echo htmlspecialchars($footer_company); ?>. All rights reserved.</span>
            <a href="../index.php">Admin login</a>
        </div>
    </footer>
</body>
</html>
