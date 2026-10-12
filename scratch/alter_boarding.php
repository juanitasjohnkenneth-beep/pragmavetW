<?php
include __DIR__ . '/../dbconnection.php';
$conn->query("ALTER TABLE boarding_reservations ADD COLUMN IF NOT EXISTS care_notes TEXT DEFAULT NULL AFTER admin_message");
echo "DONE\n";
