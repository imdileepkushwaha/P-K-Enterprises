<?php
require 'includes/header.php';
require 'config.php';
require 'includes/employee_helper.php';

$filter_status = $_GET['status'] ?? 'all';
$filter_dept = trim($_GET['dept'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$where_sql = '1=1';
$types = '';
$params = [];
$branch_filter = branch_employees_sql('');
if ($branch_filter['sql'] !== '') {
    $where_sql .= $branch_filter['sql'];
    $types .= $branch_filter['types'];
    $params = array_merge($params, $branch_filter['params']);
}
if ($filter_status === 'active') {
    $where_sql .= ' AND is_active = 1';
} elseif ($filter_status === 'inactive') {
    $where_sql .= ' AND is_active = 0';
}
if ($filter_dept !== '') {
    $where_sql .= ' AND department = ?';
    $types .= 's';
    $params[] = $filter_dept;
}

$count_sql = "SELECT COUNT(*) AS c FROM employees WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types !== '') {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$filtered_total = (int) $count_stmt->get_result()->fetch_assoc()['c'];
$total_pages = max(1, (int) ceil($filtered_total / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$list_sql = "SELECT * FROM employees WHERE $where_sql ORDER BY name ASC LIMIT $per_page OFFSET $offset";
$list_stmt = $conn->prepare($list_sql);
if ($types !== '') {
    $list_stmt->bind_param($types, ...$params);
}
$list_stmt->execute();
$employees = $list_stmt->get_result();

$dept_list = $conn->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");

$stats_sql_base = 'SELECT COUNT(*) AS c FROM employees WHERE 1=1' . $branch_filter['sql'];
$stats_types = $branch_filter['types'];
$stats_params = $branch_filter['params'];

$total_stmt = $conn->prepare($stats_sql_base);
bind_branch_stmt_params($total_stmt, $stats_types, $stats_params);
$total_stmt->execute();
$total_all = (int) $total_stmt->get_result()->fetch_assoc()['c'];

$active_stmt = $conn->prepare($stats_sql_base . ' AND is_active = 1');
bind_branch_stmt_params($active_stmt, $stats_types, $stats_params);
$active_stmt->execute();
$active_count = (int) $active_stmt->get_result()->fetch_assoc()['c'];
$inactive_count = $total_all - $active_count;

$email_stmt = $conn->prepare($stats_sql_base . " AND is_active = 1 AND email IS NOT NULL AND email != ''");
bind_branch_stmt_params($email_stmt, $stats_types, $stats_params);
$email_stmt->execute();
$with_email_active = (int) $email_stmt->get_result()->fetch_assoc()['c'];
$active_branch_label = get_branch_label($conn, get_active_branch_id());
$listed = $employees ? $employees->num_rows : 0;
$range_start = $filtered_total > 0 ? $offset + 1 : 0;
$range_end = $filtered_total > 0 ? $offset + $listed : 0;

function emp_list_url($page, $filter_status, $filter_dept)
{
    $query = ['page' => $page];
    if ($filter_status !== 'all') {
        $query['status'] = $filter_status;
    }
    if ($filter_dept !== '') {
        $query['dept'] = $filter_dept;
    }
    return '?' . http_build_query($query);
}

function emp_pagination_pages($page, $total_pages)
{
    if ($total_pages <= 1) {
        return [];
    }
    if ($total_pages <= 7) {
        return range(1, $total_pages);
    }

    $pages = [1];
    if ($page > 3) {
        $pages[] = null;
    }
    for ($i = max(2, $page - 1); $i <= min($total_pages - 1, $page + 1); $i++) {
        $pages[] = $i;
    }
    if ($page < $total_pages - 2) {
        $pages[] = null;
    }
    $pages[] = $total_pages;

    return $pages;
}

require_once 'includes/csrf_helper.php';
?>
<div class="page-header page-header-row">
    <div class="page-header-main">
        <p class="page-eyebrow">People</p>
        <h2>Employees</h2>
        <p>Manage team profiles, salaries, and view attendance history for <strong><?php echo htmlspecialchars($active_branch_label); ?></strong>.</p>
    </div>
    <div class="page-header-actions">
        <button type="button" class="btn btn-header" onclick="document.getElementById('addEmployeeModal').showModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Employee
        </button>
    </div>
</div>

<?php if (isset($_SESSION['flash_message'])): ?>
    <div class="alert <?php echo $_SESSION['flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page">
        <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_success']); ?>
    </div>
<?php endif; ?>

<?php
$email_pct = $active_count > 0 ? round(($with_email_active / $active_count) * 100) : 0;
?>
<div class="emp-stats">
    <div class="emp-stat-card emp-stat-total">
        <div class="emp-stat-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="emp-stat-body">
            <span class="emp-stat-label">Total staff</span>
            <strong class="emp-stat-value"><?php echo $total_all; ?></strong>
            <span class="emp-stat-hint"><?php echo $inactive_count > 0 ? $inactive_count . ' inactive' : 'All registered employees'; ?></span>
        </div>
    </div>
    <div class="emp-stat-card emp-stat-active">
        <div class="emp-stat-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="emp-stat-body">
            <span class="emp-stat-label">Active staff</span>
            <strong class="emp-stat-value"><?php echo $active_count; ?></strong>
            <span class="emp-stat-hint">Eligible for salary slip emails</span>
        </div>
    </div>
    <div class="emp-stat-card emp-stat-email">
        <div class="emp-stat-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <div class="emp-stat-body">
            <span class="emp-stat-label">Active with email</span>
            <strong class="emp-stat-value"><?php echo $with_email_active; ?></strong>
            <span class="emp-stat-hint"><?php echo $email_pct; ?>% of active staff</span>
        </div>
    </div>
    <div class="emp-stat-card emp-stat-listed" id="empStatListedCard">
        <div class="emp-stat-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>
        <div class="emp-stat-body">
            <span class="emp-stat-label" id="empStatListedLabel">In directory</span>
            <strong class="emp-stat-value" id="empStatListed"><?php echo $listed; ?></strong>
            <span class="emp-stat-hint" id="empStatListedHint">Currently listed below</span>
        </div>
    </div>
</div>

<div class="panel panel-elevated employees-panel">
    <div class="panel-header employees-panel-head">
        <div class="panel-title-group">
            <h3>Employee directory</h3>
            <span class="panel-badge" id="empDirectoryCount"><?php echo $listed; ?> records</span>
        </div>
        <form method="GET" class="emp-filters inline-filter">
            <select name="status" onchange="this.form.submit()">
                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All status</option>
                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active only</option>
                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive only</option>
            </select>
            <select name="dept" onchange="this.form.submit()">
                <option value="">All departments</option>
                <?php if ($dept_list): while ($d = $dept_list->fetch_assoc()): ?>
                    <option value="<?php echo htmlspecialchars($d['department']); ?>" <?php echo $filter_dept === $d['department'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department']); ?></option>
                <?php endwhile; endif; ?>
            </select>
        </form>
        <div class="emp-search">
            <svg class="emp-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" id="empDirectorySearch" placeholder="Search ID, name, email, dept…" autocomplete="off" aria-label="Search employees">
            <button type="button" class="emp-search-clear" id="empSearchClear" hidden aria-label="Clear search">&times;</button>
        </div>
    </div>
    <div class="panel-body">
        <?php if ($employees && $employees->num_rows > 0): ?>
            <div class="table-wrap">
                <table class="data-table emp-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Branch</th>
                            <th>Role</th>
                            <th>Salary</th>
                            <th>Contact</th>
                            <th class="th-actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($emp = $employees->fetch_assoc()): ?>
                            <?php
                            $initial = strtoupper(substr($emp['name'], 0, 1));
                            $has_email = !empty($emp['email']);
                            $is_active = employee_is_active($emp);
                            $branch_name = get_branch_label($conn, (int) ($emp['branch_id'] ?? 0));
                            $search_blob = strtolower(implode(' ', [
                                $emp['emp_id'],
                                $emp['name'],
                                $branch_name,
                                $emp['email'] ?? '',
                                $emp['phone'] ?? '',
                                $emp['department'] ?? '',
                                $emp['designation'] ?? '',
                            ]));
                            ?>
                            <tr class="emp-row<?php echo $is_active ? '' : ' emp-row-inactive'; ?>" data-search="<?php echo htmlspecialchars($search_blob, ENT_QUOTES, 'UTF-8'); ?>" data-employee="<?php echo htmlspecialchars(json_encode([
                                'emp_id' => $emp['emp_id'],
                                'name' => $emp['name'],
                                'email' => $emp['email'] ?? '',
                                'phone' => $emp['phone'] ?? '',
                                'department' => $emp['department'] ?? '',
                                'designation' => $emp['designation'] ?? '',
                                'base_salary' => $emp['base_salary'],
                                'joined_date' => normalize_joined_date_for_input($emp['joined_date'] ?? null),
                                'pan' => $emp['pan'] ?? '',
                                'bank_name' => $emp['bank_name'] ?? '',
                                'bank_account' => $emp['bank_account'] ?? '',
                                'bank_ifsc' => $emp['bank_ifsc'] ?? '',
                            ]), ENT_QUOTES, 'UTF-8'); ?>">
                                <td>
                                    <div class="cell-employee">
                                        <span class="emp-avatar"><?php echo htmlspecialchars($initial); ?></span>
                                        <div>
                                            <span class="emp-name"><?php echo htmlspecialchars($emp['name']); ?></span>
                                            <span class="emp-id"><?php echo htmlspecialchars($emp['emp_id']); ?></span>
                                            <?php if ($is_active): ?>
                                                <span class="badge badge-active-status">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-inactive-status">Inactive</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="branch-pill branch-pill-table"><?php echo htmlspecialchars($branch_name); ?></span></td>
                                <td>
                                    <span class="dept-badge"><?php echo htmlspecialchars($emp['department'] ?: 'General'); ?></span>
                                    <?php if (!empty($emp['designation'])): ?>
                                        <span class="emp-designation"><?php echo htmlspecialchars($emp['designation']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="salary-pill">₹<?php echo number_format((float) $emp['base_salary'], 0); ?></span>
                                    <span class="salary-sub">/ month</span>
                                </td>
                                <td>
                                    <?php if ($has_email): ?>
                                        <span class="contact-line">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                            <?php echo htmlspecialchars($emp['email']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="contact-missing">No email</span>
                                    <?php endif; ?>
                                    <?php if (!empty($emp['phone'])): ?>
                                        <span class="contact-line contact-phone">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                            <?php echo htmlspecialchars($emp['phone']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-actions">
                                    <div class="action-btns">
                                        <a href="employee_view.php?emp_id=<?php echo urlencode($emp['emp_id']); ?>" class="btn-action btn-view" title="View profile">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                        <button type="button" class="btn-action btn-edit" title="Edit employee" onclick="openEditEmployee(this)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <form method="POST" action="employee_toggle_active.php" class="action-toggle-form" onsubmit="return confirmToggleStatus(<?php echo json_encode($emp['name']); ?>, <?php echo $is_active ? 'true' : 'false'; ?>);">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($emp['emp_id']); ?>">
                                            <button type="submit" class="btn-action <?php echo $is_active ? 'btn-deactivate' : 'btn-activate'; ?>" title="<?php echo $is_active ? 'Deactivate — no salary emails' : 'Activate employee'; ?>">
                                                <?php if ($is_active): ?>
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                                <?php else: ?>
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                        <form method="POST" action="employee_delete.php" class="action-delete-form" onsubmit="return confirmDelete(<?php echo json_encode($emp['name']); ?>, <?php echo json_encode($emp['emp_id']); ?>);">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($emp['emp_id']); ?>">
                                            <button type="submit" class="btn-action btn-delete" title="Delete employee">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
            <div class="emp-pagination" id="empPagination">
                <p class="emp-pagination-info">
                    Showing <strong><?php echo $range_start; ?>–<?php echo $range_end; ?></strong>
                    of <strong><?php echo $filtered_total; ?></strong> employees
                    <span class="emp-pagination-page">· Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                </p>
                <nav class="emp-pagination-nav" aria-label="Employee pages">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo htmlspecialchars(emp_list_url($page - 1, $filter_status, $filter_dept)); ?>" class="emp-page-btn" aria-label="Previous page">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                            <span>Prev</span>
                        </a>
                    <?php else: ?>
                        <span class="emp-page-btn is-disabled" aria-disabled="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                            <span>Prev</span>
                        </span>
                    <?php endif; ?>

                    <div class="emp-page-links">
                        <?php foreach (emp_pagination_pages($page, $total_pages) as $p): ?>
                            <?php if ($p === null): ?>
                                <span class="emp-page-ellipsis" aria-hidden="true">…</span>
                            <?php elseif ($p === $page): ?>
                                <span class="emp-page-link is-active" aria-current="page"><?php echo $p; ?></span>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars(emp_list_url($p, $filter_status, $filter_dept)); ?>" class="emp-page-link"><?php echo $p; ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?php echo htmlspecialchars(emp_list_url($page + 1, $filter_status, $filter_dept)); ?>" class="emp-page-btn" aria-label="Next page">
                            <span>Next</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                    <?php else: ?>
                        <span class="emp-page-btn is-disabled" aria-disabled="true">
                            <span>Next</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </span>
                    <?php endif; ?>
                </nav>
            </div>
            <?php endif; ?>
            <div class="empty-state compact" id="empSearchEmpty" hidden>
                <h4>No matches found</h4>
                <p>Try a different search term.</p>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <h4>No employees yet</h4>
                <p>Add an employee or upload attendance to import staff from Excel.</p>
                <button type="button" class="btn" onclick="document.getElementById('addEmployeeModal').showModal()">Add Employee</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<dialog class="modal modal-employee" id="addEmployeeModal">
    <form method="POST" action="employee_save.php" class="modal-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add">
        <div class="modal-head">
            <div class="modal-head-content">
                <div class="modal-head-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                </div>
                <div>
                    <h3>Add New Employee</h3>
                    <p>Register a new team member for payroll &amp; attendance</p>
                </div>
            </div>
            <button type="button" class="modal-close" aria-label="Close" onclick="document.getElementById('addEmployeeModal').close()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="modal-section">
                <h4 class="modal-section-title">Basic information</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="emp_id">Employee ID <span class="req">*</span></label>
                        <input type="text" name="emp_id" id="emp_id" required placeholder="e.g. EMP001" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="name">Full Name <span class="req">*</span></label>
                        <input type="text" name="name" id="name" required placeholder="Employee full name">
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <h4 class="modal-section-title">Contact</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" name="email" id="email" placeholder="name@company.com">
                        <span class="form-hint">Used for salary slip emails</span>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" name="phone" id="phone" placeholder="+91 98765 43210">
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <h4 class="modal-section-title">Job &amp; compensation</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="department">Department</label>
                        <input type="text" name="department" id="department" placeholder="e.g. Engineering">
                    </div>
                    <div class="form-group">
                        <label for="designation">Designation</label>
                        <input type="text" name="designation" id="designation" placeholder="e.g. Developer">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="base_salary">Base Salary (₹) <span class="req">*</span></label>
                        <div class="input-prefix-wrap">
                            <span class="input-prefix">₹</span>
                            <input type="number" name="base_salary" id="base_salary" min="0" step="0.01" value="0" required placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="joined_date">Joined Date</label>
                        <input type="date" name="joined_date" id="joined_date">
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <h4 class="modal-section-title">Bank &amp; tax (optional)</h4>
                <div class="form-row">
                    <div class="form-group"><label for="pan">PAN</label><input type="text" name="pan" id="pan"></div>
                    <div class="form-group"><label for="bank_name">Bank</label><input type="text" name="bank_name" id="bank_name"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="bank_account">Account</label><input type="text" name="bank_account" id="bank_account"></div>
                    <div class="form-group"><label for="bank_ifsc">IFSC</label><input type="text" name="bank_ifsc" id="bank_ifsc"></div>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('addEmployeeModal').close()">Cancel</button>
            <button type="submit" class="btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Employee
            </button>
        </div>
    </form>
</dialog>

<dialog class="modal modal-employee" id="editEmployeeModal">
    <form method="POST" action="employee_save.php" class="modal-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="emp_id" id="edit_emp_id">
        <div class="modal-head">
            <div class="modal-head-content">
                <div class="modal-head-icon" style="background:linear-gradient(135deg,#0891b2,#06b6d4)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div>
                    <h3>Edit Employee</h3>
                    <p>Update employee details and salary</p>
                </div>
            </div>
            <button type="button" class="modal-close" aria-label="Close" onclick="document.getElementById('editEmployeeModal').close()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="modal-section">
                <h4 class="modal-section-title">Basic information</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Employee ID</label>
                        <input type="text" id="edit_emp_id_display" disabled class="input-disabled">
                    </div>
                    <div class="form-group">
                        <label for="edit_name">Full Name <span class="req">*</span></label>
                        <input type="text" name="name" id="edit_name" required>
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <h4 class="modal-section-title">Contact</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" name="email" id="edit_email">
                    </div>
                    <div class="form-group">
                        <label for="edit_phone">Phone</label>
                        <input type="tel" name="phone" id="edit_phone">
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <h4 class="modal-section-title">Job &amp; compensation</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_department">Department</label>
                        <input type="text" name="department" id="edit_department">
                    </div>
                    <div class="form-group">
                        <label for="edit_designation">Designation</label>
                        <input type="text" name="designation" id="edit_designation">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_base_salary">Base Salary (₹) <span class="req">*</span></label>
                        <div class="input-prefix-wrap">
                            <span class="input-prefix">₹</span>
                            <input type="number" name="base_salary" id="edit_base_salary" min="0" step="0.01" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit_joined_date">Joined Date</label>
                        <input type="date" name="joined_date" id="edit_joined_date">
                    </div>
                </div>
            </div>
            <div class="modal-section">
                <h4 class="modal-section-title">Bank &amp; tax</h4>
                <div class="form-row">
                    <div class="form-group"><label for="edit_pan">PAN</label><input type="text" name="pan" id="edit_pan"></div>
                    <div class="form-group"><label for="edit_bank_name">Bank</label><input type="text" name="bank_name" id="edit_bank_name"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="edit_bank_account">Account</label><input type="text" name="bank_account" id="edit_bank_account"></div>
                    <div class="form-group"><label for="edit_bank_ifsc">IFSC</label><input type="text" name="bank_ifsc" id="edit_bank_ifsc"></div>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('editEmployeeModal').close()">Cancel</button>
            <button type="submit" class="btn">Save Changes</button>
        </div>
    </form>
</dialog>

<script>
function confirmDelete(name, empId) {
    return confirm('Delete employee "' + name + '" (' + empId + ')?\n\nThis will also remove their attendance records. This cannot be undone.');
}

function confirmToggleStatus(name, isActive) {
    if (isActive) {
        return confirm('Deactivate "' + name + '"?\n\nThey will not receive salary slip emails while inactive. Attendance data is kept.');
    }
    return confirm('Activate "' + name + '"?\n\nThey can receive salary slip emails again if email and salary are set.');
}

function openEditEmployee(btn) {
    var row = btn.closest('tr');
    if (!row || !row.dataset.employee) return;
    var emp = JSON.parse(row.dataset.employee);
    document.getElementById('edit_emp_id').value = emp.emp_id;
    document.getElementById('edit_emp_id_display').value = emp.emp_id;
    document.getElementById('edit_name').value = emp.name || '';
    document.getElementById('edit_email').value = emp.email || '';
    document.getElementById('edit_phone').value = emp.phone || '';
    document.getElementById('edit_department').value = emp.department || '';
    document.getElementById('edit_designation').value = emp.designation || '';
    document.getElementById('edit_base_salary').value = emp.base_salary || 0;
    var jd = emp.joined_date || '';
    if (jd.indexOf('0000') === 0) {
        jd = '';
    } else if (jd.length >= 10) {
        jd = jd.substring(0, 10);
    }
    document.getElementById('edit_joined_date').value = jd;
    document.getElementById('edit_pan').value = emp.pan || '';
    document.getElementById('edit_bank_name').value = emp.bank_name || '';
    document.getElementById('edit_bank_account').value = emp.bank_account || '';
    document.getElementById('edit_bank_ifsc').value = emp.bank_ifsc || '';
    document.getElementById('editEmployeeModal').showModal();
}

document.addEventListener('DOMContentLoaded', function () {
    ['addEmployeeModal', 'editEmployeeModal'].forEach(function (id) {
        var modal = document.getElementById(id);
        if (modal && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    });

    var searchInput = document.getElementById('empDirectorySearch');
    if (!searchInput) return;

    var rows = document.querySelectorAll('.emp-table tbody .emp-row');
    var badge = document.getElementById('empDirectoryCount');
    var statListed = document.getElementById('empStatListed');
    var statLabel = document.getElementById('empStatListedLabel');
    var statHint = document.getElementById('empStatListedHint');
    var statCard = document.getElementById('empStatListedCard');
    var noResults = document.getElementById('empSearchEmpty');
    var clearBtn = document.getElementById('empSearchClear');
    var tableWrap = document.querySelector('.employees-panel .table-wrap');
    var pagination = document.getElementById('empPagination');
    var totalRows = rows.length;

    function applyFilter() {
        var q = searchInput.value.trim().toLowerCase();
        var visible = 0;

        rows.forEach(function (row) {
            var haystack = row.getAttribute('data-search') || '';
            var match = q === '' || haystack.indexOf(q) !== -1;
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        if (badge) badge.textContent = visible + ' record' + (visible === 1 ? '' : 's');
        if (statListed) statListed.textContent = visible;
        if (statLabel) statLabel.textContent = q ? 'Search results' : 'In directory';
        if (statHint) statHint.textContent = q ? 'Matching your search' : 'Currently listed below';
        if (statCard) statCard.classList.toggle('is-filtered', q !== '');
        if (clearBtn) clearBtn.hidden = q === '';
        if (noResults) noResults.hidden = visible > 0 || totalRows === 0;
        if (tableWrap) tableWrap.hidden = visible === 0 && totalRows > 0;
        if (pagination) pagination.hidden = q !== '';
    }

    searchInput.addEventListener('input', applyFilter);
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            applyFilter();
            searchInput.focus();
        });
    }
});
</script>

<?php require 'includes/footer.php'; ?>
