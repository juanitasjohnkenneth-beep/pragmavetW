<?php
include __DIR__ . '/../dbconnection.php';
$conn->query("ALTER TABLE consultations ADD COLUMN IF NOT EXISTS followup_date DATE DEFAULT NULL AFTER prescription");
echo "DONE\n";
