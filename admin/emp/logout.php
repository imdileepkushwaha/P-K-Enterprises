<?php
require_once __DIR__ . '/../includes/employee_portal_auth.php';
init_employee_session();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
