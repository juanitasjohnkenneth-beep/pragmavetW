<?php
ob_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'Admin';
include __DIR__ . '/../admin_users.php';
$out = ob_get_clean();
echo "Rendered length: " . strlen($out) . " bytes\n";
