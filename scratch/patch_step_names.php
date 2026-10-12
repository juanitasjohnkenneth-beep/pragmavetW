<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

$pattern = '/\$step_names\s*=\s*\[\s*1\s*=>\s*\'Select Client & Pet\',\s*2\s*=>\s*\'Select Service\',\s*3\s*=>\s*\'Select Veterinarian\',\s*4\s*=>\s*\'Date, Time & Details\',\s*5\s*=>\s*\'Review & Confirm\',\s*6\s*=>\s*\'Appointment Created\'\s*\];\s*for\s*\(\$i\s*=\s*1;\s*\$i\s*<=\s*6;\s*\$i\+\+\):/s';

$replacement = '$step_names = [
                1 => \'Select Client & Pet\',
                2 => \'Select Service\',
                3 => \'Select Veterinarian\',
                4 => \'Date, Time & Details\',
                5 => \'Review & Confirm\',
                6 => \'Reservation Fee\',
                7 => \'Booking Confirmed\'
            ];
            for ($i = 1; $i <= 7; $i++):';

$content = preg_replace($pattern, $replacement, $content);

file_put_contents($file, $content);
echo "Fixed step_names array.";
?>
