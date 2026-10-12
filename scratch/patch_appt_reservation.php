<?php
/**
 * Patch: Add reservation fee PayMongo step to appointments.php
 * 
 * Changes:
 * 1. Add include paymongo_config.php at top
 * 2. Add backend handler for ?initpay=reservation&appt_id=X (create checkout session)
 * 3. Add backend handler for ?pm_success=reservation&appt_id=X (mark downpayment paid)
 * 4. Update step names to show 7 steps (insert Payment step between Review and Created)
 * 5. Update the submitAppointment() JS to go to step 6 (payment) after saving
 * 6. Insert Step 6 (Payment) panel HTML between step 5 and step 6 (rename old 6 to 7)
 * 7. Show payment confirmation success message on return from PayMongo
 */

$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

// ============================================================
// 1. Add paymongo_config.php include after dbconnection.php
// ============================================================
$content = str_replace(
    'include("dbconnection.php");',
    'include("dbconnection.php");' . "\n" . 'include_once("paymongo_config.php");',
    $content
);

// ============================================================
// 2. Add backend handler for ?initpay=reservation 
//    (called via redirect from step 6)
//    AND ?pm_success=reservation (PayMongo callback)
// ============================================================
$backend_insert = <<<'PHPCODE'

// ===== RESERVATION FEE: Initiate PayMongo Checkout =====
if (isset($_GET['initpay']) && $_GET['initpay'] === 'reservation' && isset($_GET['appt_id'])) {
    $appt_id = intval($_GET['appt_id']);
    // Verify this appointment belongs to the user
    $aq = $conn->prepare("SELECT id, pet_name, service_type FROM appointments WHERE id=? AND user_id=?");
    $aq->bind_param("ii", $appt_id, $user_id);
    $aq->execute();
    $appt = $aq->get_result()->fetch_assoc();
    if (!$appt) {
        $_SESSION['message'] = "Invalid appointment.";
        $_SESSION['msg_type'] = "danger";
        header("Location: appointments.php");
        exit();
    }
    $label = "PragmaVet Reservation Fee - " . $appt['service_type'] . " for " . $appt['pet_name'];
    $domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $result = paymongo_create_checkout(
        500.00,
        $label,
        $domain . '/appointments.php?pm_success=reservation&appt_id=' . $appt_id,
        $domain . '/appointments.php?pm_cancelled=1&appt_id=' . $appt_id,
        ['appt_id' => (string)$appt_id, 'type' => 'reservation']
    );
    if (isset($result['url'])) {
        // Store session ID for verification
        $_SESSION['reservation_session_id_' . $appt_id] = $result['id'] ?? '';
        header("Location: " . $result['url']);
        exit();
    } else {
        $_SESSION['message'] = "Payment gateway error: " . ($result['error'] ?? 'Could not create checkout session.');
        $_SESSION['msg_type'] = "danger";
        header("Location: appointments.php");
        exit();
    }
}

// ===== RESERVATION FEE: PayMongo Success Callback =====
if (isset($_GET['pm_success']) && $_GET['pm_success'] === 'reservation' && isset($_GET['appt_id'])) {
    $appt_id = intval($_GET['appt_id']);
    // Update appointment to mark downpayment as paid
    $upd = $conn->prepare("UPDATE appointments SET downpayment_amount=500.00, downpayment_status='Paid', downpayment_paid_at=NOW() WHERE id=? AND user_id=?");
    if ($upd) {
        $upd->bind_param("ii", $appt_id, $user_id);
        $upd->execute();
    }
    // Also update billing record if exists
    $conn->query("UPDATE billing SET downpayment_amount=500.00, downpayment_status='Paid', downpayment_paid_at=NOW(), payment_status='Partial' WHERE appointment_id=$appt_id");
    // Notify client
    $note = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, '✅ Reservation fee of ₱500 received for Appointment #" . $appt_id . ". Your slot is secured — pending staff review.', 0, NOW())");
    if ($note) { $note->bind_param("i", $user_id); $note->execute(); }
    $_SESSION['message'] = "✅ Reservation fee paid! Your appointment slot is secured. Staff will review and approve shortly.";
    $_SESSION['msg_type'] = "success";
    $_SESSION['reservation_paid_appt'] = $appt_id;
    header("Location: appointments.php");
    exit();
}

