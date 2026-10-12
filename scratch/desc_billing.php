<?php
require 'dbconnection.php';
$res = $conn->query('DESCRIBE billing');
while($r = $res->fetch_assoc()) print_r($r);
?>
