<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require 'config.php';

if (!is_super_admin()) {
    header('Location: dashboard.php');
    exit;
}

$branch_id = (int) ($_GET['branch_id'] ?? BRANCH_FILTER_ALL);
$redirect = $_GET['redirect'] ?? 'dashboard.php';
if (!preg_match('/^[a-z0-9_\-]+\.php(\?.*)?$/i', $redirect)) {
    $redirect = 'dashboard.php';
}

if ($branch_id === BRANCH_FILTER_ALL) {
    set_active_branch_id(BRANCH_FILTER_ALL);
} elseif (get_branch_by_id($conn, $branch_id)) {
    set_active_branch_id($branch_id);
}

header('Location: ' . $redirect);
exit;
