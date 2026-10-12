<?php
$content = file_get_contents('c:/xampp/htdocs/pragmavet/appointments.php');
preg_match_all('/<script>(.*?)<\/script>/s', $content, $matches);
$js = '';
foreach ($matches[1] as $match) {
    $js .= $match . "\n";
}
file_put_contents('c:/xampp/htdocs/pragmavet/scratch/test_script.js', $js);
echo "Extracted JS to test_script.js";
?>
