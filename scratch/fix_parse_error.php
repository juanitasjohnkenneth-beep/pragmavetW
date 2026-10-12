<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

$broken_block = '    if ($new_date < $tomorrow) {
        $_SESSION[\'message\'] = "Reschedule dates must be booked at least 24 hours in advance. Earliest available: $tomorrow";
            exit();
        }
        header("Location: appointments.php");
        exit();
    }';

$fixed_block = '    if ($new_date < $tomorrow) {
        $_SESSION[\'message\'] = "Reschedule dates must be booked at least 24 hours in advance. Earliest available: $tomorrow";
        $_SESSION[\'msg_type\'] = "danger";
        header("Location: appointments.php");
        exit();
    }';

$content = str_replace($broken_block, $fixed_block, $content);
file_put_contents($file, $content);
echo "Fixed parse error.";
?>
