<?php
if (isset($GLOBALS['pk_db_conn']) && $GLOBALS['pk_db_conn'] instanceof mysqli) {
    $conn = $GLOBALS['pk_db_conn'];
    return;
}

$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'pk_db';

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
require_once __DIR__ . '/includes/branch_helper.php';
require_once __DIR__ . '/includes/weekoff_roster_helper.php';
require_once __DIR__ . '/includes/employee_portal_helper.php';

$GLOBALS['pk_db_conn'] = $conn;
