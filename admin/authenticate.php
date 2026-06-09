<?php
require_once 'includes/session_auth.php';
init_admin_session();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$selected_branch = (int) ($_POST['branch_id'] ?? 0);

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Username, password, and branch are required.';
    header('Location: index.php');
    exit;
}

$stmt = $conn->prepare('SELECT id, username, password, branch_id FROM admin_users WHERE username = ?');
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    $_SESSION['login_error'] = 'Invalid username or branch.';
    header('Location: index.php');
    exit;
}

$row = $result->fetch_assoc();
if (!password_verify($password, $row['password'])) {
    $_SESSION['login_error'] = 'Invalid password.';
    header('Location: index.php');
    exit;
}

$user_branch = $row['branch_id'] !== null ? (int) $row['branch_id'] : null;
if (!validate_login_branch($user_branch, $selected_branch)) {
    $_SESSION['login_error'] = 'This account is not authorized for the selected branch.';
    header('Location: index.php');
    exit;
}

$active_branch = $user_branch ?? $selected_branch;
if ($user_branch === null && $selected_branch === BRANCH_FILTER_ALL) {
    $active_branch = BRANCH_FILTER_ALL;
}

set_admin_session_on_login($row['username'], $user_branch, $active_branch);
header('Location: dashboard.php');
exit;
