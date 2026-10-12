<?php
/**
 * Shared report data: which invoices count, and what each one paid / still owes.
 * Same rules as the admin Reports & Analytics page (admin.php):
 *  - excluded: void invoices, invoices whose booking was cancelled/rejected or no longer exists,
 *    and duplicate invoices for the same boarding stay (the one with the most payment progress is kept).
 */

/** Returns [valid bills (each with _paid, _owed, _date), excluded summary, excluded rows (reason, date, amount)]. */
function rpt_valid_bills($conn) {
    $q = $conn->query("
        SELECT b.*, a.id AS a_id, a.status AS a_status, a.service_type AS a_service, a.doctor_id AS a_doctor, a.pet_name AS a_pet,
               br.id AS br_id, br.status AS br_status, br.pet_name AS br_pet,
               COALESCE(b.boarding_res_id, b.appointment_id) AS res_key,
               COALESCE(NULLIF(TRIM(b.client_name), ''), cu.fullname, 'Walk-in client') AS client
        FROM billing b
        LEFT JOIN appointments a ON a.id = b.appointment_id AND b.is_boarding = 0
        LEFT JOIN boarding_reservations br ON br.id = COALESCE(b.boarding_res_id, b.appointment_id) AND b.is_boarding = 1
        LEFT JOIN users cu ON cu.id = COALESCE(b.user_id, a.user_id, br.user_id)
        ORDER BY b.id ASC
    ");
    $excluded = ['void' => [0, 0.0], 'cancelled' => [0, 0.0], 'missing' => [0, 0.0], 'duplicate' => [0, 0.0]];
    $excluded_rows = []; // one entry per left-out invoice, so a report can count only those issued in its period
    $valid = [];
    $pick = [];
    $rank = function ($b) {
        if ($b['payment_status'] === 'Paid') return 2;
        if (in_array($b['downpayment_status'], ['Paid', 'Partial'], true)) return 1;
        return 0;
    };
    $bad = ['Cancelled', 'Rejected', 'No Show'];
    if ($q) while ($b = $q->fetch_assoc()) {
        $amt = (float)$b['total_amount'];
        $d = substr((string)$b['created_at'], 0, 10);
        if (in_array($b['payment_status'], ['Void', 'Cancelled'], true)) { $excluded['void'][0]++; $excluded['void'][1] += $amt; $excluded_rows[] = ['void', $d, $amt]; continue; }
        if ((int)$b['is_boarding'] === 1) {
            if (!$b['br_id'])                          { $excluded['missing'][0]++;   $excluded['missing'][1]   += $amt; $excluded_rows[] = ['missing', $d, $amt]; continue; }
            if (in_array($b['br_status'], $bad, true)) { $excluded['cancelled'][0]++; $excluded['cancelled'][1] += $amt; $excluded_rows[] = ['cancelled', $d, $amt]; continue; }
            $k = $b['res_key'];
            if (isset($pick[$k])) {
                $prev = $valid[$pick[$k]];
                $dup_d = $d;
                if ($rank($b) > $rank($prev)) { $valid[$pick[$k]] = $b; $amt = (float)$prev['total_amount']; $dup_d = substr((string)$prev['created_at'], 0, 10); }
                $excluded['duplicate'][0]++; $excluded['duplicate'][1] += $amt; $excluded_rows[] = ['duplicate', $dup_d, $amt];
                continue;
            }
            $pick[$k] = count($valid);
        } else {
            if ($b['appointment_id'] && !$b['a_id'])   { $excluded['missing'][0]++;   $excluded['missing'][1]   += $amt; $excluded_rows[] = ['missing', $d, $amt]; continue; }
            if (in_array($b['a_status'], $bad, true))  { $excluded['cancelled'][0]++; $excluded['cancelled'][1] += $amt; $excluded_rows[] = ['cancelled', $d, $amt]; continue; }
        }
        $valid[] = $b;
    }
    foreach ($valid as &$b) {
        $amt = (float)$b['total_amount'];
        if ($b['payment_status'] === 'Paid')  $paid = (float)$b['amount_paid'] > 0 ? (float)$b['amount_paid'] : $amt;
        elseif (in_array($b['downpayment_status'], ['Paid', 'Partial'], true)) $paid = (float)$b['downpayment_amount'];
        else $paid = 0.0;
        $b['_paid'] = min($paid, $amt);
        $b['_owed'] = $b['payment_status'] === 'Paid' ? 0.0 : max(0.0, $amt - $b['_paid']);
        $b['_date'] = substr((string)$b['created_at'], 0, 10);
        $b['_pet']  = $b['pet_name'] ?: ($b['a_pet'] ?: ($b['br_pet'] ?: ''));
    }
    unset($b);
    return [$valid, $excluded, $excluded_rows];
}
