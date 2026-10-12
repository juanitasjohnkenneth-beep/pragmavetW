<?php
ob_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'Admin';
include __DIR__ . '/../admin_list.php';
$out = ob_get_clean();
echo "admin_list.php rendered: " . strlen($out) . " bytes\n";
