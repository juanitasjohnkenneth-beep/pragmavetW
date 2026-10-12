<?php
include __DIR__ . '/../dbconnection.php';

$alters = [
    "ALTER TABLE inventory ADD COLUMN IF NOT EXISTS batch_number VARCHAR(100) DEFAULT NULL AFTER expiry_date",
    "ALTER TABLE inventory ADD COLUMN IF NOT EXISTS lot_number VARCHAR(100) DEFAULT NULL AFTER batch_number",
    "ALTER TABLE inventory ADD COLUMN IF NOT EXISTS unit_of_measure VARCHAR(50) DEFAULT 'piece' AFTER lot_number",
    "ALTER TABLE inventory ADD COLUMN IF NOT EXISTS reorder_level INT DEFAULT 10 AFTER unit_of_measure",
    "ALTER TABLE inventory ADD COLUMN IF NOT EXISTS supplier VARCHAR(150) DEFAULT NULL AFTER reorder_level",
    "ALTER TABLE inventory ADD COLUMN IF NOT EXISTS cost_price DECIMAL(10,2) DEFAULT 0.00 AFTER supplier",
    "ALTER TABLE inventory ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER cost_price",
];

foreach ($alters as $sql) {
    if ($conn->query($sql)) {
        echo "OK: $sql\n";
    } else {
        echo "ERR: " . $conn->error . " | $sql\n";
    }
}
echo "\nDONE. Final schema:\n";
$r = $conn->query('DESCRIBE inventory');
while ($row = $r->fetch_assoc()) {
    echo "  " . $row['Field'] . " | " . $row['Type'] . "\n";
}
