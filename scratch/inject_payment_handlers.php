<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

// The RBAC block - insert the GET handlers right after it
$after_rbac = '$user_id = $_SESSION[\'user_id\'];
$user_name = $_SESSION[\'user\'];';

$payment_handlers = '$user_id = $_SESSION[\'user_id\'];
$user_name = $_SESSION[\'user\'];

// ===== RESERVATION FEE: Initiate PayMongo Checkout =====
if (isset($_GET[\'initpay\']) && $_GET[\'initpay\'] === \'reservation\' && isset($_GET[\'appt_id\'])) {
    $appt_id = intval($_GET[\'appt_id\']);
    $aq = $conn->prepare("SELECT id, pet_name, service_type FROM appointments WHERE id=? AND user_id=?");
    $aq->bind_param("ii", $appt_id, $user_id);
    $aq->execute();
    $appt = $aq->get_result()->fetch_assoc();
    if (!$appt) {
        $_SESSION[\'message\'] = "Invalid appointment.";
        $_SESSION[\'msg_type\'] = "danger";
        header("Location: appointments.php");
        exit();
    }
    $protocol = (isset($_SERVER[\'HTTPS\']) && $_SERVER[\'HTTPS\'] === \'on\') ? \'https\' : \'http\';
    $domain = $protocol . \'://\' . $_SERVER[\'HTTP_HOST\'];
    $label = "PragmaVet Reservation Fee - " . $appt[\'service_type\'] . " for " . $appt[\'pet_name\'];
    $result = paymongo_create_checkout(
        500.00,
        $label,
        $domain . \'/pragmavet/appointments.php?pm_success=reservation&appt_id=\' . $appt_id,
        $domain . \'/pragmavet/appointments.php?pm_cancelled=1&appt_id=\' . $appt_id,
        [\'appt_id\' => (string)$appt_id, \'type\' => \'reservation\']
    );
    if (isset($result[\'url\'])) {
        header("Location: " . $result[\'url\']);
        exit();
    } else {
        $err = $result[\'error\'] ?? json_encode($result);
        $_SESSION[\'message\'] = "Payment gateway error: " . $err;
        $_SESSION[\'msg_type\'] = "danger";
        header("Location: appointments.php");
        exit();
    }
}

// ===== RESERVATION FEE: PayMongo Success Callback =====
if (isset($_GET[\'pm_success\']) && $_GET[\'pm_success\'] === \'reservation\' && isset($_GET[\'appt_id\'])) {
    $appt_id = intval($_GET[\'appt_id\']);
    $conn->query("UPDATE appointments SET downpayment_amount=500.00, downpayment_status=\'Paid\', downpayment_paid_at=NOW() WHERE id=$appt_id AND user_id=$user_id");
    $conn->query("UPDATE billing SET downpayment_amount=500.00, downpayment_status=\'Paid\', downpayment_paid_at=NOW(), payment_status=\'Partial\' WHERE appointment_id=$appt_id");
    $note = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
    if ($note) {
        $notif_msg = "Reservation fee of P500 paid for Appointment #$appt_id. Your slot is secured.";
        $note->bind_param("is", $user_id, $notif_msg);
        $note->execute();
    }
    $_SESSION[\'message\'] = "Reservation fee paid! Your appointment slot is secured. Staff will confirm shortly.";
    $_SESSION[\'msg_type\'] = "success";
    header("Location: appointments.php");
    exit();
}

// ===== RESERVATION FEE: Payment Cancelled =====
if (isset($_GET[\'pm_cancelled\']) && isset($_GET[\'appt_id\'])) {
    $_SESSION[\'message\'] = "Payment was cancelled. Your appointment is saved but the reservation fee was not paid.";
    $_SESSION[\'msg_type\'] = "warning";
    header("Location: appointments.php");
    exit();
}
';

if (strpos($content, '// ===== RESERVATION FEE: Initiate PayMongo Checkout =====') === false) {
    $content = str_replace($after_rbac, $payment_handlers, $content);
    file_put_contents($file, $content);
    echo "Payment handlers injected.\n";
} else {
    echo "Handlers already exist.\n";
}
?>
