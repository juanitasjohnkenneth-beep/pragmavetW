<?php
require 'dbconnection.php';
$res = $conn->query('DESCRIBE inventory');
while($r = $res->fetch_assoc()) print_r($r);
?>
