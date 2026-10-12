<?php
$c = new mysqli('localhost', 'root', '', 'vet_system');

echo "=== APPOINTMENTS WITH NO DOCTOR ASSIGNED ===\n";
$r = $c->query("SELECT COUNT(*) as cnt FROM appointments WHERE doctor_id IS NULL AND status NOT IN ('Cancelled','Rejected')");
echo "Count: " . $r->fetch_assoc()['cnt'] . "\n";

echo "\n=== APPROVED APPTS PAST DATE (should have been Checked-in) ===\n";
$r = $c->query("SELECT id, pet_name, appointment_date, status FROM appointments WHERE status='Approved' AND appointment_date < CURDATE()");
while($row=$r->fetch_assoc()) echo $row['id'] . " | " . $row['pet_name'] . " | " . $row['appointment_date'] . "\n";

echo "\n=== CONSULTATIONS TABLE ===\n";
$r = $c->query("DESCRIBE consultations");
if($r) while($row=$r->fetch_assoc()) echo $row['Field'] . " - " . $row['Type'] . "\n";
else echo "No consultations table\n";

echo "\n=== CONSULTATIONS SAMPLE ===\n";
$r = $c->query("SELECT * FROM consultations ORDER BY id DESC LIMIT 3");
if($r) while($row=$r->fetch_assoc()) echo json_encode($row) . "\n";

echo "\n=== BILLING LINKING TO APPOINTMENTS ===\n";
$r = $c->query("SELECT b.id, b.appointment_id, b.total_amount, b.payment_status, a.status as appt_status FROM billing b LEFT JOIN appointments a ON a.id = b.appointment_id WHERE b.is_boarding=0 ORDER BY b.id DESC LIMIT 5");
while($row=$r->fetch_assoc()) echo json_encode($row) . "\n";

echo "\n=== MEDICAL RECORDS SAMPLE ===\n";
$r = $c->query("SELECT * FROM medical_records ORDER BY id DESC LIMIT 3");
if($r) while($row=$r->fetch_assoc()) echo json_encode($row) . "\n";
else echo "No records\n";

echo "\n=== PRESCRIPTION (print_rx.php exists) ===\n";
$r = $c->query("SHOW TABLES LIKE '%prescription%'");
echo "Prescription tables: " . ($r ? $r->num_rows : 0) . "\n";

echo "\n=== CERTIFICATE REQUESTS ===\n";
$r = $c->query("DESCRIBE certificate_requests");
if($r) while($row=$r->fetch_assoc()) echo $row['Field'] . "\n";
echo "\n--- sample ---\n";
$r = $c->query("SELECT * FROM certificate_requests ORDER BY id DESC LIMIT 3");
if($r) while($row=$r->fetch_assoc()) echo json_encode($row) . "\n";

echo "\n=== MONITORING TABLE ===\n";
$r = $c->query("DESCRIBE monitoring");
if($r) while($row=$r->fetch_assoc()) echo $row['Field'] . " - " . $row['Type'] . "\n";
else echo "No monitoring table\n";

echo "\n=== STAFF_DASHBOARD: check-in logic ===\n";
$r = $c->query("SELECT id, pet_name, appointment_date, status FROM appointments WHERE status='Approved' ORDER BY appointment_date ASC LIMIT 5");
while($row=$r->fetch_assoc()) echo $row['id'] . " | " . $row['pet_name'] . " | " . $row['appointment_date'] . " | " . $row['status'] . "\n";
?>
