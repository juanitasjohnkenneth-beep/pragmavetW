<?php
// Official receipt for a paid invoice — printed by the vet staff who received the payment (admin / owner can view it too)
session_start();
include("dbconnection.php");
include_once("includes/rbac_guard.php");

$role = rbac_get_role();
if (!isset($_SESSION['user_id']) || !in_array($role, ['staff', 'admin', 'clinic_owner'], true)) {
    header("Location: index.php");
    exit();
}

$bill_id = (int)($_GET['bill'] ?? 0);
$st = $conn->prepare("
    SELECT b.*, a.service_type, a.appointment_date, a.appointment_time, d.fullname AS doctor_name, u.fullname AS owner_name, u.contact_number,
           br.appointment_date AS stay_start, br.end_date AS stay_end, br.cage_number, br.pet_name AS stay_pet
    FROM billing b
    LEFT JOIN appointments a ON a.id = b.appointment_id AND b.is_boarding = 0
    LEFT JOIN boarding_reservations br ON b.is_boarding = 1 AND br.id = COALESCE(b.boarding_res_id, b.appointment_id)
    LEFT JOIN users d ON d.id = a.doctor_id
    LEFT JOIN users u ON u.id = COALESCE(b.user_id, br.user_id)
    WHERE b.id = ?
");
$st->bind_param("i", $bill_id);
$st->execute();
$bill = $st->get_result()->fetch_assoc();
if (!$bill) die("Invoice not found.");
if ($bill['payment_status'] !== 'Paid') die("This invoice is not paid yet — a receipt can only be printed after payment.");

$items   = json_decode($bill['items_json'] ?? '', true) ?: [];
$is_boarding = (int)$bill['is_boarding'] === 1;
$credit  = in_array($bill['downpayment_status'], $is_boarding ? ['Paid', 'Partial'] : ['Paid'], true) ? (float)$bill['downpayment_amount'] : 0.0;
if (!$items && $is_boarding) $items = [['name' => 'Pet Boarding' . ($bill['stay_start'] ? ' (' . date('M d', strtotime($bill['stay_start'])) . ' → ' . date('M d, Y', strtotime($bill['stay_end'])) . ')' : ''), 'qty' => 1, 'price' => (float)$bill['total_amount']]];
if (!$items) $items = [['name' => $bill['service_type'] ?: ($bill['service_category'] ?: 'Clinic Services'), 'qty' => 1, 'price' => (float)$bill['total_amount']]];
$total    = (float)$bill['total_amount'];
$discount = (float)($bill['discount_amount'] ?? 0);
$paid_now = max(0, $total - $credit);
$subtotal = 0.0;
foreach ($items as $it) $subtotal += isset($it['subtotal']) ? (float)$it['subtotal'] : (int)($it['qty'] ?? 1) * (float)($it['price'] ?? 0);
$inv_no  = $bill['invoice_no'] ?: 'INV-' . str_pad($bill['id'], 5, '0', STR_PAD_LEFT);
$issued_by = $_SESSION['user'] ?? 'Clinic Staff';
$peso = fn($v) => '₱ ' . number_format((float)$v, 2);
$back = $role === 'staff' ? ($is_boarding ? 'staff_boarding.php' : 'staff_dashboard.php?tab=appointments&filter=all') : 'admin_billing.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Official Receipt <?= htmlspecialchars($inv_no) ?> | PragmaVet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { background: #f1f5f9; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; color: #0f172a; padding: 28px 16px; }
    .rc-wrap { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 20px; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
    .rc-top { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px 0; }
    .rc-top h5 { font-weight: 700; margin: 0; font-size: 17px; }
    .rc-body { padding: 18px 24px; }
    .receipt-card { background: #fff; border: 1px dashed #cbd5e1; border-radius: 16px; padding: 30px; }
    .receipt-header { text-align: center; border-bottom: 2px dashed #94a3b8; padding-bottom: 16px; margin-bottom: 20px; }
    .receipt-header .name { font-weight: 800; font-size: 21px; text-transform: uppercase; letter-spacing: .5px; }
    .receipt-header .sub { font-size: 12.5px; color: #64748b; }
    .rc-badge { display: inline-block; background: #212529; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 14px; border-radius: 5px; margin-top: 8px; letter-spacing: .3px; }
    .rc-row { display: flex; justify-content: space-between; gap: 12px; font-size: 13.5px; margin-bottom: 5px; }
    .rc-row .k { color: #64748b; }
    .rc-row .v { color: #0f172a; text-align: right; }
    .receipt-table { width: 100%; margin: 14px 0 12px; border-collapse: collapse; }
    .receipt-table th { border-bottom: 1px solid #000; color: #000; font-size: 12px; font-weight: 700; text-transform: uppercase; padding: 8px 4px; }
    .receipt-table td { border-bottom: 1px dashed #e2e8f0; padding: 8px 4px; font-size: 13.5px; }
    .num { text-align: right; white-space: nowrap; }
    .ctr { text-align: center; }
    .rc-totals { border-top: 1px solid #dee2e6; padding-top: 8px; }
    .rc-grand { display: flex; justify-content: space-between; font-weight: 700; font-size: 16px; border-top: 1px solid #dee2e6; padding-top: 8px; margin: 4px 0 5px; }
    .rc-foot { text-align: center; border-top: 2px dashed #dee2e6; padding-top: 14px; margin-top: 14px; font-size: 13px; color: #64748b; }
    .rc-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 0 24px 22px; }
    .rc-actions .btn { border-radius: 99px; padding: 9px 22px; font-weight: 700; font-size: 14px; }
    .rc-print { background: #2563eb; border: 0; color: #fff; }
    .rc-print:hover { background: #1d4ed8; color: #fff; }
    @media print {
        body { background: #fff; padding: 0; }
        .rc-wrap { box-shadow: none; border-radius: 0; max-width: 100%; }
        .no-print { display: none !important; }
        .rc-body { padding: 0; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>
    <div class="rc-wrap">
        <div class="rc-top no-print">
            <h5><i class="fa fa-receipt me-2" style="color:#2563eb;"></i> Official Clinic Receipt</h5>
        </div>
        <div class="rc-body">
            <div class="receipt-card">
                <div class="receipt-header">
                    <div class="name">PragmaVet Animal Clinic</div>
                    <div class="sub">Complete Veterinary Medical &amp; Boarding Care</div>
                    <div class="sub">0234 Unit B Palico St, Palico 4, Imus, Cavite</div>
                    <div class="sub">Tel: 046-8851-221 | 0954-285-3053</div>
                    <span class="rc-badge">Official Receipt</span>
                </div>

                <div class="rc-row"><span class="k">Invoice No:</span><span class="v fw-bold"><?= htmlspecialchars($inv_no) ?></span></div>
                <div class="rc-row"><span class="k">Date &amp; Time:</span><span class="v"><?= $bill['paid_at'] ? date('M d, Y h:i A', strtotime($bill['paid_at'])) : date('M d, Y h:i A', strtotime($bill['created_at'])) ?></span></div>
                <div class="rc-row"><span class="k">Client Name:</span><span class="v fw-semibold"><?= htmlspecialchars($bill['client_name'] ?: ($bill['owner_name'] ?? '')) ?></span></div>
                <div class="rc-row"><span class="k">Patient (Pet):</span><span class="v fw-semibold"><?= htmlspecialchars($bill['pet_name'] ?: ($bill['stay_pet'] ?? '')) ?></span></div>
                <?php if ($is_boarding && !empty($bill['stay_start'])): ?>
                <div class="rc-row"><span class="k">Boarding Stay:</span><span class="v"><?= date('M d', strtotime($bill['stay_start'])) ?> – <?= date('M d, Y', strtotime($bill['stay_end'])) ?><?= $bill['cage_number'] ? ' · Cage ' . str_pad((int)$bill['cage_number'], 2, '0', STR_PAD_LEFT) : '' ?></span></div>
                <?php elseif (!empty($bill['doctor_name'])): ?>
                <div class="rc-row"><span class="k">Veterinarian:</span><span class="v"><?= htmlspecialchars($bill['doctor_name']) ?></span></div>
                <?php endif; ?>
                <div class="rc-row" style="margin-bottom:0;"><span class="k">Cashier / Staff:</span><span class="v"><?= htmlspecialchars($issued_by) ?></span></div>

                <table class="receipt-table">
                    <thead><tr><th>Description</th><th class="ctr">Qty</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $it):
                            $q = (int)($it['qty'] ?? 1); $p = (float)($it['price'] ?? 0);
                            $line = isset($it['subtotal']) ? (float)$it['subtotal'] : $q * $p; ?>
                        <tr><td><?= htmlspecialchars($it['name'] ?? '') ?></td><td class="ctr"><?= $q ?></td><td class="num"><?= $peso($p) ?></td><td class="num fw-semibold"><?= $peso($line) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="rc-totals">
                    <div class="rc-row"><span class="k">Subtotal:</span><span class="v fw-semibold"><?= $peso($subtotal) ?></span></div>
                    <?php if ($discount > 0): ?><div class="rc-row text-success"><span>Discount:</span><span class="fw-semibold">- <?= $peso($discount) ?></span></div><?php endif; ?>
                    <div class="rc-grand"><span>Grand Total:</span><span><?= $peso($total) ?></span></div>
                    <?php if ($credit > 0): ?><div class="rc-row text-success"><span><?= $is_boarding ? 'Deposit' : 'Reservation Fee' ?> (paid at booking):</span><span class="fw-bold"><?= $peso($credit) ?></span></div><?php endif; ?>
                    <div class="rc-row text-success"><span>Amount Paid<?= $credit > 0 ? ' (today)' : '' ?>:</span><span class="fw-bold"><?= $peso($paid_now) ?></span></div>
                    <?php if ((float)$bill['change_amount'] > 0): ?><div class="rc-row"><span class="k">Change:</span><span class="v fw-semibold"><?= $peso($bill['change_amount']) ?></span></div><?php endif; ?>
                    <div class="rc-row"><span class="k">Payment Method:</span><span class="v fw-semibold"><?= htmlspecialchars($bill['payment_method'] ?: 'Cash') ?></span></div>
                    <?php if (!empty($bill['reference_no'])): ?><div class="rc-row"><span class="k">Reference No:</span><span class="v fw-semibold"><?= htmlspecialchars($bill['reference_no']) ?></span></div><?php endif; ?>
                </div>

                <div class="rc-foot">
                    <p class="mb-1">Thank you for trusting PragmaVet with your pet's care!</p>
                    <p class="fst-italic mb-0">For medical inquiries, reach us at pragmavet@gmail.com</p>
                </div>
            </div>
        </div>
        <div class="rc-actions no-print">
            <a href="<?= $back ?>" class="btn btn-outline-secondary">Close</a>
            <button onclick="window.print()" class="btn rc-print"><i class="fa fa-print me-2"></i> Print Official Slip</button>
        </div>
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
