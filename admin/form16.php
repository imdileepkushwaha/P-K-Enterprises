<?php
require 'includes/header.php';
require 'config.php';
require 'includes/settings_helper.php';

$fy_year = (int) ($_GET['fy'] ?? (date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1));
$fy_label = $fy_year . '-' . substr((string) ($fy_year + 1), -2);
$settings = get_all_settings($conn);
$company = $settings['company_name'] ?? 'Company';

$employees = $conn->query('SELECT emp_id, name, pan FROM employees WHERE is_active = 1 ORDER BY name');
?>
<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">Payroll · Tax</p>
        <h2>Form 16 / TDS summary</h2>
        <p>Annual tax summary from TDS ledger for financial year <?php echo htmlspecialchars($fy_label); ?>.</p>
    </div>
    <form method="GET" class="inline-filter">
        <label class="sr-only">Financial year start</label>
        <select name="fy" onchange="this.form.submit()">
            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $y === $fy_year ? 'selected' : ''; ?>>FY <?php echo $y; ?>-<?php echo substr((string) ($y + 1), -2); ?></option>
            <?php endfor; ?>
        </select>
    </form>
</div>

<div class="panel panel-elevated">
    <div class="panel-body padded">
        <p class="form-hint" style="margin-bottom:16px">TDS is calculated monthly when enabled in Settings → Payroll. Form 16 shows YTD deductions from payroll runs.</p>
        <div class="table-wrap">
            <table class="data-table data-table-compact">
                <thead><tr><th>Employee</th><th>PAN</th><th>Regime</th><th class="col-money">TDS (FY)</th><th class="col-action">Actions</th></tr></thead>
                <tbody>
                    <?php while ($emp = $employees->fetch_assoc()):
                        $profile = get_employee_payroll_profile($conn, $emp['emp_id']);
                        $tds_ytd = get_tds_ytd_for_fy($conn, $emp['emp_id'], $fy_label);
                        ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($emp['name']); ?></strong><br><span class="emp-id"><?php echo htmlspecialchars($emp['emp_id']); ?></span></td>
                        <td><?php echo htmlspecialchars($emp['pan'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($profile['tax_regime'] ?? 'new'); ?></td>
                        <td class="col-money"><strong>₹<?php echo format_money($tds_ytd); ?></strong></td>
                        <td class="col-action">
                            <a href="form16_pdf.php?emp_id=<?php echo urlencode($emp['emp_id']); ?>&fy=<?php echo $fy_year; ?>" class="btn-action btn-pdf" title="Download Form 16 PDF" target="_blank">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
