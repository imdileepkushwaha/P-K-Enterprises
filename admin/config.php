<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'payroll_db';

/** Set false on production to disable setup.php and seed_demo_data.php */
if (!defined('PAYROLL_ALLOW_SETUP_TOOLS')) {
    define('PAYROLL_ALLOW_SETUP_TOOLS', true);
}

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

require_once __DIR__ . '/includes/schema.php';
ensure_database_schema($conn);
require_once __DIR__ . '/includes/payroll_extensions.php';
