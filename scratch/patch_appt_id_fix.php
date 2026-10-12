<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

$pattern = '/\$_SESSION\[\'msg_type\'\] = "success";(\s*)\} else \{/s';
$replacement = '$_SESSION[\'msg_type\'] = "success";
        
        // Return appointment ID to JS for payment step
        if (!empty($_POST[\'return_appt_id\'])) {
            echo "APPT_ID:" . $appointment_id;
            exit();
        }
    } else {';

$content = preg_replace($pattern, $replacement, $content);

file_put_contents($file, $content);
echo "Fixed backend return APPT_ID logic.";
?>
