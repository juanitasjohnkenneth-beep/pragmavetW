<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

$bad_block = '$_SESSION[\'msg_type\'] = "success";
        
        // Return appointment ID to JS for payment step
        if (!empty($_POST[\'return_appt_id\'])) {
            echo "APPT_ID:" . $appointment_id;
            exit();
        }
    } else {
        $_SESSION[\'message\'] = "Could not cancel';

$good_block = '$_SESSION[\'msg_type\'] = "success";
    } else {
        $_SESSION[\'message\'] = "Could not cancel';

$content = str_replace($bad_block, $good_block, $content);
file_put_contents($file, $content);
echo "Cleaned up APPT_ID in cancel branch.";
?>
