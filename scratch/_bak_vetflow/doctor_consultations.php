<?php
session_start();
include("dbconnection.php");
include_once("includes/rbac_guard.php");
include_once("includes/doctor_weekly_schedule.php");

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// RBAC: Restrict strictly to doctor role
$user_role = rbac_get_role();
if($user_role !== 'doctor') {
    header("Location: " . rbac_home_url($user_role));
    exit();
}

$me = (int)$_SESSION['user_id'];

// Appointment this doctor may act on: assigned to them (or unassigned) and still Approved
function consult_get_appt($conn, $apt_id, $me) {
    $st = $conn->prepare("SELECT * FROM appointments WHERE id = ? AND status = 'Approved' AND (doctor_id = ? OR doctor_id IS NULL)");
    $st->bind_param("ii", $apt_id, $me);
    $st->execute();
    return $st->get_result()->fetch_assoc();
}

// Pet ids for an appointment (multi-pet aware): appointment_pets first, else match by owner + name
function consult_pet_ids($conn, $appt) {
    $ids = [];
    $names = [];
    $r = $conn->query("SELECT pet_id, pet_name FROM appointment_pets WHERE appointment_id = " . (int)$appt['id']);
    if ($r) while ($p = $r->fetch_assoc()) {
        if ($p['pet_id']) $ids[] = (int)$p['pet_id']; else $names[] = $p['pet_name'];
    }
    if (!$ids && !$names) $names = array_map('trim', explode(',', $appt['pet_name']));
    if ($names) {
        $st = $conn->prepare("SELECT id FROM pets WHERE user_id = ? AND name = ? LIMIT 1");
        foreach ($names as $nm) {
            $st->bind_param("is", $appt['user_id'], $nm);
            $st->execute();
            if ($row = $st->get_result()->fetch_assoc()) $ids[] = (int)$row['id'];
        }
    }
    return array_values(array_unique($ids));
}

// Edit Rx (before printing) — only this doctor's consultations
if (isset($_POST['save_rx'])) {
    header('Content-Type: application/json');
    $cid = (int)($_POST['consult_id'] ?? 0);
    $own = $conn->prepare("SELECT c.id, c.appointment_id FROM consultations c JOIN appointments a ON a.id = c.appointment_id
                           WHERE c.id = ? AND (a.doctor_id = ? OR (a.doctor_id IS NULL AND c.vet_name = (SELECT fullname FROM users WHERE id = ?)))");
    $own->bind_param("iii", $cid, $me, $me);
    $own->execute();
    $c = $own->get_result()->fetch_assoc();

    $diagnosis    = trim($_POST['diagnosis'] ?? '');
    $treatment    = trim($_POST['treatment'] ?? '');
    $prescription = trim($_POST['prescription'] ?? '');
    $followup     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['followup_date'] ?? '') ? $_POST['followup_date'] : null;

    if (!$c) {
        echo json_encode(['ok' => false, 'error' => 'Record not found or not yours to edit.']);
    } elseif ($diagnosis === '' || $treatment === '') {
        echo json_encode(['ok' => false, 'error' => 'Diagnosis and treatment are required.']);
    } else {
        $up = $conn->prepare("UPDATE consultations SET diagnosis=?, treatment=?, prescription=?, followup_date=? WHERE id=?");
        $up->bind_param("ssssi", $diagnosis, $treatment, $prescription, $followup, $cid);
        $ok = $up->execute();

        // Keep the matching EMR entries in sync
        $emr_notes = 'Treatment: ' . $treatment
                   . ($prescription !== '' ? "\nPrescription: " . $prescription : '')
                   . ($followup ? "\nFollow-up: " . date('M d, Y', strtotime($followup)) : '');
        $em = $conn->prepare("UPDATE medical_records SET description=?, notes=? WHERE appointment_id=? AND record_type='checkup'");
        $em->bind_param("ssi", $diagnosis, $emr_notes, $c['appointment_id']);
        $em->execute();

        echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Could not save changes.']);
    }
    exit();
}

// Default fee for a service (same rules as the client billing page)
function consult_base_fee($service) {
    global $conn;
    include_once(__DIR__ . "/includes/service_prices.php");
    return sp_price($conn, $service); // price set by the admin in System Settings
}

