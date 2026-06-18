<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: approvals.php');
    exit;
}

require_csrf_or_redirect('approvals.php');

$type = $_POST['request_type'] ?? '';
$request_id = (int) ($_POST['request_id'] ?? 0);
$action = $_POST['action'] ?? '';
$note = $_POST['review_note'] ?? '';
$reviewer = $_SESSION['admin_username'] ?? 'admin';

if ($request_id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    $_SESSION['flash_message'] = 'Invalid approval request.';
    $_SESSION['flash_success'] = false;
    header('Location: approvals.php');
    exit;
}

$branch_filter = get_active_branch_id();

if ($type === 'profile') {
    $stmt = $conn->prepare('SELECT branch_id FROM employee_profile_requests WHERE id = ? AND request_status = ?');
    $pending = 'pending';
    $stmt->bind_param('is', $request_id, $pending);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || ($branch_filter !== null && (int) $row['branch_id'] !== $branch_filter)) {
        $_SESSION['flash_message'] = 'Request not found or not in your branch.';
        $_SESSION['flash_success'] = false;
        header('Location: approvals.php');
        exit;
    }
    $result = $action === 'approve'
        ? approve_profile_request($conn, $request_id, $reviewer, $note)
        : reject_profile_request($conn, $request_id, $reviewer, $note);
} elseif ($type === 'attendance') {
    $stmt = $conn->prepare('SELECT branch_id FROM employee_attendance_requests WHERE id = ? AND request_status = ?');
    $pending = 'pending';
    $stmt->bind_param('is', $request_id, $pending);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || ($branch_filter !== null && (int) $row['branch_id'] !== $branch_filter)) {
        $_SESSION['flash_message'] = 'Request not found or not in your branch.';
        $_SESSION['flash_success'] = false;
        header('Location: approvals.php');
        exit;
    }
    $result = $action === 'approve'
        ? approve_attendance_request($conn, $request_id, $reviewer, $note)
        : reject_attendance_request($conn, $request_id, $reviewer, $note);
} elseif ($type === 'leave') {
    $is_cancellation = !empty($_POST['is_cancellation']);
    $stmt = $conn->prepare('SELECT branch_id FROM employee_leave_requests WHERE id = ? AND request_status = ?');
    $status_expected = $is_cancellation ? 'cancellation_pending' : 'pending';
    $stmt->bind_param('is', $request_id, $status_expected);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || ($branch_filter !== null && (int) $row['branch_id'] !== $branch_filter)) {
        $_SESSION['flash_message'] = 'Request not found or not in your branch.';
        $_SESSION['flash_success'] = false;
        header('Location: approvals.php');
        exit;
    }
    if ($is_cancellation) {
        $result = $action === 'approve'
            ? approve_leave_cancellation($conn, $request_id, $reviewer, $note)
            : reject_leave_cancellation($conn, $request_id, $reviewer, $note);
    } else {
        $result = $action === 'approve'
            ? approve_leave_request($conn, $request_id, $reviewer, $note)
            : reject_leave_request($conn, $request_id, $reviewer, $note);
    }
} else {
    $_SESSION['flash_message'] = 'Unknown request type.';
    $_SESSION['flash_success'] = false;
    header('Location: approvals.php');
    exit;
}

$_SESSION['flash_message'] = $result['message'];
$_SESSION['flash_success'] = $result['ok'];
header('Location: approvals.php');
exit;
