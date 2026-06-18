<?php
require_once __DIR__ . '/../includes/employee_portal_auth.php';
require_once __DIR__ . '/../includes/csrf_helper.php';
init_employee_session();
require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: leave.php');
    exit;
}

enforce_employee_session();
require_csrf_or_redirect('leave.php');

$employee = require_logged_in_employee($conn);

$redirect = trim($_POST['redirect'] ?? 'leave.php');
if (!preg_match('/^leave\.php(\?[\w=&.-]*)?$/', $redirect)) {
    $redirect = 'leave.php';
}

$request_id = (int) ($_POST['request_id'] ?? 0);
$emp_id = $employee['emp_id'];

$stmt = $conn->prepare("SELECT * FROM employee_leave_requests WHERE id = ? AND emp_id = ?");
$stmt->bind_param('is', $request_id, $emp_id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();

if (!$req) {
    $_SESSION['emp_flash_message'] = 'Leave request not found.';
    $_SESSION['emp_flash_success'] = false;
    header('Location: ' . $redirect);
    exit;
}

if ($req['request_status'] === 'pending') {
    $upd = $conn->prepare("UPDATE employee_leave_requests SET request_status = 'cancelled' WHERE id = ?");
    $upd->bind_param('i', $request_id);
    $upd->execute();
    $_SESSION['emp_flash_message'] = 'Pending leave request cancelled.';
    $_SESSION['emp_flash_success'] = true;
} elseif ($req['request_status'] === 'approved') {
    $upd = $conn->prepare("UPDATE employee_leave_requests SET request_status = 'cancellation_pending' WHERE id = ?");
    $upd->bind_param('i', $request_id);
    $upd->execute();
    $_SESSION['emp_flash_message'] = 'Leave cancellation request sent to your branch admin for approval.';
    $_SESSION['emp_flash_success'] = true;
} else {
    $_SESSION['emp_flash_message'] = 'Cannot cancel this leave request.';
    $_SESSION['emp_flash_success'] = false;
}

header('Location: ' . $redirect);
exit;
