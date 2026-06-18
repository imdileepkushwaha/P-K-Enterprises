<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require 'includes/settings_helper.php';
require 'includes/signature_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings.php');
    exit;
}

require_csrf_or_redirect('settings.php');

$section = $_POST['section'] ?? '';

if ($section === 'smtp') {
    set_setting($conn, 'smtp_host', trim($_POST['smtp_host'] ?? ''));
    set_setting($conn, 'smtp_port', trim($_POST['smtp_port'] ?? '587'));
    set_setting($conn, 'smtp_encryption', trim($_POST['smtp_encryption'] ?? 'tls'));
    set_setting($conn, 'smtp_username', trim($_POST['smtp_username'] ?? ''));
    set_setting($conn, 'smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
    set_setting($conn, 'smtp_from_name', trim($_POST['smtp_from_name'] ?? 'Payroll System'));

    $new_pass = $_POST['smtp_password'] ?? '';
    if ($new_pass !== '') {
        set_setting($conn, 'smtp_password', $new_pass);
    }

    $_SESSION['flash_message'] = 'SMTP settings saved.';
    $_SESSION['flash_success'] = true;
    header('Location: settings.php?tab=smtp');
    exit;
}

if ($section === 'password') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new !== $confirm) {
        $_SESSION['flash_message'] = 'New passwords do not match.';
        $_SESSION['flash_success'] = false;
        header('Location: settings.php?tab=password');
        exit;
    }

    if (strlen($new) < 6) {
        $_SESSION['flash_message'] = 'Password must be at least 6 characters.';
        $_SESSION['flash_success'] = false;
        header('Location: settings.php?tab=password');
        exit;
    }

    $username = $_SESSION['admin_username'];
    $stmt = $conn->prepare("SELECT password FROM admin_users WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || !password_verify($current, $row['password'])) {
        $_SESSION['flash_message'] = 'Current password is incorrect.';
        $_SESSION['flash_success'] = false;
        header('Location: settings.php?tab=password');
        exit;
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE admin_users SET password = ? WHERE username = ?");
    $stmt->bind_param('ss', $hash, $username);
    $stmt->execute();

    $_SESSION['flash_message'] = 'Password updated successfully.';
    $_SESSION['flash_success'] = true;
    header('Location: settings.php?tab=password');
    exit;
}

if ($section === 'payroll') {
    set_setting($conn, 'company_name', trim($_POST['company_name'] ?? ''));
    set_setting($conn, 'working_days_per_month', trim($_POST['working_days_per_month'] ?? '26'));
    set_setting($conn, 'signature_authority_name', trim($_POST['signature_authority_name'] ?? 'Authorized Signatory'));
    set_setting($conn, 'pct_basic', trim($_POST['pct_basic'] ?? '50'));
    set_setting($conn, 'pct_hra', trim($_POST['pct_hra'] ?? '20'));
    set_setting($conn, 'pct_conveyance', trim($_POST['pct_conveyance'] ?? '5'));
    set_setting($conn, 'pct_medical', trim($_POST['pct_medical'] ?? '5'));
    set_setting($conn, 'pct_special', trim($_POST['pct_special'] ?? '20'));
    set_setting($conn, 'pf_percent', trim($_POST['pf_percent'] ?? '12'));
    set_setting($conn, 'pf_min_limit', trim($_POST['pf_min_limit'] ?? '0'));
    set_setting($conn, 'pf_max_limit', trim($_POST['pf_max_limit'] ?? '15000'));
    set_setting($conn, 'professional_tax', trim($_POST['professional_tax'] ?? '200'));
    set_setting($conn, 'esi_percent', trim($_POST['esi_percent'] ?? '0.75'));
    set_setting($conn, 'esi_gross_limit', trim($_POST['esi_gross_limit'] ?? '21000'));
    set_setting($conn, 'leave_day_credit', trim($_POST['leave_day_credit'] ?? '1'));
    set_setting($conn, 'half_day_credit', trim($_POST['half_day_credit'] ?? '0.5'));
    set_setting($conn, 'overtime_hours_per_day', trim($_POST['overtime_hours_per_day'] ?? '8'));
    set_setting($conn, 'overtime_multiplier', trim($_POST['overtime_multiplier'] ?? '1.5'));
    set_setting($conn, 'require_payroll_approval', !empty($_POST['require_payroll_approval']) ? '1' : '0');

    $messages = ['Payroll settings saved.'];

    if (!empty($_POST['remove_signature'])) {
        remove_payslip_signature();
        set_setting($conn, 'payslip_signature', '');
        $messages[] = 'Signature removed.';
    } elseif (isset($_FILES['payslip_signature']) && $_FILES['payslip_signature']['error'] !== UPLOAD_ERR_NO_FILE) {
        $result = save_payslip_signature_upload($_FILES['payslip_signature']);
        if ($result['success']) {
            set_setting($conn, 'payslip_signature', $result['path']);
            $messages[] = 'Signature uploaded for payslip PDF.';
        } else {
            $_SESSION['flash_message'] = $result['message'];
            $_SESSION['flash_success'] = false;
            header('Location: settings.php?tab=payroll');
            exit;
        }
    }

    $_SESSION['flash_message'] = implode(' ', $messages);
    $_SESSION['flash_success'] = true;
    header('Location: settings.php?tab=payroll');
    exit;
}

