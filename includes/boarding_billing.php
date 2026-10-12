<?php
/**
 * Boarding invoices for the staff side.
 * A stay is billed nights × the "Pet Boarding" rate (System Settings → Service Prices).
 * Online bookings already have a billing row (deposit / reservation fee paid via PayMongo);
 * walk-ins get one here. At check-out staff see the balance and can take cash.
 */
include_once __DIR__ . '/service_prices.php';

function bb_nights($start, $end) {
    return max(1, (int)round((strtotime($end) - strtotime($start)) / 86400));
}

function bb_reservation($conn, $res_id) {
    $res_id = (int)$res_id;
    return $conn->query("SELECT b.*, u.fullname, u.contact_number, u.role AS owner_role
                         FROM boarding_reservations b LEFT JOIN users u ON u.id = b.user_id WHERE b.id = $res_id")->fetch_assoc();
}

/** A stay's invoice. Some stays have duplicate rows; prefer the paid one, then the one with the deposit paid, then the original. */
function bb_find_bill($conn, $res_id) {
    $res_id = (int)$res_id;
    return $conn->query("SELECT * FROM billing WHERE is_boarding = 1 AND COALESCE(boarding_res_id, appointment_id) = $res_id
                         AND COALESCE(payment_status, '') <> 'Void'
                         ORDER BY (payment_status = 'Paid') DESC, (downpayment_status IN ('Paid', 'Partial')) DESC, id ASC LIMIT 1")->fetch_assoc();
}

/** Amount already paid toward the stay before check-out (deposit or reservation fee). */
function bb_credit($bill) {
    if (!$bill) return 0.0;
    return in_array($bill['downpayment_status'] ?? '', ['Paid', 'Partial'], true) ? (float)$bill['downpayment_amount'] : 0.0;
}

function bb_balance($bill) {
    if (!$bill || ($bill['payment_status'] ?? '') === 'Paid') return 0.0;
    return max(0, round((float)$bill['total_amount'] - bb_credit($bill), 2));
}

function bb_item_label($r) {
    return 'Pet Boarding' . ($r['cage_number'] ? ' — Cage ' . str_pad((int)$r['cage_number'], 2, '0', STR_PAD_LEFT) : '')
         . ' (' . date('M d', strtotime($r['appointment_date'])) . ' → ' . date('M d, Y', strtotime($r['end_date'])) . ')';
}

/** Read-only numbers for the check-out window (no row is written). */
function bb_preview($conn, $r) {
    $bill = bb_find_bill($conn, $r['id']);
    $nights = bb_nights($r['appointment_date'], $r['end_date']);
    $rate = sp_boarding_rate($conn);
    $total = $bill ? (float)$bill['total_amount'] : round($nights * $rate, 2);
    $credit = bb_credit($bill);
    return [
        'nights' => $nights,
        'rate' => $bill && $nights ? round($total / $nights, 2) : $rate,
        'total' => $total,
        'credit' => $credit,
        'paid' => $bill && $bill['payment_status'] === 'Paid',
        'balance' => $bill ? bb_balance($bill) : $total,
        'invoice_no' => $bill['invoice_no'] ?? null,
        'bill_id' => $bill['id'] ?? null,
    ];
}

/**
 * Get the stay's invoice, creating it (walk-ins) or filling in missing details (older online bookings).
 * $client_name: name typed at the desk for a walk-in.
 */
function bb_ensure_bill($conn, $res_id, $client_name = null) {
    $r = bb_reservation($conn, $res_id);
    if (!$r) return null;
    $is_client = in_array($r['owner_role'] ?? '', ['client', 'user'], true);
    $uid   = $is_client ? (int)$r['user_id'] : null;
    $name  = $client_name ?: ($is_client ? $r['fullname'] : 'Walk-in client');
    $phone = $is_client ? ($r['contact_number'] ?? '') : '';
    $nights = bb_nights($r['appointment_date'], $r['end_date']);
    $bill = bb_find_bill($conn, $r['id']);

    if (!$bill) {
        $rate = sp_boarding_rate($conn);
        $total = round($nights * $rate, 2);
        $items = json_encode([['name' => bb_item_label($r), 'qty' => $nights, 'price' => $rate]], JSON_UNESCAPED_UNICODE);
        $st = $conn->prepare("INSERT INTO billing (boarding_res_id, user_id, client_name, client_phone, pet_name, service_category, items_json, total_amount,
                                                   payment_status, is_boarding, downpayment_amount, downpayment_status, created_at)
                              VALUES (?, ?, ?, ?, ?, 'Boarding', ?, ?, 'Unpaid', 1, 0, 'Unpaid', NOW())");
        $rid = (int)$r['id'];
        $st->bind_param("iissssd", $rid, $uid, $name, $phone, $r['pet_name'], $items, $total);
        $st->execute();
        $bill_id = $conn->insert_id;
    } else {
        $bill_id = (int)$bill['id'];
        $items = $bill['items_json'];
        if (empty($items) || $items === '[]') {
            $total = (float)$bill['total_amount'];
            $items = json_encode([['name' => bb_item_label($r), 'qty' => $nights, 'price' => round($total / $nights, 2)]], JSON_UNESCAPED_UNICODE);
        }
        $st = $conn->prepare("UPDATE billing SET user_id = COALESCE(user_id, ?), client_name = COALESCE(NULLIF(client_name, ''), ?),
                                                 client_phone = COALESCE(NULLIF(client_phone, ''), ?), pet_name = COALESCE(NULLIF(pet_name, ''), ?),
                                                 service_category = COALESCE(NULLIF(service_category, ''), 'Boarding'), items_json = ?,
                                                 boarding_res_id = COALESCE(boarding_res_id, ?) WHERE id = ?");
        $rid = (int)$r['id'];
        $st->bind_param("issssii", $uid, $name, $phone, $r['pet_name'], $items, $rid, $bill_id);
        $st->execute();
    }
    $conn->query("UPDATE billing SET invoice_no = CONCAT('INV-', YEAR(NOW()), '-', LPAD(id, 5, '0')) WHERE id = $bill_id AND (invoice_no IS NULL OR invoice_no = '')");
    return $conn->query("SELECT * FROM billing WHERE id = $bill_id")->fetch_assoc();
}
