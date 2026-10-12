<?php
// ===== TABLER.IO BOOTSTRAP INTEGRATION =====
// Loads Tabler.io framework assets and provides a compatibility layer
// that preserves the existing PragmaVet UI design.
// 
// This file is designed to be included via the auto-inject system.
// It loads Tabler.io CSS/JS locally and adds compatibility overrides
// so Tabler.io does not disrupt the existing UI.

// Prevent double-loading
if (defined('TABLER_BOOTSTRAP_LOADED')) return;
define('TABLER_BOOTSTRAP_LOADED', true);

// Only run on actual page views, not API/AJAX
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$skip_patterns = ['api_', 'temp_', 'fix_', 'patch', 'upgrade_', 'migrate_', 'check_', 'tmp_', 'test', 'dbconnection', 'apply_theme', 'log_js_error', 'chat_widget', 'process_demo_', 'payment_demo', 'paymongo_', 'helpers_', 'create_doctor', 'inject_', 'move_widgets', 'master_calendar', 'final_fix', 'run_migration', 'setup_', 'stripe_config', 'paymongo_config', 'composer'];
foreach ($skip_patterns as $pattern) {
    if (strpos($current_script, $pattern) !== false) return;
}
if (empty($_SERVER['SCRIPT_NAME']) || $current_script === '') return;

// Get the current page buffer
$html = ob_get_clean();

// ===== 1. INJECT TABLER.IO CSS BEFORE </head> =====
$tabler_head = '
    <!-- Tabler.io Bootstrap Integration -->
    <link href="assets/tabler/css/tabler.min.css" rel="stylesheet">
    <link href="assets/tabler/css/tabler-compat.css" rel="stylesheet">
';

// Only inject if not already present
if (strpos($html, 'tabler.min.css') === false) {
    $html = str_replace('</head>', $tabler_head . '</head>', $html);
}

// ===== 2. INJECT TABLER.IO JS BEFORE </body> =====
$tabler_scripts = '
    <!-- Tabler.io Bootstrap Integration -->
    <script src="assets/tabler/js/tabler.min.js"></script>
';

if (strpos($html, 'tabler.min.js') === false) {
    $html = str_replace('</body>', $tabler_scripts . "\n" . '</body>', $html);
}

echo $html;
// ===== END TABLER.IO BOOTSTRAP =====
?>