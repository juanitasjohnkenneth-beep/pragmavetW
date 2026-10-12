<?php
include __DIR__ . '/../dbconnection.php';
$res = $conn->query("DESCRIBE boarding_reservations");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . "\n";
}
