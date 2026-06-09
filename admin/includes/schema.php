<?php

function ensure_database_schema($conn)
{
    $messages = [];

    $tables = [
        "CREATE TABLE IF NOT EXISTS `branches` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `code` varchar(20) NOT NULL,
            `name` varchar(100) NOT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `admin_users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL,
            `password` varchar(255) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `employees` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `emp_id` varchar(50) NOT NULL,
            `name` varchar(100) NOT NULL,
            `email` varchar(150) DEFAULT NULL,
            `phone` varchar(30) DEFAULT NULL,
            `department` varchar(100) DEFAULT NULL,
            `designation` varchar(100) DEFAULT NULL,
            `base_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
            `joined_date` date DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `emp_id` (`emp_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `attendance` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `emp_id` varchar(50) NOT NULL,
            `attendance_date` date NOT NULL,
            `status` varchar(20) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `emp_date` (`emp_id`, `attendance_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key` varchar(100) NOT NULL,
            `setting_value` text,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `salary_slip_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `emp_id` varchar(50) NOT NULL,
            `period_month` tinyint NOT NULL,
            `period_year` smallint NOT NULL,
            `net_salary` decimal(12,2) NOT NULL,
            `sent_to` varchar(150) DEFAULT NULL,
            `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `status` varchar(20) NOT NULL DEFAULT 'sent',
            PRIMARY KEY (`id`),
            KEY `period` (`period_year`, `period_month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `payroll_periods` (
            `period_year` smallint NOT NULL,
            `period_month` tinyint NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'open',
            `approved_by` varchar(50) DEFAULT NULL,
            `approved_at` datetime DEFAULT NULL,
            `locked_by` varchar(50) DEFAULT NULL,
            `locked_at` datetime DEFAULT NULL,
            `notes` text,
            PRIMARY KEY (`period_year`, `period_month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `holidays` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `calendar_date` date NOT NULL,
            `name` varchar(120) NOT NULL,
            `kind` varchar(20) NOT NULL DEFAULT 'holiday',
            PRIMARY KEY (`id`),
            UNIQUE KEY `calendar_date` (`calendar_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `leave_types` (
            `code` varchar(10) NOT NULL,
            `name` varchar(60) NOT NULL,
            `paid_credit` decimal(3,2) NOT NULL DEFAULT 1.00,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `employee_payroll_profiles` (
            `emp_id` varchar(50) NOT NULL,
            `use_custom` tinyint(1) NOT NULL DEFAULT 0,
            `pct_basic` decimal(5,2) DEFAULT NULL,
            `pct_hra` decimal(5,2) DEFAULT NULL,
            `pct_conveyance` decimal(5,2) DEFAULT NULL,
            `pct_medical` decimal(5,2) DEFAULT NULL,
            `pct_special` decimal(5,2) DEFAULT NULL,
            `pf_percent` decimal(5,2) DEFAULT NULL,
            `professional_tax` decimal(10,2) DEFAULT NULL,
            `portal_password_hash` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`emp_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `employee_weekoff_days` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `emp_id` varchar(50) NOT NULL,
            `branch_id` int(11) NOT NULL,
            `off_date` date NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `emp_off_date` (`emp_id`, `off_date`),
            KEY `branch_month` (`branch_id`, `off_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `payroll_adjustments` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `emp_id` varchar(50) NOT NULL,
            `period_year` smallint NOT NULL,
            `period_month` tinyint NOT NULL,
            `adj_type` varchar(20) NOT NULL,
            `label` varchar(100) NOT NULL,
            `amount` decimal(12,2) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `emp_period` (`emp_id`, `period_year`, `period_month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `employee_profile_requests` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `emp_id` varchar(50) NOT NULL,
            `branch_id` int(11) NOT NULL,
            `proposed_email` varchar(150) DEFAULT NULL,
            `proposed_phone` varchar(30) DEFAULT NULL,
            `proposed_pan` varchar(20) DEFAULT NULL,
            `proposed_bank_account` varchar(40) DEFAULT NULL,
            `proposed_bank_ifsc` varchar(20) DEFAULT NULL,
            `proposed_bank_name` varchar(100) DEFAULT NULL,
            `employee_note` text,
            `request_status` varchar(20) NOT NULL DEFAULT 'pending',
            `reviewed_by` varchar(50) DEFAULT NULL,
            `reviewed_at` datetime DEFAULT NULL,
            `review_note` text,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `branch_status` (`branch_id`, `request_status`),
            KEY `emp_status` (`emp_id`, `request_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `employee_attendance_requests` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `emp_id` varchar(50) NOT NULL,
            `branch_id` int(11) NOT NULL,
            `attendance_date` date NOT NULL,
            `status` varchar(20) NOT NULL,
            `leave_type` varchar(10) DEFAULT NULL,
            `employee_note` text,
            `request_status` varchar(20) NOT NULL DEFAULT 'pending',
            `reviewed_by` varchar(50) DEFAULT NULL,
            `reviewed_at` datetime DEFAULT NULL,
            `review_note` text,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `branch_status` (`branch_id`, `request_status`),
            KEY `emp_month` (`emp_id`, `attendance_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tables as $sql) {
        $conn->query($sql);
    }

    $employee_columns = [
        'email' => "ALTER TABLE `employees` ADD COLUMN `email` varchar(150) DEFAULT NULL",
        'phone' => "ALTER TABLE `employees` ADD COLUMN `phone` varchar(30) DEFAULT NULL",
        'department' => "ALTER TABLE `employees` ADD COLUMN `department` varchar(100) DEFAULT NULL",
        'designation' => "ALTER TABLE `employees` ADD COLUMN `designation` varchar(100) DEFAULT NULL",
        'base_salary' => "ALTER TABLE `employees` ADD COLUMN `base_salary` decimal(12,2) NOT NULL DEFAULT 0.00",
        'joined_date' => "ALTER TABLE `employees` ADD COLUMN `joined_date` date DEFAULT NULL",
        'created_at' => "ALTER TABLE `employees` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'is_active' => "ALTER TABLE `employees` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1",
        'pan' => "ALTER TABLE `employees` ADD COLUMN `pan` varchar(20) DEFAULT NULL",
        'bank_account' => "ALTER TABLE `employees` ADD COLUMN `bank_account` varchar(40) DEFAULT NULL",
        'bank_ifsc' => "ALTER TABLE `employees` ADD COLUMN `bank_ifsc` varchar(20) DEFAULT NULL",
        'bank_name' => "ALTER TABLE `employees` ADD COLUMN `bank_name` varchar(100) DEFAULT NULL",
    ];

    foreach ($employee_columns as $column => $sql) {
        if (!column_exists($conn, 'employees', $column)) {
            $conn->query($sql);
        }
    }

    $attendance_columns = [
        'leave_type' => "ALTER TABLE `attendance` ADD COLUMN `leave_type` varchar(10) DEFAULT NULL",
        'overtime_hours' => "ALTER TABLE `attendance` ADD COLUMN `overtime_hours` decimal(5,2) NOT NULL DEFAULT 0",
    ];
    foreach ($attendance_columns as $column => $sql) {
        if (!column_exists($conn, 'attendance', $column)) {
            $conn->query($sql);
        }
    }

    // Prevent repeated slip log rows per employee/period.
    // We keep only the latest entry and then enforce a unique key.
    if (!index_exists($conn, 'salary_slip_logs', 'emp_period_unique')) {
        // Delete older duplicates, keep the latest (highest sent_at; tie-breaker by id).
        $conn->query("
            DELETE l1 FROM salary_slip_logs l1
            INNER JOIN salary_slip_logs l2
                ON l1.emp_id = l2.emp_id
               AND l1.period_year = l2.period_year
               AND l1.period_month = l2.period_month
               AND (l1.sent_at < l2.sent_at OR (l1.sent_at = l2.sent_at AND l1.id < l2.id))
        ");
        $conn->query("ALTER TABLE `salary_slip_logs` ADD UNIQUE KEY `emp_period_unique` (`emp_id`, `period_year`, `period_month`)");
    }

    seed_default_branches($conn);
    migrate_branch_columns($conn);

    seed_default_leave_types($conn);
    seed_default_settings($conn);
    seed_employee_portal_passwords($conn);

    return $messages;
}

function seed_default_branches($conn)
{
    $branches = [
        ['INDRA', 'Indra Nagar'],
        ['ALAM', 'Alambagh'],
    ];
    foreach ($branches as $b) {
        $stmt = $conn->prepare('INSERT IGNORE INTO branches (code, name) VALUES (?, ?)');
        $stmt->bind_param('ss', $b[0], $b[1]);
        $stmt->execute();
    }
}

function migrate_branch_columns($conn)
{
    if (!column_exists($conn, 'admin_users', 'branch_id')) {
        $conn->query('ALTER TABLE `admin_users` ADD COLUMN `branch_id` int(11) DEFAULT NULL AFTER `password`');
    }

    if (!column_exists($conn, 'employees', 'branch_id')) {
        $conn->query('ALTER TABLE `employees` ADD COLUMN `branch_id` int(11) NOT NULL DEFAULT 1 AFTER `emp_id`');
        $conn->query('UPDATE employees SET branch_id = 1 WHERE branch_id = 0 OR branch_id IS NULL');
    }

    if (!column_exists($conn, 'holidays', 'branch_id')) {
        if (index_exists($conn, 'holidays', 'calendar_date')) {
            $conn->query('ALTER TABLE `holidays` DROP INDEX `calendar_date`');
        }
        $conn->query('ALTER TABLE `holidays` ADD COLUMN `branch_id` int(11) NOT NULL DEFAULT 1 AFTER `id`');
        $conn->query('UPDATE holidays SET branch_id = 1 WHERE branch_id = 0 OR branch_id IS NULL');
        if (!index_exists($conn, 'holidays', 'branch_date')) {
            $conn->query('ALTER TABLE `holidays` ADD UNIQUE KEY `branch_date` (`branch_id`, `calendar_date`)');
        }
    }

    if (!column_exists($conn, 'payroll_periods', 'branch_id')) {
        $conn->query('ALTER TABLE `payroll_periods` ADD COLUMN `branch_id` int(11) NOT NULL DEFAULT 1 FIRST');
        $conn->query('UPDATE payroll_periods SET branch_id = 1 WHERE branch_id = 0 OR branch_id IS NULL');
        $conn->query('ALTER TABLE `payroll_periods` DROP PRIMARY KEY');
        $conn->query('ALTER TABLE `payroll_periods` ADD PRIMARY KEY (`branch_id`, `period_year`, `period_month`)');
    }
}

function seed_default_leave_types($conn)
{
    $types = [
        ['CL', 'Casual Leave', '1.00'],
        ['SL', 'Sick Leave', '1.00'],
        ['LOP', 'Loss of Pay', '0.00'],
    ];
    foreach ($types as $t) {
        $stmt = $conn->prepare('INSERT IGNORE INTO leave_types (code, name, paid_credit) VALUES (?, ?, ?)');
        $stmt->bind_param('ssd', $t[0], $t[1], $t[2]);
        $stmt->execute();
    }
}

function column_exists($conn, $table, $column)
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

function index_exists($conn, $table, $index_name)
{
    // MariaDB does not allow LIMIT on SHOW INDEX in some versions.
    // Use information_schema.statistics to check index existence.
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND index_name = ?
        LIMIT 1
    ");
    $stmt->bind_param('ss', $table, $index_name);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function seed_employee_portal_passwords($conn)
{
    $default = 'Emp@123';
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'default_employee_portal_password' LIMIT 1");
    if ($stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['setting_value'])) {
            $default = $row['setting_value'];
        }
    }
    $hash = password_hash($default, PASSWORD_DEFAULT);

    $emps = $conn->query('SELECT emp_id FROM employees WHERE is_active = 1');
    if (!$emps) {
        return;
    }
    while ($emp = $emps->fetch_assoc()) {
        $emp_id = $emp['emp_id'];
        $check = $conn->prepare('SELECT portal_password_hash FROM employee_payroll_profiles WHERE emp_id = ?');
        $check->bind_param('s', $emp_id);
        $check->execute();
        $profile = $check->get_result()->fetch_assoc();
        if ($profile && !empty($profile['portal_password_hash'])) {
            continue;
        }
        if ($profile) {
            $upd = $conn->prepare('UPDATE employee_payroll_profiles SET portal_password_hash = ? WHERE emp_id = ?');
            $upd->bind_param('ss', $hash, $emp_id);
            $upd->execute();
        } else {
            $ins = $conn->prepare('INSERT INTO employee_payroll_profiles (emp_id, use_custom, portal_password_hash) VALUES (?, 0, ?)');
            $ins->bind_param('ss', $emp_id, $hash);
            $ins->execute();
        }
    }
}

function seed_default_settings($conn)
{
    $defaults = [
        'company_name' => 'Payroll Company',
        'working_days_per_month' => '26',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => 'Payroll System',
        'payslip_signature' => '',
        'signature_authority_name' => 'Authorized Signatory',
        'pct_basic' => '50',
        'pct_hra' => '20',
        'pct_conveyance' => '5',
        'pct_medical' => '5',
        'pct_special' => '20',
        'pf_percent' => '12',
        'professional_tax' => '200',
        'esi_percent' => '0.75',
        'esi_gross_limit' => '21000',
        'leave_day_credit' => '1',
        'half_day_credit' => '0.5',
        'overtime_hours_per_day' => '8',
        'overtime_multiplier' => '1.5',
        'require_payroll_approval' => '1',
        'weekoff_day_credit' => '1',
        'employee_attendance_requests_per_month' => '3',
        'default_employee_portal_password' => 'Emp@123',
    ];

    foreach ($defaults as $key => $value) {
        $stmt = $conn->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
}
