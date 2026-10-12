<?php
$c = new mysqli('localhost', 'root', '', 'vet_system');
$tables = $c->query('SHOW TABLES');
echo "=== TABLES ===\n";
while($r=$tables->fetch_row()) echo $r[0] . "\n";

echo "\n=== BILLING COLUMNS ===\n";
$cols = $c->query('DESCRIBE billing');
while($r=$cols->fetch_assoc()) echo $r['Field'] . ' - ' . $r['Type'] . "\n";

echo "\n=== APPOINTMENTS STATUS VALUES ===\n";
$r = $c->query("SELECT DISTINCT status FROM appointments");
while($row=$r->fetch_row()) echo $row[0] . "\n";

echo "\n=== BILLING PAYMENT STATUS VALUES ===\n";
$r = $c->query("SELECT DISTINCT payment_status FROM billing");
while($row=$r->fetch_row()) echo $row[0] . "\n";

echo "\n=== APPOINTMENTS COUNT ===\n";
$r = $c->query("SELECT status, COUNT(*) as cnt FROM appointments GROUP BY status");
while($row=$r->fetch_assoc()) echo $row['status'] . ': ' . $row['cnt'] . "\n";

echo "\n=== BILLING COUNT ===\n";
$r = $c->query("SELECT COUNT(*) as cnt FROM billing");
echo "Total billing rows: " . $r->fetch_assoc()['cnt'] . "\n";

echo "\n=== COMPLETED APPTS WITHOUT BILLING ===\n";
$r = $c->query("SELECT COUNT(*) as cnt FROM appointments a LEFT JOIN billing b ON b.appointment_id=a.id WHERE a.status='Completed' AND b.id IS NULL");
echo "Completed but no billing: " . $r->fetch_assoc()['cnt'] . "\n";

echo "\n=== APPROVED APPTS ===\n";
$r = $c->query("SELECT id, pet_name, service_type, appointment_date, status FROM appointments WHERE status='Approved' ORDER BY appointment_date DESC LIMIT 5");
while($row=$r->fetch_assoc()) echo $row['id'] . " | " . $row['pet_name'] . " | " . $row['status'] . " | " . $row['appointment_date'] . "\n";

echo "\n=== NOTIFICATIONS TABLE ===\n";
$r = $c->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 3");
if($r) while($row=$r->fetch_assoc()) echo json_encode($row) . "\n";
else echo "No notifications table\n";

echo "\n=== MEDICAL RECORDS TABLE ===\n";
$r = $c->query("DESCRIBE medical_records");
if($r) while($row=$r->fetch_assoc()) echo $row['Field'] . "\n";
else echo "No medical_records table\n";

echo "\n=== FEEDBACKS TABLE ===\n";
$r = $c->query("DESCRIBE feedbacks");
if($r) while($row=$r->fetch_assoc()) echo $row['Field'] . "\n";
else echo "No feedbacks table\n";
?>
