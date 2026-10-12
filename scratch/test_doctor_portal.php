<?php
ob_start();
$_SESSION['user_id'] = 2;
$_SESSION['role'] = 'doctor';
$_SESSION['fullname'] = 'Dr. Smith';
include __DIR__ . '/../doctor.php';
$out1 = ob_get_clean();
echo "doctor.php rendered: " . strlen($out1) . " bytes\n";

ob_start();
include __DIR__ . '/../doctor_consultations.php';
$out2 = ob_get_clean();
echo "doctor_consultations.php rendered: " . strlen($out2) . " bytes\n";

ob_start();
include __DIR__ . '/../doctor_patients.php';
$out3 = ob_get_clean();
echo "doctor_patients.php rendered: " . strlen($out3) . " bytes\n";

ob_start();
include __DIR__ . '/../doctor_inventory.php';
$out4 = ob_get_clean();
echo "doctor_inventory.php rendered: " . strlen($out4) . " bytes\n";

ob_start();
include __DIR__ . '/../doctor_messages.php';
$out5 = ob_get_clean();
echo "doctor_messages.php rendered: " . strlen($out5) . " bytes\n";
