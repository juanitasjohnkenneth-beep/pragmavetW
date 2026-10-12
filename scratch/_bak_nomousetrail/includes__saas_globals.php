<?php
// ===== SHARED PRAGMAVET UI =====
// Stores mousetrail.js script as a variable. dbconnection.php handles injection.

// Skip utility/API/temp files
$current_page = basename($_SERVER['SCRIPT_NAME'] ?? '');
$skip_patterns = ['api_', 'temp_', 'fix_', 'patch', 'upgrade_', 'migrate_', 'check_', 'tmp_', 'dbconnection', 'apply_theme', 'log_js_error', 'chat_widget', 'process_demo_', 'payment_demo', 'paymongo_', 'helpers_', 'create_doctor', 'inject_', 'move_widgets', 'master_calendar', 'final_fix', 'run_migration', 'setup_', 'stripe_config', 'paymongo_config', 'composer', 'test'];
foreach ($skip_patterns as $pattern) {
    if (strpos($current_page, $pattern) !== false) return;
}
if (defined('SAAS_GLOBALS_LOADED')) return;
define('SAAS_GLOBALS_LOADED', true);

// Store script tag - dbconnection.php will inject it before </body>
$GLOBALS['pragmavet_script_tag'] = '<script src="mousetrail.js?v=' . time() . '"></script>';
?>
