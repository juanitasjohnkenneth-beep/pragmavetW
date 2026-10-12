<?php
ob_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['user'] = 'Clinic Admin';
include __DIR__ . '/../admin_boarding.php';
$out = ob_get_clean();
echo "Rendered admin_boarding.php length: " . strlen($out) . " bytes\n";