// Quick clinical summary for each pet in an appointment
function consult_pet_summary($conn, $appt) {
    $out = [];
    $soon = date('Y-m-d', strtotime('+14 days'));
    foreach (consult_pet_ids($conn, $appt) as $pid) {
        $p = $conn->query("SELECT id, name, species, breed, age, weight, medical_history FROM pets WHERE id = $pid")->fetch_assoc();
        if (!$p) continue;
        $p['last_visit'] = $conn->query("SELECT record_date, title, doctor_name FROM medical_records WHERE pet_id = $pid ORDER BY record_date DESC, id DESC LIMIT 1")->fetch_assoc();
        $p['vaccines_due'] = [];
        $vr = $conn->query("SELECT v.vaccine_name, v.next_due FROM vaccination_records v
                            WHERE v.pet_id = $pid AND v.next_due IS NOT NULL AND v.next_due <= '$soon'
                              AND v.id = (SELECT v2.id FROM vaccination_records v2 WHERE v2.pet_id = v.pet_id AND v2.vaccine_name = v.vaccine_name ORDER BY v2.date_given DESC, v2.id DESC LIMIT 1)
                            ORDER BY v.next_due ASC");
        if ($vr) while ($v = $vr->fetch_assoc()) $p['vaccines_due'][] = $v;
        $out[] = $p;
    }
    return $out;
}

// Add Consultation logic
if(isset($_POST['save_consultation'])) {
    $apt_id       = (int)$_POST['appointment_id'];
    $appt         = consult_get_appt($conn, $apt_id, $me);
    if (!$appt) {
        $_SESSION['message'] = 'Appointment not found, already completed, or assigned to another doctor.';
        $_SESSION['msg_type'] = 'danger';
        header("Location: doctor_consultations.php");
        exit();
    }
    // Attending vet = doctor assigned to the appointment (fallback: the doctor completing it)
    $vq = $conn->prepare("SELECT COALESCE(d.fullname, me.fullname) AS vet_name
                          FROM appointments a
                          LEFT JOIN users d  ON d.id = a.doctor_id
                          LEFT JOIN users me ON me.id = ?
                          WHERE a.id = ?");
    $vq->bind_param("ii", $_SESSION['user_id'], $apt_id);
    $vq->execute();
    $vet_name = $vq->get_result()->fetch_assoc()['vet_name'] ?? ($_SESSION['user'] ?? '');
    $diagnosis    = trim($_POST['diagnosis']);
    $treatment    = trim($_POST['treatment']);
    $prescription = trim($_POST['prescription']);
    $followup     = !empty($_POST['followup_date']) ? $_POST['followup_date'] : NULL;
    if ($followup && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $followup) || $followup <= date('Y-m-d'))) $followup = NULL;

    // Charges: service fee + extra procedures/medicines
    $fee_name  = trim($_POST['fee_name'] ?? '') ?: ($appt['service_type'] ?: 'Consultation');
    $fee_price = max(0, round((float)($_POST['fee_price'] ?? consult_base_fee($appt['service_type'])), 2));
    $extra_items = [];
    foreach (($_POST['item_name'] ?? []) as $i => $nm) {
        $nm = trim($nm);
        $qty = max(1, (int)($_POST['item_qty'][$i] ?? 1));
        $price = max(0, round((float)($_POST['item_price'][$i] ?? 0), 2));
        if ($nm === '') continue;
        $extra_items[] = ['name' => mb_substr($nm, 0, 120), 'qty' => $qty, 'price' => $price];
    }

    // Optional: book the follow-up as a Pending appointment with this doctor (staff approve it)
    $book_followup = !empty($_POST['book_followup']);
    $fu_time = $_POST['followup_time'] ?? '';
    if ($book_followup) {
        $fu_err = '';
        if (!$followup) {
            $fu_err = 'Choose a future follow-up date to book it.';
        } elseif (!preg_match('/^\d{2}:\d{2}$/', $fu_time)) {
            $fu_err = 'Choose a time for the follow-up appointment.';
        } else {
            $fu_s = substr($fu_time, 0, 5);
            $fu_e = date('H:i', strtotime($fu_s . ' +30 minutes'));
            $day  = dws_get_day($conn, $me, $followup);
            $hol  = $conn->prepare("SELECT 1 FROM clinic_holidays WHERE holiday_date = ? AND is_active = 1");
            $hol->bind_param("s", $followup); $hol->execute();
            $lv   = $conn->prepare("SELECT 1 FROM doctor_unavailability WHERE doctor_id = ? AND unavailable_date = ?");
            $lv->bind_param("is", $me, $followup); $lv->execute();
            $cf   = $conn->prepare("SELECT 1 FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND status IN ('Pending','Approved')
                                    AND appointment_time < ? AND ADDTIME(appointment_time, '00:30:00') > ?");
            $fu_e_full = $fu_e . ':00'; $fu_s_full = $fu_s . ':00';
            $cf->bind_param("isss", $me, $followup, $fu_e_full, $fu_s_full); $cf->execute();

            if ($hol->get_result()->num_rows)            $fu_err = 'The follow-up date is a clinic holiday.';
            elseif (!$day['is_working'])                 $fu_err = 'You do not work on ' . date('l', strtotime($followup)) . 's (see your Weekly Schedule).';
            elseif ($lv->get_result()->num_rows)         $fu_err = 'You are on leave on the follow-up date.';
            elseif ($fu_s < $day['work_start'] || $fu_e > $day['work_end'])
                                                         $fu_err = 'Follow-up time is outside your hours (' . $day['work_start'] . '–' . $day['work_end'] . ').';
            elseif ($day['break_start'] && $fu_s < $day['break_end'] && $fu_e > $day['break_start'])
                                                         $fu_err = 'Follow-up time overlaps your break.';
            elseif ($cf->get_result()->num_rows)         $fu_err = 'You already have an appointment at that follow-up time.';
        }
        if ($fu_err) {
            $_SESSION['message'] = 'Consultation not saved. ' . $fu_err;
            $_SESSION['msg_type'] = 'danger';
            header("Location: doctor_consultations.php");
            exit();
        }
    }
    
    $lab_url = NULL;
    if(isset($_FILES['lab_result']) && $_FILES['lab_result']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['lab_result']['name'], PATHINFO_EXTENSION));
        if(in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
            $lab_url = uniqid('lab_') . '.' . $ext;
            if(!file_exists('uploads/labs/')) {
                mkdir('uploads/labs/', 0777, true);
            }
            move_uploaded_file($_FILES['lab_result']['tmp_name'], 'uploads/labs/' . $lab_url);
        }
    }

    // Insert to consultations
    $stmt = $conn->prepare("INSERT INTO consultations(appointment_id, vet_name, diagnosis, treatment, prescription, followup_date, lab_results_url) VALUES(?,?,?,?,?,?,?)");
    $stmt->bind_param("issssss", $apt_id, $vet_name, $diagnosis, $treatment, $prescription, $followup, $lab_url);
    
    if($stmt->execute()){
        $new_consult_id = $conn->insert_id;

        // Mark appointment as Completed
        $conn->query("UPDATE appointments SET status='Completed' WHERE id=$apt_id");

        // Add the visit to each pet's medical record (EMR)
        $emr_notes = 'Treatment: ' . $treatment
                   . ($prescription !== '' ? "\nPrescription: " . $prescription : '')
                   . ($followup ? "\nFollow-up: " . date('M d, Y', strtotime($followup)) : '');
        $emr_title = $appt['service_type'] ?: 'Consultation';
        $emr_date  = date('Y-m-d');
        $emr = $conn->prepare("INSERT INTO medical_records (pet_id, appointment_id, record_type, title, description, doctor_name, record_date, status, notes) VALUES (?, ?, 'checkup', ?, ?, ?, ?, 'Completed', ?)");
        foreach (consult_pet_ids($conn, $appt) as $pid) {
            $emr->bind_param("iisssss", $pid, $apt_id, $emr_title, $diagnosis, $vet_name, $emr_date, $emr_notes);
            $emr->execute();
        }

        // Invoice: update the unpaid bill, or add a new bill for extras if it was already paid
        $client = $conn->query("SELECT fullname, contact_number FROM users WHERE id = " . (int)$appt['user_id'])->fetch_assoc();
        $fee_item = ['name' => $fee_name, 'qty' => 1, 'price' => $fee_price];
        $bills = $conn->query("SELECT id, payment_status FROM billing WHERE appointment_id = $apt_id AND COALESCE(payment_status,'') <> 'Void' ORDER BY id ASC");
        $unpaid = null; $paid = false;
        if ($bills) while ($b = $bills->fetch_assoc()) {
            if ($b['payment_status'] === 'Paid') $paid = true; elseif (!$unpaid) $unpaid = $b;
        }
        $items = $paid ? $extra_items : array_merge([$fee_item], $extra_items);
        $bill_total = 0;
        foreach ($items as $it) $bill_total += $it['qty'] * $it['price'];
        $items_json = json_encode($items, JSON_UNESCAPED_UNICODE);
        $c_name = $client['fullname'] ?? ''; $c_phone = $client['contact_number'] ?? '';
        $bill_note = '';

        if ($unpaid) {
            $ub = $conn->prepare("UPDATE billing SET items_json=?, total_amount=?, user_id=?, client_name=?, client_phone=?, pet_name=? WHERE id=?");
            $ub->bind_param("sdisssi", $items_json, $bill_total, $appt['user_id'], $c_name, $c_phone, $appt['pet_name'], $unpaid['id']);
            $ub->execute();
            $bill_note = ' Invoice updated: ₱' . number_format($bill_total, 2) . '.';
        } elseif ($items) {
            $ib = $conn->prepare("INSERT INTO billing (appointment_id, user_id, client_name, client_phone, pet_name, service_category, items_json, total_amount, payment_status, is_boarding, downpayment_status)
                                  VALUES (?, ?, ?, ?, ?, 'Clinic', ?, ?, 'Unpaid', 0, 'Unpaid')");
            $ib->bind_param("iissssd", $apt_id, $appt['user_id'], $c_name, $c_phone, $appt['pet_name'], $items_json, $bill_total);
            $ib->execute();
            $bid = $conn->insert_id;
            $conn->query("UPDATE billing SET invoice_no = 'INV-" . date('Y') . "-" . str_pad($bid, 5, '0', STR_PAD_LEFT) . "' WHERE id = $bid");
            $bill_note = ($paid ? ' Additional invoice' : ' Invoice') . ' created: ₱' . number_format($bill_total, 2) . '.';
        }

        // Follow-up appointment (Pending — staff approve)
        $fu_note = '';
        if ($book_followup && $followup) {
            $fu_t = substr($fu_time, 0, 5) . ':00';
            $fa = $conn->prepare("INSERT INTO appointments (user_id, pet_name, is_multi_pet, service_type, appointment_date, appointment_time, status, service_location, doctor_id, admin_message)
                                  VALUES (?, ?, ?, 'Follow-up Checkup', ?, ?, 'Pending', 'On-Site', ?, ?)");
            $fu_msg = 'Follow-up booked by ' . $vet_name . ' after consultation on ' . date('M d, Y') . '.';
            $multi = (int)($appt['is_multi_pet'] ?? 0);
            $fa->bind_param("isissis", $appt['user_id'], $appt['pet_name'], $multi, $followup, $fu_t, $me, $fu_msg);
            if ($fa->execute()) {
                $new_appt = $conn->insert_id;
                $conn->query("INSERT INTO appointment_pets (appointment_id, pet_id, pet_name, pet_picture)
                              SELECT $new_appt, pet_id, pet_name, pet_picture FROM appointment_pets WHERE appointment_id = $apt_id");
                $fu_note = ' Follow-up requested for ' . date('M d', strtotime($followup)) . ' at ' . date('g:i A', strtotime($fu_t)) . ' (pending staff approval).';
            }
        }

        // Followup notification if set
        if(!empty($followup)) {
            $apt_info = $conn->query("SELECT user_id, pet_name FROM appointments WHERE id=$apt_id")->fetch_assoc();
            if($apt_info) {
                $uid = $apt_info['user_id'];
                $pname = $apt_info['pet_name'];
                $fdate = date("M d, Y", strtotime($followup));
                $notif = $book_followup
                    ? "A follow-up visit for <b>$pname</b> was requested on <b>$fdate</b> at <b>" . date('g:i A', strtotime($fu_time)) . "</b>. The clinic will confirm it."
                    : "Recommended follow-up visit for <b>$pname</b> on <b>$fdate</b>.";
                $ins_n = $conn->prepare("INSERT INTO notifications(user_id, message) VALUES(?, ?)");
                $ins_n->bind_param("is", $uid, $notif);
                $ins_n->execute();
            }
        }
        
        $_SESSION['message'] = "Consultation recorded!" . htmlspecialchars($bill_note . $fu_note) . " <a href='print_rx.php?id=$new_consult_id' target='_blank' class='btn btn-sm btn-light fw-bold ms-2'><i class='fa fa-print me-1'></i>Print Rx Slip</a>";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error saving consultation: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: doctor_consultations.php");
    exit();
}

// Fetch Approved Appointments (Pending Consultations)
$active_query = "
    SELECT a.*, u.fullname as client_name, d.fullname as doctor_name
    FROM appointments a 
    JOIN users u ON a.user_id = u.id 
    LEFT JOIN users d ON d.id = a.doctor_id
    WHERE a.status='Approved' AND a.service_type != 'Pet Boarding Reservation'
      AND (a.doctor_id = $me OR a.doctor_id IS NULL)
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
";
$active_apts = $conn->query($active_query);

// Group the queue: overdue / today / upcoming
$today = date('Y-m-d');
$queue = ['overdue' => [], 'today' => [], 'upcoming' => []];
while ($r = $active_apts->fetch_assoc()) {
    $k = $r['appointment_date'] < $today ? 'overdue' : ($r['appointment_date'] === $today ? 'today' : 'upcoming');
    $r['pet_summary'] = consult_pet_summary($conn, $r);
    $queue[$k][] = $r;
}

$price_list = [];
$pl = $conn->query("SELECT item_name, price_per_unit FROM inventory WHERE price_per_unit > 0 ORDER BY item_name ASC");
if ($pl) while ($p = $pl->fetch_assoc()) $price_list[] = $p;
$queue_total = count($queue['overdue']) + count($queue['today']) + count($queue['upcoming']);

// Fetch Recent Consultations
$recent_query = "
    SELECT c.*, a.pet_name, a.service_type, u.fullname as client_name
    FROM consultations c
    JOIN appointments a ON c.appointment_id = a.id
    JOIN users u ON a.user_id = u.id
    WHERE a.doctor_id = $me OR (a.doctor_id IS NULL AND c.vet_name = (SELECT fullname FROM users WHERE id = $me))
    ORDER BY c.created_at DESC LIMIT 10
";
$recent_consults = $conn->query($recent_query);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Consultations | VetAdmin</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --primary: #2563eb;
    --secondary: #475569;
    --dark: #0f172a;
    --light-bg: #f8fafc;
    --sidebar-width:260px;
}
body { background: #f8fafc !important; font-family: 'Inter', sans-serif; color: #334155; }

.main-content {
    margin-left: 260px !important;
    width: calc(100% - 260px) !important;
    max-width: calc(100% - 260px) !important;
    padding: 24px 28px !important;
    min-height: 100vh;
    box-sizing: border-box !important;
}

@media (max-width: 1200px) {
    .main-content { padding: 20px 20px !important; }
}

@media (max-width: 768px) {
    .main-content { margin-left: 0 !important; width: 100% !important; max-width: 100% !important; padding: 16px !important; }
    .sidebar { position: relative !important; width: 100% !important; height: auto !important; }
}

.card-premium {
    background: white; border: none; border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.04); padding: 24px; margin-bottom: 24px;
}
.form-control, .form-select { border-radius: 12px; border: 1px solid #e2e8f0; padding: 10px 14px; }
.table-custom thead th { background: #f8fafc; color: #64748b; text-transform: uppercase; font-size: 11px; font-weight: 700; border-bottom: 2px solid #e2e8f0; }

/* ====== WHITE SIDEBAR THEME ====== */
.sidebar {
    background: #ffffff !important;
    width: var(--sidebar-width, 260px) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    padding: 28px 20px 24px !important;
    z-index: 1000 !important;
    display: flex !important;
    flex-direction: column !important;
    border-right: 1px solid #eef2f6 !important;
    box-shadow: none !important;
    overflow-y: auto !important;
}

.sidebar-brand {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    margin-bottom: 24px !important;
    padding: 0 4px !important;
    text-decoration: none !important;
}

.sidebar-brand-icon {
    width: 44px !important;
    height: 44px !important;
    min-width: 44px !important;
    border-radius: 13px !important;
    background: #2563eb !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    color: #ffffff !important;
    font-size: 20px !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28) !important;
}

.sidebar-brand span {
    font-weight: 800 !important;
    color: #182032 !important;
    font-size: 22px !important;
    letter-spacing: -0.4px !important;
}

.sidebar-user {
    margin-bottom: 24px !important;
    padding: 15px 18px !important;
    border-radius: 14px !important;
    background: #f8fafc !important;
    border: 1px solid #eef2f6 !important;
}

.sidebar-user .welcome {
    font-size: 13px !important;
    color: #64748b !important;
    font-weight: 500 !important;
    line-height: 1.3 !important;
}

.sidebar-user .uname {
    font-weight: 700 !important;
    color: #0f172a !important;
    font-size: 15.5px !important;
    margin-top: 3px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

.sidebar a {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    padding: 12px 16px !important;
    border-radius: 12px !important;
    color: #334155 !important;
    text-decoration: none !important;
    margin-bottom: 4px !important;
    font-weight: 600 !important;
    font-size: 14.5px !important;
    transition: all 0.2s ease !important;
    letter-spacing: -0.1px !important;
}

.sidebar a i {
    width: 22px !important;
    text-align: center !important;
    font-size: 16px !important;
    color: #64748b !important;
    transition: color 0.2s !important;
    flex-shrink: 0 !important;
}

.sidebar a:hover {
    background: #f8fafc !important;
    color: #0f172a !important;
}

.sidebar a:hover i {
    color: #2563eb !important;
}

.sidebar a.active {
    background: #2563eb !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28) !important;
}

.sidebar a.active i {
    color: #ffffff !important;
}

.sidebar .sidebar-logout {
    margin-top: auto !important;
    padding-top: 20px !important;
    border-top: 1px solid #f1f5f9 !important;
}

.sidebar .sidebar-logout a {
    color: #dc2626 !important;
    font-weight: 600 !important;
    margin-bottom: 0 !important;
}

.sidebar .sidebar-logout a i {
    color: #dc2626 !important;
}

.sidebar .sidebar-logout a:hover {
    background: #fef2f2 !important;
    color: #b91c1c !important;
}

.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.06); border-radius: 10px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }
</style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<?php if(isset($_SESSION['message'])): ?>
<div class="position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> shadow-sm">
        <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['msg_type']); ?>
    </div>
</div>
<?php endif; ?>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon"><i class="fa fa-user-md"></i></div>
        <span>PragmaVet</span>
    </div>
    <div class="sidebar-user">
        <div class="welcome">Welcome Doctor,</div>
        <div class="uname"><?php echo htmlspecialchars($_SESSION['user'] ?? $_SESSION['fullname'] ?? 'Veterinarian'); ?></div>
    </div>
    <a href="doctor.php" class=""><i class="fa fa-calendar-check"></i> <span>Schedule & Availability</span></a>
    <a href="doctor_consultations.php" class="active"><i class="fa fa-stethoscope"></i> <span>Active Consultations</span></a>
    <a href="doctor_patients.php" class=""><i class="fa fa-paw"></i> <span>Patient EMR Lookup</span></a>
    <a href="doctor_inventory.php" class=""><i class="fa fa-boxes"></i> <span>Pharmacy Stock</span></a>
    <div class="sidebar-logout">
        <a href="logout.php"><i class="fa fa-sign-out-alt"></i> <span>Logout</span></a>
    </div>
</div>

<?php include("includes/staff_bell.php"); ?>
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold m-0 text-dark">Active Consultations</h2>
            <p class="text-muted mb-0">Record clinical diagnoses, treatments, lab results, and pharmacy prescriptions.</p>
        </div>
    </div>

    <div class="row">
        <!-- PENDING CONSULTATIONS -->
        <div class="col-xl-5">
            <div class="card-premium border-top border-4 border-primary">
                <h5 class="fw-bold mb-4"><i class="fa fa-clipboard-list text-primary me-2"></i> Ready for Consultation</h5>
                
                <?php if($queue_total > 0): ?>
                    <?php
                    $groups = [
                        'overdue'  => ['Overdue', 'fa-triangle-exclamation', '#dc2626', 'Past their date and never completed. Staff handle No Shows and rescheduling.'],
                        'today'    => ['Today', 'fa-sun', '#2563eb', ''],
                        'upcoming' => ['Upcoming', 'fa-calendar-days', '#64748b', ''],
                    ];
                    foreach ($groups as $gk => $g):
                        if (empty($queue[$gk])) continue; ?>
                    <div class="d-flex align-items-center gap-2 mt-2 mb-2">
                        <i class="fa <?= $g[1] ?>" style="color:<?= $g[2] ?>;"></i>
                        <span class="fw-bold text-uppercase" style="font-size:12px;letter-spacing:.06em;color:<?= $g[2] ?>;"><?= $g[0] ?></span>
                        <span class="badge rounded-pill" style="background:<?= $g[2] ?>1a;color:<?= $g[2] ?>;"><?= count($queue[$gk]) ?></span>
                    </div>
                    <?php if ($g[3]): ?><p class="small text-muted mb-2"><?= $g[3] ?></p><?php endif; ?>
                    <div class="list-group mb-3">
                    <?php foreach ($queue[$gk] as $row): ?>
                        <div class="list-group-item list-group-item-action p-3 mb-2 border rounded" <?= $gk === 'overdue' ? 'style="border-left:4px solid #dc2626 !important;"' : '' ?>>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($row['pet_name']); ?></h6>
                                <span class="badge <?= $gk === 'overdue' ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-light text-dark border' ?>">
                                    <?php echo date("M d", strtotime($row['appointment_date'])); ?> · <?php echo date("g:i A", strtotime($row['appointment_time'])); ?>
                                </span>
                            </div>
                            <p class="mb-2 text-muted small"><i class="fa fa-tag me-1"></i> <?php echo htmlspecialchars($row['service_type']); ?>
                                <?php if (!$row['doctor_id']): ?><span class="badge bg-warning-subtle text-warning-emphasis ms-1">Unassigned</span><?php endif; ?>
                            </p>
                            <button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#consultModal<?php echo $row['id']; ?>">
                                Add Medical Logic
                            </button>
                        </div>

                        <!-- MODAL FOR THIS APT -->
                        <div class="modal fade" id="consultModal<?php echo $row['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content border-0 shadow">
                                    <div class="modal-header bg-light">
                                        <h5 class="fw-bold mb-0">Consultation: <?php echo htmlspecialchars($row['pet_name']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="appointment_id" value="<?php echo $row['id']; ?>">

                                            <!-- PET SUMMARY -->
                                            <?php if (!empty($row['pet_summary'])): ?>
                                            <div class="mb-3 p-3 rounded-3 border" style="background:#f8fafc;">
                                                <div class="fw-bold small text-uppercase text-secondary mb-2" style="letter-spacing:.05em;"><i class="fa fa-notes-medical me-1"></i> Patient Summary</div>
                                                <?php foreach ($row['pet_summary'] as $ps): ?>
                                                <div class="<?= count($row['pet_summary']) > 1 ? 'mb-2 pb-2 border-bottom' : '' ?>">
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($ps['name']) ?>
                                                        <span class="fw-normal text-muted small">· <?= htmlspecialchars($ps['species'] ?: 'Pet') ?><?= $ps['breed'] ? ' (' . htmlspecialchars($ps['breed']) . ')' : '' ?>
                                                        <?= $ps['age'] !== null ? '· ' . (int)$ps['age'] . ' yr' : '' ?><?= $ps['weight'] ? ' · ' . htmlspecialchars($ps['weight']) . ' kg' : '' ?></span>
                                                    </div>
                                                    <?php if (trim((string)$ps['medical_history']) !== ''): ?>
                                                        <div class="small mt-1 px-2 py-1 rounded" style="background:#fef2f2;color:#991b1b;"><i class="fa fa-triangle-exclamation me-1"></i><b>History / Allergies:</b> <?= htmlspecialchars($ps['medical_history']) ?></div>
                                                    <?php endif; ?>
                                                    <div class="small text-muted mt-1"><i class="fa fa-clock-rotate-left me-1"></i><b>Last visit:</b>
                                                        <?= $ps['last_visit'] ? date('M d, Y', strtotime($ps['last_visit']['record_date'])) . ' — ' . htmlspecialchars($ps['last_visit']['title']) . ($ps['last_visit']['doctor_name'] ? ' (' . htmlspecialchars($ps['last_visit']['doctor_name']) . ')' : '') : 'No previous records' ?>
                                                    </div>
                                                    <?php if ($ps['vaccines_due']): ?>
                                                    <div class="small mt-1"><i class="fa fa-syringe me-1 text-warning"></i><b>Vaccines due:</b>
                                                        <?php foreach ($ps['vaccines_due'] as $vd): $od = $vd['next_due'] < date('Y-m-d'); ?>
                                                            <span class="badge <?= $od ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning-emphasis' ?> me-1"><?= htmlspecialchars($vd['vaccine_name']) ?> — <?= $od ? 'overdue since ' : 'due ' ?><?= date('M d', strtotime($vd['next_due'])) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold small">ATTENDING VETERINARIAN</label>
                                                <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($row['doctor_name'] ?: ($_SESSION['user'] ?? '')); ?>" readonly tabindex="-1" style="cursor:not-allowed;">
                                                <small class="text-muted"><i class="fa fa-lock me-1"></i><?php echo $row['doctor_name'] ? 'Assigned veterinarian for this appointment.' : 'No vet was assigned; recorded under your name.'; ?></small>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold small">DIAGNOSIS</label>
                                                <textarea name="diagnosis" class="form-control" rows="3" required></textarea>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold small">TREATMENT PLAN</label>
                                                <textarea name="treatment" class="form-control" rows="3" required></textarea>
                                            </div>
                                            
                                            <div class="p-3 mb-3" style="background: rgba(43,108,176,0.05); border: 1px dashed rgba(43,108,176,0.3); border-radius: 8px;">
                                                <label class="form-label fw-bold small text-secondary"><i class="fa fa-file-pdf me-1"></i> ATTACH LAB RESULTS / X-RAY</label>
                                                <input type="file" name="lab_result" class="form-control" accept=".pdf, .jpg, .jpeg, .png">
                                                <small class="text-muted d-block mt-1">Accepts PDF or Image files.</small>
                                            </div>

                                            <div class="row g-2 mb-3">
                                                <div class="col-md-7">
                                                    <label class="form-label fw-bold small text-dark">PRESCRIPTION TEXT</label>
                                                    <textarea name="prescription" class="form-control" rows="2" placeholder="Brief textual description of medications..."></textarea>
                                                </div>
                                                <div class="col-md-5">
                                                    <label class="form-label fw-bold small text-dark">RECOMMENDED FOLLOW-UP</label>
                                                    <input type="date" name="followup_date" class="form-control" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                                                    <div class="form-check mt-2">
                                                        <input class="form-check-input fu-book" type="checkbox" name="book_followup" value="1" id="fuBook<?= (int)$row['id'] ?>">
                                                        <label class="form-check-label small" for="fuBook<?= (int)$row['id'] ?>">Book this follow-up appointment</label>
                                                    </div>
                                                    <div class="fu-time mt-2" style="display:none;">
                                                        <input type="time" name="followup_time" class="form-control form-control-sm" step="1800" value="09:00">
                                                        <small class="text-muted">Checked against your weekly schedule. Staff will confirm it.</small>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- CHARGES -->
                                            <div class="p-3 mb-4 rounded-3 border" style="background:#f8fafc;">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <label class="form-label fw-bold small m-0"><i class="fa fa-file-invoice-dollar me-1 text-primary"></i> CHARGES (added to the client's invoice)</label>
                                                    <button type="button" class="btn btn-sm btn-link text-decoration-none fw-semibold p-0" onclick="addChargeRow(this)"><i class="fa fa-plus me-1"></i>Add item</button>
                                                </div>
                                                <table class="table table-sm align-middle mb-2 charges-table" style="font-size:13px;">
                                                    <thead><tr class="text-muted small"><th>Item</th><th style="width:90px;">Qty</th><th style="width:130px;">Price (₱)</th><th style="width:32px;"></th></tr></thead>
                                                    <tbody>
                                                        <tr>
                                                            <td><input type="text" name="fee_name" class="form-control form-control-sm" value="<?= htmlspecialchars($row['service_type'] ?: 'Consultation') ?>" required></td>
                                                            <td class="text-muted text-center">1</td>
                                                            <td><input type="number" name="fee_price" class="form-control form-control-sm text-end charge-price" data-qty="1" min="0" step="0.01" value="<?= number_format(consult_base_fee($row['service_type']), 2, '.', '') ?>" oninput="updateChargeTotal(this)" required></td>
                                                            <td></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <small class="text-muted">Pick a medicine from the list to fill its price. Stock is not deducted.</small>
                                                    <div class="fw-bold">Total: ₱<span class="charge-total"><?= number_format(consult_base_fee($row['service_type']), 2) ?></span></div>
                                                </div>
                                            </div>

                                            <button type="submit" name="save_consultation" class="btn btn-primary w-100 py-3 fw-bold rounded-pill shadow-sm">Save & Complete Appointment</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-4">No approved appointments waiting for consultation.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- RECENT CONSULTATIONS LOG -->
        <div class="col-xl-7">
            <div class="card-premium">
                <h5 class="fw-bold mb-4"><i class="fa fa-history text-secondary me-2"></i> Recent Clinical Records</h5>
                
                <?php if($recent_consults->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-custom table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Pet & Vet</th>
                                <th>Diagnosis</th>
                                <th>Resources</th>
                                <th>Date</th>
                                <th class="text-end">Rx Slip</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $recent_consults->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['pet_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['vet_name']); ?></div>
                                </td>
                                <td><span class="text-truncate d-inline-block" style="max-width: 140px;" title="<?php echo htmlspecialchars($row['diagnosis']); ?>"><?php echo htmlspecialchars($row['diagnosis']); ?></span></td>
                                <td>
                                    <?php if(!empty($row['lab_results_url'])): ?>
                                        <a href="uploads/labs/<?php echo htmlspecialchars($row['lab_results_url']); ?>" target="_blank" class="badge bg-info text-dark text-decoration-none shadow-sm"><i class="fa fa-file-pdf me-1"></i>Lab PDF</a>
                                    <?php else: ?>
                                        <span class="text-muted small">No File</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?php echo date("M d", strtotime($row['created_at'])); ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2.5 py-1 fw-bold" title="Review, edit and print the prescription slip"
                                        onclick='openRxEditor(<?= json_encode([
                                            "id" => (int)$row["id"], "pet" => $row["pet_name"], "diagnosis" => $row["diagnosis"],
                                            "treatment" => $row["treatment"], "prescription" => $row["prescription"], "followup" => $row["followup_date"]
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        <i class="fa fa-pen-to-square me-1"></i> Edit &amp; Print Rx
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <p class="text-muted text-center py-4">No past records to display.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- EDIT RX MODAL -->
<div class="modal fade" id="rxEditModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h5 class="fw-bold mb-0"><i class="fa fa-prescription me-2 text-primary"></i>Prescription Slip: <span id="rxPet"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="rxEditForm" class="modal-body p-4">
                <input type="hidden" name="save_rx" value="1">
                <input type="hidden" name="consult_id" id="rxId">
                <div id="rxAlert"></div>
                <div class="mb-3">
                    <label class="form-label fw-bold small">DIAGNOSIS</label>
                    <textarea name="diagnosis" id="rxDiagnosis" class="form-control" rows="2" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small">PRESCRIPTION (Rx)</label>
                    <textarea name="prescription" id="rxPrescription" class="form-control" rows="4" placeholder="e.g. Amoxicillin 250mg — 1 tablet twice a day for 7 days"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small">TREATMENT PLAN / INSTRUCTIONS</label>
                    <textarea name="treatment" id="rxTreatment" class="form-control" rows="3" required></textarea>
                </div>
                <div class="mb-1" style="max-width:260px;">
                    <label class="form-label fw-bold small">RECOMMENDED FOLLOW-UP</label>
                    <input type="date" name="followup_date" id="rxFollowup" class="form-control">
                </div>
            </form>
            <div class="modal-footer border-0 px-4 pb-4">
                <a href="#" id="rxPrintOnly" target="_blank" class="btn btn-link text-muted me-auto px-0">Print without changes</a>
                <button type="button" class="btn btn-light border rounded-pill px-3" onclick="saveRx(false)"><i class="fa fa-save me-1"></i> Save</button>
                <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" onclick="saveRx(true)"><i class="fa fa-print me-1"></i> Save &amp; Print</button>
            </div>
        </div>
    </div>
</div>

<datalist id="priceList">
    <?php foreach ($price_list as $pl): ?>
        <option value="<?= htmlspecialchars($pl['item_name']) ?>" label="₱<?= number_format($pl['price_per_unit'], 2) ?>"></option>
    <?php endforeach; ?>
</datalist>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Consultation charges ──
var PRICE_LIST = {};
<?php foreach ($price_list as $pl): ?>PRICE_LIST[<?= json_encode($pl['item_name']) ?>] = <?= (float)$pl['price_per_unit'] ?>;
<?php endforeach; ?>

function addChargeRow(btn) {
    var tbody = btn.closest('.p-3').querySelector('.charges-table tbody');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" name="item_name[]" class="form-control form-control-sm" list="priceList" placeholder="Procedure or medicine" oninput="fillChargePrice(this)"></td>'
        + '<td><input type="number" name="item_qty[]" class="form-control form-control-sm text-center charge-qty" value="1" min="1" oninput="updateChargeTotal(this)"></td>'
        + '<td><input type="number" name="item_price[]" class="form-control form-control-sm text-end charge-price" value="0.00" min="0" step="0.01" oninput="updateChargeTotal(this)"></td>'
        + '<td><button type="button" class="btn btn-sm text-danger p-0" title="Remove" onclick="var t=this.closest(\'table\');this.closest(\'tr\').remove();updateChargeTotal(t)"><i class="fa fa-times-circle"></i></button></td>';
    tbody.appendChild(tr);
    tr.querySelector('input').focus();
}

function fillChargePrice(input) {
    if (PRICE_LIST.hasOwnProperty(input.value)) {
        input.closest('tr').querySelector('.charge-price').value = PRICE_LIST[input.value].toFixed(2);
        updateChargeTotal(input);
    }
}

function updateChargeTotal(el) {
    var box = el.closest('.p-3');
    var total = 0;
    box.querySelectorAll('.charges-table tbody tr').forEach(function(tr) {
        var price = parseFloat(tr.querySelector('.charge-price').value) || 0;
        var q = tr.querySelector('.charge-qty');
        total += price * (q ? (parseInt(q.value) || 1) : 1);
    });
    box.querySelector('.charge-total').textContent = total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Show the follow-up time only when booking it
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('fu-book')) {
        var wrap = e.target.closest('.col-md-5');
        wrap.querySelector('.fu-time').style.display = e.target.checked ? 'block' : 'none';
        wrap.querySelector('input[name="followup_date"]').required = e.target.checked;
    }
});

function openRxEditor(c) {
    document.getElementById('rxPet').textContent = c.pet || '';
    document.getElementById('rxId').value = c.id;
    document.getElementById('rxDiagnosis').value = c.diagnosis || '';
    document.getElementById('rxTreatment').value = c.treatment || '';
    document.getElementById('rxPrescription').value = c.prescription || '';
    document.getElementById('rxFollowup').value = c.followup || '';
    document.getElementById('rxPrintOnly').href = 'print_rx.php?id=' + c.id;
    document.getElementById('rxAlert').innerHTML = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rxEditModal')).show();
}

function saveRx(andPrint) {
    var form = document.getElementById('rxEditForm');
    if (!form.reportValidity()) return;
    var id = document.getElementById('rxId').value;
    // Open the print tab now (inside the click) so pop-up blockers allow it
    var win = andPrint ? window.open('', '_blank') : null;
    fetch('doctor_consultations.php', { method: 'POST', body: new FormData(form) })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.ok) throw new Error(res.error || 'Could not save changes.');
            if (win) win.location = 'print_rx.php?id=' + id;
            location.reload();
        })
        .catch(function(err) {
            if (win) win.close();
            document.getElementById('rxAlert').innerHTML = '<div class="alert alert-danger py-2 small">' + err.message.replace(/</g, '&lt;') + '</div>';
        });
}
</script>
    <script src="mousetrail.js"></script>
</body>
</html>