// ===== RESERVATION FEE: Payment Cancelled =====
if (isset($_GET['pm_cancelled']) && isset($_GET['appt_id'])) {
    $appt_id = intval($_GET['appt_id']);
    $_SESSION['message'] = "⚠️ Payment was cancelled. Your appointment is still saved but the reservation fee has not been paid.";
    $_SESSION['msg_type'] = "warning";
    header("Location: appointments.php");
    exit();
}

PHPCODE;

// Insert after the 'include_once rbac_guard' block
$content = str_replace(
    '// RBAC: Client Portal access control
$user_role = rbac_get_role();
if (!in_array($user_role, [\'user\', \'client\'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}',
    '// RBAC: Client Portal access control
$user_role = rbac_get_role();
if (!in_array($user_role, [\'user\', \'client\'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}
' . $backend_insert,
    $content
);

// ============================================================
// 3. Update $step_names to 7 steps (insert Payment step)
// ============================================================
$content = str_replace(
    '$step_names = [
              1 => \'Select Client & Pet\',
              2 => \'Select Service\',
              3 => \'Select Veterinarian\',
              4 => \'Date, Time & Details\',
              5 => \'Review & Confirm\',
              6 => \'Appointment Created\'
          ];
          for ($i = 1; $i <= 6; $i++):',
    '$step_names = [
              1 => \'Select Client & Pet\',
              2 => \'Select Service\',
              3 => \'Select Veterinarian\',
              4 => \'Date, Time & Details\',
              5 => \'Review & Confirm\',
              6 => \'Reservation Fee\',
              7 => \'Booking Confirmed\'
          ];
          for ($i = 1; $i <= 7; $i++):',
    $content
);

// ============================================================
// 4. Update process-step sidebar loop (Step 6 was panel count)
// ============================================================
$content = str_replace(
    '<?php for ($k = 1; $k <= 6; $k++): ?>',
    '<?php for ($k = 1; $k <= 7; $k++): ?>',
    $content
);

// ============================================================
// 5. Update showStep loop from 6 to 7
// ============================================================
$content = str_replace(
    'for (let i = 1; i <= 6; i++) {
            const p = document.getElementById(\'panel-\' + i);
            if (p) p.style.display = (i === n) ? \'block\' : \'none\';

            const node = document.getElementById(\'step-node-\' + i);',
    'for (let i = 1; i <= 7; i++) {
            const p = document.getElementById(\'panel-\' + i);
            if (p) p.style.display = (i === n) ? \'block\' : \'none\';

            const node = document.getElementById(\'step-node-\' + i);',
    $content
);

// ============================================================
// 6. Update process-step sidebar loop JS (in proc-item loop)
// ============================================================
$content = preg_replace(
    '/for \(let i = 1; i <= 6; i\+\+\) \{(\s+const procItem)/',
    'for (let i = 1; i <= 7; i++) {$1',
    $content
);

// ============================================================
// 7. Change submitAppointment() to save first, then redirect to payment step
// ============================================================
$old_submit_end = "fetch('appointments.php', { method: 'POST', body: fd })
                .then(async r => {
                    const text = await r.text();
                    const rnd = Math.floor(1000 + Math.random() * 9000);
                    const ref = document.getElementById('confirmRefNum');
                    if (ref) ref.textContent = '#PV-' + new Date().getFullYear() + String(new Date().getMonth()+1).padStart(2,'0') + '-' + rnd;
                    showStep(6);
                })
                .catch(() => {
                    const rnd = Math.floor(1000 + Math.random() * 9000);
                    const ref = document.getElementById('confirmRefNum');
                    if (ref) ref.textContent = '#PV-' + new Date().getFullYear() + String(new Date().getMonth()+1).padStart(2,'0') + '-' + rnd;
                    showStep(6);
                });";

$new_submit_end = "fd.append('return_appt_id', '1'); // Signal we want the appointment ID back
            fetch('appointments.php', { method: 'POST', body: fd })
                .then(async r => {
                    const text = await r.text();
                    // Try to extract appointment ID from response
                    const idMatch = text.match(/APPT_ID:(\d+)/);
                    if (idMatch && idMatch[1]) {
                        window._newApptId = idMatch[1];
                    }
                    showStep(6);
                })
                .catch(() => {
                    showStep(6);
                });";

$content = str_replace($old_submit_end, $new_submit_end, $content);

// ============================================================
// 8. Make POST handler return APPT_ID marker when book is set
// ============================================================
$content = str_replace(
    '$_SESSION[\'message\'] = "Appointment for <b>$msg_pet</b> booked successfully! We look forward to seeing you.";
        $_SESSION[\'msg_type\'] = "success";',
    '$_SESSION[\'message\'] = "Appointment for <b>$msg_pet</b> booked successfully! We look forward to seeing you.";
        $_SESSION[\'msg_type\'] = "success";
        // Return appointment ID to JS for payment step
        if (!empty($_POST[\'return_appt_id\'])) {
            echo "APPT_ID:" . $appointment_id;
            exit();
        }',
    $content
);

// ============================================================
// 9. Replace old panel-6 with new payment step panel-6, and rename old panel-6 to panel-7
// ============================================================
$old_panel6 = '<!-- STEP 6: Appointment Created -->
                <div class="wiz-panel" id="panel-6" style="display:none;">
                    <div class="text-center py-5">
                        <div style="width:80px;height:80px;border-radius:50%;background:#d1fae5;color:#10b981;display:flex;align-items:center;justify-content:center;font-size:36px;margin:0 auto 20px;">
                            <i class="fa fa-check"></i>
                        </div>
                        <h2 class="fw-bold" style="color:#1e1b4b;font-size:24px;margin-bottom:8px;">Appointment Created!</h2>
                        <div class="fw-bold text-purple mb-3" style="font-size:20px;color:#5b21b6;" id="confirmRefNum">#PV-202608-8842</div>
                        <p class="text-muted mx-auto mb-4" style="max-width:460px;font-size:14px;">
                            Your appointment has been successfully scheduled. A confirmation notification will be sent shortly.
                        </p>';

$new_panels = '<!-- STEP 6: Reservation Fee Payment -->
                <div class="wiz-panel" id="panel-6" style="display:none;">
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
                        <div style="width:42px;height:42px;border-radius:12px;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:20px;"><i class="fa fa-credit-card"></i></div>
                        <h2 style="font-size:20px;font-weight:800;color:#1e1b4b;margin:0;">Step 6 of 7: Reservation Fee</h2>
                    </div>
                    <div style="font-size:13px;color:#64748b;margin-bottom:24px;margin-left:56px;">Pay a ₱500 reservation fee to secure your appointment slot.</div>

                    <div class="card border-0 rounded-4 p-4 mb-4" style="background:#fffbeb;border:1px solid #fde68a !important;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div style="width:48px;height:48px;border-radius:12px;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:22px;"><i class="fa fa-shield-halved"></i></div>
                            <div>
                                <div style="font-weight:800;font-size:15px;color:#92400e;">Secure Your Slot</div>
                                <div style="font-size:13px;color:#78350f;">One-time reservation fee to hold your appointment</div>
                            </div>
                        </div>
                        <div style="background:#fff;border-radius:12px;padding:16px;border:1px solid #fde68a;">
                            <div class="d-flex justify-content-between align-items-center">
                                <span style="font-size:14px;color:#64748b;font-weight:600;">Reservation Fee</span>
                                <span style="font-size:20px;font-weight:800;color:#2563eb;">₱500.00</span>
                            </div>
                            <div style="font-size:12px;color:#94a3b8;margin-top:4px;">This will be deducted from your final consultation bill.</div>
                        </div>
                    </div>

                    <div class="p-3 rounded-3 mb-4" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px;">
                        <i class="fa fa-info-circle me-2"></i>Your appointment has been saved. Pay the reservation fee to secure your slot. Staff will confirm after payment.
                    </div>

                    <div id="payment-loading-msg" style="display:none;text-align:center;padding:20px;color:#64748b;font-size:14px;">
                        <i class="fa fa-spinner fa-spin me-2"></i> Redirecting to secure payment...
                    </div>

                    <div style="display:flex;align-items:center;justify-content:space-between;padding-top:16px;border-top:1px solid #f1f5f9;">
                        <div></div>
                        <div style="display:flex;gap:10px;">
                            <button type="button" onclick="skipReservationFee()" style="padding:10px 20px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-weight:700;font-size:13px;color:#64748b;cursor:pointer;">
                                Skip for Now
                            </button>
                            <button type="button" id="payReservationBtn" onclick="payReservationFee()" style="padding:10px 28px;border-radius:10px;background:#2563eb;color:#fff;font-weight:700;font-size:14px;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(37,99,235,0.35);display:flex;align-items:center;gap:8px;">
                                <i class="fa fa-credit-card"></i> Pay ₱500 via PayMongo
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 7: Appointment Created -->
                <div class="wiz-panel" id="panel-7" style="display:none;">
                    <div class="text-center py-5">
                        <div style="width:80px;height:80px;border-radius:50%;background:#d1fae5;color:#10b981;display:flex;align-items:center;justify-content:center;font-size:36px;margin:0 auto 20px;">
                            <i class="fa fa-check"></i>
                        </div>
                        <h2 class="fw-bold" style="color:#1e1b4b;font-size:24px;margin-bottom:8px;">Appointment Created!</h2>
                        <div class="fw-bold text-purple mb-3" style="font-size:20px;color:#5b21b6;" id="confirmRefNum">#PV-202608-8842</div>
                        <p class="text-muted mx-auto mb-4" style="max-width:460px;font-size:14px;">
                            Your appointment has been successfully scheduled. A confirmation notification will be sent shortly.
                        </p>';

$content = str_replace($old_panel6, $new_panels, $content);

// ============================================================
// 10. Add JS functions for payment step
// ============================================================
$old_reset = "function resetWizard() {
        S.step = 1;";

$new_reset = "function payReservationFee() {
        if (!window._newApptId) {
            alert('Appointment ID not found. Please try again.');
            return;
        }
        document.getElementById('payReservationBtn').disabled = true;
        document.getElementById('payReservationBtn').innerHTML = '<i class=\"fa fa-spinner fa-spin me-2\"></i> Redirecting...';
        document.getElementById('payment-loading-msg').style.display = 'block';
        window.location.href = 'appointments.php?initpay=reservation&appt_id=' + window._newApptId;
    }

    function skipReservationFee() {
        showStep(7);
    }

    function resetWizard() {
        S.step = 1;";

$content = str_replace($old_reset, $new_reset, $content);

// ============================================================
// Done - write file
// ============================================================
file_put_contents($file, $content);
echo "appointments.php patched successfully with reservation fee step.\n";

// Verify the new step 6 panel exists
if (strpos($content, 'panel-6') !== false && strpos($content, 'panel-7') !== false) {
    echo "✅ Step 6 (Payment) and Step 7 (Confirmation) panels found.\n";
} else {
    echo "⚠️  Panel check failed — verify manually.\n";
}
if (strpos($content, 'initpay=reservation') !== false) {
    echo "✅ Payment initiation backend found.\n";
}
if (strpos($content, 'pm_success=reservation') !== false) {
    echo "✅ Payment success callback found.\n";
}
?>
