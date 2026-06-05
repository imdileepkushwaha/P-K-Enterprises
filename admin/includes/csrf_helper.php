<?php

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function verify_csrf()
{
    $sent = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($sent === '' || $expected === '' || !hash_equals($expected, $sent)) {
        $_SESSION['flash_message'] = 'Security check failed. Please refresh the page and try again.';
        $_SESSION['flash_success'] = false;
        return false;
    }
    return true;
}

function require_csrf_or_redirect($redirect = 'dashboard.php')
{
    if (!verify_csrf()) {
        header('Location: ' . $redirect);
        exit;
    }
}
