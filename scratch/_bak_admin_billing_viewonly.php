<?php
session_start();
include("dbconnection.php");
include_once("includes/service_prices.php");
include_once("includes/client_notify.php");

$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_owner = ($user_role === 'clinic_owner' || $user_role === 'owner');

if (!isset($_SESSION['user_id']) || (!$is_admin && !$is_owner)) {
    if ($user_role === 'doctor') {
        header("Location: doctor.php");
    } elseif ($user_role === 'staff') {
        header("Location: staff_dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

// Ensure helpful columns exist in billing table (fail-safe idempotency)
$helper_cols = [
    'invoice_no'       => "VARCHAR(50) NULL AFTER `id`",
    'user_id'          => "INT(11) NULL AFTER `appointment_id`",
    'client_name'      => "VARCHAR(150) NULL AFTER `user_id`",
    'client_phone'     => "VARCHAR(50) NULL AFTER `client_name`",
    'pet_name'         => "VARCHAR(100) NULL AFTER `client_phone`",
    'service_category' => "VARCHAR(100) DEFAULT 'Clinic' AFTER `is_boarding`",
    'items_json'       => "LONGTEXT NULL",
    'discount_amount'  => "DECIMAL(10,2) DEFAULT 0.00",
    'discount_type'    => "VARCHAR(50) NULL",
    'amount_paid'      => "DECIMAL(10,2) DEFAULT 0.00",
    'change_amount'    => "DECIMAL(10,2) DEFAULT 0.00",
    'payment_method'   => "VARCHAR(50) DEFAULT 'Cash'",
    'reference_no'     => "VARCHAR(100) NULL",
    'paid_at'          => "DATETIME NULL",
    'notes'            => "TEXT NULL"
];
foreach ($helper_cols as $c_name => $c_def) {
    $chk = $conn->query("SHOW COLUMNS FROM `billing` LIKE '$c_name'");
    if ($chk && $chk->num_rows == 0) {
        $conn->query("ALTER TABLE `billing` ADD COLUMN `$c_name` $c_def");
    }
}

// Boarding invoices are created by the booking itself or by vet staff at check-out (includes/boarding_billing.php),
// so this monitoring page no longer creates any.

// ── Backfill any billing record missing invoice_no ──
$unnum = $conn->query("SELECT id, YEAR(COALESCE(created_at, NOW())) yr FROM billing WHERE invoice_no IS NULL OR invoice_no = ''");
if ($unnum && $unnum->num_rows > 0) {
    while ($un = $unnum->fetch_assoc()) {
        $i_id = $un['id'];
        $inv_code = "INV-" . $un['yr'] . "-" . str_pad($i_id, 5, '0', STR_PAD_LEFT);
        $conn->query("UPDATE billing SET invoice_no='$inv_code' WHERE id=$i_id");
    }
}

// ── Admin role on this page: oversight. Vet staff create invoices and collect payments at check-out; the admin audits,
//    prints receipts and voids an invoice issued by mistake (only while no money has been received). The clinic owner views only.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pv_act  = $_POST['action'] ?? '';
    $pv_mark = $_POST['mark_action'] ?? '';
    $pv_block = '';
    if (in_array($pv_act, ['collect_payment', 'create_invoice'], true) || in_array($pv_mark, ['mark_full', 'mark_dp', 'reset'], true)) {
        $pv_block = "Payments are collected by the vet staff at check-out. Use this page to monitor invoices and print receipts.";
    } elseif ($pv_mark === 'void') {
        $vb = $conn->query("SELECT payment_status, downpayment_status, amount_paid FROM billing WHERE id = " . (int)($_POST['bill_id'] ?? 0))->fetch_assoc();
        if (!$is_admin) $pv_block = "Only the administrator can void invoices.";
        elseif (!$vb) $pv_block = "Invoice not found.";
        elseif ($vb['payment_status'] === 'Void') $pv_block = "This invoice is already void.";
        elseif ((float)$vb['amount_paid'] > 0 || in_array($vb['payment_status'], ['Paid', 'Partial'], true) || $vb['downpayment_status'] === 'Paid')
            $pv_block = "This invoice already has a payment recorded, so it can't be voided.";
    }
    if ($pv_block !== '') {
        $_SESSION['message'] = $pv_block; $_SESSION['msg_type'] = 'warning';
        header("Location: admin_billing.php"); exit();
    }
}

// ── POST ACTION: Collect Payment ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_payment') {
    $bill_id = (int)$_POST['bill_id'];
    $pay_type = $_POST['payment_type'] ?? 'full'; // 'full', 'downpayment', 'custom'
    $method = $conn->real_escape_string($_POST['payment_method'] ?? 'Cash');
    $ref_no = $conn->real_escape_string($_POST['reference_no'] ?? '');
    $notes = $conn->real_escape_string($_POST['payment_notes'] ?? '');
    $tendered = (float)($_POST['cash_tendered'] ?? 0);
    $now = date('Y-m-d H:i:s');

    $bRow = $conn->query("SELECT * FROM billing WHERE id = $bill_id")->fetch_assoc();
    if ($bRow) {
        $total = (float)$bRow['total_amount'];
        $curr_paid = (float)$bRow['amount_paid'];
        if ($curr_paid <= 0 && $bRow['payment_status'] === 'Paid') {
            $curr_paid = $total;
        } elseif ($curr_paid <= 0 && $bRow['downpayment_status'] === 'Paid') {
            $curr_paid = (float)$bRow['downpayment_amount'];
        }

        $balance_due = max(0, $total - $curr_paid);
        $amount_to_pay = 0;

        if ($pay_type === 'full') {
            $amount_to_pay = $balance_due;
        } elseif ($pay_type === 'downpayment') {
            $dp_target = (float)$bRow['downpayment_amount'];
            if ($dp_target <= 0) $dp_target = round($total * 0.30, 2);
            $amount_to_pay = max(0, $dp_target - $curr_paid);
            if ($amount_to_pay <= 0) $amount_to_pay = $balance_due;
        } elseif ($pay_type === 'custom') {
            $amount_to_pay = min($balance_due, (float)($_POST['custom_amount'] ?? $balance_due));
        }

        $new_total_paid = round($curr_paid + $amount_to_pay, 2);
        $change = 0;
        if ($method === 'Cash' && $tendered > $amount_to_pay) {
            $change = round($tendered - $amount_to_pay, 2);
        }

        $new_pay_status = ($new_total_paid >= $total) ? 'Paid' : (($new_total_paid > 0) ? 'Partial' : 'Pending');
        $new_dp_status = ($new_total_paid >= ((float)$bRow['downpayment_amount'] > 0 ? (float)$bRow['downpayment_amount'] : ($total * 0.30))) ? 'Paid' : $bRow['downpayment_status'];

        $sql_up = "UPDATE billing SET 
            amount_paid = $new_total_paid,
            payment_status = '$new_pay_status',
            downpayment_status = '$new_dp_status',
            payment_method = '$method',
            reference_no = '$ref_no',
            change_amount = $change,
            paid_at = '$now',
            notes = CONCAT(IFNULL(notes, ''), ' | " . $conn->real_escape_string("Paid ₱" . number_format($amount_to_pay, 2) . " via $method on " . date('M d, Y h:i A')) . "')
            WHERE id = $bill_id";
        
        $conn->query($sql_up);

        // (no booking status change here: vet staff approve, check in and complete bookings)
        if (!empty($bRow['boarding_res_id'])) {
            // Booking status is managed by vet staff — recording a payment never approves or reopens a booking
        }
        if (!empty($bRow['appointment_id'])) {
            // Booking status is managed by vet staff — recording a payment never approves or reopens a booking
        }

        $_SESSION['message'] = "Payment of ₱" . number_format($amount_to_pay, 2) . " recorded successfully via $method. Invoice status: $new_pay_status.";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Billing record not found.";
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_billing.php");
    exit();
}

// ── POST ACTION: Create New Invoice (POS Checkout) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    $inv_type = $_POST['invoice_type'] ?? 'walkin'; // 'walkin', 'appointment', 'boarding'
    $appt_id = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 'NULL';
    $board_id = !empty($_POST['boarding_res_id']) ? (int)$_POST['boarding_res_id'] : 'NULL';
    $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : 'NULL';
    
    $client_name = $conn->real_escape_string(trim($_POST['client_name'] ?? ''));
    $client_phone = $conn->real_escape_string(trim($_POST['client_phone'] ?? ''));
    $pet_name = $conn->real_escape_string(trim($_POST['pet_name'] ?? ''));
    $service_category = $conn->real_escape_string($_POST['service_category'] ?? 'Clinic');

    // Parse line items
    $item_names = $_POST['item_name'] ?? [];
    $item_types = $_POST['item_type'] ?? [];
    $item_qtys = $_POST['item_qty'] ?? [];
    $item_prices = $_POST['item_price'] ?? [];
    $item_inv_ids = $_POST['item_inv_id'] ?? [];

    $line_items = [];
    $subtotal = 0.0;

    for ($i = 0; $i < count($item_names); $i++) {
        $n = trim($item_names[$i]);
        if ($n === '') continue;
        $qty = max(1, (int)($item_qtys[$i] ?? 1));
        $price = max(0, (float)($item_prices[$i] ?? 0));
        $line_sub = round($qty * $price, 2);
        $subtotal += $line_sub;
        $inv_id = (int)($item_inv_ids[$i] ?? 0);

        $line_items[] = [
            'name'     => $n,
            'type'     => $item_types[$i] ?? 'service',
            'qty'      => $qty,
            'price'    => $price,
            'subtotal' => $line_sub,
            'inv_id'   => $inv_id
        ];

        // Deduct inventory if product from inventory
        if ($inv_id > 0) {
            $conn->query("UPDATE inventory SET quantity = GREATEST(0, quantity - $qty) WHERE id = $inv_id");
        }
    }

    if (empty($line_items)) {
        // Fallback single line item if form was submitted with simple service
        $svc_fee = (float)($_POST['flat_amount'] ?? 500);
        $line_items[] = [
            'name'     => $service_category . ' Fee',
            'type'     => 'service',
            'qty'      => 1,
            'price'    => $svc_fee,
            'subtotal' => $svc_fee,
            'inv_id'   => 0
        ];
        $subtotal = $svc_fee;
    }

    // Discounts
    $discount_type = $conn->real_escape_string($_POST['discount_type'] ?? 'None');
    $discount_amount = 0.0;
    if ($discount_type === 'Senior_PWD') {
        $discount_amount = round($subtotal * 0.20, 2); // 20% discount
    } elseif ($discount_type === 'Custom' || $discount_type === 'Promo') {
        $discount_amount = min($subtotal, (float)($_POST['discount_amount'] ?? 0));
    }

    $grand_total = max(0, round($subtotal - $discount_amount, 2));
    $is_boarding = ($inv_type === 'boarding' || $board_id !== 'NULL') ? 1 : 0;
    $dp_amount = $is_boarding ? round($grand_total * 0.30, 2) : 0.00;

    // Payment collection right now
    $pay_now = isset($_POST['pay_now']) && $_POST['pay_now'] === '1';
    $amount_paid = 0.0;
    $change_amount = 0.0;
    $method = $conn->real_escape_string($_POST['payment_method'] ?? 'Cash');
    $ref_no = $conn->real_escape_string($_POST['reference_no'] ?? '');
    $tendered = (float)($_POST['cash_tendered'] ?? 0);
    $pay_status = 'Pending';
    $dp_status = 'Unpaid';
    $paid_at = 'NULL';

    if ($pay_now) {
        $tender_type = $_POST['pos_tender_type'] ?? 'full'; // 'full' or 'downpayment'
        if ($tender_type === 'full') {
            $amount_paid = $grand_total;
            $pay_status = 'Paid';
            $dp_status = 'Paid';
        } else {
            $amount_paid = ($dp_amount > 0) ? $dp_amount : round($grand_total * 0.30, 2);
            $pay_status = 'Partial';
            $dp_status = 'Paid';
        }
        if ($method === 'Cash' && $tendered > $amount_paid) {
            $change_amount = round($tendered - $amount_paid, 2);
        }
        $paid_at = "'" . date('Y-m-d H:i:s') . "'";
    }

    $items_json = $conn->real_escape_string(json_encode($line_items));
    $notes = $conn->real_escape_string($_POST['invoice_notes'] ?? '');

    $ins_sql = "INSERT INTO billing (
        appointment_id, user_id, client_name, client_phone, pet_name,
        service_category, items_json, total_amount, discount_amount, discount_type,
        amount_paid, change_amount, payment_method, reference_no,
        downpayment_amount, downpayment_status, payment_status, is_boarding,
        boarding_res_id, paid_at, notes, created_at
    ) VALUES (
        $appt_id, $user_id, '$client_name', '$client_phone', '$pet_name',
        '$service_category', '$items_json', $grand_total, $discount_amount, '$discount_type',
        $amount_paid, $change_amount, '$method', '$ref_no',
        $dp_amount, '$dp_status', '$pay_status', $is_boarding,
        $board_id, $paid_at, '$notes', NOW()
    )";

    if ($conn->query($ins_sql)) {
        $bill_id = $conn->insert_id;
        $inv_no = "INV-" . date('Y') . "-" . str_pad($bill_id, 5, '0', STR_PAD_LEFT);
        $conn->query("UPDATE billing SET invoice_no='$inv_no' WHERE id=$bill_id");
        $cl_uid = bill_owner_id($conn, $conn->query("SELECT * FROM billing WHERE id=$bill_id")->fetch_assoc());
        if ($cl_uid) notify_user($conn, $cl_uid, $pay_now
            ? "Payment received: ₱" . number_format($amount_paid, 2) . " for invoice $inv_no. Thank you!"
            : "A new invoice ($inv_no, ₱" . number_format($grand_total, 2) . ") was issued to you. Tap to view it.", 'billing.php');

        // (no booking status change here: vet staff approve, check in and complete bookings)
        if ($appt_id !== 'NULL' && $pay_status === 'Paid') {
            // Booking status is managed by vet staff — recording a payment never approves or reopens a booking
        }
        if ($board_id !== 'NULL' && ($pay_status === 'Paid' || $dp_status === 'Paid')) {
            // Booking status is managed by vet staff — recording a payment never approves or reopens a booking
        }

        $_SESSION['message'] = "Invoice #$inv_no created successfully (" . ($pay_now ? "Settled ₱" . number_format($amount_paid, 2) : "Status: Pending") . ").";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error generating invoice: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_billing.php");
    exit();
}

// ── POST ACTION: Quick Mark or Reset ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_action'], $_POST['bill_id'])) {
    $bill_id = (int)$_POST['bill_id'];
    $act = $_POST['mark_action'];
    $now = date('Y-m-d H:i:s');

    $bRow = $conn->query("SELECT * FROM billing WHERE id = $bill_id")->fetch_assoc();
    if ($bRow) {
        $total = (float)$bRow['total_amount'];
        $dp = (float)$bRow['downpayment_amount'];
        if ($dp <= 0) $dp = round($total * 0.30, 2);

        if ($act === 'mark_full') {
            $conn->query("UPDATE billing SET payment_status='Paid', downpayment_status='Paid', amount_paid=$total, paid_at='$now' WHERE id=$bill_id");
            // Booking status is managed by vet staff — recording a payment never approves or reopens a booking
            $_SESSION['message'] = "Invoice marked as Fully Paid (₱" . number_format($total, 2) . ").";
            if ($cl_uid = bill_owner_id($conn, $bRow)) notify_user($conn, $cl_uid, "Payment received: invoice " . ($bRow['invoice_no'] ?: '#' . $bill_id) . " (₱" . number_format($total, 2) . ") is now fully paid. Thank you!", 'billing.php');
            $_SESSION['msg_type'] = "success";
        } elseif ($act === 'mark_dp') {
            $conn->query("UPDATE billing SET downpayment_status='Paid', payment_status='Partial', amount_paid=$dp, downpayment_paid_at='$now', paid_at='$now' WHERE id=$bill_id");
            // Booking status is managed by vet staff — recording a payment never approves or reopens a booking
            $_SESSION['message'] = "Downpayment of ₱" . number_format($dp, 2) . " marked as Paid.";
            if ($cl_uid = bill_owner_id($conn, $bRow)) notify_user($conn, $cl_uid, "Payment received: ₱" . number_format($dp, 2) . " downpayment for invoice " . ($bRow['invoice_no'] ?: '#' . $bill_id) . ". Thank you!", 'billing.php');
            $_SESSION['msg_type'] = "success";
        } elseif ($act === 'reset') {
            $conn->query("UPDATE billing SET payment_status='Pending', downpayment_status='Unpaid', amount_paid=0.00, paid_at=NULL WHERE id=$bill_id");
            $_SESSION['message'] = "Bill reset to Unpaid/Pending.";
            $_SESSION['msg_type'] = "warning";
        } elseif ($act === 'void') {
            $conn->query("UPDATE billing SET payment_status='Void' WHERE id=$bill_id");
            $_SESSION['message'] = "Invoice " . ($bRow['invoice_no'] ?: '#' . $bill_id) . " has been voided.";
            if ($cl_uid = bill_owner_id($conn, $bRow)) notify_user($conn, $cl_uid, "Invoice " . ($bRow['invoice_no'] ?: '#' . $bill_id) . " was cancelled by the clinic. You don't need to pay it.", 'billing.php');
            $_SESSION['msg_type'] = "secondary";
        }
    }
    header("Location: admin_billing.php");
    exit();
}

