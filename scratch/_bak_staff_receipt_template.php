<?php
// Official receipt for a paid invoice — printed by clinic staff (staff / admin / owner only)
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
$total   = (float)$bill['total_amount'];
$paid_now = max(0, $total - $credit);
$inv_no  = $bill['invoice_no'] ?: 'INV-' . str_pad($bill['id'], 5, '0', STR_PAD_LEFT);
$issued_by = $_SESSION['user'] ?? 'Clinic Staff';
$peso = fn($v) => '₱' . number_format((float)$v, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Official Receipt <?= htmlspecialchars($inv_no) ?> | PragmaVet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
    body { background: #f1f5f9; font-family: 'Inter', Arial, sans-serif; color: #0f172a; padding: 30px 16px; }
    .receipt { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 36px 40px; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
    .clinic { text-align: center; border-bottom: 3px double #0E5A6B; padding-bottom: 14px; margin-bottom: 18px; }
    .clinic h1 { font-size: 21px; color: #0E5A6B; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 1px; font-weight: 800; }
    .clinic p { margin: 1px 0; color: #475569; font-size: 12px; }
    .title { text-align: center; font-weight: 800; letter-spacing: 2px; color: #0E5A6B; font-size: 16px; margin-bottom: 18px; }
    .meta td { padding: 3px 0; font-size: 13px; vertical-align: top; }
    .meta td:first-child { color: #64748b; width: 38%; }
    table.items { width: 100%; border-collapse: collapse; margin: 18px 0 10px; font-size: 13px; }
    table.items th { background: #0E5A6B; color: #fff; padding: 7px 10px; text-align: left; font-weight: 600; }
    table.items td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: right; white-space: nowrap; }
    .totals td { padding: 4px 10px; font-size: 13px; }
    .totals .grand td { font-size: 16px; font-weight: 800; border-top: 2px solid #0f172a; padding-top: 8px; }
    .paid-stamp { display: inline-block; border: 3px solid #16a34a; color: #16a34a; font-weight: 800; letter-spacing: 3px; padding: 4px 16px; border-radius: 8px; transform: rotate(-6deg); }
    .sign { margin-top: 36px; display: flex; justify-content: space-between; gap: 30px; font-size: 12px; color: #475569; }
    .sign div { flex: 1; text-align: center; border-top: 1px solid #0f172a; padding-top: 6px; }
    .actions { max-width: 640px; margin: 0 auto 16px; display: flex; gap: 10px; justify-content: flex-end; }
    @media print {
        body { background: #fff; padding: 0; }
        .receipt { box-shadow: none; border-radius: 0; padding: 10px 6px; max-width: 100%; }
        .actions { display: none !important; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>
    <div class="actions">
        <a href="<?= $is_boarding ? 'staff_boarding.php' : 'staff_dashboard.php?tab=appointments&filter=all' ?>" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fa fa-print me-1"></i> Print Receipt</button>
    </div>

    <div class="receipt">
        <div class="clinic">
            <h1>PragmaVet Animal Clinic</h1>
            <p>0234 Unit B Palico St, Palico 4, Imus, Cavite, Philippines</p>
            <p>Tel: 046-8851-221 &bull; 0954-285-3053 &bull; pragmavet@gmail.com</p>
        </div>
        <div class="title">OFFICIAL RECEIPT</div>

        <div class="d-flex justify-content-between align-items-start">
            <table class="meta">
                <tr><td>Receipt / Invoice No.</td><td><b><?= htmlspecialchars($inv_no) ?></b></td></tr>
                <tr><td>Date Paid</td><td><?= $bill['paid_at'] ? date('F d, Y g:i A', strtotime($bill['paid_at'])) : date('F d, Y') ?></td></tr>
                <tr><td>Client</td><td><?= htmlspecialchars($bill['client_name'] ?: ($bill['owner_name'] ?? '')) ?></td></tr>
                <tr><td>Contact</td><td><?= htmlspecialchars($bill['client_phone'] ?: ($bill['contact_number'] ?? '—')) ?></td></tr>
                <tr><td>Patient (Pet)</td><td><?= htmlspecialchars($bill['pet_name'] ?: ($bill['stay_pet'] ?? '')) ?></td></tr>
                <?php if ($is_boarding && !empty($bill['stay_start'])): ?>
                <tr><td>Boarding Stay</td><td><?= date('M d', strtotime($bill['stay_start'])) ?> → <?= date('M d, Y', strtotime($bill['stay_end'])) ?><?= $bill['cage_number'] ? ' · Cage ' . str_pad((int)$bill['cage_number'], 2, '0', STR_PAD_LEFT) : '' ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($bill['appointment_date'])): ?>
                <tr><td>Visit</td><td><?= date('M d, Y', strtotime($bill['appointment_date'])) ?><?= !empty($bill['doctor_name']) ? ' · ' . htmlspecialchars($bill['doctor_name']) : '' ?></td></tr>
                <?php endif; ?>
            </table>
            <span class="paid-stamp">PAID</span>
        </div>

        <table class="items">
            <thead><tr><th>Service / Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($items as $it): $q = (int)($it['qty'] ?? 1); $p = (float)($it['price'] ?? 0); ?>
                <tr><td><?= htmlspecialchars($it['name'] ?? '') ?></td><td class="num"><?= $q ?></td><td class="num"><?= $peso($p) ?></td><td class="num"><?= $peso($q * $p) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="totals" style="width:100%;">
            <tr><td>Total Charges</td><td class="num"><?= $peso($total) ?></td></tr>
            <?php if ($credit > 0): ?><tr><td>Less: <?= $is_boarding ? 'Deposit / reservation fee' : 'Reservation Fee' ?> (paid at booking)</td><td class="num">− <?= $peso($credit) ?></td></tr><?php endif; ?>
            <tr class="grand"><td>Amount Paid<?= $bill['payment_method'] ? ' (' . htmlspecialchars($bill['payment_method']) . ')' : '' ?></td><td class="num"><?= $peso($paid_now) ?></td></tr>
            <?php if ((float)$bill['change_amount'] > 0): ?><tr><td>Change</td><td class="num"><?= $peso($bill['change_amount']) ?></td></tr><?php endif; ?>
            <?php if (!empty($bill['reference_no'])): ?><tr><td>Reference No.</td><td class="num"><?= htmlspecialchars($bill['reference_no']) ?></td></tr><?php endif; ?>
            <tr><td>Balance</td><td class="num"><?= $peso(0) ?></td></tr>
        </table>

        <div class="sign">
            <div>Received by: <?= htmlspecialchars($issued_by) ?></div>
            <div>Client Signature</div>
        </div>
        <p class="text-center text-muted mt-4 mb-0" style="font-size:11px;">Thank you for trusting PragmaVet with your pet's care.<br>Printed <?= date('F d, Y g:i A', strtotime($conn->query("SELECT NOW() t")->fetch_assoc()['t'])) ?></p>
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
