<?php
$file = 'c:/xampp/htdocs/pragmavet/admin_list.php';
$content = file_get_contents($file);

$search = '<button type="button" class="btn btn-sm btn-outline-primary rounded-circle" style="width:32px;height:32px;padding:0;" title="View Details"';

$replace = '<button type="button" class="btn btn-sm rounded-circle" style="width:32px;height:32px;padding:0; background:#2563eb; color:#fff; border:none; box-shadow:0 4px 10px rgba(37,99,235,0.35); transition:transform 0.2s;" onmouseover="this.style.transform=\'scale(1.1)\'" onmouseout="this.style.transform=\'scale(1)\'" title="View Details"';

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);
echo "Eye icon updated to solid blue with glow.";
?>
