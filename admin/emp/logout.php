<?php
require_once __DIR__ . '/../includes/employee_portal_auth.php';
init_employee_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/csrf_helper.php';
    if (!verify_csrf()) {
        header('Location: dashboard.php');
        exit;
    }
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
