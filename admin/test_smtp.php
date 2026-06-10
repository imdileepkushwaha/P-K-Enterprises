<?php
require_once 'includes/session_auth.php';
enforce_admin_session();
require_once 'includes/csrf_helper.php';
require 'config.php';
require 'includes/settings_helper.php';
require 'includes/mailer.php';

$settings = get_all_settings($conn);
$test_to = trim($_POST['test_email'] ?? $settings['smtp_from_email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_or_redirect('settings.php?tab=smtp');
    if (!is_smtp_configured($settings) || empty($settings['smtp_password'])) {
        $_SESSION['flash_message'] = 'Complete SMTP settings including password first.';
        $_SESSION['flash_success'] = false;
    } elseif ($test_to === '' || !filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_message'] = 'Enter a valid test email address.';
        $_SESSION['flash_success'] = false;
    } else {
        $html = '<h2>SMTP Test OK</h2><p>Payroll system can send emails. Time: ' . date('Y-m-d H:i:s') . '</p>';
        $result = send_email_smtp($settings, $test_to, 'Test', 'Payroll SMTP Test', $html);
        $_SESSION['flash_message'] = $result['success']
            ? "Test email sent to {$test_to}. Check inbox/spam."
            : 'Failed: ' . $result['message'];
        $_SESSION['flash_success'] = $result['success'];
    }
    header('Location: settings.php?tab=smtp');
    exit;
}

header('Location: settings.php?tab=smtp');
exit;
