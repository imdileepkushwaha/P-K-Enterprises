<?php
require_once 'includes/session_auth.php';
init_admin_session();
$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;
