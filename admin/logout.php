<?php
require_once 'includes/session_auth.php';
init_admin_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'includes/csrf_helper.php';
    if (!verify_csrf()) {
        header('Location: dashboard.php');
        exit;
    }
}

$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;
