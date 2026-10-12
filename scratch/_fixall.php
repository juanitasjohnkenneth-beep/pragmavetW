<?php
// Pet owner side: security fixes + missing connections. Every replacement must match the expected number of times.
$root = 'C:/xampp/htdocs/pragmavet/';
$plan = [];
$fail = false;

function P($file, $old, $new, $count = 1, $regex = false) { global $plan; $plan[$file][] = [$old, $new, $count, $regex]; }

// ── 1. camera_view.php: login + owner/active-stay check ─────────────────────
P('camera_view.php', <<<'A'
        SELECT br.id, br.pet_name, br.cage_number, br.cctv_link, br.cctv_enabled,
               br.appointment_date, br.end_date
A, <<<'B'
        SELECT br.id, br.user_id, br.status, br.pet_name, br.cage_number, br.cctv_link, br.cctv_enabled,
               br.appointment_date, br.end_date
B);
P('camera_view.php', <<<'A'
    if($r) $row = $r->fetch_assoc();
}
A, <<<'B'
    if($r) $row = $r->fetch_assoc();
}

// Only clinic staff, or the pet's owner while the stay is active, may watch (same rule as the Pet Boarding page)
$viewer_id = (int)($_SESSION['user_id'] ?? 0);
if (!$viewer_id) { header("Location: index.php"); exit(); }
if ($row && !in_array($_SESSION['role'] ?? '', ['admin', 'staff', 'doctor', 'clinic_owner', 'owner'], true)) {
    $stay_live = $row['status'] === 'Checked In'
              || ($row['status'] === 'Approved' && strtotime($row['appointment_date']) <= time() && strtotime($row['end_date'] . ' 23:59:59') >= time());
    if ((int)$row['user_id'] !== $viewer_id || !$stay_live) $row = null;
}
B);

// ── 2. download_emr.php: every pet owner role may only open their own pets ──
P('download_emr.php', "if (\$user_role === 'client') {",
  "if (!in_array(\$user_role, ['admin', 'staff', 'doctor', 'clinic_owner', 'owner'], true)) { // pet owners ('client' or 'user') may only open their own pets");

// ── 3. medical_certificate.php + admin_certificates.php: consultation must belong to this owner's pet ──
P('medical_certificate.php', "    WHERE a.pet_name = ? \n", "    WHERE a.pet_name = ? AND a.user_id = ?\n");
P('medical_certificate.php', "\$h_stmt->bind_param(\"s\", \$pet['name']);", "\$h_stmt->bind_param(\"si\", \$pet['name'], \$user_id);");
P('admin_certificates.php', "WHERE a.pet_name=? ORDER BY a.appointment_date DESC LIMIT 1\");\n        \$hq->bind_param(\"s\", \$data['pet_name']);",
  "WHERE a.pet_name=? AND a.user_id=? ORDER BY a.appointment_date DESC LIMIT 1\");\n        \$hq->bind_param(\"si\", \$data['pet_name'], \$data['user_id']);");
P('admin_certificates.php', "include_once(\"includes/rbac_guard.php\");", "include_once(\"includes/rbac_guard.php\");\ninclude_once(\"includes/client_notify.php\");");
P('admin_certificates.php', <<<'A'
        $upd->bind_param("si", $pdf_url, $req_id);
        $upd->execute();
A, <<<'B'
        $upd->bind_param("si", $pdf_url, $req_id);
        $upd->execute();
        notify_user($conn, $data['user_id'], "The health certificate for {$data['pet_name']} is ready. Tap to download it.", $pdf_url);
B);
P('admin_certificates.php', <<<'A'
    $upd->bind_param("si", $reason, $req_id);
    $upd->execute();
    $_SESSION['message'] = "Request rejected.";
A, <<<'B'
    $upd->bind_param("si", $reason, $req_id);
    $upd->execute();
    $rq = $conn->query("SELECT cr.user_id, p.name AS pet_name FROM certificate_requests cr JOIN pets p ON p.id = cr.pet_id WHERE cr.id = $req_id")->fetch_assoc();
    if ($rq) notify_user($conn, $rq['user_id'], "Your certificate request for {$rq['pet_name']} was not approved." . ($reason !== '' ? " Reason: $reason" : ''), 'pets.php');
    $_SESSION['message'] = "Request rejected.";
B);

