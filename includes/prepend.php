<?php
// ===== AUTO PREPEND: Start output buffering to inject shared UI =====
ob_start();

// Prevent this from running on API/ajax endpoints
$skip_scripts = false;
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$api_files = ['api_appointment_slots.php', 'api_chat.php', 'api_check_slot_conflict.php', 'api_notifications.php', 'log_js_error.php', 'chat_widget.php', 'process_demo_payment.php', 'payment_demo.php', 'paymongo_test.php', 'ajax_forgot_password.php'];

if (in_array($current_script, $api_files) || strpos($current_script, 'temp_') === 0 || strpos($current_script, 'fix_') === 0 || strpos($current_script, 'patch') === 0 || strpos($current_script, 'upgrade_') === 0 || strpos($current_script, 'migrate_') === 0 || strpos($current_script, 'check_') === 0 || strpos($current_script, 'tmp_') === 0 || $current_script === 'dbconnection.php' || $current_script === 'apply_theme.php' || $current_script === 'test.php' || $current_script === 'test_db.php') {
    $skip_scripts = true;
}

if (!$skip_scripts) {
    // Buffer everything for modification
}
?>