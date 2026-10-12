<?php
$file = 'c:/xampp/htdocs/pragmavet/staff_dashboard.php';
$content = file_get_contents($file);

$start_marker = '<!-- ── TAB 2: APPOINTMENT REQUESTS TABLE ────────────────────────────────── -->';
$start_pos = strpos($content, $start_marker);
if ($start_pos === false) { die("ERROR: Start marker not found"); }

$end_pos = strpos($content, '    <!-- ── TAB 3:', $start_pos);
if ($end_pos === false) { die("ERROR: End marker not found"); }

$new_section = file_get_contents('c:/xampp/htdocs/pragmavet/scratch/appt_tab_new.html');

$content = substr($content, 0, $start_pos) . $new_section . "\n\n    " . substr($content, $end_pos);
file_put_contents($file, $content);
echo "Done!";
?>
