<?php

define('EMPLOYEE_PORTAL_EDITABLE_FIELDS', [
    'email' => 'Email',
    'phone' => 'Phone',
    'pan' => 'PAN',
    'bank_account' => 'Bank account',
    'bank_ifsc' => 'IFSC',
    'bank_name' => 'Bank name',
]);

function get_attendance_requests_per_month_limit($settings)
{
    $limit = (int) ($settings['employee_attendance_requests_per_month'] ?? 3);
    return $limit > 0 ? $limit : 3;
}

function get_employee_portal_profile($conn, $emp_id)
{
    $stmt = $conn->prepare('SELECT * FROM employees WHERE emp_id = ? AND is_active = 1');
    $stmt->bind_param('s', $emp_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function get_employee_portal_password_hash($conn, $emp_id)
{
    $profile = get_employee_payroll_profile($conn, $emp_id);
    return $profile['portal_password_hash'] ?? null;
}

function set_employee_portal_password($conn, $emp_id, $plain_password)
{
    $hash = password_hash($plain_password, PASSWORD_DEFAULT);
    $profile = get_employee_payroll_profile($conn, $emp_id);
    if ($profile) {
        $stmt = $conn->prepare('UPDATE employee_payroll_profiles SET portal_password_hash = ? WHERE emp_id = ?');
        $stmt->bind_param('ss', $hash, $emp_id);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare('INSERT INTO employee_payroll_profiles (emp_id, use_custom, portal_password_hash) VALUES (?, 0, ?)');
        $stmt->bind_param('ss', $emp_id, $hash);
        $stmt->execute();
    }
}

function change_employee_portal_password($conn, $emp_id, $current_password, $new_password, $confirm_password)
{
    $current_password = (string) $current_password;
    $new_password = (string) $new_password;
    $confirm_password = (string) $confirm_password;

    if ($current_password === '') {
        return ['ok' => false, 'message' => 'Please enter your current password.'];
    }
    if (strlen($new_password) < 6) {
        return ['ok' => false, 'message' => 'New password must be at least 6 characters.'];
    }
    if ($new_password !== $confirm_password) {
        return ['ok' => false, 'message' => 'New password and confirmation do not match.'];
    }

    $hash = get_employee_portal_password_hash($conn, $emp_id);
    if (!$hash || !password_verify($current_password, $hash)) {
        return ['ok' => false, 'message' => 'Current password is incorrect.'];
    }
    if (password_verify($new_password, $hash)) {
        return ['ok' => false, 'message' => 'Choose a password different from your current one.'];
    }

    set_employee_portal_password($conn, $emp_id, $new_password);

    return ['ok' => true, 'message' => 'Portal password updated successfully. Use your new password next time you sign in.'];
}

function employee_has_pending_profile_request($conn, $emp_id)
{
    $stmt = $conn->prepare("SELECT id FROM employee_profile_requests WHERE emp_id = ? AND request_status = 'pending' LIMIT 1");
    $stmt->bind_param('s', $emp_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function count_employee_attendance_requests_for_month($conn, $emp_id, $year, $month)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c FROM employee_attendance_requests
        WHERE emp_id = ?
          AND YEAR(attendance_date) = ?
          AND MONTH(attendance_date) = ?
          AND request_status IN ('pending', 'approved')
    ");
    $stmt->bind_param('sii', $emp_id, $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int) ($row['c'] ?? 0);
}

function employee_attendance_request_remaining($conn, $emp_id, $year, $month, $settings)
{
    $limit = get_attendance_requests_per_month_limit($settings);
    $used = count_employee_attendance_requests_for_month($conn, $emp_id, $year, $month);
    return max(0, $limit - $used);
}

function get_employee_profile_requests($conn, $emp_id, $limit = 10)
{
    $stmt = $conn->prepare('SELECT * FROM employee_profile_requests WHERE emp_id = ? ORDER BY created_at DESC LIMIT ?');
    $stmt->bind_param('si', $emp_id, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_employee_attendance_requests($conn, $emp_id, $limit = 20)
{
    $stmt = $conn->prepare('SELECT * FROM employee_attendance_requests WHERE emp_id = ? ORDER BY created_at DESC LIMIT ?');
    $stmt->bind_param('si', $emp_id, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function create_employee_profile_request($conn, $emp_id, $branch_id, array $proposed, $note = '')
{
    if (employee_has_pending_profile_request($conn, $emp_id)) {
        return ['ok' => false, 'message' => 'You already have a pending profile update. Wait for branch admin approval.'];
    }

    $employee = get_employee_portal_profile($conn, $emp_id);
    if (!$employee) {
        return ['ok' => false, 'message' => 'Employee record not found.'];
    }

    $changed = false;
    foreach (array_keys(EMPLOYEE_PORTAL_EDITABLE_FIELDS) as $field) {
        $new_val = trim((string) ($proposed[$field] ?? ''));
        $old_val = trim((string) ($employee[$field] ?? ''));
        if ($new_val !== $old_val) {
            $changed = true;
            break;
        }
    }
    if (!$changed) {
        return ['ok' => false, 'message' => 'No changes detected. Update at least one field before submitting.'];
    }

    $stmt = $conn->prepare('
        INSERT INTO employee_profile_requests
        (emp_id, branch_id, proposed_email, proposed_phone, proposed_pan, proposed_bank_account, proposed_bank_ifsc, proposed_bank_name, employee_note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $email = trim((string) ($proposed['email'] ?? ''));
    $phone = trim((string) ($proposed['phone'] ?? ''));
    $pan = strtoupper(trim((string) ($proposed['pan'] ?? '')));
    $bank_account = trim((string) ($proposed['bank_account'] ?? ''));
    $bank_ifsc = strtoupper(trim((string) ($proposed['bank_ifsc'] ?? '')));
    $bank_name = trim((string) ($proposed['bank_name'] ?? ''));
    $note = trim((string) $note);
    $stmt->bind_param('sisssssss', $emp_id, $branch_id, $email, $phone, $pan, $bank_account, $bank_ifsc, $bank_name, $note);

    if (!$stmt->execute()) {
        return ['ok' => false, 'message' => 'Could not submit profile request.'];
    }

    return ['ok' => true, 'message' => 'Profile update sent to your branch admin for approval.'];
}

function create_employee_attendance_request($conn, $emp_id, $branch_id, $date, $status, $leave_type, $note, $settings)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'message' => 'Invalid date.'];
    }

    $year = (int) date('Y', strtotime($date));
    $month = (int) date('n', strtotime($date));

    if (is_payroll_period_locked($conn, $year, $month, $branch_id)) {
        return ['ok' => false, 'message' => 'That payroll month is locked. Contact your branch admin.'];
    }

    $remaining = employee_attendance_request_remaining($conn, $emp_id, $year, $month, $settings);
    if ($remaining <= 0) {
        $limit = get_attendance_requests_per_month_limit($settings);
        return ['ok' => false, 'message' => 'You have used all ' . $limit . ' manual attendance requests for ' . date('F Y', strtotime($date)) . '.'];
    }

    $allowed = ['Present', 'Absent', 'Half day', 'Leave', 'Week off'];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'message' => 'Invalid attendance status.'];
    }

    $dup = $conn->prepare("SELECT id FROM employee_attendance_requests WHERE emp_id = ? AND attendance_date = ? AND request_status = 'pending' LIMIT 1");
    $dup->bind_param('ss', $emp_id, $date);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) {
        return ['ok' => false, 'message' => 'You already have a pending request for this date.'];
    }

    if ($status === 'Leave') {
        $types = get_leave_types($conn);
        if ($leave_type === '' || !isset($types[$leave_type])) {
            $leave_type = 'CL';
        }
    } else {
        $leave_type = null;
    }

    $stmt = $conn->prepare('
        INSERT INTO employee_attendance_requests
        (emp_id, branch_id, attendance_date, status, leave_type, employee_note)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $note = trim((string) $note);
    $stmt->bind_param('sissss', $emp_id, $branch_id, $date, $status, $leave_type, $note);
    if (!$stmt->execute()) {
        return ['ok' => false, 'message' => 'Could not submit attendance request.'];
    }

    return ['ok' => true, 'message' => 'Attendance request sent to your branch admin for approval.'];
}

function count_pending_approvals_for_branch($conn, $branch_id = null)
{
    $profile_sql = "SELECT COUNT(*) AS c FROM employee_profile_requests WHERE request_status = 'pending'";
    $att_sql = "SELECT COUNT(*) AS c FROM employee_attendance_requests WHERE request_status = 'pending'";
    $types = '';
    $params = [];

    if ($branch_id !== null) {
        $profile_sql .= ' AND branch_id = ?';
        $att_sql .= ' AND branch_id = ?';
        $types = 'i';
        $params = [$branch_id];
    }

    $total = 0;
    foreach ([$profile_sql, $att_sql] as $sql) {
        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $total += (int) ($row['c'] ?? 0);
    }
    return $total;
}

function get_pending_profile_requests($conn, $branch_id = null)
{
    $sql = "
        SELECT r.*, e.name AS employee_name
        FROM employee_profile_requests r
        INNER JOIN employees e ON e.emp_id = r.emp_id
        WHERE r.request_status = 'pending'
    ";
    if ($branch_id !== null) {
        $sql .= ' AND r.branch_id = ?';
        $stmt = $conn->prepare($sql . ' ORDER BY r.created_at ASC');
        $stmt->bind_param('i', $branch_id);
    } else {
        $stmt = $conn->prepare($sql . ' ORDER BY r.created_at ASC');
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_pending_attendance_requests($conn, $branch_id = null)
{
    $sql = "
        SELECT r.*, e.name AS employee_name
        FROM employee_attendance_requests r
        INNER JOIN employees e ON e.emp_id = r.emp_id
        WHERE r.request_status = 'pending'
    ";
    if ($branch_id !== null) {
        $sql .= ' AND r.branch_id = ?';
        $stmt = $conn->prepare($sql . ' ORDER BY r.created_at ASC');
        $stmt->bind_param('i', $branch_id);
    } else {
        $stmt = $conn->prepare($sql . ' ORDER BY r.created_at ASC');
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function approve_profile_request($conn, $request_id, $reviewer, $note = '')
{
    $stmt = $conn->prepare("SELECT * FROM employee_profile_requests WHERE id = ? AND request_status = 'pending'");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    if (!$req) {
        return ['ok' => false, 'message' => 'Request not found or already processed.'];
    }

    $upd = $conn->prepare('
        UPDATE employees SET
            email = ?,
            phone = ?,
            pan = ?,
            bank_account = ?,
            bank_ifsc = ?,
            bank_name = ?
        WHERE emp_id = ?
    ');
    $upd->bind_param(
        'sssssss',
        $req['proposed_email'],
        $req['proposed_phone'],
        $req['proposed_pan'],
        $req['proposed_bank_account'],
        $req['proposed_bank_ifsc'],
        $req['proposed_bank_name'],
        $req['emp_id']
    );
    $upd->execute();

    $mark = $conn->prepare("
        UPDATE employee_profile_requests
        SET request_status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
        WHERE id = ?
    ");
    $note = trim((string) $note);
    $mark->bind_param('ssi', $reviewer, $note, $request_id);
    $mark->execute();

    return ['ok' => true, 'message' => 'Profile update approved and applied.'];
}

function reject_profile_request($conn, $request_id, $reviewer, $note = '')
{
    $stmt = $conn->prepare("
        UPDATE employee_profile_requests
        SET request_status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
        WHERE id = ? AND request_status = 'pending'
    ");
    $note = trim((string) $note);
    $stmt->bind_param('ssi', $reviewer, $note, $request_id);
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        return ['ok' => false, 'message' => 'Request not found or already processed.'];
    }
    return ['ok' => true, 'message' => 'Profile request rejected.'];
}

function approve_attendance_request($conn, $request_id, $reviewer, $note = '')
{
    $stmt = $conn->prepare("SELECT * FROM employee_attendance_requests WHERE id = ? AND request_status = 'pending'");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    if (!$req) {
        return ['ok' => false, 'message' => 'Request not found or already processed.'];
    }

    $year = (int) date('Y', strtotime($req['attendance_date']));
    $month = (int) date('n', strtotime($req['attendance_date']));
    if (is_payroll_period_locked($conn, $year, $month, (int) $req['branch_id'])) {
        return ['ok' => false, 'message' => 'Payroll period is locked. Reopen the period before approving attendance.'];
    }

    $leave_type = $req['leave_type'];
    $ot = 0;
    $ins = $conn->prepare('
        INSERT INTO attendance (emp_id, attendance_date, status, leave_type, overtime_hours)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), leave_type = VALUES(leave_type), overtime_hours = VALUES(overtime_hours)
    ');
    $ins->bind_param('ssssd', $req['emp_id'], $req['attendance_date'], $req['status'], $leave_type, $ot);
    $ins->execute();

    $mark = $conn->prepare("
        UPDATE employee_attendance_requests
        SET request_status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
        WHERE id = ?
    ");
    $note = trim((string) $note);
    $mark->bind_param('ssi', $reviewer, $note, $request_id);
    $mark->execute();

    return ['ok' => true, 'message' => 'Attendance approved and saved.'];
}

function reject_attendance_request($conn, $request_id, $reviewer, $note = '')
{
    $stmt = $conn->prepare("
        UPDATE employee_attendance_requests
        SET request_status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
        WHERE id = ? AND request_status = 'pending'
    ");
    $note = trim((string) $note);
    $stmt->bind_param('ssi', $reviewer, $note, $request_id);
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        return ['ok' => false, 'message' => 'Request not found or already processed.'];
    }
    return ['ok' => true, 'message' => 'Attendance request rejected.'];
}

function profile_request_field_diffs(array $employee, array $request)
{
    $diffs = [];
    $map = [
        'email' => 'proposed_email',
        'phone' => 'proposed_phone',
        'pan' => 'proposed_pan',
        'bank_account' => 'proposed_bank_account',
        'bank_ifsc' => 'proposed_bank_ifsc',
        'bank_name' => 'proposed_bank_name',
    ];
    foreach ($map as $field => $proposed_key) {
        $old = trim((string) ($employee[$field] ?? ''));
        $new = trim((string) ($request[$proposed_key] ?? ''));
        if ($old !== $new) {
            $diffs[] = [
                'label' => EMPLOYEE_PORTAL_EDITABLE_FIELDS[$field],
                'old' => $old !== '' ? $old : '—',
                'new' => $new !== '' ? $new : '—',
            ];
        }
    }
    return $diffs;
}
