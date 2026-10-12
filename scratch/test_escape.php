<?php
$row = ['id'=>1, 'pet_name'=>"Buddy's Dog", 'service_type'=>'Consultation', 'status'=>'Approved']; 
$rowData = json_encode($row, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP); 
$escaped = htmlspecialchars($rowData, ENT_QUOTES); 
echo '<button onclick="openDetailModal(' . $escaped . ')">Test</button>';
?>
