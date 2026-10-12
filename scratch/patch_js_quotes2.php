<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

$content = preg_replace('/clientName:\s*"\<\?=addslashes\(\$user_name\s*\?\?\s*\'Client\'\)\?\>"/i', 'clientName: <?=json_encode($user_name ?? \'Client\')?>', $content);
$content = preg_replace('/clientPhone:\s*"\<\?=addslashes\(\$myAddr\s*\?\s*\$myAddr\s*:\s*\'Registered Client\'\)\?\>"/i', 'clientPhone: <?=json_encode($myAddr ? $myAddr : \'Registered Client\')?>', $content);
$content = preg_replace('/addr:\s*"\<\?=addslashes\(\$myAddr\s*\?\?\s*\'\'\)\?\>"/i', 'addr: <?=json_encode($myAddr ?? \'\')?>', $content);

file_put_contents($file, $content);
echo "Fixed JS string injection.";
?>
