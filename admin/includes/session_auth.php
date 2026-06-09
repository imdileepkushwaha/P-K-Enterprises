<?php

/** Inactivity timeout: 30 minutes */
define('ADMIN_SESSION_TIMEOUT', 30 * 60);

if (!defined('BRANCH_FILTER_ALL')) {
    define('BRANCH_FILTER_ALL', 0);
}

function init_admin_session()
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.gc_maxlifetime', (string) ADMIN_SESSION_TIMEOUT);
    session_set_cookie_params([
        'lifetime' => ADMIN_SESSION_TIMEOUT,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_admin_session_expired()
{
    if (empty($_SESSION['admin_logged_in'])) {
        return false;
    }

    $last = $_SESSION['admin_last_activity'] ?? 0;
    if ($last === 0) {
        return false;
    }

    return (time() - $last) > ADMIN_SESSION_TIMEOUT;
}

function expire_admin_session($message = null)
{
    init_admin_session();
    $msg = $message ?? 'Your session expired after 30 minutes of inactivity. Please sign in again.';
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION['login_error'] = $msg;
}

function set_admin_session_on_login($username, $branch_id = null, $active_branch_id = null)
{
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = $username;
    $_SESSION['admin_branch_id'] = $branch_id;
    $_SESSION['admin_active_branch_id'] = $active_branch_id ?? $branch_id ?? BRANCH_FILTER_ALL;
    $_SESSION['admin_last_activity'] = time();
}

function enforce_admin_session()
{
    require_once __DIR__ . '/csrf_helper.php';
    csrf_token();
    init_admin_session();

    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: index.php');
        exit;
    }

    if (is_admin_session_expired()) {
        expire_admin_session();
        header('Location: index.php');
        exit;
    }

    $_SESSION['admin_last_activity'] = time();
}
