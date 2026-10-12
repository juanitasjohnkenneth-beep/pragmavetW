<?php
// process_demo_payment.php
// Called by payment_demo.php via fetch() to update the billing record directly
// This ensures payment is recorded even if session-based redirect fails.
session_start();
include("dbconnection.php");

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])){
    echo json_encode(['success'=>false,'error'=>'Not authenticated']);
    exit();
}

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
$pay_type   = $_POST['pay_type'] ?? ''; // 'downpayment' or 'full'

if(!$invoice_id || !in_array($pay_type, ['downpayment','full'])){
    echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
    exit();
}

// Fetch billing record with boarding dates for recalculation
$bRow = $conn->query("
    SELECT b.id, b.total_amount, b.boarding_res_id, b.is_boarding,
           br.appointment_date, br.end_date
    FROM billing b
    LEFT JOIN boarding_reservations br ON b.boarding_res_id = br.id
    WHERE b.id = $invoice_id AND b.is_boarding = 1
")->fetch_assoc();

if(!$bRow){
    echo json_encode(['success'=>false,'error'=>'Invoice not found']);
    exit();
}

$now   = date('Y-m-d H:i:s');
$total = (float)$bRow['total_amount'];

// Recalculate if total is 0
if($total <= 0 && !empty($bRow['appointment_date']) && !empty($bRow['end_date'])){
    $days  = max(1, round((strtotime($bRow['end_date']) - strtotime($bRow['appointment_date'])) / 86400));
    $total = round($days * 800, 2);
    $conn->query("UPDATE billing SET total_amount=$total WHERE id=$invoice_id");
}

$dp     = round($total * 0.30, 2);
$res_id = (int)($bRow['boarding_res_id'] ?? 0);

if($pay_type === 'downpayment'){
    $ok = $conn->query("UPDATE billing SET
        downpayment_status = 'Paid',
        downpayment_amount = $dp,
        downpayment_paid_at = '$now'
        WHERE id = $invoice_id");
    if($res_id) $conn->query("UPDATE boarding_reservations SET status='Approved' WHERE id=$res_id");
    echo json_encode([
        'success'  => (bool)$ok,
        'pay_type' => 'downpayment',
        'amount'   => $dp,
        'total'    => $total,
        'message'  => 'Downpayment of ₱'.number_format($dp,2).' confirmed.'
    ]);
} else {
    $ok = $conn->query("UPDATE billing SET
        payment_status    = 'Paid',
        downpayment_status = 'Paid',
        downpayment_amount = $total,
        downpayment_paid_at = '$now'
        WHERE id = $invoice_id");
    if($res_id) $conn->query("UPDATE boarding_reservations SET status='Approved' WHERE id=$res_id");
    echo json_encode([
        'success'  => (bool)$ok,
        'pay_type' => 'full',
        'amount'   => $total,
        'total'    => $total,
        'message'  => 'Full payment of ₱'.number_format($total,2).' confirmed.'
    ]);
}
