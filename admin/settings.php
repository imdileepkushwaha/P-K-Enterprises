<?php
require 'includes/header.php';
require 'config.php';
require 'includes/settings_helper.php';
require 'includes/signature_helper.php';

$settings = get_all_settings($conn);
$signature_url = payslip_signature_url($settings);
$tab = $_GET['tab'] ?? 'smtp';
$smtp_ready = is_smtp_configured($settings);

function render_password_input($name, $attrs = '')
{
    $id = 'pwd_' . preg_replace('/[^a-z0-9]/i', '_', $name);
    ?>
    <div class="password-field">
        <input type="password" name="<?php echo htmlspecialchars($name); ?>" id="<?php echo $id; ?>" <?php echo $attrs; ?>>
        <button type="button" class="password-toggle" data-target="<?php echo $id; ?>" aria-label="Show password">
            <svg class="icon-eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="icon-eye-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
    </div>
    <?php
}

$tab_meta = [
    'smtp' => [
        'title' => 'SMTP & Email',
        'desc' => 'Configure outgoing mail for salary slip delivery.',
        'icon' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
    ],
    'password' => [
        'title' => 'Change Password',
        'desc' => 'Update your admin account credentials.',
        'icon' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    ],
    'payroll' => [
        'title' => 'Payroll Rules',
        'desc' => 'Company info and salary calculation settings.',
        'icon' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    ],
    'leave' => [
        'title' => 'Leave & Attendance',
        'desc' => 'Manage leave quotas and monthly limits.',
        'icon' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    ],
    'admins' => [
        'title' => 'Admin Users',
        'desc' => 'Manage who can access this panel.',
        'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    ],
];
$admin_users = $conn->query('SELECT id, username, branch_id FROM admin_users ORDER BY username');
$all_branches = get_branches($conn);
$active_meta = $tab_meta[$tab] ?? $tab_meta['smtp'];
?>
<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">Configuration</p>
        <h2>Settings</h2>
        <p>SMTP, security, and payroll rules for your organization.</p>
    </div>
</div>

<?php if (isset($_SESSION['flash_message'])): ?>
    <div class="alert <?php echo $_SESSION['flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page">
        <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_success']); ?>
    </div>
<?php endif; ?>

<div class="settings-status">
    <div class="settings-status-chip <?php echo $smtp_ready ? 'ok' : 'warn'; ?>">
        <span class="status-dot"></span>
        <div>
            <strong><?php echo $smtp_ready ? 'SMTP configured' : 'SMTP not configured'; ?></strong>
            <span><?php echo $smtp_ready ? 'Ready to send salary slips' : 'Set up email to send slips'; ?></span>
        </div>
    </div>
    <div class="settings-status-chip neutral">
        <span class="status-dot"></span>
        <div>
            <strong><?php echo htmlspecialchars($settings['company_name'] ?? 'Company'); ?></strong>
            <span><?php echo (int) ($settings['working_days_per_month'] ?? 26); ?> working days / month</span>
        </div>
    </div>
</div>

<div class="settings-layout">
    <nav class="settings-tabs" aria-label="Settings sections">
        <p class="settings-nav-label">Sections</p>
        <?php foreach ($tab_meta as $key => $meta): ?>
            <?php if ($key === 'admins' && !is_super_admin()) { continue; } ?>
            <a href="?tab=<?php echo $key; ?>" class="settings-tab <?php echo $tab === $key ? 'active' : ''; ?>">
                <span class="settings-tab-icon-wrap">
                    <svg class="settings-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?php echo $meta['icon']; ?></svg>
                </span>
                <span class="settings-tab-text">
                    <span class="settings-tab-title"><?php echo htmlspecialchars($meta['title']); ?></span>
                    <span class="settings-tab-desc"><?php echo htmlspecialchars($meta['desc']); ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="settings-content">
        <div class="settings-card panel-elevated">
            <div class="settings-card-head">
                <div class="settings-card-icon tab-<?php echo htmlspecialchars($tab); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?php echo $active_meta['icon']; ?></svg>
                </div>
                <div>
                    <h3><?php echo htmlspecialchars($active_meta['title']); ?></h3>
                    <p><?php echo htmlspecialchars($active_meta['desc']); ?></p>
                </div>
            </div>

            <?php if ($tab === 'smtp'): ?>
            <div class="settings-tip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <p>For <strong>Gmail</strong>: use <code>smtp.gmail.com</code>, port <code>587</code>, TLS, and an <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">App Password</a>.</p>
            </div>
            <form method="POST" action="settings_save.php" class="stack-form settings-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="section" value="smtp">
                <div class="settings-form-section">
                    <h4>Server</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>SMTP Host</label>
                            <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com">
                        </div>
                        <div class="form-group">
                            <label>SMTP Port</label>
                            <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '587'); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Encryption</label>
                        <select name="smtp_encryption">
                            <option value="tls" <?php echo ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS (recommended)</option>
                            <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                            <option value="none" <?php echo ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                        </select>
                    </div>
                </div>
                <div class="settings-form-section">
                    <h4>Authentication</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>SMTP Username</label>
                            <input type="text" name="smtp_username" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>" placeholder="your@email.com" autocomplete="username">
                        </div>
                        <div class="form-group">
                            <label>SMTP Password</label>
                            <?php
                            $smtp_placeholder = !empty($settings['smtp_password']) ? 'Saved — leave blank to keep' : 'SMTP password';
                            render_password_input('smtp_password', 'placeholder="' . htmlspecialchars($smtp_placeholder) . '" autocomplete="new-password"');
                            ?>
                        </div>
                    </div>
                </div>
                <div class="settings-form-section">
                    <h4>Sender identity</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>From Email</label>
                            <input type="email" name="smtp_from_email" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>" required placeholder="noreply@company.com">
                        </div>
                        <div class="form-group">
                            <label>From Name</label>
                            <input type="text" name="smtp_from_name" value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? 'Payroll System'); ?>">
                        </div>
                    </div>
                </div>
                <div class="settings-form-actions">
                    <button type="submit" class="btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save SMTP Settings
                    </button>
                </div>
            </form>
            <form method="POST" action="test_smtp.php" class="smtp-test-form">
                <?php echo csrf_field(); ?>
                <h4>Test connection</h4>
                <p class="form-hint">Save SMTP settings first, then send a test email (e.g. payroll@yopmail.com).</p>
                <div class="form-row">
                    <div class="form-group">
                        <label>Send test to</label>
                        <input type="email" name="test_email" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? 'payroll@yopmail.com'); ?>" required>
                    </div>
                    <div class="form-group form-group-btn">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-outline">Send Test Email</button>
                    </div>
                </div>
            </form>
            <?php endif; ?>

            <?php if ($tab === 'password'): ?>
            <div class="settings-tip settings-tip-security">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <p>Use at least 6 characters. Avoid sharing your admin password.</p>
            </div>
            <form method="POST" action="settings_save.php" class="stack-form settings-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="section" value="password">
                <div class="settings-form-section">
                    <div class="form-group">
                        <label>Current Password</label>
                        <?php render_password_input('current_password', 'required autocomplete="current-password"'); ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>New Password</label>
                            <?php render_password_input('new_password', 'required minlength="6" autocomplete="new-password"'); ?>
                        </div>
                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <?php render_password_input('confirm_password', 'required minlength="6" autocomplete="new-password"'); ?>
                        </div>
                    </div>
                </div>
                <div class="settings-form-actions">
                    <button type="submit" class="btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Update Password
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <?php if ($tab === 'payroll'): ?>
            <div class="settings-tip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <p><strong>Paid days</strong> = Present + (Half day × credit) + (Leave × credit). <strong>Net</strong> = gross split − PF, PT, ESI (if applicable).</p>
            </div>
            <form method="POST" action="settings_save.php" enctype="multipart/form-data" class="stack-form settings-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="section" value="payroll">
                <div class="settings-form-section">
                    <h4>Company &amp; calculation</h4>
                    <div class="form-group">
                        <label>Company Name</label>
                        <input type="text" name="company_name" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" placeholder="Shown on salary slips">
                    </div>
                    <div class="form-group">
                        <label>Working Days Per Month</label>
                        <input type="number" name="working_days_per_month" min="1" max="31" value="<?php echo htmlspecialchars($settings['working_days_per_month'] ?? '26'); ?>" required>
                        <span class="form-hint">Typically 22–26 for monthly payroll</span>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Half day credit</label>
                            <input type="number" name="half_day_credit" step="0.1" min="0" max="1" value="<?php echo htmlspecialchars($settings['half_day_credit'] ?? '0.5'); ?>">
                            <span class="form-hint">0.5 = half day counts as half paid day</span>
                        </div>
                        <div class="form-group">
                            <label>Leave day credit</label>
                            <input type="number" name="leave_day_credit" step="0.1" min="0" max="1" value="<?php echo htmlspecialchars($settings['leave_day_credit'] ?? '1'); ?>">
                            <span class="form-hint">1 = paid leave, 0 = unpaid</span>
                        </div>
                    </div>
                </div>
                <div class="settings-form-section">
                    <h4>Salary structure (% of gross)</h4>
                    <div class="form-row">
                        <div class="form-group"><label>Basic %</label><input type="number" name="pct_basic" step="0.1" min="0" max="100" value="<?php echo htmlspecialchars($settings['pct_basic'] ?? '50'); ?>"></div>
                        <div class="form-group"><label>HRA %</label><input type="number" name="pct_hra" step="0.1" min="0" max="100" value="<?php echo htmlspecialchars($settings['pct_hra'] ?? '20'); ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Conveyance %</label><input type="number" name="pct_conveyance" step="0.1" min="0" max="100" value="<?php echo htmlspecialchars($settings['pct_conveyance'] ?? '5'); ?>"></div>
                        <div class="form-group"><label>Medical %</label><input type="number" name="pct_medical" step="0.1" min="0" max="100" value="<?php echo htmlspecialchars($settings['pct_medical'] ?? '5'); ?>"></div>
                        <div class="form-group"><label>Special %</label><input type="number" name="pct_special" step="0.1" min="0" max="100" value="<?php echo htmlspecialchars($settings['pct_special'] ?? '20'); ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>PF % of Basic</label><input type="number" name="pf_percent" step="0.1" min="0" max="30" value="<?php echo htmlspecialchars($settings['pf_percent'] ?? '12'); ?>"></div>
                        <div class="form-group"><label>PF Basic Min Limit (₹)</label><input type="number" name="pf_min_limit" step="1" min="0" value="<?php echo htmlspecialchars($settings['pf_min_limit'] ?? '0'); ?>"><span class="form-hint">0 for no limit</span></div>
                        <div class="form-group"><label>PF Basic Max Limit (₹)</label><input type="number" name="pf_max_limit" step="1" min="0" value="<?php echo htmlspecialchars($settings['pf_max_limit'] ?? '15000'); ?>"><span class="form-hint">0 for no limit</span></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Professional tax (₹)</label><input type="number" name="professional_tax" step="1" min="0" value="<?php echo htmlspecialchars($settings['professional_tax'] ?? '200'); ?>"></div>
                        <div class="form-group"><label>ESI %</label><input type="number" name="esi_percent" step="0.01" min="0" max="5" value="<?php echo htmlspecialchars($settings['esi_percent'] ?? '0.75'); ?>"></div>
                        <div class="form-group"><label>ESI if gross ≤</label><input type="number" name="esi_gross_limit" step="1" min="0" value="<?php echo htmlspecialchars($settings['esi_gross_limit'] ?? '21000'); ?>"></div>
                    </div>
                </div>
                <div class="settings-form-section">
                    <h4>Payroll workflow</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="require_payroll_approval" value="1" <?php echo !isset($settings['require_payroll_approval']) || (int) $settings['require_payroll_approval'] === 1 ? 'checked' : ''; ?>>
                                Require payroll approval before sending slips
                            </label>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Overtime hours / day</label>
                            <input type="number" name="overtime_hours_per_day" step="0.5" min="1" max="24" value="<?php echo htmlspecialchars($settings['overtime_hours_per_day'] ?? '8'); ?>">
                        </div>
                        <div class="form-group">
                            <label>OT pay multiplier</label>
                            <input type="number" name="overtime_multiplier" step="0.1" min="1" max="3" value="<?php echo htmlspecialchars($settings['overtime_multiplier'] ?? '1.5'); ?>">
                            <span class="form-hint">e.g. 1.5× hourly rate</span>
                        </div>
                    </div>
                </div>
                <div class="settings-form-section">
                    <h4>Payslip authorized signature</h4>
                    <p class="form-hint" style="margin-bottom:16px">Upload a PNG/JPG signature image. It will appear on the bottom-right of every salary slip PDF.</p>
                    <?php if ($signature_url): ?>
                        <div class="signature-preview-box">
                            <img src="<?php echo htmlspecialchars($signature_url); ?>" alt="Current signature">
                            <div class="signature-preview-meta">
                                <span class="badge badge-present">Signature active</span>
                                <label class="signature-remove-label">
                                    <input type="checkbox" name="remove_signature" value="1"> Remove signature
                                </label>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="signature-preview-box signature-preview-empty">
                            <p>No signature uploaded yet</p>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Upload signature image</label>
                        <input type="file" name="payslip_signature" accept="image/png,image/jpeg,image/jpg,image/gif">
                        <span class="form-hint">Transparent PNG recommended. Max 2MB.</span>
                    </div>
                    <div class="form-group">
                        <label>Name below signature</label>
                        <input type="text" name="signature_authority_name" value="<?php echo htmlspecialchars($settings['signature_authority_name'] ?? 'Authorized Signatory'); ?>" placeholder="e.g. HR Manager / Director">
                    </div>
                </div>
                <div class="settings-form-actions">
                    <button type="submit" class="btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save Payroll Settings
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <?php if ($tab === 'leave'): ?>
            <form method="POST" action="settings_save.php" class="settings-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="section" value="leave">
                <div class="settings-form-section">
                    <h4>Leave &amp; Attendance Quotas</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Yearly PL Quota</label>
                            <input type="number" name="leave_quota_pl" step="1" min="0" value="<?php echo htmlspecialchars($settings['leave_quota_pl'] ?? '13'); ?>">
                            <span class="form-hint">Accrues 1.08 per month</span>
                        </div>
                        <div class="form-group">
                            <label>Yearly SL Quota</label>
                            <input type="number" name="leave_quota_sl" step="1" min="0" value="<?php echo htmlspecialchars($settings['leave_quota_sl'] ?? '9'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Yearly CL Quota</label>
                            <input type="number" name="leave_quota_cl" step="1" min="0" value="<?php echo htmlspecialchars($settings['leave_quota_cl'] ?? '8'); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Max Leaves Per Month</label>
                            <input type="number" name="max_leaves_per_month" step="1" min="0" value="<?php echo htmlspecialchars($settings['max_leaves_per_month'] ?? '4'); ?>">
                            <span class="form-hint">Leaves beyond this are unpaid</span>
                        </div>
                        <div class="form-group">
                            <label>Max Week Offs Per Month</label>
                            <input type="number" name="max_wo_per_month" step="1" min="0" value="<?php echo htmlspecialchars($settings['max_wo_per_month'] ?? '4'); ?>">
                            <span class="form-hint">WOs beyond this are unpaid</span>
                        </div>
                    </div>
                </div>
                <div class="settings-form-actions">
                    <button type="submit" class="btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save Leave Settings
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <?php if ($tab === 'admins' && !is_super_admin()): ?>
                <div class="alert alert-error alert-page">Only Head Office (super admin) can manage administrator accounts.</div>
            <?php elseif ($tab === 'admins'): ?>
            <?php
            $admin_rows = [];
            if ($admin_users) {
                while ($row = $admin_users->fetch_assoc()) {
                    $admin_rows[] = $row;
                }
            }
            $admin_count = count($admin_rows);
            ?>
            <div class="settings-tip settings-tip-security settings-tip-admins">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <p>Each admin can sign in to this panel. You cannot remove your own account while logged in. Use strong passwords and limit access to trusted staff.</p>
            </div>
            <div class="settings-form settings-admins-panel">
                <div class="settings-form-section">
                    <div class="admin-users-section-head">
                        <div>
                            <h4>Current administrators</h4>
                            <p class="form-hint admin-users-section-hint"><?php echo $admin_count === 1 ? '1 account with panel access' : $admin_count . ' accounts with panel access'; ?></p>
                        </div>
                        <span class="admin-users-count-badge" aria-hidden="true"><?php echo (int) $admin_count; ?></span>
                    </div>
                    <?php if ($admin_count === 0): ?>
                        <div class="admin-users-empty">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <p>No admin users found. Add one below to restore access.</p>
                        </div>
                    <?php else: ?>
                        <ul class="admin-user-list">
                            <?php foreach ($admin_rows as $au):
                                $uname = $au['username'];
                                $is_self = $uname === $_SESSION['admin_username'];
                                $initial = strtoupper(substr($uname, 0, 1));
                                ?>
                            <li class="admin-user-card<?php echo $is_self ? ' is-self' : ''; ?>">
                                <div class="admin-user-card-main">
                                    <span class="admin-user-avatar" aria-hidden="true"><?php echo htmlspecialchars($initial); ?></span>
                                    <div class="admin-user-card-text">
                                        <span class="admin-user-name"><?php echo htmlspecialchars($uname); ?></span>
                                        <span class="admin-user-meta"><?php
                                            if ($is_self) {
                                                echo 'Signed in as you';
                                            } elseif ($au['branch_id'] === null) {
                                                echo 'Head Office · All branches';
                                            } else {
                                                echo htmlspecialchars(get_branch_label($conn, (int) $au['branch_id']));
                                            }
                                        ?></span>
                                    </div>
                                </div>
                                <div class="admin-user-card-actions">
                                    <?php if ($is_self): ?>
                                        <span class="badge badge-present admin-user-you-badge">You</span>
                                    <?php else: ?>
                                        <form method="POST" action="settings_save.php" class="inline-delete-form" onsubmit="return confirm('Remove admin <?php echo htmlspecialchars($uname, ENT_QUOTES); ?>?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="section" value="admins">
                                            <input type="hidden" name="admin_action" value="delete">
                                            <input type="hidden" name="delete_username" value="<?php echo htmlspecialchars($uname); ?>">
                                            <button type="submit" class="btn btn-outline btn-sm btn-danger-outline">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <form method="POST" action="settings_save.php" class="stack-form admin-add-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="section" value="admins">
                    <input type="hidden" name="admin_action" value="add">
                    <div class="settings-form-section settings-form-section-add">
                        <h4>Add administrator</h4>
                        <p class="form-hint">Create a new username and password. The user can log in immediately after saving.</p>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" name="new_username" required autocomplete="off" placeholder="e.g. indranagar">
                            </div>
                            <div class="form-group">
                                <label>Branch access</label>
                                <select name="new_branch_id" required>
                                    <option value="">Select branch</option>
                                    <option value="0">Head Office (All branches)</option>
                                    <?php foreach ($all_branches as $branch): ?>
                                        <option value="<?php echo (int) $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <?php render_password_input('new_password', 'required minlength="6" autocomplete="new-password" placeholder="Min. 6 characters"'); ?>
                            </div>
                        </div>
                        <div class="settings-form-actions">
                            <button type="submit" class="btn">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                                Add administrator
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.password-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-target'));
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.classList.toggle('is-visible', show);
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
});
</script>

<?php require 'includes/footer.php'; ?>