if ($section === 'leave') {
    set_setting($conn, 'leave_quota_pl', trim($_POST['leave_quota_pl'] ?? '13'));
    set_setting($conn, 'leave_quota_sl', trim($_POST['leave_quota_sl'] ?? '9'));
    set_setting($conn, 'leave_quota_cl', trim($_POST['leave_quota_cl'] ?? '8'));
    set_setting($conn, 'max_leaves_per_month', trim($_POST['max_leaves_per_month'] ?? '4'));
    set_setting($conn, 'max_wo_per_month', trim($_POST['max_wo_per_month'] ?? '4'));

    $_SESSION['flash_message'] = 'Leave and attendance rules saved.';
    $_SESSION['flash_success'] = true;
    header('Location: settings.php?tab=leave');
    exit;
}

if ($section === 'admin_add') {
    if (!is_super_admin()) {
        $_SESSION['flash_message'] = 'Only Head Office can manage administrator accounts.';
        $_SESSION['flash_success'] = false;
        header('Location: settings.php?tab=admins');
        exit;
    }

    $action = $_POST['admin_action'] ?? '';

    if ($action === 'add') {
        $new_user = trim($_POST['new_username'] ?? '');
        $new_pass = $_POST['new_password'] ?? '';
        $new_branch_raw = $_POST['new_branch_id'] ?? '';
        $new_branch_id = $new_branch_raw === '0' || $new_branch_raw === '' ? null : (int) $new_branch_raw;
        if ($new_branch_id !== null && !get_branch_by_id($conn, $new_branch_id)) {
            $new_branch_id = null;
        }
        if ($new_user === '' || strlen($new_pass) < 6 || $new_branch_raw === '') {
            $_SESSION['flash_message'] = 'Username, branch, and password (min 6 characters) are required.';
            $_SESSION['flash_success'] = false;
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            if ($new_branch_id === null) {
                $stmt = $conn->prepare('INSERT INTO admin_users (username, password, branch_id) VALUES (?, ?, NULL)');
                $stmt->bind_param('ss', $new_user, $hash);
            } else {
                $stmt = $conn->prepare('INSERT INTO admin_users (username, password, branch_id) VALUES (?, ?, ?)');
                $stmt->bind_param('ssi', $new_user, $hash, $new_branch_id);
            }
            if ($stmt->execute()) {
                $_SESSION['flash_message'] = 'Admin user added.';
                $_SESSION['flash_success'] = true;
            } else {
                $_SESSION['flash_message'] = 'Could not add user (username may exist).';
                $_SESSION['flash_success'] = false;
            }
        }
        header('Location: settings.php?tab=admins');
        exit;
    }

    if ($action === 'delete') {
        $del_user = trim($_POST['delete_username'] ?? '');
        if ($del_user === $_SESSION['admin_username']) {
            $_SESSION['flash_message'] = 'You cannot delete your own account while logged in.';
            $_SESSION['flash_success'] = false;
        } elseif ($del_user !== '') {
            $stmt = $conn->prepare('DELETE FROM admin_users WHERE username = ?');
            $stmt->bind_param('s', $del_user);
            $stmt->execute();
            $_SESSION['flash_message'] = 'Admin user removed.';
            $_SESSION['flash_success'] = true;
        }
        header('Location: settings.php?tab=admins');
        exit;
    }
}

header('Location: settings.php');
exit;