// ── 4/6/7. appointments.php: one boarding cancel rule, staff + vet notices, boarding reschedule request ──
P('appointments.php', "include_once(\"includes/staff_notify.php\");", "include_once(\"includes/staff_notify.php\");\ninclude_once(\"includes/client_notify.php\");");
P('appointments.php', <<<'A'
    if ($type === 'Boarding') {
        $stmt = $conn->prepare("UPDATE boarding_reservations SET status='Cancelled' WHERE id=? AND user_id=? AND status IN ('Pending','Approved')");
    } else {
A, <<<'B'
    if ($type === 'Boarding') {
        // Same rule as the Pet Boarding page: Pending/Approved and at least 24 hours before check-in
        $bq = $conn->prepare("SELECT status, appointment_date, pet_name FROM boarding_reservations WHERE id=? AND user_id=?");
        $bq->bind_param("ii", $cid, $user_id);
        $bq->execute();
        $brow = $bq->get_result()->fetch_assoc();
        $why = board_cancel_problem($brow);
        if ($why) {
            $_SESSION['message'] = $why;
            $_SESSION['msg_type'] = "danger";
            header("Location: appointments.php");
            exit();
        }
        $stmt = $conn->prepare("UPDATE boarding_reservations SET status='Cancelled' WHERE id=? AND user_id=? AND status IN ('Pending','Approved')");
    } else {
B, 2);
P('appointments.php', <<<'A'
        $_SESSION['message'] = "Appointment cancelled.";
        $_SESSION['msg_type'] = "success";
        if ($type !== 'Boarding' && function_exists('notify_staff')) {
A, <<<'B'
        $_SESSION['message'] = $type === 'Boarding' ? "Boarding reservation cancelled." : "Appointment cancelled.";
        $_SESSION['msg_type'] = "success";
        if ($type === 'Boarding') {
            notify_clinic_appt($conn, "$user_name cancelled boarding reservation #$cid for {$brow['pet_name']} (check-in " . date('M d, Y', strtotime($brow['appointment_date'])) . "). The cage is free again.", 'Boarding', $cid);
        } else {
B, 2);
P('appointments.php', '~if \(\$ca\) notify_staff\(\$conn, (.+), staff_link_for\(\'Appointment\', \$cid\)\);~', 'if ($ca) notify_clinic_appt($conn, $1, \'Appointment\', $cid);', 2, true);
P('appointments.php', '~notify_staff\(\$conn, (.+), staff_link_for\((\$type|\'Appointment\'), \$aid\)\);~', 'notify_clinic_appt($conn, $1, $2, $aid);', 7, true);
P('appointments.php', <<<'A'
    $table = $type === 'Boarding' ? 'boarding_reservations' : 'appointments';
    $stmt = $conn->prepare("UPDATE $table SET status='Reschedule Requested'
A, <<<'B'
    // Boarding stays have no "requested" status: the front desk checks the cage and proposes the dates, which the client then confirms
    if ($type === 'Boarding') {
        $bq = $conn->prepare("SELECT pet_name, appointment_date, end_date FROM boarding_reservations WHERE id=? AND user_id=? AND status IN ('Pending','Approved')");
        $bq->bind_param("ii", $aid, $user_id);
        $bq->execute();
        $bs = $bq->get_result()->fetch_assoc();
        if ($bs) {
            $nights = max(1, (int)round((strtotime($bs['end_date']) - strtotime($bs['appointment_date'])) / 86400));
            $new_end = date('Y-m-d', strtotime("$new_date +$nights day"));
            notify_clinic_appt($conn, "$user_name asked to move boarding reservation #$aid for {$bs['pet_name']} to " . date('M d', strtotime($new_date)) . " - " . date('M d, Y', strtotime($new_end)) . "." . ($reason !== '' ? " Reason: $reason" : '') . " Propose the new dates from Pet Boarding Care.", 'Boarding', $aid);
            $_SESSION['message'] = "Reschedule request sent. The clinic will check cage availability and send you the new dates to confirm.";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Could not submit request. Please try again.";
            $_SESSION['msg_type'] = "danger";
        }
        header("Location: appointments.php");
        exit();
    }
    $table = $type === 'Boarding' ? 'boarding_reservations' : 'appointments';
    $stmt = $conn->prepare("UPDATE $table SET status='Reschedule Requested'
B, 1);

// ── 4/5/6/11. boarding.php ─────────────────────────────────────────────────
P('boarding.php', "include_once(\"includes/staff_notify.php\");", "include_once(\"includes/staff_notify.php\");\ninclude_once(\"includes/client_notify.php\");");
P('boarding.php', <<<'A'
    $stmt = $conn->prepare("SELECT appointment_date FROM boarding_reservations WHERE id=? AND user_id=?");
A, <<<'B'
    $stmt = $conn->prepare("SELECT status, appointment_date, pet_name FROM boarding_reservations WHERE id=? AND user_id=?");
B);
P('boarding.php', <<<'A'
        $drop_off = strtotime($row['appointment_date']);
        if (($drop_off - time()) >= 86400) {
            $conn->query("UPDATE boarding_reservations SET status='Cancelled' WHERE id=$apt_id");
            $_SESSION['message']  = "Boarding reservation successfully cancelled.";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message']  = "Cancellation failed: Must be completed at least 24 hours prior to check-in.";
            $_SESSION['msg_type'] = "danger";
        }
A, <<<'B'
        $why = board_cancel_problem($row);
        if (!$why) {
            $conn->query("UPDATE boarding_reservations SET status='Cancelled' WHERE id=$apt_id AND status IN ('Pending','Approved')");
            $_SESSION['message']  = "Boarding reservation successfully cancelled.";
            $_SESSION['msg_type'] = "success";
            notify_clinic_appt($conn, "$user_name cancelled boarding reservation #$apt_id for {$row['pet_name']} (check-in " . date('M d, Y', strtotime($row['appointment_date'])) . "). The cage is free again.", 'Boarding', $apt_id);
        } else {
            $_SESSION['message']  = $why;
            $_SESSION['msg_type'] = "danger";
        }
B);
// The old self-service reschedule (no form uses it any more) now only sends a request to the front desk
P('boarding.php', <<<'A'
    $curr_q = $conn->prepare("SELECT appointment_date, cage_number FROM boarding_reservations WHERE id=? AND user_id=?");
A, <<<'B'
    $curr_q = $conn->prepare("SELECT appointment_date, cage_number, status, pet_name FROM boarding_reservations WHERE id=? AND user_id=?");
B);
P('boarding.php', <<<'A'
    } elseif ($curr['appointment_date'] <= $today) {
        $_SESSION['message']  = "Stays starting today or already in progress cannot be rescheduled.";
        $_SESSION['msg_type'] = "warning";
    } else {
A, <<<'B'
    } elseif (!in_array($curr['status'], ['Pending', 'Approved'], true)) {
        $_SESSION['message']  = "This reservation can no longer be rescheduled.";
        $_SESSION['msg_type'] = "warning";
    } elseif ($curr['appointment_date'] <= $today) {
        $_SESSION['message']  = "Stays starting today or already in progress cannot be rescheduled.";
        $_SESSION['msg_type'] = "warning";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$new_start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$new_end) || $new_start <= $today || $new_end <= $new_start) {
        $_SESSION['message']  = "Please choose a check-in date from tomorrow onwards and a check-out date after it.";
        $_SESSION['msg_type'] = "danger";
    } else {
B);
P('boarding.php', <<<'A'
            $stmt = $conn->prepare("UPDATE boarding_reservations SET appointment_date=?, end_date=?, status='Approved', proposed_reschedule_date=NULL, proposed_reschedule_end_date=NULL, client_action_needed=0 WHERE id=? AND user_id=?");
            $stmt->bind_param("ssii", $new_start, $new_end, $apt_id, $user_id);
            if ($stmt->execute()) {
                $_SESSION['message']  = "Reservation successfully updated to new dates!";
                $_SESSION['msg_type'] = "success";
            }
A, <<<'B'
            // Dates only change once the front desk proposes them and the client confirms (accept_reschedule_boarding)
            notify_clinic_appt($conn, "$user_name asked to move boarding reservation #$apt_id for {$curr['pet_name']} to " . date('M d', strtotime($new_start)) . " - " . date('M d, Y', strtotime($new_end)) . ". Propose the new dates from Pet Boarding Care.", 'Boarding', $apt_id);
            $_SESSION['message']  = "Reschedule request sent. The clinic will confirm the new dates with you.";
            $_SESSION['msg_type'] = "success";
B);
P('boarding.php', "<?php include_once(\"includes/reminder_popup.php\"); ?>", "<?php include(\"chat_widget.php\"); ?>\n<?php include_once(\"includes/reminder_popup.php\"); ?>");
P('billing.php', "<?php include_once(\"includes/reminder_popup.php\"); ?>", "<?php include(\"chat_widget.php\"); ?>\n<?php include_once(\"includes/reminder_popup.php\"); ?>");

// ── staff_dashboard.php: act on a client's "Reschedule Requested" with the existing reschedule form ──
P('staff_dashboard.php', "\$cur = \$conn->query(\"SELECT doctor_id FROM appointments WHERE id = \$apt_id\")->fetch_assoc();",
  "\$cur = \$conn->query(\"SELECT doctor_id, status, proposed_reschedule_date, proposed_reschedule_time FROM appointments WHERE id = \$apt_id\")->fetch_assoc();");
P('staff_dashboard.php', <<<'A'
            // Staff propose the new schedule; the client accepts it or declines and picks another date
A, <<<'B'
            // The client asked for this exact date and time: approve it straight away (no need to send it back to them)
            if (($cur['status'] ?? '') === 'Reschedule Requested' && $new_date === $cur['proposed_reschedule_date'] && substr($new_time, 0, 5) === substr((string)$cur['proposed_reschedule_time'], 0, 5)) {
                include_once("includes/client_notify.php");
                $audit_ok = "Reschedule request approved by Vet Staff ({$user_name}): $new_date $new_time";
                $stmt = $conn->prepare("UPDATE appointments SET appointment_date=?, appointment_time=?, status='Approved', proposed_reschedule_date=NULL, proposed_reschedule_time=NULL, client_action_needed=0, admin_message=?, staff_id=COALESCE(staff_id, ?) WHERE id=?");
                $stmt->bind_param("sssii", $new_date, $new_time, $audit_ok, $user_id, $apt_id);
                if ($stmt->execute()) {
                    $r = $conn->query("SELECT user_id, pet_name FROM appointments WHERE id=$apt_id")->fetch_assoc();
                    $when = date('M d, Y', strtotime($new_date)) . " at " . date('g:i A', strtotime($new_time));
                    if ($r) notify_user($conn, $r['user_id'], "Your reschedule request for {$r['pet_name']} was approved. New schedule: $when.", "appointments.php?open_appt=$apt_id");
                    if ($r) notify_appt_vet($conn, $apt_id, "Appointment #$apt_id ({$r['pet_name']}) was moved to $when at the client's request.");
                    $_SESSION['flash_msg'] = "Reschedule request approved. Appointment #$apt_id is now on $when.";
                    $_SESSION['flash_type'] = "success";
                }
                header("Location: staff_dashboard.php?tab=" . urlencode($redirect_tab));
                exit();
            }
            // Staff propose the new schedule; the client accepts it or declines and picks another date
B);
P('staff_dashboard.php', "<?php if (in_array(\$s, ['Pending', 'Approved'])): // Reschedule / No Show only before check-in ?>",
  "<?php if (in_array(\$s, ['Pending', 'Approved', 'Reschedule Requested'])): // Reschedule / No Show only before check-in ?>");
P('staff_dashboard.php', "value=\"<?= \$apt['appointment_date'] ?>\" required>",
  "value=\"<?= \$s === 'Reschedule Requested' && !empty(\$apt['proposed_reschedule_date']) ? \$apt['proposed_reschedule_date'] : \$apt['appointment_date'] ?>\" required>");
P('staff_dashboard.php', "value=\"<?= \$apt['appointment_time'] ?>\" required>",
  "value=\"<?= \$s === 'Reschedule Requested' && !empty(\$apt['proposed_reschedule_time']) ? substr(\$apt['proposed_reschedule_time'], 0, 5) : \$apt['appointment_time'] ?>\" required>");

// ── 9/10. admin_billing.php ────────────────────────────────────────────────
P('admin_billing.php', "include_once(\"includes/service_prices.php\");", "include_once(\"includes/service_prices.php\");\ninclude_once(\"includes/client_notify.php\");");
P('admin_billing.php', "FROM users WHERE role='client' ORDER BY fullname ASC", "FROM users WHERE role IN ('client', 'user') ORDER BY fullname ASC");
P('admin_billing.php', <<<'A'
        $conn->query("UPDATE billing SET invoice_no='$inv_no' WHERE id=$bill_id");
A, <<<'B'
        $conn->query("UPDATE billing SET invoice_no='$inv_no' WHERE id=$bill_id");
        $cl_uid = bill_owner_id($conn, $conn->query("SELECT * FROM billing WHERE id=$bill_id")->fetch_assoc());
        if ($cl_uid) notify_user($conn, $cl_uid, $pay_now
            ? "Payment received: ₱" . number_format($amount_paid, 2) . " for invoice $inv_no. Thank you!"
            : "A new invoice ($inv_no, ₱" . number_format($grand_total, 2) . ") was issued to you. Tap to view it.", 'billing.php');
B);
P('admin_billing.php', <<<'A'
            $_SESSION['message'] = "Invoice marked as Fully Paid (₱" . number_format($total, 2) . ").";
A, <<<'B'
            $_SESSION['message'] = "Invoice marked as Fully Paid (₱" . number_format($total, 2) . ").";
            if ($cl_uid = bill_owner_id($conn, $bRow)) notify_user($conn, $cl_uid, "Payment received: invoice " . ($bRow['invoice_no'] ?: '#' . $bill_id) . " (₱" . number_format($total, 2) . ") is now fully paid. Thank you!", 'billing.php');
B);
P('admin_billing.php', <<<'A'
            $_SESSION['message'] = "Downpayment of ₱" . number_format($dp, 2) . " marked as Paid.";
A, <<<'B'
            $_SESSION['message'] = "Downpayment of ₱" . number_format($dp, 2) . " marked as Paid.";
            if ($cl_uid = bill_owner_id($conn, $bRow)) notify_user($conn, $cl_uid, "Payment received: ₱" . number_format($dp, 2) . " downpayment for invoice " . ($bRow['invoice_no'] ?: '#' . $bill_id) . ". Thank you!", 'billing.php');
B);

// ── 12. feedback.php: the vet who handled the visit sees the rating ─────────
P('feedback.php', "include_once(\"includes/rbac_guard.php\");", "include_once(\"includes/rbac_guard.php\");\ninclude_once(\"includes/client_notify.php\");");
P('feedback.php', <<<'A'
            $_SESSION['message'] = "Thank you for your valuable feedback!";
A, <<<'B'
            $_SESSION['message'] = "Thank you for your valuable feedback!";
            notify_appt_vet($conn, $apt_id, "$user_name rated visit #$apt_id $rating/5" . ($comments !== '' ? ": \"" . mb_strimwidth($comments, 0, 140, '...') . "\"" : '.'));
B);

// ── 8 + email check. pets.php ──────────────────────────────────────────────
P('pets.php', <<<'A'
    } else {
        $pic_query = "";
        $uploaded_pic = uploadImage($_FILES['profile_pic'], 'uploads/profiles');
        if ($uploaded_pic) {
            $pic_query = ", profile_picture = '$uploaded_pic' ";
        }

        if (!empty($new_password) || !empty($current_password)) {
A, <<<'B'
    } else {
        // The email is the login and receives the OTP code, so changing it needs the current password
        $cur_email = $conn->query("SELECT email, password FROM users WHERE id = " . (int)$user_id)->fetch_assoc();
        if ($cur_email && strcasecmp(trim($cur_email['email']), $email) !== 0 && !password_verify($current_password, $cur_email['password'])) {
            $_SESSION['message'] = "To change your email address, enter your current password.";
            $_SESSION['msg_type'] = "danger";
            header("Location: pets.php");
            exit();
        }
        $pic_query = "";
        $uploaded_pic = uploadImage($_FILES['profile_pic'], 'uploads/profiles');
        if ($uploaded_pic) {
            $pic_query = ", profile_picture = '$uploaded_pic' ";
        }

        if (!empty($new_password)) {
B);
P('pets.php', <<<'A'
$pet_id = isset($_GET['pet_id']) ? (int)$_GET['pet_id'] : 0;
A, <<<'B'
// Request a Health & Vaccination Certificate (reviewed by the admin in Certificates)
if (isset($_POST['request_certificate'])) {
    $req_pet = (int)($_POST['pet_id'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');
    $own = $conn->prepare("SELECT name FROM pets WHERE id = ? AND user_id = ?");
    $own->bind_param("ii", $req_pet, $user_id);
    $own->execute();
    $own_pet = $own->get_result()->fetch_assoc();
    if (!$own_pet || $purpose === '') {
        $_SESSION['message'] = "Please choose the purpose of the certificate.";
        $_SESSION['msg_type'] = "danger";
    } elseif ($conn->query("SELECT 1 FROM certificate_requests WHERE pet_id = $req_pet AND user_id = " . (int)$user_id . " AND status = 'Pending'")->num_rows) {
        $_SESSION['message'] = "You already have a pending certificate request for {$own_pet['name']}. We'll notify you once it's reviewed.";
        $_SESSION['msg_type'] = "warning";
    } else {
        $ins = $conn->prepare("INSERT INTO certificate_requests (user_id, pet_id, purpose, status) VALUES (?, ?, ?, 'Pending')");
        $ins->bind_param("iis", $user_id, $req_pet, $purpose);
        $ins->execute();
        $_SESSION['message'] = "Certificate request sent for {$own_pet['name']}. We'll notify you when it's ready.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: pets.php?pet_id=" . $req_pet);
    exit();
}

$pet_id = isset($_GET['pet_id']) ? (int)$_GET['pet_id'] : 0;
B);
P('pets.php', <<<'A'
                                        <i class="fa fa-download me-1"></i> Download EMR (PDF)
                                    </a>
A, <<<'B'
                                        <i class="fa fa-download me-1"></i> Download EMR (PDF)
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#requestCertModal">
                                        <i class="fa fa-file-contract me-1"></i> Request Certificate
                                    </button>
B);
P('pets.php', <<<'A'
<?php include("chat_widget.php"); ?>
A, <<<'B'
<?php if (!empty($pet_id)): ?>
<div class="modal fade" id="requestCertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="fw-bold text-dark"><i class="fa fa-file-contract text-primary me-2"></i> Request Certificate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="modal-body p-4">
                <input type="hidden" name="request_certificate" value="1">
                <input type="hidden" name="pet_id" value="<?= (int)$pet_id ?>">
                <p class="text-muted small mb-3">Request a Health and Vaccination Certificate for <strong><?= htmlspecialchars($pet_name) ?></strong>. The clinic will review it and notify you when it's ready to download.</p>
                <label class="form-label text-muted small fw-bold">PURPOSE</label>
                <select name="purpose" class="form-select mb-4" required>
                    <option value="" selected disabled>Choose a purpose</option>
                    <option>Travel</option>
                    <option>Boarding / Grooming</option>
                    <option>Pet show or event</option>
                    <option>Housing / Landlord requirement</option>
                    <option>Personal record</option>
                </select>
                <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold">Send Request</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php include("chat_widget.php"); ?>
B);

// ── 7. Veterinarian pages: the floating notification bell ───────────────────
foreach (['doctor.php', 'doctor_consultations.php', 'doctor_patients.php', 'doctor_inventory.php'] as $df) {
    P($df, "\n<div class=\"main-content\">", "\n<?php include(\"includes/staff_bell.php\"); ?>\n<div class=\"main-content\">");
}
P('includes/staff_bell.php', "    .main-wrapper > .top-action-bar, .main-wrapper > .d-flex:first-child { padding-right:70px; }",
  "    .main-wrapper > .top-action-bar, .main-wrapper > .d-flex:first-child, .main-content > .d-flex:first-child { padding-right:70px; }");

// ── Run: verify everything first, then write ────────────────────────────────
$out = [];
foreach ($plan as $file => $reps) {
    $path = $root . $file;
    $raw = file_get_contents($path);
    $nl = strpos($raw, "\r\n") !== false ? "\r\n" : "\n";
    $s = str_replace("\r\n", "\n", $raw);
    foreach ($reps as $k => [$old, $new, $count, $regex]) {
        if ($regex) { $s = preg_replace($old, $new, $s, -1, $c); }
        else { $c = substr_count($s, $old); if ($c === $count) $s = str_replace($old, $new, $s); }
        if ($c !== $count) { echo "MISMATCH $file #$k: found $c, expected $count\n"; $fail = true; }
    }
    $out[$file] = [$path, str_replace("\n", $nl, $s), $raw];
}
if ($fail) { echo "Nothing written.\n"; exit(1); }
@mkdir($root . 'scratch/_bak_fixall', 0777, true);
foreach ($out as $file => [$path, $new, $raw]) {
    $bak = $root . 'scratch/_bak_fixall/' . str_replace('/', '__', $file);
    if (!file_exists($bak)) file_put_contents($bak, $raw);
    file_put_contents($path, $new);
    echo "patched $file\n";
}
