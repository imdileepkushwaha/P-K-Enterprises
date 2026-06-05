<?php
require_once 'includes/session_auth.php';
init_admin_session();
require 'config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, password FROM admin_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        if (password_verify($password, $row['password'])) {
            set_admin_session_on_login($row['username']);
            header("Location: dashboard.php");
            exit;
        } else {
            $_SESSION['login_error'] = "Invalid password.";
            header("Location: index.php");
            exit;
        }
    } else {
        $_SESSION['login_error'] = "Invalid username.";
        header("Location: index.php");
        exit;
    }
}
header("Location: index.php");
exit;
?>