// ── Search & Filter Parameters ──
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$date_range = $_GET['range'] ?? 'all';

$where_clauses = ["1=1"];

if ($search !== '') {
    $safe_s = $conn->real_escape_string($search);
    $where_clauses[] = "(
        b.invoice_no LIKE '%$safe_s%' OR 
        b.client_name LIKE '%$safe_s%' OR 
        b.pet_name LIKE '%$safe_s%' OR 
        b.reference_no LIKE '%$safe_s%' OR 
        u.fullname LIKE '%$safe_s%' OR 
        br.pet_name LIKE '%$safe_s%' OR 
        a.pet_name LIKE '%$safe_s%'
    )";
}

if ($status_filter === 'paid') {
    $where_clauses[] = "b.payment_status = 'Paid'";
} elseif ($status_filter === 'partial') {
    $where_clauses[] = "(b.payment_status = 'Partial' OR (b.downpayment_status = 'Paid' AND b.payment_status != 'Paid'))";
} elseif ($status_filter === 'unpaid') {
    $where_clauses[] = "(b.payment_status IN ('Pending', 'Unpaid') AND (b.downpayment_status != 'Paid' OR b.downpayment_status IS NULL))";
} elseif ($status_filter === 'void') {
    $where_clauses[] = "b.payment_status = 'Void'";
}

