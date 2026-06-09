<?php
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/employee_helper.php';

$emp_id = $employee['emp_id'];
$profile_requests = get_employee_profile_requests($conn, $emp_id, 8);
$has_pending_profile = employee_has_pending_profile_request($conn, $emp_id);
$profile_form_disabled = $has_pending_profile;

$dept = $employee['department'] ?: 'General';
$designation = $employee['designation'] ?: 'Staff';
$joined_display = format_joined_date_display($employee['joined_date'] ?? null);
?>
<div class="emp-page emp-page-details">
    <?php require __DIR__ . '/includes/flash.php'; ?>

    <div class="emp-page-hero emp-page-hero-profile">
        <div class="emp-page-hero-main">
            <div class="emp-page-hero-user">
                <span class="emp-avatar emp-avatar-xl emp-page-avatar" aria-hidden="true"><?php echo htmlspecialchars($initial); ?></span>
                <div class="emp-page-hero-text">
                    <p class="page-eyebrow emp-page-eyebrow">Profile</p>
                    <h1><?php echo htmlspecialchars($employee['name']); ?></h1>
                    <p class="emp-page-hero-sub"><?php echo htmlspecialchars($employee['emp_id']); ?> · <?php echo htmlspecialchars($designation); ?> · <?php echo htmlspecialchars($branch_label); ?></p>
                </div>
            </div>
        </div>
        <?php if ($has_pending_profile): ?>
            <span class="emp-badge pending">Update pending approval</span>
        <?php endif; ?>
    </div>

    <div class="emp-section-grid emp-section-grid-profile">
        <div class="emp-details-board">
            <div class="emp-details-group">
                <h3 class="emp-details-group-title">Work info</h3>
                <div class="emp-info-board">
                    <div class="emp-info-card">
                        <span class="emp-info-label">Employee ID</span>
                        <strong><?php echo htmlspecialchars($employee['emp_id']); ?></strong>
                    </div>
                    <div class="emp-info-card">
                        <span class="emp-info-label">Name</span>
                        <strong><?php echo htmlspecialchars($employee['name']); ?></strong>
                        <small>Managed by admin</small>
                    </div>
                    <div class="emp-info-card">
                        <span class="emp-info-label">Branch</span>
                        <strong><?php echo htmlspecialchars($branch_label); ?></strong>
                    </div>
                    <div class="emp-info-card">
                        <span class="emp-info-label">Department</span>
                        <strong><?php echo htmlspecialchars($dept); ?></strong>
                    </div>
                    <div class="emp-info-card">
                        <span class="emp-info-label">Designation</span>
                        <strong><?php echo htmlspecialchars($designation); ?></strong>
                    </div>
                    <div class="emp-info-card">
                        <span class="emp-info-label">Joined</span>
                        <strong><?php echo htmlspecialchars($joined_display); ?></strong>
                    </div>
                    <div class="emp-info-card emp-info-card-wide">
                        <span class="emp-info-label">Base salary</span>
                        <strong>₹<?php echo number_format((float) $employee['base_salary'], 0); ?> / month</strong>
                        <small>View only — contact admin for changes</small>
                    </div>
                </div>
            </div>

            <div class="emp-details-group">
                <h3 class="emp-details-group-title">Contact & bank</h3>
                <div class="emp-info-board emp-info-board-contact">
                    <div class="emp-info-card emp-info-card-wide">
                        <span class="emp-info-label">Email</span>
                        <strong><?php echo htmlspecialchars($employee['email'] ?: '—'); ?></strong>
                    </div>
                    <div class="emp-info-card emp-info-card-wide">
                        <span class="emp-info-label">Phone</span>
                        <strong><?php echo htmlspecialchars($employee['phone'] ?: '—'); ?></strong>
                    </div>
                    <div class="emp-info-card emp-info-card-wide">
                        <span class="emp-info-label">PAN</span>
                        <strong><?php echo htmlspecialchars($employee['pan'] ?: '—'); ?></strong>
                    </div>
                    <div class="emp-info-card emp-info-card-wide">
                        <span class="emp-info-label">Bank name</span>
                        <strong><?php echo htmlspecialchars($employee['bank_name'] ?: '—'); ?></strong>
                    </div>
                    <div class="emp-info-card">
                        <span class="emp-info-label">Account number</span>
                        <strong><?php echo htmlspecialchars($employee['bank_account'] ?: '—'); ?></strong>
                    </div>
                    <div class="emp-info-card">
                        <span class="emp-info-label">IFSC</span>
                        <strong><?php echo htmlspecialchars($employee['bank_ifsc'] ?: '—'); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <aside class="emp-request-panel emp-request-panel-profile">
            <div class="emp-request-panel-header">
                <span class="emp-request-panel-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </span>
                <div>
                    <h3>Request profile update</h3>
                    <p>Update contact or bank details. Name, salary and designation are managed by admin only.</p>
                </div>
            </div>
            <div class="emp-request-panel-body">
                <?php if ($has_pending_profile): ?>
                    <div class="emp-inline-alert emp-inline-alert-info">
                        <strong>Pending review</strong>
                        <span>Your previous request is with the branch admin. You can submit again after it is approved or rejected.</span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="profile_request_save.php" class="emp-request-form<?php echo $profile_form_disabled ? ' is-disabled' : ''; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="redirect" value="details.php">
                    <div class="emp-request-fieldset">
                        <h4 class="emp-request-fieldset-title">Contact</h4>
                        <div class="emp-request-fields">
                            <div class="form-group">
                                <label for="empProfEmail">Email</label>
                                <input type="email" id="empProfEmail" name="email" value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>" placeholder="you@company.com" <?php echo $profile_form_disabled ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="empProfPhone">Phone</label>
                                <input type="tel" id="empProfPhone" name="phone" value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>" placeholder="10-digit mobile" <?php echo $profile_form_disabled ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                    </div>
                    <div class="emp-request-fieldset">
                        <h4 class="emp-request-fieldset-title">Bank & tax</h4>
                        <div class="emp-request-fields">
                            <div class="form-group">
                                <label for="empProfPan">PAN</label>
                                <input type="text" id="empProfPan" name="pan" value="<?php echo htmlspecialchars($employee['pan'] ?? ''); ?>" maxlength="20" placeholder="ABCDE1234F" <?php echo $profile_form_disabled ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="empProfBankName">Bank name</label>
                                <input type="text" id="empProfBankName" name="bank_name" value="<?php echo htmlspecialchars($employee['bank_name'] ?? ''); ?>" placeholder="e.g. SBI, HDFC" <?php echo $profile_form_disabled ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="empProfBankAccount">Account number</label>
                                <input type="text" id="empProfBankAccount" name="bank_account" value="<?php echo htmlspecialchars($employee['bank_account'] ?? ''); ?>" inputmode="numeric" <?php echo $profile_form_disabled ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="empProfIfsc">IFSC code</label>
                                <input type="text" id="empProfIfsc" name="bank_ifsc" value="<?php echo htmlspecialchars($employee['bank_ifsc'] ?? ''); ?>" placeholder="SBIN0001234" <?php echo $profile_form_disabled ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                    </div>
                    <div class="emp-request-fields">
                        <div class="form-group">
                            <label for="empProfNote">Note for admin</label>
                            <textarea id="empProfNote" name="employee_note" rows="3" placeholder="Why are you updating these details? (optional)" <?php echo $profile_form_disabled ? 'disabled' : ''; ?>></textarea>
                        </div>
                    </div>
                    <div class="emp-request-submit">
                        <button type="submit" class="btn btn-block" <?php echo $profile_form_disabled ? 'disabled' : ''; ?>>Send to branch admin</button>
                        <p class="emp-request-footnote">Only changed fields are reviewed. Approved updates apply to your profile.</p>
                    </div>
                </form>

                <?php if ($profile_requests !== []): ?>
                <div class="emp-timeline">
                    <h4>Request history</h4>
                    <?php foreach ($profile_requests as $req): ?>
                        <article class="emp-timeline-item">
                            <div class="emp-timeline-dot emp-timeline-<?php echo htmlspecialchars($req['request_status']); ?>"></div>
                            <div class="emp-timeline-body">
                                <div class="emp-timeline-top">
                                    <strong>Profile update</strong>
                                    <span class="emp-req-status emp-req-<?php echo htmlspecialchars($req['request_status']); ?>"><?php echo htmlspecialchars(ucfirst($req['request_status'])); ?></span>
                                </div>
                                <time>Submitted <?php echo date('d M Y, h:i A', strtotime($req['created_at'])); ?></time>
                                <?php if (!empty($req['review_note'])): ?>
                                    <p class="emp-timeline-note">Admin: <?php echo htmlspecialchars($req['review_note']); ?></p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
