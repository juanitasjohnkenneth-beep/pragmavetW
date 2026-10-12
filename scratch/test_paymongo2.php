<?php
include('c:/xampp/htdocs/pragmavet/dbconnection.php');
include('c:/xampp/htdocs/pragmavet/paymongo_config.php');

$protocol = 'http';
$domain = $protocol . '://localhost';
$label = "PragmaVet Reservation Fee - General Checkup for Buddy";

$result = paymongo_create_checkout(
    500.00,
    $label,
    $domain . '/pragmavet/appointments.php?pm_success=reservation&appt_id=99',
    $domain . '/pragmavet/appointments.php?pm_cancelled=1&appt_id=99',
    ['appt_id' => '99', 'type' => 'reservation']
);

if (isset($result['url'])) {
    echo "SUCCESS! PayMongo URL: " . $result['url'] . "\n";
} else {
    echo "FAILED. Response:\n";
    print_r($result);
}
?>
