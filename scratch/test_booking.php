<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'client';
$_SESSION['user'] = 'TestUser';

$_POST['book'] = '1';
$_POST['return_appt_id'] = '1';
$_POST['pet_name'] = 'Buddy';
$_POST['service_type'] = 'General Checkup';
$_POST['appointment_date'] = date('Y-m-d', strtotime('+3 days'));
$_POST['appointment_time'] = '10:00';
$_POST['service_location'] = 'Clinic Visit';
$_POST['doctor_id'] = 2; // Make sure doctor 2 exists or remove

ob_start();
include('c:/xampp/htdocs/pragmavet/appointments.php');
$output = ob_get_clean();

echo "OUTPUT START\n";
echo substr($output, 0, 500);
echo "\n...\n";
if (preg_match('/APPT_ID:(\d+)/', $output, $matches)) {
    echo "FOUND APPT_ID: " . $matches[1] . "\n";
} else {
    echo "APPT_ID NOT FOUND IN OUTPUT.\n";
    if (isset($_SESSION['message'])) {
        echo "SESSION MESSAGE: " . $_SESSION['message'] . "\n";
    }
}
?>