if ($category_filter !== 'all') {
    $safe_cat = $conn->real_escape_string($category_filter);
    if ($safe_cat === 'Boarding') {
        $where_clauses[] = "b.is_boarding = 1";
    } else {
        $where_clauses[] = "(b.service_category = '$safe_cat' OR a.service_type LIKE '%$safe_cat%')";
    }
}

if ($date_range === 'today') {
    $where_clauses[] = "DATE(b.created_at) = CURDATE()";
} elseif ($date_range === 'week') {
    $where_clauses[] = "b.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($date_range === 'month') {
    $where_clauses[] = "b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$where_sql = implode(' AND ', $where_clauses);

// ── Unified Fetch: Invoices Data Query ──
$invoices_sql = "
    SELECT 
        b.id AS bill_id,
        b.invoice_no,
        b.total_amount,
        b.discount_amount,
        b.discount_type,
        b.amount_paid,
        b.change_amount,
        b.payment_method,
        b.reference_no,
        b.downpayment_amount,
        b.downpayment_status,
        b.downpayment_paid_at,
        b.payment_status,
        b.is_boarding,
        b.service_category,
        b.items_json,
        b.notes,
        b.paid_at,
        b.created_at,
        
        -- Client info priority: billing column -> registered user
        COALESCE(NULLIF(b.client_name, ''), u.fullname, 'Walk-in Client') AS display_client_name,
        COALESCE(NULLIF(b.client_phone, ''), u.contact_number, '') AS display_client_phone,
        u.email AS client_email,
        
        -- Pet info priority: billing column -> appointment pet -> boarding pet
        COALESCE(NULLIF(b.pet_name, ''), a.pet_name, br.pet_name, 'Pet') AS display_pet_name,
        
        -- Appointment links
        a.id AS appt_id,
        a.service_type AS appt_service,
        a.appointment_date AS appt_date,
        a.appointment_time AS appt_time,
        a.status AS appt_status,
        
        -- Boarding links
        br.id AS res_id,
        br.appointment_date AS checkin,
        br.end_date AS checkout,
        br.cage_number,
        br.status AS res_status
    FROM billing b
    LEFT JOIN users u ON (b.user_id = u.id OR (b.user_id IS NULL AND b.appointment_id IS NOT NULL AND (SELECT user_id FROM appointments WHERE id=b.appointment_id)=u.id) OR (b.user_id IS NULL AND b.boarding_res_id IS NOT NULL AND (SELECT user_id FROM boarding_reservations WHERE id=b.boarding_res_id)=u.id))
    LEFT JOIN appointments a ON b.appointment_id = a.id
    LEFT JOIN boarding_reservations br ON b.boarding_res_id = br.id
    WHERE $where_sql
    ORDER BY b.created_at DESC, b.id DESC
";
$invoices_res = $conn->query($invoices_sql);

// ── Overall Financial Metrics Aggregation ──
$metrics_q = $conn->query("
    SELECT 
        COUNT(*) AS total_invoices,
        IFNULL(SUM(CASE WHEN DATE(paid_at) = CURDATE() OR (paid_at IS NULL AND payment_status='Paid' AND DATE(created_at)=CURDATE()) THEN 
            (CASE WHEN amount_paid > 0 THEN amount_paid ELSE total_amount END) ELSE 0 END), 0) AS today_collected,
        IFNULL(SUM(CASE WHEN payment_status != 'Paid' AND payment_status != 'Void' THEN 
            GREATEST(0, total_amount - (CASE WHEN amount_paid > 0 THEN amount_paid WHEN downpayment_status='Paid' THEN downpayment_amount ELSE 0 END)) ELSE 0 END), 0) AS outstanding_balance,
        IFNULL(SUM(CASE WHEN is_boarding = 1 AND downpayment_status = 'Paid' AND payment_status != 'Paid' THEN 
            (CASE WHEN amount_paid > 0 THEN amount_paid ELSE downpayment_amount END) ELSE 0 END), 0) AS active_deposits,
        SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN payment_status = 'Partial' OR (downpayment_status='Paid' AND payment_status != 'Paid') THEN 1 ELSE 0 END) AS partial_count,
        SUM(CASE WHEN payment_status IN ('Pending', 'Unpaid') AND (downpayment_status != 'Paid' OR downpayment_status IS NULL) THEN 1 ELSE 0 END) AS unpaid_count
    FROM billing
");
$metrics = $metrics_q->fetch_assoc() ?? [
    'total_invoices' => 0,
    'today_collected' => 0,
    'outstanding_balance' => 0,
    'active_deposits' => 0,
    'paid_count' => 0,
    'partial_count' => 0,
    'unpaid_count' => 0
];

// ── Pull Active Inventory for POS Selector ──
$inventory_items = [];
$inv_q = $conn->query("SELECT id, item_name, category, price_per_unit, quantity, unit_of_measure FROM inventory WHERE quantity > 0 ORDER BY item_name ASC");
if ($inv_q) {
    while ($inv = $inv_q->fetch_assoc()) {
        $inventory_items[] = $inv;
    }
}

// ── Pull Clients for POS Selector ──
$clients_list = [];
$cl_q = $conn->query("SELECT id, fullname, contact_number, email FROM users WHERE role IN ('client', 'user') ORDER BY fullname ASC");
if ($cl_q) {
    while ($cl = $cl_q->fetch_assoc()) {
        $clients_list[] = $cl;
    }
}

// ── Pull Pending Appointments to Link ──
$unbilled_appts = [];
$ua_q = $conn->query("
    SELECT a.id, a.pet_name, a.service_type, a.appointment_date, a.user_id, u.fullname, u.contact_number
    FROM appointments a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.status IN ('Approved', 'Completed', 'Checked In')
    ORDER BY a.appointment_date DESC LIMIT 30
");
if ($ua_q) {
    while ($ua = $ua_q->fetch_assoc()) {
        $unbilled_appts[] = $ua;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script src="theme.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="theme.css?v=<?= time() ?>">
    <title>Billing & Payments | PragmaVet Admin</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">

    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --sidebar-width: 260px;
            --bg-color: #f8fafc;
            --card-border: #e2e8f0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-color);
            color: #1e293b;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        /* ====== WHITE SIDEBAR THEME ====== */
        .sidebar {
            background: #ffffff !important;
            width: var(--sidebar-width) !important;
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
        }

        .sidebar a i {
            width: 22px !important;
            text-align: center !important;
            font-size: 16px !important;
            color: #64748b !important;
        }

        .sidebar a:hover {
            background: #f1f5f9 !important;
            color: #0f172a !important;
        }

        .sidebar a.active {
            background: #2563eb !important;
            color: #ffffff !important;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25) !important;
        }

        .sidebar a.active i {
            color: #ffffff !important;
        }

        .sidebar-logout {
            margin-top: auto !important;
            padding-top: 16px !important;
            border-top: 1px solid #eef2f6 !important;
        }

        .sidebar-logout a {
            color: #ef4444 !important;
        }

        .sidebar-logout a:hover {
            background: #fee2e2 !important;
            color: #b91c1c !important;
        }

        /* ====== MAIN CONTENT LAYOUT ====== */
        .main-content {
            margin-left: 260px !important;
            width: calc(100% - 260px) !important;
            max-width: calc(100% - 260px) !important;
            padding: 24px 28px !important;
            min-height: 100vh;
            box-sizing: border-box !important;
        }

        /* Metric Cards */
        .metric-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.03);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
        }

        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        /* ── Admin blue alignment (overrides the site-wide gradient buttons / boxed inputs) ── */
        .bh-eyebrow { display: inline-block; background: #eff6ff; color: #2563eb; font-size: 11px; font-weight: 800; letter-spacing: .4px; text-transform: uppercase; padding: 4px 12px; border-radius: 99px; margin-bottom: 8px; }
        .bh-btn-outline { background: #fff !important; color: #1d4ed8 !important; border: 1px solid #bfdbfe !important; box-shadow: none !important; }
        .bh-btn-outline:hover { background: #eff6ff !important; }
        .bh-btn-outline i { color: #16a34a; }
        .bh-search { display: flex; align-items: center; gap: 8px; background: #fff; border: 1px solid #e2e8f0; border-radius: 99px; padding: 0 14px; height: 36px; }
        .bh-search:focus-within { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
        .bh-search i { color: #94a3b8; font-size: 13px; }
        .bh-search input { border: 0 !important; outline: 0; box-shadow: none !important; background: transparent !important; padding: 0 !important; height: 34px !important; min-height: 0 !important; margin: 0 !important; font-size: 13px; width: 100%; border-radius: 0 !important; }
        .filter-toolbar .form-select { border-radius: 99px !important; border: 1px solid #e2e8f0 !important; height: 36px; font-size: 13px; box-shadow: none !important; }
        .bh-receipt { background: #fff !important; color: #2563eb !important; border: 1px solid #bfdbfe !important; box-shadow: none !important; border-radius: 10px !important; padding: 6px 14px !important; font-size: 12.5px !important; font-weight: 700 !important; white-space: nowrap; display: inline-flex !important; align-items: center; gap: 6px; }
        .bh-receipt:hover { background: #eff6ff !important; }
        .bh-void { background: #fff !important; color: #dc2626 !important; border: 1px solid #fecaca !important; box-shadow: none !important; border-radius: 10px !important; width: 34px; height: 32px; padding: 0 !important; display: inline-flex !important; align-items: center; justify-content: center; }
        .bh-void:hover { background: #fef2f2 !important; }
        #billingMainTable td:first-child .fw-bold { white-space: nowrap; }
        #billingMainTable td:first-child small { white-space: nowrap; }
        .badge-paid, .badge-partial, .badge-pending, .badge-void { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .badge-method { white-space: nowrap; }

        /* Filter Pills & Toolbar */
        .filter-toolbar {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
            margin-bottom: 24px;
        }

        .status-pill {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }

        .status-pill.active {
            background: #2563eb;
            color: #ffffff !important;
        }

        .status-pill:not(.active) {
            background: #f1f5f9;
            color: #475569;
        }

        .status-pill:not(.active):hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        /* Table Card */
        .data-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .table > :not(caption) > * > * {
            padding: 16px 18px;
            vertical-align: middle;
        }

        .table thead th {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .table tbody tr {
            transition: background 0.15s ease;
        }

        .table tbody tr:hover {
            background: #fbfcfe;
        }

        /* Status & Method Badges */
        .badge-paid {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 12px;
        }

        .badge-partial {
            background: #dbeafe;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 12px;
        }

        .badge-pending {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 12px;
        }

        .badge-void {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 12px;
        }

        .badge-method {
            background: #f1f5f9;
            color: #334155;
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Alert Toast */
        .alert-container {
            position: fixed;
            top: 25px;
            right: 35px;
            z-index: 9999;
            animation: slideDown 0.4s ease-out forwards;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Printable Official Receipt Modal Styling */
        @media print {
            body * {
                visibility: hidden;
            }
            #printableReceiptArea, #printableReceiptArea * {
                visibility: visible;
            }
            #printableReceiptArea {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                padding: 20px;
                background: #ffffff !important;
                color: #000000 !important;
            }
            .no-print {
                display: none !important;
            }
        }

        .receipt-card {
            background: #ffffff;
            border: 1px dashed #cbd5e1;
            border-radius: 16px;
            padding: 30px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, monospace;
        }

        .receipt-header {
            text-align: center;
            border-bottom: 2px dashed #94a3b8;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }

        .receipt-table th {
            background: transparent !important;
            border-bottom: 1px solid #000 !important;
            color: #000 !important;
            font-size: 13px;
        }

        .receipt-table td {
            border-bottom: 1px dashed #e2e8f0;
            padding: 8px 4px;
            font-size: 13.5px;
        }
    </style>
</head>

<body>

    <!-- Notification Alert -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert-container">
            <div class="alert alert-<?= htmlspecialchars($_SESSION['msg_type'] ?? 'info'); ?> alert-dismissible fade show shadow-lg pe-5"
                style="border-radius: 12px; font-weight: 500;">
                <i class="fa <?= ($_SESSION['msg_type'] ?? '') === 'success' ? 'fa-check-circle' : 'fa-info-circle'; ?> me-2"></i>
                <?= htmlspecialchars($_SESSION['message']); ?>
                <?php unset($_SESSION['message'], $_SESSION['msg_type']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Sidebar Navigation -->
    <?php include("includes/admin_sidebar.php"); ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Header Bar -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="bh-eyebrow"><i class="fa fa-file-invoice-dollar me-1"></i> Billing Oversight</span>
                <h2 class="fw-bold m-0 text-dark">Billing &amp; Payments</h2>
                <p class="text-muted mb-0">Monitor invoices, payments and receipts across the clinic. Payments are collected by the vet staff at check-out.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn bh-btn-outline px-3 py-2 fw-semibold rounded-pill" onclick="exportBillingCSV()">
                    <i class="fa fa-file-excel me-1"></i> Export CSV
                </button>
            </div>
        </div>
        <!-- 4 Top KPI Financial Metric Cards -->
        <div class="row g-3 mb-4">
            <!-- Today's Revenue -->
            <div class="col-xl-3 col-md-6">
                <div class="metric-card">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="text-muted fw-semibold small text-uppercase letter-spacing-1">Today's Collections</span>
                        <div class="metric-icon" style="background: #ecfdf5; color: #10b981;">
                            <i class="fa fa-hand-holding-dollar"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold text-dark mb-1">₱ <?= number_format($metrics['today_collected'], 2); ?></h3>
                    <div class="text-muted small">
                        <i class="fa fa-clock text-success me-1"></i> Cash & electronic cleared today
                    </div>
                </div>
            </div>

            <!-- Unpaid / Outstanding Balance -->
            <div class="col-xl-3 col-md-6">
                <div class="metric-card">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="text-muted fw-semibold small text-uppercase letter-spacing-1">Outstanding Receivables</span>
                        <div class="metric-icon" style="background: #fffbeb; color: #f59e0b;">
                            <i class="fa fa-clock-rotate-left"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold text-dark mb-1">₱ <?= number_format($metrics['outstanding_balance'], 2); ?></h3>
                    <div class="text-muted small">
                        <span class="badge bg-warning text-dark fw-bold me-1"><?= (int)$metrics['unpaid_count']; ?> Pending</span> Invoices awaiting settlement
                    </div>
                </div>
            </div>

            <!-- Downpayments Held -->
            <div class="col-xl-3 col-md-6">
                <div class="metric-card">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="text-muted fw-semibold small text-uppercase letter-spacing-1">Boarding Deposits Held</span>
                        <div class="metric-icon" style="background: #eff6ff; color: #3b82f6;">
                            <i class="fa fa-shield-halved"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold text-dark mb-1">₱ <?= number_format($metrics['active_deposits'], 2); ?></h3>
                    <div class="text-muted small">
                        <i class="fa fa-paw text-primary me-1"></i> 30% advance for upcoming stays
                    </div>
                </div>
            </div>

            <!-- Settle Rate -->
            <div class="col-xl-3 col-md-6">
                <div class="metric-card">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="text-muted fw-semibold small text-uppercase letter-spacing-1">Settled Invoices</span>
                        <div class="metric-icon" style="background: #f5f3ff; color: #8b5cf6;">
                            <i class="fa fa-receipt"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold text-dark mb-1"><?= (int)$metrics['paid_count']; ?> <span class="fs-6 text-muted fw-normal">/ <?= (int)$metrics['total_invoices']; ?> Total</span></h3>
                    <div class="text-muted small">
                        <span class="text-success fw-bold"><?= $metrics['total_invoices'] > 0 ? round(($metrics['paid_count'] / $metrics['total_invoices']) * 100, 1) : 0; ?>%</span> settlement completion rate
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="filter-toolbar">
            <form method="GET" action="admin_billing.php" class="row g-2 align-items-center">
                <!-- Status Pills -->
                <div class="col-lg-6 col-12 d-flex flex-wrap gap-2">
                    <a href="admin_billing.php?status=all&category=<?= urlencode($category_filter); ?>&range=<?= urlencode($date_range); ?>&search=<?= urlencode($search); ?>" 
                       class="status-pill <?= $status_filter === 'all' ? 'active' : ''; ?>">
                       <i class="fa fa-layer-group"></i> All Invoices
                    </a>
                    <a href="admin_billing.php?status=unpaid&category=<?= urlencode($category_filter); ?>&range=<?= urlencode($date_range); ?>&search=<?= urlencode($search); ?>" 
                       class="status-pill <?= $status_filter === 'unpaid' ? 'active' : ''; ?>">
                       <i class="fa fa-clock text-warning"></i> Pending Unpaid
                    </a>
                    <a href="admin_billing.php?status=partial&category=<?= urlencode($category_filter); ?>&range=<?= urlencode($date_range); ?>&search=<?= urlencode($search); ?>" 
                       class="status-pill <?= $status_filter === 'partial' ? 'active' : ''; ?>">
                       <i class="fa fa-circle-half-stroke text-primary"></i> Partial / DP Paid
                    </a>
                    <a href="admin_billing.php?status=paid&category=<?= urlencode($category_filter); ?>&range=<?= urlencode($date_range); ?>&search=<?= urlencode($search); ?>" 
                       class="status-pill <?= $status_filter === 'paid' ? 'active' : ''; ?>">
                       <i class="fa fa-check-circle text-success"></i> Fully Paid
                    </a>
                </div>

                <!-- Category & Range Filters -->
                <div class="col-lg-2 col-sm-6">
                    <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?= $category_filter === 'all' ? 'selected' : ''; ?>>All Service Types</option>
                        <option value="Boarding" <?= $category_filter === 'Boarding' ? 'selected' : ''; ?>>Pet Boarding</option>
                        <option value="Consultation" <?= $category_filter === 'Consultation' ? 'selected' : ''; ?>>Consultation & Checkup</option>
                        <option value="Vaccination" <?= $category_filter === 'Vaccination' ? 'selected' : ''; ?>>Vaccination</option>
                        <option value="Surgery" <?= $category_filter === 'Surgery' ? 'selected' : ''; ?>>Surgery / Dental</option>
                        <option value="Pharmacy" <?= $category_filter === 'Pharmacy' ? 'selected' : ''; ?>>Pharmacy / POS</option>
                    </select>
                </div>

                <div class="col-lg-2 col-sm-6">
                    <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?= $date_range === 'all' ? 'selected' : ''; ?>>All Time</option>
                        <option value="today" <?= $date_range === 'today' ? 'selected' : ''; ?>>Today Only</option>
                        <option value="week" <?= $date_range === 'week' ? 'selected' : ''; ?>>Past 7 Days</option>
                        <option value="month" <?= $date_range === 'month' ? 'selected' : ''; ?>>Past 30 Days</option>
                    </select>
                </div>

                <!-- Search Input -->
                <div class="col-lg-2 col-12">
                    <div class="bh-search">
                        <i class="fa fa-search"></i>
                        <input type="text" name="search" placeholder="Search invoice, pet, owner..." value="<?= htmlspecialchars($search); ?>">
                    </div>
                </div>
                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter); ?>">
            </form>
        </div>

        <!-- Invoices Data Table -->
        <div class="data-card">
            <div class="table-responsive">
                <table class="table mb-0" id="billingMainTable">
                    <thead>
                        <tr>
                            <th>Invoice # & Date</th>
                            <th>Client & Pet</th>
                            <th>Service Category</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Paid Amount</th>
                            <th class="text-end">Balance Due</th>
                            <th>Payment Method</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($invoices_res && $invoices_res->num_rows > 0): ?>
                            <?php while ($row = $invoices_res->fetch_assoc()): ?>
                                <?php
                                $total = (float)$row['total_amount'];
                                $paid = (float)$row['amount_paid'];
                                if ($paid <= 0 && $row['payment_status'] === 'Paid') {
                                    $paid = $total;
                                } elseif ($paid <= 0 && $row['downpayment_status'] === 'Paid') {
                                    $paid = (float)$row['downpayment_amount'];
                                }
                                $balance = max(0, $total - $paid);

                                // Determine display status
                                $status = $row['payment_status'] ?? 'Pending';
                                if ($status === 'Paid') {
                                    $badge = '<span class="badge-paid"><i class="fa fa-check-circle me-1"></i> Paid</span>';
                                } elseif ($status === 'Partial' || ($row['downpayment_status'] === 'Paid' && $status !== 'Paid')) {
                                    $badge = '<span class="badge-partial"><i class="fa fa-circle-half-stroke me-1"></i> Partial (DP)</span>';
                                } elseif ($status === 'Void') {
                                    $badge = '<span class="badge-void"><i class="fa fa-ban me-1"></i> Void</span>';
                                } else {
                                    $badge = '<span class="badge-pending"><i class="fa fa-clock me-1"></i> Unpaid</span>';
                                }

                                // Category icon & label
                                $is_b = (int)$row['is_boarding'] === 1;
                                $category_label = $is_b ? 'Pet Boarding' : (!empty($row['appt_service']) ? $row['appt_service'] : (!empty($row['service_category']) ? $row['service_category'] : 'Clinical Care'));
                                $cat_icon = $is_b ? 'fa-hotel text-info' : 'fa-stethoscope text-primary';

                                // Service line items description
                                $items_arr = !empty($row['items_json']) ? json_decode($row['items_json'], true) : [];
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['invoice_no'] ?: 'INV-' . str_pad($row['bill_id'], 5, '0', STR_PAD_LEFT)); ?></div>
                                        <small class="text-muted"><i class="fa fa-calendar-alt me-1"></i> <?= date('M d, Y h:i A', strtotime($row['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['display_client_name']); ?></div>
                                        <div class="small text-muted d-flex align-items-center gap-1">
                                            <i class="fa fa-paw text-primary"></i> <span class="fw-semibold text-dark"><?= htmlspecialchars($row['display_pet_name']); ?></span>
                                            <?php if (!empty($row['display_client_phone'])): ?>
                                                &bull; <span><?= htmlspecialchars($row['display_client_phone']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border fw-semibold">
                                            <i class="fa <?= $cat_icon; ?> me-1"></i> <?= htmlspecialchars($category_label); ?>
                                        </span>
                                        <?php if ($is_b && !empty($row['checkin']) && !empty($row['checkout'])): ?>
                                            <div class="small text-muted mt-1" style="font-size: 11.5px;">
                                                <?= date('M d', strtotime($row['checkin'])) ?> - <?= date('M d', strtotime($row['checkout'])) ?>
                                                <?php if (!empty($row['cage_number'])): ?>
                                                    (Cage #<?= $row['cage_number'] ?>)
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold text-dark">
                                        ₱ <?= number_format($total, 2); ?>
                                        <?php if ((float)$row['discount_amount'] > 0): ?>
                                            <div class="text-success small" style="font-size: 11px;">
                                                Disc: -₱<?= number_format($row['discount_amount'], 2); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-semibold text-success">
                                        ₱ <?= number_format($paid, 2); ?>
                                    </td>
                                    <td class="text-end fw-bold <?= $balance > 0 ? 'text-danger' : 'text-muted'; ?>">
                                        ₱ <?= number_format($balance, 2); ?>
                                    </td>
                                    <td>
                                        <span class="badge-method">
                                            <i class="fa <?= $row['payment_method'] === 'Cash' ? 'fa-money-bill-wave text-success' : ($row['payment_method'] === 'GCash' ? 'fa-mobile-screen-button text-primary' : 'fa-credit-card text-dark'); ?>"></i>
                                            <?= htmlspecialchars($row['payment_method'] ?: 'Cash'); ?>
                                        </span>
                                        <?php if (!empty($row['reference_no'])): ?>
                                            <div class="small text-muted mt-1" style="font-size: 11px;">
                                                Ref: <?= htmlspecialchars($row['reference_no']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $badge; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-inline-flex align-items-center gap-2">
                                            <!-- View / Print Receipt Button -->
                                            <button type="button" class="btn bh-receipt" title="View & Print Official Receipt"
                                                    onclick="openReceiptModal(<?= htmlspecialchars(json_encode([
                                                        'invoice_no' => $row['invoice_no'] ?: 'INV-' . str_pad($row['bill_id'], 5, '0', STR_PAD_LEFT),
                                                        'created_at' => date('M d, Y h:i A', strtotime($row['created_at'])),
                                                        'client_name' => $row['display_client_name'],
                                                        'client_phone' => $row['display_client_phone'],
                                                        'pet_name' => $row['display_pet_name'],
                                                        'category' => $category_label,
                                                        'total' => $total,
                                                        'discount' => (float)$row['discount_amount'],
                                                        'paid' => $paid,
                                                        'balance' => $balance,
                                                        'change' => (float)$row['change_amount'],
                                                        'payment_method' => $row['payment_method'] ?: 'Cash',
                                                        'reference_no' => $row['reference_no'] ?? '',
                                                        'status' => $status,
                                                        'items' => $items_arr
                                                    ])); ?>)">
                                                <i class="fa fa-receipt"></i> Receipt
                                            </button>

                                            <!-- Void: admin only, for an invoice issued by mistake with no payment received -->
                                            <?php if ($is_admin && $status !== 'Void' && $paid <= 0 && $status !== 'Paid' && $status !== 'Partial' && $row['downpayment_status'] !== 'Paid'): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Void invoice <?= htmlspecialchars($row['invoice_no'] ?: '#' . $row['bill_id'], ENT_QUOTES) ?>? Use this only for an invoice issued by mistake. The client will be told they do not need to pay it.');">
                                                    <input type="hidden" name="bill_id" value="<?= $row['bill_id']; ?>">
                                                    <input type="hidden" name="mark_action" value="void">
                                                    <button type="submit" class="btn bh-void" title="Void invoice issued by mistake"><i class="fa fa-ban"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fa fa-file-invoice-dollar fa-3x mb-3 text-secondary" style="opacity: 0.4;"></i>
                                        <h5 class="fw-bold">No billing records found</h5>
                                        <p class="small mb-0">Try clearing the filters. Invoices appear here when the vet staff bill a visit or a boarding stay.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 1: NEW INVOICE / POS CHECKOUT BUILDER -->
    <!-- ========================================== -->
    <div class="modal fade" id="newInvoiceModal" tabindex="-1" aria-labelledby="newInvoiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <form method="POST" action="admin_billing.php" id="posInvoiceForm">
                    <input type="hidden" name="action" value="create_invoice">
                    <input type="hidden" name="user_id" id="pos_user_id" value="">
                    <input type="hidden" name="appointment_id" id="pos_appointment_id" value="">
                    <input type="hidden" name="boarding_res_id" id="pos_boarding_res_id" value="">

                    <div class="modal-header border-0 pb-0 px-4 pt-4">
                        <div>
                            <h4 class="modal-title fw-bold text-dark" id="newInvoiceModalLabel">
                                <i class="fa fa-cash-register text-primary me-2"></i> New Invoice / POS Checkout
                            </h4>
                            <p class="text-muted small mb-0">Create clinical visit bills, dispense pharmacy items, or register walk-in billing.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body px-4 py-3">
                        <!-- Invoice Target Type Radio -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small text-uppercase letter-spacing-1">Billing Type</label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="invoice_type" id="type_walkin" value="walkin" checked onchange="togglePosType('walkin')">
                                    <label class="form-check-label fw-semibold" for="type_walkin">Walk-in / Direct POS</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="invoice_type" id="type_appt" value="appointment" onchange="togglePosType('appointment')">
                                    <label class="form-check-label fw-semibold" for="type_appt">Link Appointment</label>
                                </div>
                            </div>
                        </div>

                        <!-- Appointment Selector (Hidden by default) -->
                        <div id="apptSelectSection" class="mb-3 p-3 bg-light rounded-3" style="display: none;">
                            <label class="form-label fw-bold text-dark small">Select Clinic Appointment</label>
                            <select class="form-select" id="appointmentPicker" onchange="populateApptData(this)">
                                <option value="">-- Choose Approved / Completed Visit --</option>
                                <?php foreach ($unbilled_appts as $ua): ?>
                                    <option value="<?= $ua['id']; ?>" 
                                            data-client="<?= htmlspecialchars($ua['fullname']); ?>"
                                            data-phone="<?= htmlspecialchars($ua['contact_number']); ?>"
                                            data-pet="<?= htmlspecialchars($ua['pet_name']); ?>"
                                            data-service="<?= htmlspecialchars($ua['service_type']); ?>"
                                            data-userid="<?= $ua['user_id']; ?>">
                                        #<?= $ua['id']; ?> - <?= htmlspecialchars($ua['fullname']); ?> (Pet: <?= htmlspecialchars($ua['pet_name']); ?>) - <?= htmlspecialchars($ua['service_type']); ?> on <?= date('M d, Y', strtotime($ua['appointment_date'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Client & Pet Info Fields -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-5">
                                <label class="form-label fw-bold text-dark small">Client / Owner Name *</label>
                                <input type="text" name="client_name" id="pos_client_name" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark small">Contact Number</label>
                                <input type="text" name="client_phone" id="pos_client_phone" class="form-control" placeholder="e.g. 09171234567">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold text-dark small">Pet Name *</label>
                                <input type="text" name="pet_name" id="pos_pet_name" class="form-control" placeholder="e.g. Milo" required>
                            </div>
                        </div>

                        <!-- Service Category -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small">Primary Category</label>
                            <select name="service_category" id="pos_service_category" class="form-select">
                                <option value="General Consultation">General Consultation</option>
                                <option value="Vaccination & Deworming">Vaccination & Deworming</option>
                                <option value="Surgery / Dental">Surgery / Dental Procedure</option>
                                <option value="Laboratory / Diagnostics">Laboratory / Diagnostics</option>
                                <option value="Pet Hotel Boarding">Pet Hotel Boarding</option>
                                <option value="Pharmacy / Pet Supplies">Pharmacy / Pet Supplies</option>
                            </select>
                        </div>

                        <!-- Itemized Billing Table -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold text-dark small text-uppercase letter-spacing-1 mb-0">Itemized Breakdown</label>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill" onclick="addLineItem('service')">
                                        <i class="fa fa-plus me-1"></i> Add Service
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success fw-semibold rounded-pill" onclick="addLineItem('inventory')">
                                        <i class="fa fa-box-open me-1"></i> Add Medicine / Supply
                                    </button>
                                </div>
                            </div>

                            <div class="table-responsive border rounded-3 p-2 bg-white">
                                <table class="table table-sm align-middle mb-0" id="posItemsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width: 45%;">Item / Service Description</th>
                                            <th style="width: 15%;">Qty</th>
                                            <th style="width: 20%;">Unit Price (₱)</th>
                                            <th style="width: 15%;" class="text-end">Subtotal</th>
                                            <th style="width: 5%;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="posItemsBody">
                                        <!-- Initial Default Service Row -->
                                        <tr class="item-row">
                                            <td>
                                                <input type="text" name="item_name[]" class="form-control form-control-sm item-desc" placeholder="e.g. General Consultation" value="General Consultation" required>
                                                <input type="hidden" name="item_type[]" value="service">
                                                <input type="hidden" name="item_inv_id[]" value="0">
                                            </td>
                                            <td>
                                                <input type="number" name="item_qty[]" class="form-control form-control-sm item-qty text-center" value="1" min="1" oninput="calculatePosTotals()">
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" name="item_price[]" class="form-control form-control-sm item-price text-end" value="450.00" min="0" oninput="calculatePosTotals()">
                                            </td>
                                            <td class="text-end fw-bold item-subtotal">₱ 450.00</td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm text-danger border-0" onclick="removeLineItem(this)"><i class="fa fa-trash"></i></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Totals & Discounts -->
                        <div class="row g-3 justify-content-end mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small">Discount Type</label>
                                <select name="discount_type" id="pos_discount_type" class="form-select form-select-sm" onchange="calculatePosTotals()">
                                    <option value="None">No Discount</option>
                                    <option value="Senior_PWD">Senior Citizen / PWD (20%)</option>
                                    <option value="Custom">Custom / Promo Discount (₱)</option>
                                </select>
                                <div id="customDiscountWrap" class="mt-2" style="display: none;">
                                    <input type="number" step="0.01" name="discount_amount" id="pos_discount_amount" class="form-control form-control-sm" placeholder="Enter discount in ₱" value="0" oninput="calculatePosTotals()">
                                </div>
                            </div>
                            <div class="col-md-6 bg-light p-3 rounded-3 text-end">
                                <div class="d-flex justify-content-between text-muted small mb-1">
                                    <span>Subtotal:</span>
                                    <span id="posSubtotalDisplay" class="fw-semibold">₱ 450.00</span>
                                </div>
                                <div class="d-flex justify-content-between text-success small mb-2">
                                    <span>Discount:</span>
                                    <span id="posDiscountDisplay" class="fw-semibold">- ₱ 0.00</span>
                                </div>
                                <div class="d-flex justify-content-between fw-bold fs-5 text-dark border-top pt-2">
                                    <span>Grand Total:</span>
                                    <span id="posGrandTotalDisplay" class="text-primary">₱ 450.00</span>
                                </div>
                            </div>
                        </div>

                        <!-- Instant Settlement Option -->
                        <div class="border rounded-3 p-3 bg-light mb-3">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="pay_now" id="pos_pay_now" value="1" checked onchange="togglePosPaymentDetails(this)">
                                <label class="form-check-label fw-bold text-dark" for="pos_pay_now">Collect Payment Immediately</label>
                            </div>

                            <div id="posPaymentSection">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Settlement Option</label>
                                        <select name="pos_tender_type" class="form-select form-select-sm" onchange="calculatePosChange()">
                                            <option value="full">Settle Full Balance</option>
                                            <option value="downpayment">Collect 30% Downpayment</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Payment Method</label>
                                        <select name="payment_method" id="pos_payment_method" class="form-select form-select-sm" onchange="toggleMethodFields(this.value)">
                                            <option value="Cash">💵 Cash</option>
                                            <option value="GCash">📱 GCash</option>
                                            <option value="Maya">📱 Maya</option>
                                            <option value="Card">💳 Credit / Debit Card</option>
                                            <option value="Bank Transfer">🏦 Bank Transfer</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Cash tendered vs Change -->
                                <div class="row g-2" id="posCashFields">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Cash Received (₱)</label>
                                        <input type="number" step="0.01" name="cash_tendered" id="pos_cash_tendered" class="form-control form-control-sm" placeholder="e.g. 500.00" oninput="calculatePosChange()">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Change Due</label>
                                        <input type="text" id="pos_change_due" class="form-control form-control-sm bg-white fw-bold text-success" value="₱ 0.00" readonly>
                                    </div>
                                </div>

                                <!-- Reference Number for GCash/Card -->
                                <div class="row g-2 mt-1" id="posRefFields" style="display: none;">
                                    <div class="col-12">
                                        <label class="form-label small fw-bold">Transaction / Reference Number *</label>
                                        <input type="text" name="reference_no" class="form-control form-control-sm" placeholder="e.g. GCash Ref # 1002348572">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Internal Remarks / Care Notes</label>
                            <input type="text" name="invoice_notes" class="form-control form-control-sm" placeholder="Optional notes for pet owner receipt">
                        </div>
                    </div>

                    <div class="modal-footer border-0 px-4 pb-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fa fa-check-circle me-1"></i> Issue & Finalize Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 2: COLLECT PAYMENT ON EXISTING BILL  -->
    <!-- ========================================== -->
    <div class="modal fade" id="collectPaymentModal" tabindex="-1" aria-labelledby="collectPaymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <form method="POST" action="admin_billing.php">
                    <input type="hidden" name="action" value="collect_payment">
                    <input type="hidden" name="bill_id" id="collect_bill_id" value="">

                    <div class="modal-header border-0 pb-0 px-4 pt-4">
                        <div>
                            <h4 class="modal-title fw-bold text-dark" id="collectPaymentModalLabel">
                                <i class="fa fa-hand-holding-dollar text-primary me-2"></i> Collect Payment
                            </h4>
                            <p class="text-muted small mb-0" id="collect_invoice_title">Invoice #INV-2026-0000</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body px-4 py-3">
                        <!-- Invoice Summary Box -->
                        <div class="p-3 bg-light rounded-3 mb-3">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Client & Pet:</span>
                                <span class="fw-bold text-dark" id="collect_client_pet">Client Name (Pet)</span>
                            </div>
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Total Bill Amount:</span>
                                <span class="fw-semibold text-dark" id="collect_total_display">₱ 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Already Paid:</span>
                                <span class="fw-semibold text-success" id="collect_paid_display">₱ 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between fw-bold fs-5 text-dark border-top pt-2">
                                <span>Remaining Balance Due:</span>
                                <span class="text-danger" id="collect_balance_display">₱ 0.00</span>
                            </div>
                        </div>

                        <!-- Payment Option -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">Payment Type</label>
                            <select name="payment_type" id="collect_pay_type" class="form-select" onchange="handleCollectTypeChange()">
                                <option value="full">Pay Full Remaining Balance</option>
                                <option value="downpayment">Pay 30% Downpayment Deposit</option>
                                <option value="custom">Pay Custom Partial Amount</option>
                            </select>
                        </div>

                        <div class="mb-3" id="collect_custom_wrap" style="display: none;">
                            <label class="form-label small fw-bold text-dark">Enter Custom Amount (₱)</label>
                            <input type="number" step="0.01" name="custom_amount" id="collect_custom_amount" class="form-control" placeholder="0.00" oninput="calculateCollectChange()">
                        </div>

                        <!-- Payment Method -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">Payment Method</label>
                            <select name="payment_method" id="collect_method" class="form-select" onchange="toggleCollectMethod(this.value)">
                                <option value="Cash">💵 Cash</option>
                                <option value="GCash">📱 GCash</option>
                                <option value="Maya">📱 Maya</option>
                                <option value="Card">💳 Credit / Debit Card</option>
                                <option value="Bank Transfer">🏦 Bank Transfer</option>
                            </select>
                        </div>

                        <!-- Cash tendered & Change -->
                        <div class="row g-2 mb-3" id="collectCashRow">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-dark">Cash Tendered (₱)</label>
                                <input type="number" step="0.01" name="cash_tendered" id="collect_cash_tendered" class="form-control" placeholder="0.00" oninput="calculateCollectChange()">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-dark">Change</label>
                                <input type="text" id="collect_change_due" class="form-control bg-light fw-bold text-success" value="₱ 0.00" readonly>
                            </div>
                        </div>

                        <!-- Electronic Reference # -->
                        <div class="mb-3" id="collectRefRow" style="display: none;">
                            <label class="form-label small fw-bold text-dark">Transaction / Reference #</label>
                            <input type="text" name="reference_no" id="collect_ref_no" class="form-control" placeholder="e.g. 1002348572">
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Notes / Remarks</label>
                            <input type="text" name="payment_notes" class="form-control" placeholder="Optional notes">
                        </div>
                    </div>

                    <div class="modal-footer border-0 px-4 pb-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fa fa-check-circle me-1"></i> Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 3: OFFICIAL RECEIPT / TAX INVOICE   -->
    <!-- ========================================== -->
    <div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-header border-0 pb-0 px-4 pt-4 no-print">
                    <h5 class="modal-title fw-bold text-dark" id="receiptModalLabel">
                        <i class="fa fa-receipt text-primary me-2"></i> Official Clinic Receipt
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4" id="printableReceiptArea">
                    <div class="receipt-card">
                        <!-- Header -->
                        <div class="receipt-header">
                            <div class="fw-bold fs-4 text-dark text-uppercase letter-spacing-1">PragmaVet Animal Clinic</div>
                            <div class="small text-muted">Complete Veterinary Medical & Boarding Care</div>
                            <div class="small text-muted">Tel: (02) 8123-4567 | TIN: 450-891-234-000</div>
                            <div class="badge bg-dark text-white text-uppercase mt-2 px-3 py-1">Official Receipt</div>
                        </div>

                        <!-- Meta -->
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Invoice No:</span>
                            <span class="fw-bold text-dark" id="rcpt_inv_no">INV-2026-0000</span>
                        </div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Date & Time:</span>
                            <span class="text-dark" id="rcpt_date">Oct 01, 2026</span>
                        </div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Client Name:</span>
                            <span class="fw-semibold text-dark" id="rcpt_client">Juan Dela Cruz</span>
                        </div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Patient (Pet):</span>
                            <span class="fw-semibold text-dark" id="rcpt_pet">Milo (Dog)</span>
                        </div>
                        <div class="d-flex justify-content-between small mb-3">
                            <span class="text-muted">Cashier / Staff:</span>
                            <span class="text-dark"><?= htmlspecialchars($_SESSION['fullname'] ?? 'Clinic Administrator'); ?></span>
                        </div>

                        <!-- Items Table -->
                        <table class="table table-sm receipt-table mb-3">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Price</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody id="rcpt_items_body">
                                <!-- Dynamic line items rendered here -->
                            </tbody>
                        </table>

                        <!-- Totals Breakdown -->
                        <div class="border-top pt-2">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Subtotal:</span>
                                <span class="fw-semibold text-dark" id="rcpt_subtotal">₱ 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between small text-success mb-1" id="rcpt_discount_row">
                                <span>Discount:</span>
                                <span class="fw-semibold" id="rcpt_discount">- ₱ 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between fw-bold fs-6 text-dark border-top pt-2 mb-1">
                                <span>Grand Total:</span>
                                <span class="text-dark" id="rcpt_total">₱ 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between small text-success mb-1">
                                <span>Amount Paid:</span>
                                <span class="fw-bold" id="rcpt_paid">₱ 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between small text-danger mb-1" id="rcpt_balance_row">
                                <span>Balance Due:</span>
                                <span class="fw-bold" id="rcpt_balance">₱ 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Payment Method:</span>
                                <span class="fw-semibold text-dark" id="rcpt_method">Cash</span>
                            </div>
                            <div class="d-flex justify-content-between small text-muted mb-2" id="rcpt_ref_row">
                                <span>Reference No:</span>
                                <span class="fw-semibold text-dark" id="rcpt_ref"></span>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="text-center border-top border-2 border-dashed pt-3 mt-3">
                            <p class="small text-muted mb-1">Thank you for trusting PragmaVet with your pet's care!</p>
                            <p class="small text-muted fst-italic mb-0">For medical inquiries, reach us at contact@pragmavet.com</p>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 px-4 pb-4 no-print">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" onclick="window.print()">
                        <i class="fa fa-print me-1"></i> Print Official Slip
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Inventory Data in JSON for client JS -->
    <script>
        const INVENTORY_DATA = <?= json_encode($inventory_items); ?>;
        let activeBalance = 0;
        let activeTotal = 0;
        let activeDp = 0;

        // Toggle Walkin vs Appointment
        function togglePosType(type) {
            const apptSec = document.getElementById('apptSelectSection');
            if (type === 'appointment') {
                apptSec.style.display = 'block';
            } else {
                apptSec.style.display = 'none';
                document.getElementById('pos_appointment_id').value = '';
                document.getElementById('pos_user_id').value = '';
            }
        }

        // Auto-fill from appointment picker
        function populateApptData(sel) {
            const opt = sel.options[sel.selectedIndex];
            if (!opt.value) return;
            document.getElementById('pos_appointment_id').value = opt.value;
            document.getElementById('pos_user_id').value = opt.getAttribute('data-userid') || '';
            document.getElementById('pos_client_name').value = opt.getAttribute('data-client') || '';
            document.getElementById('pos_client_phone').value = opt.getAttribute('data-phone') || '';
            document.getElementById('pos_pet_name').value = opt.getAttribute('data-pet') || '';
            
            // Set first item row to this service
            const firstDesc = document.querySelector('#posItemsBody .item-desc');
            if (firstDesc) {
                firstDesc.value = opt.getAttribute('data-service') || 'General Consultation';
            }
            calculatePosTotals();
        }

        // Add line item row (Service or Inventory product)
        function addLineItem(type) {
            const tbody = document.getElementById('posItemsBody');
            const tr = document.createElement('tr');
            tr.className = 'item-row';

            if (type === 'inventory') {
                let optionsHtml = '<option value="">-- Select Medicine / Supply --</option>';
                INVENTORY_DATA.forEach(item => {
                    optionsHtml += `<option value="${item.id}" data-price="${item.price_per_unit}" data-stock="${item.quantity}" data-name="${item.item_name}">
                        ${item.item_name} (Stock: ${item.quantity} ${item.unit_of_measure}) - ₱${parseFloat(item.price_per_unit).toFixed(2)}
                    </option>`;
                });

                tr.innerHTML = `
                    <td>
                        <select class="form-select form-select-sm" onchange="onInventorySelect(this)" required>
                            ${optionsHtml}
                        </select>
                        <input type="hidden" name="item_name[]" class="item-desc" value="">
                        <input type="hidden" name="item_type[]" value="pharmacy">
                        <input type="hidden" name="item_inv_id[]" class="item-inv-id" value="0">
                    </td>
                    <td>
                        <input type="number" name="item_qty[]" class="form-control form-control-sm item-qty text-center" value="1" min="1" oninput="calculatePosTotals()">
                    </td>
                    <td>
                        <input type="number" step="0.01" name="item_price[]" class="form-control form-control-sm item-price text-end" value="0.00" min="0" oninput="calculatePosTotals()">
                    </td>
                    <td class="text-end fw-bold item-subtotal">₱ 0.00</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm text-danger border-0" onclick="removeLineItem(this)"><i class="fa fa-trash"></i></button>
                    </td>
                `;
            } else {
                tr.innerHTML = `
                    <td>
                        <input type="text" name="item_name[]" class="form-control form-control-sm item-desc" placeholder="Service name (e.g. Vaccine, Grooming)" required>
                        <input type="hidden" name="item_type[]" value="service">
                        <input type="hidden" name="item_inv_id[]" value="0">
                    </td>
                    <td>
                        <input type="number" name="item_qty[]" class="form-control form-control-sm item-qty text-center" value="1" min="1" oninput="calculatePosTotals()">
                    </td>
                    <td>
                        <input type="number" step="0.01" name="item_price[]" class="form-control form-control-sm item-price text-end" value="500.00" min="0" oninput="calculatePosTotals()">
                    </td>
                    <td class="text-end fw-bold item-subtotal">₱ 500.00</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm text-danger border-0" onclick="removeLineItem(this)"><i class="fa fa-trash"></i></button>
                    </td>
                `;
            }

            tbody.appendChild(tr);
            calculatePosTotals();
        }

        function onInventorySelect(sel) {
            const opt = sel.options[sel.selectedIndex];
            const row = sel.closest('tr');
            if (opt.value) {
                row.querySelector('.item-desc').value = opt.getAttribute('data-name');
                row.querySelector('.item-inv-id').value = opt.value;
                row.querySelector('.item-price').value = parseFloat(opt.getAttribute('data-price') || 0).toFixed(2);
                row.querySelector('.item-qty').max = opt.getAttribute('data-stock') || 999;
            } else {
                row.querySelector('.item-desc').value = '';
                row.querySelector('.item-inv-id').value = '0';
                row.querySelector('.item-price').value = '0.00';
            }
            calculatePosTotals();
        }

        function removeLineItem(btn) {
            const rows = document.querySelectorAll('#posItemsBody .item-row');
            if (rows.length <= 1) {
                alert('At least one item is required for the invoice.');
                return;
            }
            btn.closest('tr').remove();
            calculatePosTotals();
        }

        // Calculate POS Totals
        function calculatePosTotals() {
            let subtotal = 0;
            const rows = document.querySelectorAll('#posItemsBody .item-row');
            rows.forEach(row => {
                const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
                const price = parseFloat(row.querySelector('.item-price').value) || 0;
                const line = qty * price;
                subtotal += line;
                row.querySelector('.item-subtotal').textContent = '₱ ' + line.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            });

            // Discount
            const discType = document.getElementById('pos_discount_type').value;
            const customWrap = document.getElementById('customDiscountWrap');
            let discount = 0;

            if (discType === 'Senior_PWD') {
                customWrap.style.display = 'none';
                discount = subtotal * 0.20;
            } else if (discType === 'Custom') {
                customWrap.style.display = 'block';
                discount = parseFloat(document.getElementById('pos_discount_amount').value) || 0;
                if (discount > subtotal) discount = subtotal;
            } else {
                customWrap.style.display = 'none';
                discount = 0;
            }

            const grand = Math.max(0, subtotal - discount);

            document.getElementById('posSubtotalDisplay').textContent = '₱ ' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('posDiscountDisplay').textContent = '- ₱ ' + discount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('posGrandTotalDisplay').textContent = '₱ ' + grand.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            calculatePosChange();
        }

        function calculatePosChange() {
            const grandText = document.getElementById('posGrandTotalDisplay').textContent.replace(/[^\d.]/g, '');
            const grand = parseFloat(grandText) || 0;
            const tendered = parseFloat(document.getElementById('pos_cash_tendered').value) || 0;
            const change = Math.max(0, tendered - grand);
            document.getElementById('pos_change_due').value = '₱ ' + change.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function togglePosPaymentDetails(chk) {
            document.getElementById('posPaymentSection').style.display = chk.checked ? 'block' : 'none';
        }

        function toggleMethodFields(val) {
            const cashRow = document.getElementById('posCashFields');
            const refRow = document.getElementById('posRefFields');
            if (val === 'Cash') {
                cashRow.style.display = 'flex';
                refRow.style.display = 'none';
            } else {
                cashRow.style.display = 'none';
                refRow.style.display = 'block';
            }
        }

        // Open Collect Payment Modal
        function openCollectPaymentModal(data) {
            document.getElementById('collect_bill_id').value = data.bill_id;
            document.getElementById('collect_invoice_title').textContent = 'Invoice #' + data.invoice_no;
            document.getElementById('collect_client_pet').textContent = data.client + ' (Pet: ' + data.pet + ')';
            document.getElementById('collect_total_display').textContent = '₱ ' + parseFloat(data.total).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('collect_paid_display').textContent = '₱ ' + parseFloat(data.paid).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('collect_balance_display').textContent = '₱ ' + parseFloat(data.balance).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            activeBalance = parseFloat(data.balance);
            activeTotal = parseFloat(data.total);
            activeDp = parseFloat(data.dp_amount || (data.total * 0.30));

            document.getElementById('collect_pay_type').value = 'full';
            document.getElementById('collect_custom_wrap').style.display = 'none';
            document.getElementById('collect_cash_tendered').value = activeBalance.toFixed(2);
            calculateCollectChange();

            const myModal = new bootstrap.Modal(document.getElementById('collectPaymentModal'));
            myModal.show();
        }

        function handleCollectTypeChange() {
            const val = document.getElementById('collect_pay_type').value;
            const customWrap = document.getElementById('collect_custom_wrap');
            if (val === 'custom') {
                customWrap.style.display = 'block';
            } else {
                customWrap.style.display = 'none';
                if (val === 'downpayment') {
                    document.getElementById('collect_cash_tendered').value = activeDp.toFixed(2);
                } else {
                    document.getElementById('collect_cash_tendered').value = activeBalance.toFixed(2);
                }
            }
            calculateCollectChange();
        }

        function calculateCollectChange() {
            const val = document.getElementById('collect_pay_type').value;
            let target = activeBalance;
            if (val === 'downpayment') target = activeDp;
            else if (val === 'custom') target = parseFloat(document.getElementById('collect_custom_amount').value) || 0;

            const tendered = parseFloat(document.getElementById('collect_cash_tendered').value) || 0;
            const change = Math.max(0, tendered - target);
            document.getElementById('collect_change_due').value = '₱ ' + change.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function toggleCollectMethod(val) {
            const cashRow = document.getElementById('collectCashRow');
            const refRow = document.getElementById('collectRefRow');
            if (val === 'Cash') {
                cashRow.style.display = 'flex';
                refRow.style.display = 'none';
            } else {
                cashRow.style.display = 'none';
                refRow.style.display = 'block';
            }
        }

        // Open Printable Official Receipt Modal
        function openReceiptModal(data) {
            document.getElementById('rcpt_inv_no').textContent = data.invoice_no;
            document.getElementById('rcpt_date').textContent = data.created_at;
            document.getElementById('rcpt_client').textContent = data.client_name;
            document.getElementById('rcpt_pet').textContent = data.pet_name;

            const tbody = document.getElementById('rcpt_items_body');
            tbody.innerHTML = '';

            let computedSubtotal = 0;
            if (data.items && data.items.length > 0) {
                data.items.forEach(it => {
                    const line = parseFloat(it.subtotal || (it.qty * it.price));
                    computedSubtotal += line;
                    tbody.innerHTML += `
                        <tr>
                            <td>${it.name}</td>
                            <td class="text-center">${it.qty}</td>
                            <td class="text-end">₱ ${parseFloat(it.price).toFixed(2)}</td>
                            <td class="text-end fw-semibold">₱ ${line.toFixed(2)}</td>
                        </tr>
                    `;
                });
            } else {
                tbody.innerHTML = `
                    <tr>
                        <td>${data.category}</td>
                        <td class="text-center">1</td>
                        <td class="text-end">₱ ${parseFloat(data.total).toFixed(2)}</td>
                        <td class="text-end fw-semibold">₱ ${parseFloat(data.total).toFixed(2)}</td>
                    </tr>
                `;
                computedSubtotal = parseFloat(data.total);
            }

            document.getElementById('rcpt_subtotal').textContent = '₱ ' + computedSubtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            if (data.discount > 0) {
                document.getElementById('rcpt_discount_row').style.display = 'flex';
                document.getElementById('rcpt_discount').textContent = '- ₱ ' + data.discount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            } else {
                document.getElementById('rcpt_discount_row').style.display = 'none';
            }

            document.getElementById('rcpt_total').textContent = '₱ ' + parseFloat(data.total).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('rcpt_paid').textContent = '₱ ' + parseFloat(data.paid).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            if (data.balance > 0) {
                document.getElementById('rcpt_balance_row').style.display = 'flex';
                document.getElementById('rcpt_balance').textContent = '₱ ' + parseFloat(data.balance).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            } else {
                document.getElementById('rcpt_balance_row').style.display = 'none';
            }

            document.getElementById('rcpt_method').textContent = data.payment_method;
            if (data.reference_no) {
                document.getElementById('rcpt_ref_row').style.display = 'flex';
                document.getElementById('rcpt_ref').textContent = data.reference_no;
            } else {
                document.getElementById('rcpt_ref_row').style.display = 'none';
            }

            const myModal = new bootstrap.Modal(document.getElementById('receiptModal'));
            myModal.show();
        }

        // Export Billing main table to CSV
        function exportBillingCSV() {
            let csv = [];
            const rows = document.querySelectorAll("#billingMainTable tr");
            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");
                // skip the last column (Actions)
                for (let j = 0; j < cols.length - 1; j++) {
                    let cleanText = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/"/g, '""').trim();
                    row.push('"' + cleanText + '"');
                }
                csv.push(row.join(","));
            }
            const csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
            const downloadLink = document.createElement("a");
            downloadLink.download = "pragmavet_billing_report_" + new Date().toISOString().slice(0, 10) + ".csv";
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>