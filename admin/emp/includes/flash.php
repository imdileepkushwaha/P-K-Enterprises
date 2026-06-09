<?php
if (!isset($_SESSION['emp_flash_message'])) {
    return;
}
?>
<div class="alert <?php echo $_SESSION['emp_flash_success'] ? 'alert-success' : 'alert-error'; ?> alert-page emp-dash-alert">
    <?php echo htmlspecialchars($_SESSION['emp_flash_message']); unset($_SESSION['emp_flash_message'], $_SESSION['emp_flash_success']); ?>
</div>
