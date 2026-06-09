<?php

define('EMPLOYEE_SESSION_TIMEOUT', 30 * 60);

function init_employee_session()
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    ini_set('session.gc_maxlifetime', (string) EMPLOYEE_SESSION_TIMEOUT);
    session_set_cookie_params([
        'lifetime' => EMPLOYEE_SESSION_TIMEOUT,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_employee_session_expired()
{
    if (empty($_SESSION['employee_logged_in'])) {
        return false;
    }
    $last = $_SESSION['employee_last_activity'] ?? 0;
    if ($last === 0) {
        return false;
    }
    return (time() - $last) > EMPLOYEE_SESSION_TIMEOUT;
}

function expire_employee_session($message = null)
{
    init_employee_session();
    $msg = $message ?? 'Your session expired. Please sign in again.';
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION['employee_login_error'] = $msg;
}

function set_employee_session_on_login($emp_id, $branch_id, $name)
{
    $_SESSION['employee_logged_in'] = true;
    $_SESSION['employee_emp_id'] = $emp_id;
    $_SESSION['employee_branch_id'] = (int) $branch_id;
    $_SESSION['employee_name'] = $name;
    $_SESSION['employee_last_activity'] = time();
}

function enforce_employee_session()
{
    require_once __DIR__ . '/csrf_helper.php';
    csrf_token();
    init_employee_session();

    if (empty($_SESSION['employee_logged_in']) || empty($_SESSION['employee_emp_id'])) {
        header('Location: login.php');
        exit;
    }

    if (is_employee_session_expired()) {
        expire_employee_session();
        header('Location: login.php');
        exit;
    }

    $_SESSION['employee_last_activity'] = time();
}

function get_logged_in_employee_id()
{
    return $_SESSION['employee_emp_id'] ?? '';
}

function require_logged_in_employee($conn)
{
    $emp_id = get_logged_in_employee_id();
    $employee = get_employee_portal_profile($conn, $emp_id);
    if (!$employee) {
        expire_employee_session('Your account is inactive or not found.');
        header('Location: login.php');
        exit;
    }
    return $employee;
}
