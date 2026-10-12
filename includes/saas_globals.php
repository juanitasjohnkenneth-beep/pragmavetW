<?php
// ===== SHARED PRAGMAVET UI =====
// Turns on the shared page add-ons (Tabler styles, confirm dialogs) that dbconnection.php injects.

// Skip utility/API/temp files
$current_page = basename($_SERVER['SCRIPT_NAME'] ?? '');
$skip_patterns = ['api_', 'temp_', 'fix_', 'patch', 'upgrade_', 'migrate_', 'check_', 'tmp_', 'dbconnection', 'apply_theme', 'log_js_error', 'chat_widget', 'process_demo_', 'payment_demo', 'paymongo_', 'helpers_', 'create_doctor', 'inject_', 'move_widgets', 'master_calendar', 'final_fix', 'run_migration', 'setup_', 'stripe_config', 'paymongo_config', 'composer', 'test'];
foreach ($skip_patterns as $pattern) {
    if (strpos($current_page, $pattern) !== false) return;
}
if (defined('SAAS_GLOBALS_LOADED')) return;
define('SAAS_GLOBALS_LOADED', true);

// Enables the shared add-ons in dbconnection.php (the old paw-print mouse trail was removed)
$GLOBALS['pragmavet_script_tag'] = 'on';
?>
