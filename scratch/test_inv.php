<?php
ob_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'Admin';
include __DIR__ . '/../admin_inventory.php';
$out = ob_get_clean();
echo "Rendered admin_inventory.php length: " . strlen($out) . " bytes\n";
