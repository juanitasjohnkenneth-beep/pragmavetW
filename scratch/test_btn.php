<?php
include('c:/xampp/htdocs/pragmavet/dbconnection.php');
$stmt = $conn->query("SELECT * FROM appointments ORDER BY id DESC LIMIT 1");
$row = $stmt->fetch_assoc();
$rowData = json_encode($row, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP);
$html = '<button onclick="openDetailModal(' . htmlspecialchars($rowData, ENT_QUOTES) . ')">Test</button>';
echo $html;
?>
