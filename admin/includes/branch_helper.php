<?php

/** Super admin session value — view all branches (no filter). */
if (!defined('BRANCH_FILTER_ALL')) {
    define('BRANCH_FILTER_ALL', 0);
}

function get_branches($conn)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $r = $conn->query('SELECT id, code, name, is_active FROM branches WHERE is_active = 1 ORDER BY id');
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $cache[] = $row;
        }
    }
    return $cache;
}

function get_branch_by_id($conn, $branch_id)
{
    foreach (get_branches($conn) as $branch) {
        if ((int) $branch['id'] === (int) $branch_id) {
            return $branch;
        }
    }
    return null;
}

function get_branch_label($conn, $branch_id)
{
    if (!$branch_id) {
        return 'All Branches';
    }
    $branch = get_branch_by_id($conn, $branch_id);
    return $branch ? $branch['name'] : 'Branch';
}

function is_super_admin()
{
    return !isset($_SESSION['admin_branch_id']) || $_SESSION['admin_branch_id'] === null || $_SESSION['admin_branch_id'] === '';
}

function get_admin_branch_id()
{
    if (is_super_admin()) {
        return null;
    }
    return (int) $_SESSION['admin_branch_id'];
}

function get_active_branch_id()
{
    if (!is_super_admin()) {
        return get_admin_branch_id();
    }
    $active = $_SESSION['admin_active_branch_id'] ?? BRANCH_FILTER_ALL;
    if ((int) $active === BRANCH_FILTER_ALL) {
        return null;
    }
    return (int) $active;
}

function set_active_branch_id($branch_id)
{
    if (!is_super_admin()) {
        return false;
    }
    if ($branch_id === null || (int) $branch_id === BRANCH_FILTER_ALL) {
        $_SESSION['admin_active_branch_id'] = BRANCH_FILTER_ALL;
        return true;
    }
    $_SESSION['admin_active_branch_id'] = (int) $branch_id;
    return true;
}

function branch_employees_sql($alias = 'employees')
{
    $branch_id = get_active_branch_id();
    if ($branch_id === null) {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    $col = $alias === '' ? 'branch_id' : $alias . '.branch_id';
    return ['sql' => " AND $col = ?", 'types' => 'i', 'params' => [$branch_id]];
}

function branch_id_for_write()
{
    $branch_id = get_active_branch_id();
    if ($branch_id !== null) {
        return $branch_id;
    }
    return 1;
}

function require_branch_context_for_write()
{
    if (get_active_branch_id() !== null) {
        return get_active_branch_id();
    }
    $_SESSION['flash_message'] = 'Select a branch from the top bar before performing this action.';
    $_SESSION['flash_success'] = false;
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
    exit;
}

function employee_belongs_to_active_branch($employee)
{
    $branch_id = get_active_branch_id();
    if ($branch_id === null) {
        return true;
    }
    return (int) ($employee['branch_id'] ?? 0) === $branch_id;
}

function require_employee_branch_access($conn, $emp_id, $redirect = 'employees.php')
{
    $stmt = $conn->prepare('SELECT * FROM employees WHERE emp_id = ?');
    $stmt->bind_param('s', $emp_id);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    if (!$employee) {
        $_SESSION['flash_message'] = 'Employee not found.';
        $_SESSION['flash_success'] = false;
        header('Location: ' . $redirect);
        exit;
    }
    if (!employee_belongs_to_active_branch($employee)) {
        $_SESSION['flash_message'] = 'You do not have access to this employee\'s branch.';
        $_SESSION['flash_success'] = false;
        header('Location: ' . $redirect);
        exit;
    }
    return $employee;
}

function validate_login_branch($user_branch_id, $selected_branch_id)
{
    $selected = (int) $selected_branch_id;
    if ($user_branch_id === null || $user_branch_id === '') {
        return $selected === BRANCH_FILTER_ALL || $selected === 1 || $selected === 2;
    }
    return (int) $user_branch_id === $selected;
}

function bind_branch_stmt_params($stmt, $types, $params)
{
    if ($types === '') {
        return;
    }
    $stmt->bind_param($types, ...$params);
}
