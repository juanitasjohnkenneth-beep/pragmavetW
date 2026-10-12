<?php
include 'dbconnection.php';

$r = $conn->query("DESCRIBE appointments");
$cols = [];
while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];

$needed = ['downpayment_amount', 'downpayment_status', 'downpayment_paid_at'];
foreach ($needed as $col) {
    if (!in_array($col, $cols)) {
        echo "MISSING: $col — Adding now...\n";
        if ($col === 'downpayment_amount') $conn->query("ALTER TABLE appointments ADD COLUMN downpayment_amount DECIMAL(10,2) DEFAULT 0.00");
        if ($col === 'downpayment_status') $conn->query("ALTER TABLE appointments ADD COLUMN downpayment_status VARCHAR(30) DEFAULT 'Not Paid'");
        if ($col === 'downpayment_paid_at') $conn->query("ALTER TABLE appointments ADD COLUMN downpayment_paid_at DATETIME NULL DEFAULT NULL");
        echo "  Added $col. Error: " . $conn->error . "\n";
    } else {
        echo "OK: $col\n";
    }
}
?>
