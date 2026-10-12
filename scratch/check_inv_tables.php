<?php
include __DIR__ . '/../dbconnection.php';
$res = $conn->query("SHOW TABLES LIKE 'inventory%'");
while ($row = $res->fetch_array()) {
    echo $row[0] . "\n";
}
