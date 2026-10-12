<?php
session_start();
include("dbconnection.php");
// Valid invoices only: no void ones, none for cancelled/rejected/no-show or deleted bookings, one per boarding stay
include_once("includes/report_data.php");

// Admin Dashboard — descriptive analytics of the clinic's records for a chosen period (print + CSV export).
// Admin and clinic owner (view only).
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_owner = ($user_role === 'clinic_owner' || $user_role === 'owner');
if (!isset($_SESSION['user_id']) || (!$is_admin && !$is_owner)) {
    header("Location: " . ($user_role === 'doctor' ? 'doctor.php' : ($user_role === 'staff' ? 'staff_dashboard.php' : 'index.php')));
    exit();
}

// ── 1. PERIOD ─────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'month';
$today = date('Y-m-d');
$start_date = date('Y-m-01'); $end_date = date('Y-m-t'); $filter_label = 'This Month';
if ($period === 'today')      { $start_date = $end_date = $today; $filter_label = 'Today'; }
elseif ($period === 'week')   { $start_date = date('Y-m-d', strtotime('monday this week')); $end_date = date('Y-m-d', strtotime('sunday this week')); $filter_label = 'This Week'; }
elseif ($period === 'year')   { $start_date = date('Y-01-01'); $end_date = date('Y-12-31'); $filter_label = 'This Year'; }
elseif ($period === 'custom' && !empty($_GET['start_date']) && !empty($_GET['end_date']) && strtotime($_GET['start_date']) && strtotime($_GET['end_date'])) {
    $start_date = date('Y-m-d', strtotime($_GET['start_date'])); $end_date = date('Y-m-d', strtotime($_GET['end_date']));
    if ($start_date > $end_date) [$start_date, $end_date] = [$end_date, $start_date];
    $filter_label = 'Custom Range';
} elseif ($period === 'all') {
    // All records: from the earliest record to today (or the latest future booking)
    $r = $conn->query("SELECT
            LEAST(IFNULL((SELECT MIN(appointment_date) FROM appointments WHERE appointment_date > '2000-01-01'), CURDATE()),
                  IFNULL((SELECT MIN(DATE(created_at)) FROM billing), CURDATE()),
                  IFNULL((SELECT MIN(appointment_date) FROM boarding_reservations WHERE appointment_date > '2000-01-01'), CURDATE())) mn,
            GREATEST(IFNULL((SELECT MAX(appointment_date) FROM appointments), CURDATE()),
                     IFNULL((SELECT MAX(appointment_date) FROM boarding_reservations), CURDATE()), CURDATE()) mx")->fetch_assoc();
    $start_date = $r['mn']; $end_date = $r['mx']; $filter_label = 'All Records';
} else { $period = 'month'; }
$range_text = date('M j, Y', strtotime($start_date)) . ($start_date !== $end_date ? ' – ' . date('M j, Y', strtotime($end_date)) : '');
$period_days = (int)round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1;

$peso = fn($v) => '₱' . number_format((float)$v, 2);
$pct  = fn($part, $whole) => $whole > 0 ? round($part / $whole * 100, 1) : 0;

// Chart time buckets: days (up to ~2 months), months (up to ~2 years), otherwise years
$bucket_mode = $period_days <= 62 ? 'day' : ($period_days <= 800 ? 'month' : 'year');
$bucket_keys = $bucket_labels = [];
if ($bucket_mode === 'day') {
    for ($t = strtotime($start_date); $t <= strtotime($end_date); $t = strtotime('+1 day', $t)) { $bucket_keys[] = date('Y-m-d', $t); $bucket_labels[] = date('M j', $t); }
} elseif ($bucket_mode === 'month') {
    for ($t = strtotime(date('Y-m-01', strtotime($start_date))); $t <= strtotime($end_date); $t = strtotime('+1 month', $t)) { $bucket_keys[] = date('Y-m', $t); $bucket_labels[] = date('M Y', $t); }
} else {
    for ($y = (int)substr($start_date, 0, 4); $y <= (int)substr($end_date, 0, 4); $y++) { $bucket_keys[] = (string)$y; $bucket_labels[] = (string)$y; }
}
$bucket_of = fn($date) => $bucket_mode === 'day' ? substr($date, 0, 10) : ($bucket_mode === 'month' ? substr($date, 0, 7) : substr($date, 0, 4));
$empty_buckets = fn() => array_fill_keys($bucket_keys, 0);
$bucket_word = ['day' => 'per day', 'month' => 'per month', 'year' => 'per year'][$bucket_mode];
$bucket_unit = ['day' => 'day', 'month' => 'month', 'year' => 'year'][$bucket_mode];
$label_of = fn($k) => $bucket_labels[array_search($k, $bucket_keys)] ?? $k;

// ── 2. DATA — one source per section; the screen, the printout and the CSV all use these same rows ──

// A. Appointments (clinic services; boarding has its own section)
$appt_rows = [];
$q = $conn->query("SELECT a.id, a.appointment_date, a.appointment_time, COALESCE(u.fullname, 'Walk-in Client') AS client, a.pet_name, a.service_type,
                          a.doctor_id, COALESCE(d.fullname, 'Unassigned') AS vet, a.status
                   FROM appointments a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN users d ON d.id = a.doctor_id
                   WHERE a.service_type <> 'Pet Boarding Reservation' AND a.appointment_date BETWEEN '$start_date' AND '$end_date'
                   ORDER BY a.appointment_date DESC, a.appointment_time DESC");
if ($q) while ($r = $q->fetch_assoc()) $appt_rows[] = $r;
$A = ['total' => count($appt_rows), 'completed' => 0, 'active' => 0, 'pending' => 0, 'cancelled' => 0, 'noshow' => 0];
$vets = [];
$dq = $conn->query("SELECT id, fullname FROM users WHERE role = 'doctor' ORDER BY fullname");
if ($dq) while ($d = $dq->fetch_assoc()) $vets[(int)$d['id']] = ['name' => $d['fullname'], 'assigned' => 0, 'completed' => 0, 'active' => 0, 'lost' => 0];
$services = [];
$appt_volume = $empty_buckets();
$appt_hours = array_fill(0, 24, 0);
foreach ($appt_rows as $r) {
    $s = $r['status'];
    $group = $s === 'Completed' ? 'completed'
        : (in_array($s, ['Approved', 'Checked In', 'In Progress'], true) ? 'active'
        : (in_array($s, ['Cancelled', 'Rejected'], true) ? 'cancelled'
        : ($s === 'No Show' ? 'noshow' : 'pending')));
    $A[$group]++;
    $vid = (int)$r['doctor_id'];
    if (!isset($vets[$vid])) $vets[$vid] = ['name' => $vid ? $r['vet'] : 'Unassigned', 'assigned' => 0, 'completed' => 0, 'active' => 0, 'lost' => 0];
    $vets[$vid]['assigned']++;
    if ($group === 'completed') $vets[$vid]['completed']++;
    elseif ($group === 'active') $vets[$vid]['active']++;
    elseif ($group === 'cancelled' || $group === 'noshow') $vets[$vid]['lost']++;
    if ($group !== 'cancelled') {
        $svc = trim((string)$r['service_type']) ?: 'General Checkup';
        $services[$svc] = ($services[$svc] ?? 0) + 1;
        $k = $bucket_of($r['appointment_date']);
        if (isset($appt_volume[$k])) $appt_volume[$k]++;
        if (!empty($r['appointment_time'])) $appt_hours[(int)substr($r['appointment_time'], 0, 2)]++;
    }
}
arsort($services);
uasort($vets, fn($a, $b) => $b['assigned'] <=> $a['assigned']);
$service_total = array_sum($services);
$volume_total = array_sum($appt_volume);

// B. Boarding (stays checking in within the period)
$board_rows = [];
$q = $conn->query("SELECT br.id, COALESCE(u.fullname, 'Client') AS client, br.pet_name, br.cage_number, br.appointment_date, br.end_date, br.status
                   FROM boarding_reservations br LEFT JOIN users u ON u.id = br.user_id
                   WHERE br.appointment_date BETWEEN '$start_date' AND '$end_date'
                   ORDER BY br.appointment_date DESC, br.id DESC");
if ($q) while ($r = $q->fetch_assoc()) { $r['nights'] = max(1, (int)round((strtotime($r['end_date']) - strtotime($r['appointment_date'])) / 86400)); $board_rows[] = $r; }
$B = ['total' => count($board_rows), 'active' => 0, 'completed' => 0, 'pending' => 0, 'cancelled' => 0, 'nights' => 0, 'kept' => 0];
$board_volume = $empty_buckets();
foreach ($board_rows as $r) {
    $s = $r['status'];
    if (in_array($s, ['Approved', 'Checked In'], true)) $B['active']++;
    elseif ($s === 'Completed') $B['completed']++;
    elseif ($s === 'Pending') $B['pending']++;
    else $B['cancelled']++;
    if (in_array($s, ['Approved', 'Checked In', 'Completed'], true)) {
        $B['nights'] += $r['nights']; $B['kept']++;
        $k = $bucket_of($r['appointment_date']);
        if (isset($board_volume[$k])) $board_volume[$k]++;
    }
}
$B['avg_nights'] = $B['kept'] ? round($B['nights'] / $B['kept'], 1) : 0;
$cage_count = (int)($conn->query("SELECT setting_value FROM clinic_settings WHERE setting_key = 'boarding_cage_count'")->fetch_assoc()['setting_value'] ?? 5) ?: 5;
// Cage occupancy: nights booked inside the period ÷ (cages × nights in the period)
$occ_nights = 0;
$oq = $conn->query("SELECT appointment_date s, end_date e FROM boarding_reservations
                    WHERE status IN ('Approved', 'Checked In', 'Completed') AND appointment_date <= '$end_date' AND end_date > '$start_date'");
if ($oq) while ($o = $oq->fetch_assoc()) {
    $s = max(strtotime($o['s']), strtotime($start_date)); $e = min(strtotime($o['e']), strtotime($end_date . ' +1 day'));
    if ($e > $s) $occ_nights += (int)round(($e - $s) / 86400);
}
$cage_nights = $cage_count * $period_days;
$B['occupancy'] = $pct($occ_nights, $cage_nights);

// C. Payments (valid invoices issued within the period)
[$all_valid_bills, $excluded] = rpt_valid_bills($conn);
$bills = array_values(array_filter($all_valid_bills, fn($b) => $b['_date'] >= $start_date && $b['_date'] <= $end_date));
usort($bills, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
$P = ['invoices' => count($bills), 'billed' => 0.0, 'collected' => 0.0, 'owed' => 0.0, 'unpaid' => 0, 'paid' => 0];
$methods = []; $categories = ['Clinic services' => [0, 0.0, 0.0], 'Boarding' => [0, 0.0, 0.0]];
$pay_billed = $empty_buckets(); $pay_collected = $empty_buckets();
$pay_label = fn($b) => $b['payment_status'] === 'Paid' ? 'Paid' : ($b['_paid'] > 0 ? 'Partial' : 'Unpaid');
foreach ($bills as $b) {
    $amt = (float)$b['total_amount'];
    $P['billed'] += $amt; $P['collected'] += $b['_paid']; $P['owed'] += $b['_owed'];
    if ($b['_owed'] > 0) $P['unpaid']++; else $P['paid']++;
    if ($b['_paid'] > 0) { $m = $b['payment_method'] ?: 'Cash'; $methods[$m] = [($methods[$m][0] ?? 0) + 1, ($methods[$m][1] ?? 0) + $b['_paid']]; }
    $cat = (int)$b['is_boarding'] === 1 ? 'Boarding' : 'Clinic services';
    $categories[$cat][0]++; $categories[$cat][1] += $amt; $categories[$cat][2] += $b['_paid'];
    $k = $bucket_of($b['_date']);
    if (isset($pay_billed[$k])) { $pay_billed[$k] += $amt; $pay_collected[$k] += $b['_paid']; }
}
uasort($methods, fn($a, $b) => $b[1] <=> $a[1]);
$P['rate'] = $pct($P['collected'], $P['billed']);
$bill_category = fn($b) => (int)$b['is_boarding'] === 1 ? 'Boarding' : ($b['a_service'] ?: ($b['service_category'] ?: 'Clinic'));
$excluded_cnt = array_sum(array_column($excluded, 0));

// D. Feedback (submitted within the period)
$fb_rows = [];
$q = $conn->query("SELECT f.id, f.created_at, COALESCE(u.fullname, 'Anonymous') AS client, a.pet_name, a.service_type, f.rating, f.comments
                   FROM feedbacks f LEFT JOIN users u ON u.id = f.user_id LEFT JOIN appointments a ON a.id = f.appointment_id
                   WHERE f.rating BETWEEN 1 AND 5 AND DATE(f.created_at) BETWEEN '$start_date' AND '$end_date'
                   ORDER BY f.created_at DESC");
if ($q) while ($r = $q->fetch_assoc()) $fb_rows[] = $r;
$rating_dist = array_fill(1, 5, 0);
foreach ($fb_rows as $r) $rating_dist[(int)$r['rating']]++;
$F = ['count' => count($fb_rows)];
$F['avg'] = $F['count'] ? round(array_sum(array_map(fn($s, $c) => $s * $c, array_keys($rating_dist), $rating_dist)) / $F['count'], 2) : 0;
$F['positive'] = $pct($rating_dist[4] + $rating_dist[5], $F['count']);
$F['verdict'] = !$F['count'] ? 'No ratings yet' : ($F['avg'] >= 4.5 ? 'Excellent' : ($F['avg'] >= 3.5 ? 'Very Satisfactory' : ($F['avg'] >= 2.5 ? 'Satisfactory' : ($F['avg'] >= 1.5 ? 'Fair' : 'Poor'))));

// E. Accounts & pets (current snapshot, plus how many were created in the period)
$U = $conn->query("SELECT COUNT(*) total,
        SUM(role IN ('user','client')) clients, SUM(role = 'staff') staff, SUM(role = 'doctor') doctors,
        SUM(role = 'admin') admins, SUM(role IN ('clinic_owner','owner')) owners,
        SUM(is_verified = 1) verified, SUM(DATE(created_at) BETWEEN '$start_date' AND '$end_date') new_in_period
    FROM users")->fetch_assoc();
$pets_total = (int)$conn->query("SELECT COUNT(*) c FROM pets WHERE deleted_at IS NULL")->fetch_assoc()['c'];
$pets_new = (int)$conn->query("SELECT COUNT(*) c FROM pets WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN '$start_date' AND '$end_date'")->fetch_assoc()['c'];

// F. Needs attention today (snapshot, not tied to the period)
$cnt = fn($sql) => (int)($conn->query($sql)->fetch_assoc()['c'] ?? 0);
$vacc_overdue = $cnt("SELECT COUNT(*) c FROM pets WHERE deleted_at IS NULL AND next_vaccination IS NOT NULL AND next_vaccination < CURDATE()");
$vacc_soon    = $cnt("SELECT COUNT(*) c FROM pets WHERE deleted_at IS NULL AND next_vaccination BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY");
$pending_now  = $cnt("SELECT COUNT(*) c FROM appointments WHERE status = 'Pending' AND service_type <> 'Pet Boarding Reservation'")
              + $cnt("SELECT COUNT(*) c FROM boarding_reservations WHERE status = 'Pending'");
$resched_now  = $cnt("SELECT COUNT(*) c FROM appointments WHERE status IN ('Reschedule Proposed', 'Reschedule Requested')");
$boarding_now = $cnt("SELECT COUNT(*) c FROM boarding_reservations WHERE status = 'Checked In'");

// ── 3. DESCRIPTIONS (what the records show) ──────────────────────────────
$desc = [];
$desc['appt'] = $A['total']
    ? "{$A['total']} clinic appointment(s) in this period: {$A['completed']} completed ({$pct($A['completed'], $A['total'])}%), {$A['active']} approved or in progress, {$A['pending']} pending, {$A['cancelled']} cancelled or rejected and {$A['noshow']} no-show(s)."
    : "No clinic appointments in this period.";
if ($volume_total) {
    $busy_k = array_search(max($appt_volume), $appt_volume);
    $active_buckets = count(array_filter($appt_volume));
    $desc['volume'] = "$volume_total appointment(s) (cancelled and rejected not counted) over $active_buckets $bucket_unit(s) with bookings — an average of "
        . number_format($volume_total / max(1, count($bucket_keys)), 1) . " per $bucket_unit. The busiest $bucket_unit was {$label_of($busy_k)} with {$appt_volume[$busy_k]}.";
} else $desc['volume'] = "No appointments to show for this period.";
if ($service_total) {
    $sk = array_keys($services);
    $desc['services'] = "The most requested service was {$sk[0]} ({$services[$sk[0]]} of $service_total, {$pct($services[$sk[0]], $service_total)}%)"
        . (isset($sk[1]) ? ", followed by {$sk[1]} ({$services[$sk[1]]}, {$pct($services[$sk[1]], $service_total)}%)" : '') . ".";
} else $desc['services'] = "No services to show for this period.";
$named_vets = array_filter($vets, fn($v, $id) => $id && $v['assigned'] > 0, ARRAY_FILTER_USE_BOTH);
if ($named_vets) {
    $top_vet = reset($named_vets);
    $desc['caseload'] = "{$top_vet['name']} handled the most appointments ({$top_vet['assigned']}, {$top_vet['completed']} completed)."
        . (isset($vets[0]) && $vets[0]['assigned'] ? " {$vets[0]['assigned']} appointment(s) had no veterinarian assigned." : '');
} else $desc['caseload'] = "No appointments were assigned to a veterinarian in this period.";
if (max($appt_hours) > 0) {
    $ph = array_search(max($appt_hours), $appt_hours);
    $desc['hours'] = "The most booked hour was " . date('g A', mktime($ph, 0, 0)) . " with {$appt_hours[$ph]} appointment(s) ({$pct($appt_hours[$ph], array_sum($appt_hours))}% of the period).";
} else $desc['hours'] = "No appointment times to show for this period.";
$desc['board'] = $B['total']
    ? "{$B['total']} boarding stay(s) with check-in in this period: {$B['completed']} completed, {$B['active']} approved or boarding, {$B['pending']} pending and {$B['cancelled']} cancelled or rejected. Confirmed stays averaged {$B['avg_nights']} night(s); cages were {$B['occupancy']}% occupied ($occ_nights of $cage_nights cage-nights, $cage_count cages)."
    : "No boarding stays in this period.";
if ($P['invoices']) {
    $best_k = array_search(max($pay_billed), $pay_billed);
    $desc['pay'] = "{$peso($P['billed'])} was billed on {$P['invoices']} valid invoice(s) and {$peso($P['collected'])} of it was collected ({$P['rate']}%); {$peso($P['owed'])} is still unpaid on {$P['unpaid']} invoice(s). "
        . "The highest $bucket_unit was {$label_of($best_k)} ({$peso($pay_billed[$best_k])} billed).";
} else $desc['pay'] = "No valid invoices were issued in this period.";
if ($methods) { $tm = array_key_first($methods); $desc['methods'] = "Most money was received through $tm ({$peso($methods[$tm][1])} from {$methods[$tm][0]} payment(s))."; }
else $desc['methods'] = "No payments were received in this period.";
if ($F['count']) {
    $mode = array_search(max($rating_dist), $rating_dist);
    $desc['fb'] = "{$F['count']} rating(s) with an average of " . number_format($F['avg'], 1) . " out of 5 ({$F['verdict']}). {$F['positive']}% gave 4 or 5 stars; the most frequent rating was $mode star(s) ({$rating_dist[$mode]}).";
} else $desc['fb'] = "No client ratings were submitted in this period.";
$desc['acct'] = "{$U['total']} account(s): {$U['clients']} clients, {$U['staff']} vet staff, {$U['doctors']} veterinarian(s), {$U['admins']} admin and {$U['owners']} clinic owner. {$U['new_in_period']} account(s) and $pets_new pet(s) were added in this period; $pets_total pets are registered.";

$key_findings = [
    ['fa-calendar-check', $desc['appt']],
    ['fa-peso-sign', $P['invoices'] ? "{$peso($P['billed'])} billed, {$peso($P['collected'])} collected ({$P['rate']}%) and {$peso($P['owed'])} unpaid." : $desc['pay']],
    ['fa-stethoscope', $service_total ? "Top service: " . array_key_first($services) . " ({$pct($services[array_key_first($services)], $service_total)}% of appointments)." : $desc['services']],
    ['fa-hotel', $B['total'] ? "{$B['total']} boarding stay(s); cages {$B['occupancy']}% occupied." : $desc['board']],
    ['fa-star', $F['count'] ? "Client satisfaction: " . number_format($F['avg'], 1) . " / 5 from {$F['count']} rating(s), {$F['positive']}% positive." : $desc['fb']],
    ['fa-users', "{$U['new_in_period']} new account(s) and $pets_new new pet(s) in this period."],
];

// ── 4. CSV EXPORT — the same rows and rules as the screen ────────────────
$csv_types = ['appointments' => 'Appointment Report', 'boarding' => 'Boarding Report', 'payments' => 'Payments & Revenue Report', 'feedback' => 'Client Feedback Report', 'accounts' => 'Accounts Report'];
if (isset($_GET['export']) && isset($csv_types[$_GET['export']])) {
    $type = $_GET['export'];
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pragmavet_' . $type . '_' . str_replace('-', '', $start_date) . '-' . str_replace('-', '', $end_date) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names and ₱ correctly
    fputcsv($out, ['PragmaVet Animal Clinic — ' . $csv_types[$type]]);
    fputcsv($out, ['Period', $filter_label . ' (' . $start_date . ' to ' . $end_date . ')']);
    fputcsv($out, ['Generated', date('Y-m-d H:i'), 'By', $_SESSION['user'] ?? '']);
    fputcsv($out, []);
    if ($type === 'appointments') {
        fputcsv($out, ['Summary', 'Total', $A['total'], 'Completed', $A['completed'], 'Approved / in progress', $A['active'], 'Pending', $A['pending'], 'Cancelled / rejected', $A['cancelled'], 'No-show', $A['noshow']]);
        fputcsv($out, []);
        fputcsv($out, ['Appt ID', 'Date', 'Time', 'Client', 'Pet', 'Service', 'Veterinarian', 'Status']);
        foreach ($appt_rows as $r) fputcsv($out, [$r['id'], $r['appointment_date'], $r['appointment_time'] ? date('h:i A', strtotime($r['appointment_time'])) : '', $r['client'], $r['pet_name'], $r['service_type'], $r['vet'], $r['status']]);
        fputcsv($out, []);
        fputcsv($out, ['Veterinarian', 'Assigned', 'Completed', 'Active', 'Cancelled / no-show', 'Completion %']);
        foreach ($vets as $v) fputcsv($out, [$v['name'], $v['assigned'], $v['completed'], $v['active'], $v['lost'], $pct($v['completed'], $v['assigned'])]);
    } elseif ($type === 'boarding') {
        fputcsv($out, ['Summary', 'Total stays', $B['total'], 'Completed', $B['completed'], 'Approved / boarding', $B['active'], 'Pending', $B['pending'], 'Cancelled / rejected', $B['cancelled'], 'Average nights', $B['avg_nights'], 'Cage occupancy %', $B['occupancy']]);
        fputcsv($out, []);
        fputcsv($out, ['Stay ID', 'Client', 'Pet', 'Cage', 'Check-in', 'Check-out', 'Nights', 'Status']);
        foreach ($board_rows as $r) fputcsv($out, [$r['id'], $r['client'], $r['pet_name'], $r['cage_number'] ? 'Cage ' . str_pad($r['cage_number'], 2, '0', STR_PAD_LEFT) : 'Not assigned', $r['appointment_date'], $r['end_date'], $r['nights'], $r['status']]);
    } elseif ($type === 'payments') {
        fputcsv($out, ['Summary', 'Invoices', $P['invoices'], 'Billed', number_format($P['billed'], 2, '.', ''), 'Collected', number_format($P['collected'], 2, '.', ''), 'Unpaid balance', number_format($P['owed'], 2, '.', ''), 'Collection rate %', $P['rate']]);
        fputcsv($out, []);
        fputcsv($out, ['Invoice #', 'Date', 'Client', 'Pet', 'Category', 'Total (PHP)', 'Paid (PHP)', 'Balance (PHP)', 'Status', 'Payment Method', 'Reference']);
        foreach ($bills as $b) fputcsv($out, [$b['invoice_no'] ?: 'INV-' . str_pad($b['id'], 5, '0', STR_PAD_LEFT), $b['_date'], $b['client'], $b['_pet'], $bill_category($b),
            number_format((float)$b['total_amount'], 2, '.', ''), number_format($b['_paid'], 2, '.', ''), number_format($b['_owed'], 2, '.', ''), $pay_label($b), $b['_paid'] > 0 ? ($b['payment_method'] ?: 'Cash') : '', $b['reference_no'] ?? '']);
    } elseif ($type === 'feedback') {
        fputcsv($out, ['Summary', 'Ratings', $F['count'], 'Average', $F['avg'], '5 stars', $rating_dist[5], '4 stars', $rating_dist[4], '3 stars', $rating_dist[3], '2 stars', $rating_dist[2], '1 star', $rating_dist[1]]);
        fputcsv($out, []);
        fputcsv($out, ['Date', 'Client', 'Pet', 'Service', 'Rating', 'Comment']);
        foreach ($fb_rows as $r) fputcsv($out, [substr($r['created_at'], 0, 10), $r['client'], $r['pet_name'] ?? '', $r['service_type'] ?? '', $r['rating'], $r['comments']]);
    } else {
        fputcsv($out, ['Metric', 'Value']);
        foreach ([['Total accounts', $U['total']], ['Clients / pet owners', $U['clients']], ['Vet staff', $U['staff']], ['Veterinarians', $U['doctors']], ['Administrators', $U['admins']], ['Clinic owners', $U['owners']],
                  ['Verified accounts', $U['verified']], ['Accounts created in period', $U['new_in_period']], ['Registered pets', $pets_total], ['Pets added in period', $pets_new]] as $row) fputcsv($out, $row);
    }
    fclose($out);
    exit();
}
$period_qs = http_build_query(['period' => $period] + ($period === 'custom' ? ['start_date' => $start_date, 'end_date' => $end_date] : []));
$svc_cols = ['#2563eb', '#7c3aed', '#0891b2', '#ea580c', '#15803d', '#be123c', '#d97706', '#4f46e5'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="theme.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="theme.css?v=<?= time() ?>">
    <title>Admin Dashboard | PragmaVet</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root { --sidebar-width: 260px; --blue: #2563eb; --blue-d: #1d4ed8; --ink: #0f172a; --line: #e8edf3; }
        body { background: #f6f8fb; font-family: 'Inter', sans-serif; color: #1e293b; overflow-x: hidden; }
        .main-content { margin-left: var(--sidebar-width) !important; width: calc(100% - var(--sidebar-width)) !important; padding: 28px 32px !important; min-height: 100vh; box-sizing: border-box !important; }
        /* Sidebar (same look as the other admin pages) */
        .sidebar { background: #fff !important; width: var(--sidebar-width) !important; height: 100vh !important; position: fixed !important; top: 0 !important; left: 0 !important; padding: 28px 20px 24px !important; z-index: 1000 !important; display: flex !important; flex-direction: column !important; border-right: 1px solid #eef2f6 !important; box-shadow: none !important; overflow-y: auto !important; }
        .sidebar-brand { display: flex !important; align-items: center !important; gap: 12px !important; margin-bottom: 24px !important; padding: 0 4px !important; text-decoration: none !important; }
        .sidebar-brand-icon { width: 44px !important; height: 44px !important; min-width: 44px !important; border-radius: 13px !important; background: #2563eb !important; display: flex !important; align-items: center !important; justify-content: center !important; color: #fff !important; font-size: 20px !important; box-shadow: 0 4px 14px rgba(37,99,235,.28) !important; }
        .sidebar-brand span { font-weight: 800 !important; color: #182032 !important; font-size: 22px !important; letter-spacing: -.4px !important; }
        .sidebar-user { margin-bottom: 24px !important; padding: 15px 18px !important; border-radius: 14px !important; background: #f8fafc !important; border: 1px solid #eef2f6 !important; }
        .sidebar-user .welcome { font-size: 13px !important; color: #64748b !important; font-weight: 500 !important; }
        .sidebar-user .uname { font-weight: 700 !important; color: #0f172a !important; font-size: 15.5px !important; margin-top: 3px !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; }
        .sidebar-heading { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; color: #94a3b8; padding: 12px 14px 4px; }
        .sidebar a { display: flex !important; align-items: center !important; gap: 14px !important; padding: 12px 16px !important; border-radius: 12px !important; color: #334155 !important; text-decoration: none !important; margin-bottom: 4px !important; font-weight: 600 !important; font-size: 14.5px !important; transition: all .2s ease !important; }
        .sidebar a i { width: 22px !important; text-align: center !important; font-size: 16px !important; color: #64748b !important; flex-shrink: 0 !important; }
        .sidebar a:hover { background: #f8fafc !important; color: #0f172a !important; }
        .sidebar a:hover i { color: #2563eb !important; }
        .sidebar a.active { background: #2563eb !important; color: #fff !important; font-weight: 700 !important; box-shadow: 0 4px 14px rgba(37,99,235,.28) !important; }
        .sidebar a.active i { color: #fff !important; }
        .sidebar .sidebar-logout { margin-top: auto !important; padding-top: 20px !important; border-top: 1px solid #f1f5f9 !important; }
        .sidebar .sidebar-logout a { color: #dc2626 !important; }
        .sidebar .sidebar-logout a:hover { background: #fef2f2 !important; }

        /* Header */
        .ds-hero { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 60%, #3b82f6 100%); border-radius: 22px; padding: 24px 28px; color: #fff; display: flex; justify-content: space-between; align-items: center; gap: 18px; flex-wrap: wrap; position: relative; overflow: hidden; margin-bottom: 16px; box-shadow: 0 14px 34px rgba(37,99,235,.2); }
        .ds-hero::after { content: '\f201'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 26px; bottom: -34px; font-size: 140px; opacity: .08; pointer-events: none; }
        .ds-tag { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.16); border-radius: 99px; padding: 4px 12px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .ds-hero h1 { font-size: 25px; font-weight: 800; margin: 10px 0 3px; letter-spacing: -.4px; color: #fff !important; -webkit-text-fill-color: #fff !important; background: none !important; }
        .ds-hero p { margin: 0; color: #dbeafe; font-size: 13.5px; }
        .ds-actions { position: relative; z-index: 2; display: flex; gap: 8px; flex-wrap: wrap; }
        .ds-hbtn { background: #fff !important; color: #1e3a8a !important; border: 0 !important; border-radius: 99px; padding: 10px 18px; font-weight: 800; font-size: 13.5px; display: inline-flex; gap: 8px; align-items: center; text-decoration: none; box-shadow: 0 8px 20px rgba(15,23,42,.2) !important; cursor: pointer; }
        .ds-hbtn:hover { color: #1e3a8a !important; transform: translateY(-1px); }
        .ds-hbtn.ghost { background: rgba(255,255,255,.14) !important; color: #fff !important; box-shadow: none !important; border: 1px solid rgba(255,255,255,.35) !important; }
        .ds-hbtn.ghost:hover { background: rgba(255,255,255,.24) !important; color: #fff !important; }
        .ds-menu { border-radius: 14px; border: 1px solid var(--line); box-shadow: 0 14px 30px rgba(15,23,42,.14); padding: 6px; min-width: 230px; }
        .ds-menu .dropdown-item { border-radius: 9px; padding: 8px 12px; font-size: 13.5px; font-weight: 600; color: #334155; display: flex; gap: 10px; align-items: center; }
        .ds-menu .dropdown-item i { width: 18px; color: #2563eb; }
        .ds-menu .dropdown-item:hover { background: #eff6ff; color: #1d4ed8; }

        /* Period filter */
        .ds-filter { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 12px 16px; display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; justify-content: space-between; box-shadow: 0 2px 10px rgba(15,23,42,.03); margin-bottom: 16px; }
        .ds-pills { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .ds-pills .lbl { font-size: 12.5px; font-weight: 700; color: #64748b; margin-right: 4px; }
        .ds-pill { padding: 7px 14px; border-radius: 99px; font-size: 13px; font-weight: 700; color: #475569; background: #f1f5f9; text-decoration: none; }
        .ds-pill:hover { background: #e2e8f0; color: #0f172a; }
        .ds-pill.on { background: var(--blue); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,.22); }
        .ds-range { display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; }
        .ds-range input[type=date] { border: 1px solid #e2e8f0 !important; border-radius: 99px !important; padding: 6px 12px !important; height: 36px !important; min-height: 0 !important; font-size: 13px !important; box-shadow: none !important; background: #fff !important; width: 150px !important; max-width: 150px !important; flex: 0 0 150px !important; display: inline-block !important; margin: 0 !important; }
        .ds-range input[type=date]:focus { border-color: var(--blue) !important; outline: 0; }
        .ds-range span { color: #94a3b8; font-size: 12.5px; }
        .ds-apply { background: var(--blue) !important; color: #fff !important; border: 0 !important; border-radius: 99px; height: 36px; padding: 0 16px; font-weight: 700; font-size: 13px; box-shadow: none !important; }
        .ds-apply:hover { background: var(--blue-d) !important; }
        .ds-showing { width: 100%; font-size: 12.5px; color: #64748b; border-top: 1px dashed #e2e8f0; padding-top: 9px; }
        .ds-showing b { color: var(--ink); }

        /* Cards */
        .ds-card { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 18px 20px; box-shadow: 0 2px 10px rgba(15,23,42,.03); height: 100%; }
        .ds-card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .ds-card-head h5 { font-size: 15.5px; font-weight: 800; margin: 0; color: var(--ink); }
        .ds-card-head .hint { font-size: 12px; color: #94a3b8; }
        .ds-ic { width: 38px; height: 38px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
        .ds-findings { border-left: 4px solid var(--blue); margin-bottom: 4px; height: auto; }
        .ds-kf { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; }
        .ds-kf-row { display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; color: #334155; line-height: 1.5; padding: 8px 10px; border-radius: 12px; background: #f8fafc; }
        .ds-kf-ic { width: 26px; height: 26px; border-radius: 8px; background: #eff6ff; color: var(--blue); display: inline-flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; }
        .ds-note { display: flex; gap: 9px; align-items: flex-start; margin-top: 12px; padding: 9px 12px; border-radius: 12px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 12.5px; }
        .ds-sec { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin: 24px 0 10px; display: flex; align-items: center; gap: 8px; }
        .ds-sec i { color: var(--blue); }
        .ds-sec span { text-transform: none; letter-spacing: 0; font-weight: 600; color: #94a3b8; }
        .ds-grid4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 14px; }
        .ds-kpi { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 15px 17px; box-shadow: 0 2px 10px rgba(15,23,42,.03); }
        .ds-kpi-top { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .ds-kpi-l { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .04em; }
        .ds-kpi-v { font-size: 25px; font-weight: 800; color: var(--ink); letter-spacing: -.5px; margin: 8px 0 2px; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .ds-kpi-v small { font-size: 14px; color: #94a3b8; font-weight: 700; }
        .ds-kpi-s { font-size: 12.5px; color: #64748b; }
        .ds-bar { height: 6px; background: #eef2f6; border-radius: 6px; overflow: hidden; margin-top: 10px; }
        .ds-bar span { display: block; height: 100%; border-radius: 6px; }
        .ds-status { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; display: flex; gap: 12px; align-items: center; font-size: 13px; color: #334155; }
        .ds-status b { color: var(--ink); font-size: 15px; }
        .ds-status small { display: block; color: #94a3b8; font-size: 11.5px; margin-top: 1px; }
        .ds-chart { position: relative; height: 250px; }
        .ds-chart.sm { height: 200px; }
        .ds-desc { display: flex; gap: 9px; align-items: flex-start; margin-top: 12px; padding-top: 11px; border-top: 1px solid #f1f5f9; }
        .ds-desc i { color: var(--blue); margin-top: 3px; font-size: 13px; }
        .ds-desc p { font-size: 12.5px; color: #475569; line-height: 1.6; margin: 0; }
        .ds-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .ds-table th { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #64748b; background: #f8fafc; padding: 9px 12px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
        .ds-table td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .ds-table tr:last-child td { border-bottom: 0; }
        .ds-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .ds-table .ctr { text-align: center; }
        .ds-legend { list-style: none; padding: 0; margin: 10px 0 0; }
        .ds-legend li { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .ds-legend li:last-child { border-bottom: 0; }
        .ds-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
        .ds-legend .n { margin-left: auto; font-weight: 700; color: var(--ink); }
        .ds-legend .p { color: #94a3b8; font-size: 12px; width: 50px; text-align: right; }
        .ds-mini { width: 64px; height: 6px; background: #eef2f6; border-radius: 6px; overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 6px; }
        .ds-mini span { display: block; height: 100%; background: #16a34a; }
        .ds-rd-row { display: grid; grid-template-columns: 110px 1fr 76px; gap: 12px; align-items: center; margin-bottom: 11px; }
        .ds-rd-k { font-weight: 800; font-size: 13.5px; color: var(--ink); white-space: nowrap; }
        .ds-rd-k small { font-weight: 600; color: #94a3b8; }
        .ds-rd-track { height: 11px; background: #f1f5f9; border-radius: 99px; overflow: hidden; }
        .ds-rd-track span { display: block; height: 100%; border-radius: 99px; }
        .ds-rd-n { text-align: right; font-size: 13px; }
        .ds-rd-n small { color: #94a3b8; margin-left: 3px; }
        .ds-stars, .ds-stars i { color: #f59e0b; letter-spacing: 1px; white-space: nowrap; }
        .ds-stars i.off { color: #e2e8f0; }
        .ds-fb { display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .ds-fb:last-child { border-bottom: 0; }
        .ds-fb-av { width: 36px; height: 36px; border-radius: 50%; color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ds-fb strong { font-size: 13.5px; color: var(--ink); }
        .ds-fb .date { font-size: 11.5px; color: #94a3b8; }
        .ds-fb p { font-size: 13px; color: #475569; margin: 3px 0 0; }
        .ds-fb-list { max-height: 300px; overflow-y: auto; }
        .ds-empty { text-align: center; color: #94a3b8; padding: 28px 10px; font-size: 13.5px; }
        .ds-print-head, .ds-print-foot { display: none; }
        @media (max-width: 1200px) { .ds-grid4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } .ds-kf { grid-template-columns: 1fr; } }
        @media (max-width: 992px) { .main-content { margin-left: 0 !important; width: 100% !important; padding: 18px 16px !important; } .ds-range { flex-wrap: wrap; } }
        @media (max-width: 576px) { .ds-grid4 { grid-template-columns: 1fr; } .ds-hero { padding: 20px; } .ds-hero h1 { font-size: 21px; } }

        @media print {
            @page { size: A4 portrait; margin: 14mm 12mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            body { background: #fff !important; }
            .sidebar, .no-print, .ds-filter, .position-fixed, .pv-nav-btn, .pv-nav-backdrop, .dropdown-menu, iframe { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            .ds-print-head { display: block; border-bottom: 3px double #1e3a8a; padding-bottom: 10px; margin-bottom: 14px; }
            .ds-print-head .cl { font-size: 20px; font-weight: 800; color: #1e3a8a; text-transform: uppercase; letter-spacing: .5px; }
            .ds-print-head .ad { font-size: 11px; color: #475569; }
            .ds-print-head .ti { font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 10px; }
            .ds-print-head .me { font-size: 11.5px; color: #334155; }
            .ds-print-foot { display: flex; justify-content: space-between; gap: 30px; margin-top: 30px; font-size: 11px; color: #475569; break-inside: avoid; }
            .ds-print-foot div { flex: 1; text-align: center; border-top: 1px solid #0f172a; padding-top: 5px; }
            .ds-kpi, .ds-card, .ds-status { box-shadow: none !important; break-inside: avoid; page-break-inside: avoid; }
            .ds-grid4 { grid-template-columns: repeat(4, 1fr) !important; gap: 8px; }
            .ds-kpi { padding: 9px 11px; } .ds-kpi-v { font-size: 17px; }
            .row > [class*="col-"] { flex: 0 0 100% !important; max-width: 100% !important; }
            .ds-card { height: auto !important; margin-bottom: 10px; }
            .ds-chart { height: 220px !important; } .ds-chart.sm { height: 190px !important; }
            .ds-fb-list, .table-responsive, .pv-table-scroll { max-height: none !important; overflow: visible !important; }
            .ds-sec { break-after: avoid; margin-top: 16px; }
            .ds-kf { grid-template-columns: 1fr 1fr; }
            a { color: inherit !important; text-decoration: none !important; }
        }
    </style>
</head>
<body>
    <?php include("includes/admin_sidebar.php"); ?>

    <div class="main-content">
        <!-- Printed letterhead -->
        <div class="ds-print-head">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="cl">PragmaVet Animal Clinic</div>
                    <div class="ad">0234 Unit B Palico St, Palico 4, Imus, Cavite · Tel: 046-8851-221 | 0954-285-3053 · pragmavet@gmail.com</div>
                </div>
                <div class="me text-end">Printed <?= date('M j, Y g:i A') ?></div>
            </div>
            <div class="ti">Clinic Reports &amp; Descriptive Analytics</div>
            <div class="me">Period: <b><?= htmlspecialchars($filter_label) ?></b> (<?= $range_text ?>)</div>
        </div>

        <div class="ds-hero no-print">
            <div>
                <span class="ds-tag"><i class="fa fa-chart-line"></i> Reports &amp; Analytics</span>
                <h1>Admin Dashboard</h1>
                <p>Descriptive summary of the clinic's appointments, boarding, billing and client feedback · <?= htmlspecialchars($filter_label) ?>, <?= $range_text ?></p>
            </div>
            <div class="ds-actions">
                <button type="button" class="ds-hbtn" onclick="pvPrintReport()"><i class="fa fa-print"></i> Print Report</button>
                <div class="dropdown">
                    <button type="button" class="ds-hbtn ghost dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="fa fa-file-excel"></i> Export CSV</button>
                    <ul class="dropdown-menu dropdown-menu-end ds-menu">
                        <?php foreach (['appointments' => ['fa-calendar-check', 'Appointments'], 'boarding' => ['fa-hotel', 'Boarding stays'], 'payments' => ['fa-file-invoice-dollar', 'Payments & invoices'], 'feedback' => ['fa-star', 'Client feedback'], 'accounts' => ['fa-users', 'Accounts & pets']] as $ek => [$ei, $el]): ?>
                            <li><a class="dropdown-item" href="admin.php?<?= $period_qs ?>&export=<?= $ek ?>"><i class="fa <?= $ei ?>"></i> <?= $el ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Period filter -->
        <div class="ds-filter">
            <div class="ds-pills">
                <span class="lbl"><i class="fa fa-calendar-alt me-1" style="color:#2563eb;"></i>Period</span>
                <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year', 'all' => 'All Records'] as $pk => $pl): ?>
                    <a class="ds-pill <?= $period === $pk ? 'on' : '' ?>" href="admin.php?period=<?= $pk ?>"><?= $pl ?></a>
                <?php endforeach; ?>
            </div>
            <form method="GET" action="admin.php" class="ds-range">
                <input type="hidden" name="period" value="custom">
                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required aria-label="From">
                <span>to</span>
                <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required aria-label="To">
                <button type="submit" class="ds-apply">Apply</button>
            </form>
            <div class="ds-showing">Showing <b><?= htmlspecialchars($filter_label) ?></b> · <?= $range_text ?> · every figure below follows this period unless it says "today"</div>
        </div>

        <!-- Key findings -->
        <div class="ds-card ds-findings">
            <div class="ds-card-head"><span class="ds-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-clipboard-list"></i></span><div><h5>Key Findings</h5><span class="hint">What the records show for <?= htmlspecialchars(strtolower($filter_label)) ?></span></div></div>
            <div class="ds-kf">
                <?php foreach ($key_findings as [$ic, $txt]): ?><div class="ds-kf-row"><span class="ds-kf-ic"><i class="fa <?= $ic ?>"></i></span><span><?= htmlspecialchars($txt) ?></span></div><?php endforeach; ?>
            </div>
            <?php if ($excluded_cnt): ?>
            <div class="ds-note"><i class="fa fa-filter" style="margin-top:3px;"></i><span>Billing figures count valid invoices only. <?= $excluded_cnt ?> invoice(s) are left out: <?= $excluded['void'][0] ?> void, <?= $excluded['cancelled'][0] ?> for cancelled or rejected bookings, <?= $excluded['missing'][0] ?> for bookings that no longer exist and <?= $excluded['duplicate'][0] ?> duplicate boarding invoice(s).</span></div>
            <?php endif; ?>
        </div>

        <!-- Needs attention today -->
        <div class="ds-sec"><i class="fa fa-bell"></i> Needs attention today <span>· as of <?= date('M j, g:i A') ?></span></div>
        <div class="ds-grid4">
            <div class="ds-status"><span class="ds-ic" style="background:#fffbeb;color:#d97706;"><i class="fa fa-syringe"></i></span><div><b><?= $vacc_overdue ?></b> pet(s) overdue for vaccination<small><?= $vacc_soon ?> more due within 7 days</small></div></div>
            <div class="ds-status"><span class="ds-ic" style="background:#f1f5f9;color:#475569;"><i class="fa fa-hourglass-start"></i></span><div><b><?= $pending_now ?></b> request(s) pending<small>waiting for vet staff approval</small></div></div>
            <div class="ds-status"><span class="ds-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-clock-rotate-left"></i></span><div><b><?= $resched_now ?></b> awaiting reschedule reply<small>new dates sent to clients</small></div></div>
            <div class="ds-status"><span class="ds-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-hotel"></i></span><div><b><?= $boarding_now ?> of <?= $cage_count ?></b> boarding cages occupied<small>pets checked in right now</small></div></div>
        </div>

        <!-- ── APPOINTMENTS ── -->
        <div class="ds-sec"><i class="fa fa-calendar-check"></i> Appointments <span>· <?= $range_text ?></span></div>
        <div class="ds-grid4">
            <?php foreach ([
                ['Total Appointments', $A['total'], 'Scheduled in this period', 'fa-calendar', '#2563eb', '#eff6ff', null],
                ['Completed', $A['completed'], $pct($A['completed'], $A['total']) . '% of appointments', 'fa-check-double', '#15803d', '#f0fdf4', $pct($A['completed'], $A['total'])],
                ['Approved / In Progress', $A['active'], $A['pending'] . ' still pending', 'fa-stethoscope', '#0e7490', '#ecfeff', null],
                ['Cancelled / No-Show', $A['cancelled'] + $A['noshow'], $A['cancelled'] . ' cancelled or rejected · ' . $A['noshow'] . ' no-show', 'fa-user-slash', '#be123c', '#fff1f2', $pct($A['cancelled'] + $A['noshow'], $A['total'])],
            ] as [$l, $v, $s, $ic, $c, $bg, $bar]): ?>
            <div class="ds-kpi">
                <div class="ds-kpi-top"><span class="ds-kpi-l"><?= $l ?></span><span class="ds-ic" style="width:34px;height:34px;font-size:14px;background:<?= $bg ?>;color:<?= $c ?>;"><i class="fa <?= $ic ?>"></i></span></div>
                <div class="ds-kpi-v"><?= number_format($v) ?></div>
                <div class="ds-kpi-s"><?= htmlspecialchars($s) ?></div>
                <?php if ($bar !== null): ?><div class="ds-bar"><span style="width:<?= min(100, $bar) ?>%;background:<?= $c ?>;"></span></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="ds-card">
                    <div class="ds-card-head"><span class="ds-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-chart-column"></i></span><div><h5>Appointment Volume</h5><span class="hint">Appointments <?= $bucket_word ?>, cancelled and rejected not counted</span></div></div>
                    <div class="ds-chart"><canvas id="chApptVolume"></canvas></div>
                    <div class="ds-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc['volume']) ?></p></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="ds-card">
                    <div class="ds-card-head"><span class="ds-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-chart-pie"></i></span><div><h5>Service Volume</h5><span class="hint">Share of appointments by service</span></div></div>
                    <?php if ($service_total): ?>
                        <div class="ds-chart sm"><canvas id="chServices"></canvas></div>
                        <ul class="ds-legend"><?php $si = 0; foreach ($services as $sn => $sc): ?><li><span class="ds-dot" style="background:<?= $svc_cols[$si++ % 8] ?>;"></span><?= htmlspecialchars($sn) ?><span class="n"><?= $sc ?></span><span class="p"><?= $pct($sc, $service_total) ?>%</span></li><?php endforeach; ?></ul>
                    <?php else: ?><div class="ds-empty">No appointments in this period.</div><?php endif; ?>
                    <div class="ds-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc['services']) ?></p></div>
                </div>
            </div>
        </div>
        <div class="row g-3 mt-0">
            <div class="col-xl-7">
                <div class="ds-card">
                    <div class="ds-card-head"><span class="ds-ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-user-doctor"></i></span><div><h5>Veterinarian Caseload</h5><span class="hint">Active = approved, checked in or in progress · Not seen = cancelled, rejected or no-show</span></div></div>
                    <div class="table-responsive">
                        <table class="ds-table">
                            <thead><tr><th>Veterinarian</th><th class="ctr">Assigned</th><th class="ctr">Completed</th><th class="ctr">Active</th><th class="ctr">Not Seen</th><th>Completion</th></tr></thead>
                            <tbody>
                            <?php foreach ($vets as $v): $rate = $pct($v['completed'], $v['assigned']); ?>
                                <tr><td class="fw-bold text-nowrap"><?= htmlspecialchars($v['name']) ?></td><td class="ctr fw-bold"><?= $v['assigned'] ?></td><td class="ctr" style="color:#15803d;font-weight:700;"><?= $v['completed'] ?></td>
                                    <td class="ctr" style="color:#1d4ed8;font-weight:600;"><?= $v['active'] ?></td><td class="ctr" style="color:#be123c;font-weight:600;"><?= $v['lost'] ?></td>
                                    <td class="text-nowrap"><span class="ds-mini"><span style="width:<?= $rate ?>%;"></span></span><b style="font-size:12.5px;"><?= $rate ?>%</b></td></tr>
                            <?php endforeach; ?>
                            <?php if (!$vets): ?><tr><td colspan="6" class="ds-empty">No veterinarians found.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="ds-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc['caseload']) ?></p></div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="ds-card">
                    <div class="ds-card-head"><span class="ds-ic" style="background:#fff7ed;color:#ea580c;"><i class="fa fa-clock"></i></span><div><h5>Appointments by Hour</h5><span class="hint">Busiest hour highlighted (open 24/7)</span></div></div>
                    <div class="ds-chart sm"><canvas id="chApptHours"></canvas></div>
                    <div class="ds-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc['hours']) ?></p></div>
                </div>
            </div>
        </div>

        <!-- ── BILLING ── -->
        <div class="ds-sec"><i class="fa fa-file-invoice-dollar"></i> Billing &amp; Collections <span>· valid invoices issued <?= $range_text ?></span></div>
        <div class="ds-grid4">
            <?php foreach ([
                ['Total Billed', $peso($P['billed']), $P['invoices'] . ' valid invoice(s)', 'fa-receipt', '#2563eb', '#eff6ff', null, ''],
                ['Collected', $peso($P['collected']), $P['rate'] . '% of billed', 'fa-circle-check', '#15803d', '#f0fdf4', $P['rate'], 'color:#15803d;'],
                ['Unpaid Balance', $peso($P['owed']), $P['unpaid'] . ' invoice(s) with a balance', 'fa-hourglass-half', '#b45309', '#fffbeb', null, 'color:#b45309;'],
                ['Fully Paid', $P['paid'], 'of ' . $P['invoices'] . ' invoice(s)', 'fa-file-circle-check', '#0e7490', '#ecfeff', $pct($P['paid'], $P['invoices']), ''],
            ] as [$l, $v, $s, $ic, $c, $bg, $bar, $vs]): ?>
            <div class="ds-kpi">
                <div class="ds-kpi-top"><span class="ds-kpi-l"><?= $l ?></span><span class="ds-ic" style="width:34px;height:34px;font-size:14px;background:<?= $bg ?>;color:<?= $c ?>;"><i class="fa <?= $ic ?>"></i></span></div>
                <div class="ds-kpi-v" style="<?= $vs ?>"><?= is_numeric($v) ? number_format($v) : $v ?></div>
                <div class="ds-kpi-s"><?= htmlspecialchars($s) ?></div>
                <?php if ($bar !== null): ?><div class="ds-bar"><span style="width:<?= min(100, $bar) ?>%;background:<?= $c ?>;"></span></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="ds-card">
                    <div class="ds-card-head"><span class="ds-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-chart-column"></i></span><div><h5>Billed vs Collected</h5><span class="hint">Peso amounts <?= $bucket_word ?>, by invoice date</span></div></div>
                    <div class="ds-chart"><canvas id="chPay"></canvas></div>
                    <div class="ds-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc['pay']) ?></p></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="ds-card">
                    <div class="ds-card-head"><span class="ds-ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-wallet"></i></span><div><h5>Payment Methods</h5><span class="hint">Money received in this period</span></div></div>
                    <table class="ds-table">
                        <thead><tr><th>Method</th><th class="ctr">Payments</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($methods as $mn => [$mc, $mt]): ?><tr><td class="fw-bold"><?= htmlspecialchars($mn) ?></td><td class="ctr"><?= $mc ?></td><td class="num fw-bold"><?= $peso($mt) ?></td></tr><?php endforeach; ?>
                        <?php if (!$methods): ?><tr><td colspan="3" class="ds-empty">No payments received in this period.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    <table class="ds-table mt-3">
                        <thead><tr><th>Category</th><th class="num">Billed</th><th class="num">Collected</th></tr></thead>
                        <tbody><?php foreach ($categories as $cn => [$cc, $cb, $cp]): ?><tr><td class="fw-bold"><?= $cn ?> <span class="text-muted fw-normal" style="font-size:12px;">(<?= $cc ?>)</span></td><td class="num"><?= $peso($cb) ?></td><td class="num" style="color:#15803d;font-weight:700;"><?= $peso($cp) ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                    <div class="ds-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc['methods']) ?></p></div>
                </div>
            </div>
        </div>

        <!-- ── BOARDING ── -->
        <div class="ds-sec"><i class="fa fa-hotel"></i> Boarding <span>· stays checking in <?= $range_text ?></span></div>
        <div class="ds-grid4">
            <?php foreach ([
                ['Total Stays', number_format($B['total']), 'Check-ins in this period', 'fa-hotel', '#2563eb', '#eff6ff', null],
                ['Completed', number_format($B['completed']), $B['active'] . ' approved or boarding', 'fa-circle-check', '#15803d', '#f0fdf4', $pct($B['completed'], $B['total'])],
                ['Average Stay', number_format($B['avg_nights'], 1), 'nights per confirmed stay', 'fa-moon', '#0e7490', '#ecfeff', null],
                ['Cage Occupancy', $B['occupancy'] . '%', $occ_nights . ' of ' . $cage_nights . ' cage-nights', 'fa-table-cells', '#d97706', '#fffbeb', $B['occupancy']],
            ] as [$l, $v, $s, $ic, $c, $bg, $bar]): ?>
            <div class="ds-kpi">
                <div class="ds-kpi-top"><span class="ds-kpi-l"><?= $l ?></span><span class="ds-ic" style="width:34px;height:34px;font-size:14px;background:<?= $bg ?>;color:<?= $c ?>;"><i class="fa <?= $ic ?>"></i></span></div>
                <div class="ds-kpi-v"><?= $v ?></div>
                <div class="ds-kpi-s"><?= htmlspecialchars($s) ?></div>
                <?php if ($bar !== null): ?><div class="ds-bar"><span style="width:<?= min(100, $bar) ?>%;background:<?= $c ?>;"></span></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="ds-card">
                    <div class="ds-card-head"><span class="ds-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-chart-column"></i></span><div><h5>Stays Checked In</h5><span class="hint">Confirmed stays <?= $bucket_word ?></span></div></div>
                    <div class="ds-chart"><canvas id="chBoardVolume"></canvas></div>
                    <div class="ds-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc['board']) ?></p></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="ds-card">
                    <div class="ds-card-head"><span class="ds-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-chart-pie"></i></span><div><h5>Stay Status</h5><span class="hint">All stays in this period</span></div></div>
                    <?php if ($B['total']): ?>
                        <div class="ds-chart sm"><canvas id="chBoardStatus"></canvas></div>
                        <ul class="ds-legend"><?php foreach ([['Completed', $B['completed'], '#15803d'], ['Approved / boarding', $B['active'], '#2563eb'], ['Pending', $B['pending'], '#d97706'], ['Cancelled / rejected', $B['cancelled'], '#be123c']] as [$sl, $sc, $col]): ?><li><span class="ds-dot" style="background:<?= $col ?>;"></span><?= $sl ?><span class="n"><?= $sc ?></span><span class="p"><?= $pct($sc, $B['total']) ?>%</span></li><?php endforeach; ?></ul>
                    <?php else: ?><div class="ds-empty">No boarding stays in this period.</div><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── FEEDBACK ── -->
        <div class="ds-sec"><i class="fa fa-star"></i> Client Feedback <span>· ratings submitted <?= $range_text ?></span></div>
        <div class="row g-3">
            <div class="col-xl-6">
                <div class="ds-card">
                    <div class="ds-card-head">
                        <span class="ds-ic" style="background:#fffbeb;color:#d97706;"><i class="fa fa-ranking-star"></i></span>
                        <div class="flex-grow-1"><h5>Rating Distribution</h5><span class="hint">Number of ratings per star</span></div>
                        <div class="text-end"><div style="font-size:22px;font-weight:800;color:#0f172a;line-height:1;"><?= number_format($F['avg'], 1) ?> <span style="font-size:13px;color:#94a3b8;">/ 5</span></div><div style="font-size:12px;font-weight:700;color:#15803d;"><?= $F['verdict'] ?></div></div>
                    </div>
                    <?php $rd_col = [1 => '#ef4444', 2 => '#f97316', 3 => '#f59e0b', 4 => '#22c55e', 5 => '#15803d']; $rd_lbl = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent']; $rd_max = max($rating_dist) ?: 1;
                    foreach ([5, 4, 3, 2, 1] as $s): ?>
                        <div class="ds-rd-row">
                            <span class="ds-rd-k"><?= $s ?> <i class="fa fa-star" style="color:<?= $rd_col[$s] ?>;"></i> <small><?= $rd_lbl[$s] ?></small></span>
                            <span class="ds-rd-track"><span style="width:<?= round($rating_dist[$s] * 100 / $rd_max) ?>%;background:<?= $rd_col[$s] ?>;"></span></span>
                            <span class="ds-rd-n"><b><?= $rating_dist[$s] ?></b><small><?= $pct($rating_dist[$s], $F['count']) ?>%</small></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="ds-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc['fb']) ?></p></div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="ds-card">
                    <div class="ds-card-head"><span class="ds-ic" style="background:#ecfeff;color:#0e7490;"><i class="fa fa-comments"></i></span><div><h5>Client Comments</h5><span class="hint">Latest ratings in this period</span></div></div>
                    <?php if ($fb_rows): ?>
                        <div class="ds-fb-list">
                        <?php $fb_cols = ['#2563eb', '#7c3aed', '#0e7490', '#ea580c', '#15803d', '#be123c']; foreach (array_slice($fb_rows, 0, 8) as $i => $r): $rt = (int)$r['rating']; ?>
                            <div class="ds-fb">
                                <span class="ds-fb-av" style="background:<?= $fb_cols[$i % 6] ?>;"><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($r['client']), 0, 1))) ?></span>
                                <div class="flex-grow-1" style="min-width:0;">
                                    <div class="d-flex justify-content-between gap-2"><strong><?= htmlspecialchars($r['client']) ?></strong><span class="ds-stars"><?php for ($s = 1; $s <= 5; $s++): ?><i class="fa fa-star<?= $s <= $rt ? '' : ' off' ?>"></i><?php endfor; ?></span></div>
                                    <div class="date"><?= date('M j, Y', strtotime($r['created_at'])) ?><?= $r['service_type'] ? ' · ' . htmlspecialchars($r['service_type']) : '' ?></div>
                                    <p><?= trim((string)$r['comments']) !== '' ? '“' . htmlspecialchars($r['comments']) . '”' : '<span class="fst-italic text-muted">No comment left</span>' ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?><div class="ds-empty">No ratings submitted in this period.</div><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── ACCOUNTS ── -->
        <div class="ds-sec"><i class="fa fa-users"></i> Accounts &amp; Pets <span>· current totals, with additions in this period</span></div>
        <div class="ds-grid4">
            <?php foreach ([
                ['Total Accounts', $U['total'], $U['new_in_period'] . ' created in this period', 'fa-users', '#2563eb', '#eff6ff'],
                ['Clients / Pet Owners', $U['clients'], $pct($U['clients'], $U['total']) . '% of accounts', 'fa-user', '#0e7490', '#ecfeff'],
                ['Clinic Team', (int)$U['staff'] + (int)$U['doctors'], $U['doctors'] . ' veterinarian(s) · ' . $U['staff'] . ' vet staff', 'fa-user-doctor', '#15803d', '#f0fdf4'],
                ['Registered Pets', $pets_total, $pets_new . ' added in this period', 'fa-paw', '#d97706', '#fffbeb'],
            ] as [$l, $v, $s, $ic, $c, $bg]): ?>
            <div class="ds-kpi">
                <div class="ds-kpi-top"><span class="ds-kpi-l"><?= $l ?></span><span class="ds-ic" style="width:34px;height:34px;font-size:14px;background:<?= $bg ?>;color:<?= $c ?>;"><i class="fa <?= $ic ?>"></i></span></div>
                <div class="ds-kpi-v"><?= number_format($v) ?></div>
                <div class="ds-kpi-s"><?= htmlspecialchars($s) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="ds-card mb-2" style="height:auto;"><div class="ds-desc" style="margin:0;padding:0;border:0;"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc['acct']) ?></p></div></div>

        <!-- Printed sign-off -->
        <div class="ds-print-foot">
            <div>Prepared by: <?= htmlspecialchars($_SESSION['user'] ?? '') ?> (<?= $is_owner ? 'Clinic Owner' : 'Administrator' ?>)</div>
            <div>Noted by</div>
            <div>Date: <?= date('M j, Y') ?></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        Chart.defaults.font.family = 'Inter, sans-serif';
        Chart.defaults.color = '#64748b';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.animation = false; // charts are always fully drawn, so printing never catches one half-way
        const LABELS = <?= json_encode($bucket_labels) ?>;
        const peso = v => '₱' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const grid = { color: '#eef2f6' };
        const intAxis = { beginAtZero: true, ticks: { precision: 0 }, grid, border: { display: false } };
        const xAxis = { grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0 } };
        function mk(id, cfg) { const el = document.getElementById(id); return el ? new Chart(el, cfg) : null; }

        mk('chApptVolume', { type: 'bar', data: { labels: LABELS, datasets: [{ label: 'Appointments', data: <?= json_encode(array_values($appt_volume)) ?>, backgroundColor: '#2563eb', borderRadius: 5, maxBarThickness: 28 }] },
            options: { plugins: { legend: { display: false } }, scales: { x: xAxis, y: intAxis } } });
        mk('chServices', { type: 'doughnut', data: { labels: <?= json_encode(array_keys($services)) ?>, datasets: [{ data: <?= json_encode(array_values($services)) ?>, backgroundColor: <?= json_encode($svc_cols) ?>, borderColor: '#fff', borderWidth: 2 }] },
            options: { cutout: '64%', plugins: { legend: { display: false } } } });
        const HOURS = <?= json_encode(array_values($appt_hours)) ?>, hMax = Math.max(...HOURS);
        mk('chApptHours', { type: 'bar', data: { labels: HOURS.map((_, h) => h === 0 ? '12am' : h < 12 ? h + 'am' : h === 12 ? '12pm' : (h - 12) + 'pm'), datasets: [{ label: 'Appointments', data: HOURS, backgroundColor: HOURS.map(v => v === hMax && v > 0 ? '#ea580c' : 'rgba(234,88,12,.28)'), borderRadius: 5 }] },
            options: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } }, y: intAxis } } });
        mk('chPay', { type: 'bar', data: { labels: LABELS, datasets: [
                { label: 'Billed', data: <?= json_encode(array_map(fn($v) => round($v, 2), array_values($pay_billed))) ?>, backgroundColor: '#2563eb', borderRadius: 5, maxBarThickness: 24 },
                { label: 'Collected', data: <?= json_encode(array_map(fn($v) => round($v, 2), array_values($pay_collected))) ?>, backgroundColor: '#16a34a', borderRadius: 5, maxBarThickness: 24 }] },
            options: { plugins: { legend: { position: 'top', align: 'end' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + peso(c.raw) } } },
                scales: { x: xAxis, y: { beginAtZero: true, grid, border: { display: false }, ticks: { callback: v => '₱' + Number(v).toLocaleString() } } } } });
        mk('chBoardVolume', { type: 'bar', data: { labels: LABELS, datasets: [{ label: 'Stays', data: <?= json_encode(array_values($board_volume)) ?>, backgroundColor: '#0891b2', borderRadius: 5, maxBarThickness: 28 }] },
            options: { plugins: { legend: { display: false } }, scales: { x: xAxis, y: intAxis } } });
        mk('chBoardStatus', { type: 'doughnut', data: { labels: ['Completed', 'Approved / boarding', 'Pending', 'Cancelled / rejected'], datasets: [{ data: [<?= $B['completed'] ?>, <?= $B['active'] ?>, <?= $B['pending'] ?>, <?= $B['cancelled'] ?>], backgroundColor: ['#15803d', '#2563eb', '#d97706', '#be123c'], borderColor: '#fff', borderWidth: 2 }] },
            options: { cutout: '64%', plugins: { legend: { display: false } } } });

        // Printing: charts redraw at paper width
        function pvFitCharts() { Object.values(Chart.instances).forEach(c => c.resize()); }
        window.addEventListener('beforeprint', pvFitCharts);
        window.addEventListener('afterprint', pvFitCharts);
        function pvPrintReport() { pvFitCharts(); setTimeout(() => window.print(), 150); }
    </script>
</body>
</html>
