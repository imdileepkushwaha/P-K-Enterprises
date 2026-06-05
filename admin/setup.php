<?php
require 'config.php';

if (!PAYROLL_ALLOW_SETUP_TOOLS) {
    http_response_code(403);
    die('Setup is disabled. Set PAYROLL_ALLOW_SETUP_TOOLS to true in config.php for local use only.');
}

require_once 'includes/schema.php';

$log = [];
ensure_database_schema($conn);
$log[] = ['ok', 'Database schema verified and updated.'];

$sql = "
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql) === TRUE) {
    $log[] = ['ok', "Table 'admin_users' created or already exists."];
} else {
    $log[] = ['err', "Error creating admin_users: " . $conn->error];
}

$sql = "
CREATE TABLE IF NOT EXISTS `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `emp_id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emp_id` (`emp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql) === TRUE) {
    $log[] = ['ok', "Table 'employees' created or already exists."];
} else {
    $log[] = ['err', "Error creating employees: " . $conn->error];
}

$sql = "
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `emp_id` varchar(50) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emp_date` (`emp_id`, `attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql) === TRUE) {
    $log[] = ['ok', "Table 'attendance' created or already exists."];
} else {
    $log[] = ['err', "Error creating attendance: " . $conn->error];
}

$username = 'Admin';
$password = 'Admin@6170';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO admin_users (username, password) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $hashed_password);
    if ($stmt->execute()) {
        $log[] = ['ok', "Default admin user 'Admin' created successfully."];
    } else {
        $log[] = ['err', "Error inserting admin user: " . $conn->error];
    }
} else {
    $log[] = ['ok', "Default admin user 'Admin' already exists."];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - Payroll</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-auth">
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-brand">P</div>
            <h2>Database Setup</h2>
            <p class="login-subtitle">Initializing tables and default admin account</p>
            <div class="setup-log">
                <?php foreach ($log as $entry): ?>
                    <div class="<?php echo $entry[0] === 'ok' ? 'ok' : 'err'; ?>"><?php echo htmlspecialchars($entry[1]); ?></div>
                <?php endforeach; ?>
            </div>
            <div class="alert alert-success">Setup complete! You can now sign in.</div>
            <a href="index.php" class="btn btn-block">Go to Login</a>
        </div>
    </div>
</body>
</html>
