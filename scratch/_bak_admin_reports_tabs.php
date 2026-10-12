<?php
session_start();
include("dbconnection.php");
// Valid invoices only: no void ones, none for cancelled/rejected/no-show or deleted bookings, one per boarding stay
include_once("includes/report_data.php");

// 1. ACCESS: admin and clinic owner (view, print, export)
$user_role = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? '');
$is_admin = ($user_role === 'admin');
$is_owner = ($user_role === 'clinic_owner' || $user_role === 'owner');
if (!isset($_SESSION['user_id']) || (!$is_admin && !$is_owner)) {
    header("Location: " . ($user_role === 'staff' ? 'staff_dashboard.php' : ($user_role === 'doctor' ? 'doctor.php' : 'index.php')));
    exit();
}

// 2. PERIOD
$period = $_GET['period'] ?? 'month';
$active_tab = $_GET['tab'] ?? 'appointments';
$tabs = [
    'appointments' => ['Appointments', 'fa-calendar-check', 'Appointment Report'],
    'boarding'     => ['Boarding', 'fa-hotel', 'Boarding Report'],
    'payments'     => ['Payments & Revenue', 'fa-file-invoice-dollar', 'Payments & Revenue Report'],
    'feedback'     => ['Feedback & Ratings', 'fa-star', 'Client Feedback Report'],
    'system'       => ['System & Accounts', 'fa-server', 'System & Accounts Report'],
];
if (!isset($tabs[$active_tab])) $active_tab = 'appointments';

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

// Time buckets for the charts: days (up to ~2 months), months (up to ~2 years), otherwise years
if ($period_days <= 62)      $bucket_mode = 'day';
elseif ($period_days <= 800) $bucket_mode = 'month';
else                         $bucket_mode = 'year';
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

// 3. DATA — one source per section; the screen, the printout and the CSV all use these same rows

// A. Appointments (clinic services; boarding has its own section)
$appt_rows = [];
$q = $conn->query("SELECT a.id, a.appointment_date, a.appointment_time, COALESCE(u.fullname, 'Walk-in Client') AS client, a.pet_name, a.service_type,
                          a.doctor_id, COALESCE(d.fullname, 'Unassigned') AS vet, a.status
                   FROM appointments a
                   LEFT JOIN users u ON u.id = a.user_id
                   LEFT JOIN users d ON d.id = a.doctor_id
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
    if ($vid && !isset($vets[$vid])) $vets[$vid] = ['name' => $r['vet'], 'assigned' => 0, 'completed' => 0, 'active' => 0, 'lost' => 0];
    if (!$vid) { $vets[0] = $vets[0] ?? ['name' => 'Unassigned', 'assigned' => 0, 'completed' => 0, 'active' => 0, 'lost' => 0]; }
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
$B['occupancy'] = $pct($occ_nights, $cage_count * $period_days);

// C. Payments (valid invoices issued within the period)
[$all_valid_bills] = rpt_valid_bills($conn);
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
$owed_bills = array_values(array_filter($bills, fn($b) => $b['_owed'] > 0));
usort($owed_bills, fn($a, $b) => $b['_owed'] <=> $a['_owed']);
$bill_category = fn($b) => (int)$b['is_boarding'] === 1 ? 'Boarding' : ($b['a_service'] ?: ($b['service_category'] ?: 'Clinic'));

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

// E. System & accounts (current snapshot — not tied to the period)
$U = $conn->query("SELECT COUNT(*) total,
        SUM(role IN ('user','client')) clients, SUM(role = 'staff') staff, SUM(role = 'doctor') doctors,
        SUM(role = 'admin') admins, SUM(role IN ('clinic_owner','owner')) owners,
        SUM(is_verified = 1) verified, SUM(locked_until > NOW()) locked,
        SUM(DATE(created_at) BETWEEN '$start_date' AND '$end_date') new_in_period
    FROM users")->fetch_assoc();
$pets_total = (int)$conn->query("SELECT COUNT(*) c FROM pets WHERE deleted_at IS NULL")->fetch_assoc()['c'];
$tables_info = []; $db_kb = 0;
$tq = $conn->query("SHOW TABLE STATUS");
if ($tq) while ($t = $tq->fetch_assoc()) { $kb = round(($t['Data_length'] + $t['Index_length']) / 1024, 1); $db_kb += $kb; $tables_info[] = [$t['Name'], (int)$t['Rows'], $kb]; }

// Plain-language summary for the active section (also printed)
$summary = [];
if ($active_tab === 'appointments') {
    $summary[] = "{$A['total']} clinic appointment(s) were scheduled in this period: {$A['completed']} completed ({$pct($A['completed'], $A['total'])}%), {$A['active']} approved or in progress, {$A['pending']} still pending, {$A['cancelled']} cancelled or rejected and {$A['noshow']} no-show(s).";
    if ($service_total) { $top = array_key_first($services); $summary[] = "The most requested service was $top ({$services[$top]} of $service_total, {$pct($services[$top], $service_total)}%)."; }
    $busiest = array_search(max($appt_hours), $appt_hours);
    if (max($appt_hours) > 0) $summary[] = "The busiest hour was " . date('g A', mktime($busiest, 0, 0)) . " with {$appt_hours[$busiest]} appointment(s).";
} elseif ($active_tab === 'boarding') {
    $summary[] = "{$B['total']} boarding stay(s) checked in or were booked to check in during this period: {$B['completed']} completed, {$B['active']} approved or currently boarding, {$B['pending']} pending and {$B['cancelled']} cancelled or rejected.";
    $summary[] = "Confirmed stays averaged {$B['avg_nights']} night(s). Cages were {$B['occupancy']}% occupied ($occ_nights of " . ($cage_count * $period_days) . " cage-nights, $cage_count cages).";
} elseif ($active_tab === 'payments') {
    $summary[] = "{$P['invoices']} valid invoice(s) worth {$peso($P['billed'])} were issued; {$peso($P['collected'])} has been collected ({$P['rate']}%) and {$peso($P['owed'])} is still unpaid on {$P['unpaid']} invoice(s).";
    if ($methods) { $tm = array_key_first($methods); $summary[] = "Most money was received through $tm ({$peso($methods[$tm][1])} from {$methods[$tm][0]} payment(s))."; }
    $summary[] = "Void invoices, invoices for cancelled or deleted bookings and duplicate boarding invoices are not counted.";
} elseif ($active_tab === 'feedback') {
    $summary[] = $F['count'] ? "{$F['count']} rating(s) were submitted with an average of " . number_format($F['avg'], 1) . " out of 5 ({$F['verdict']}). {$F['positive']}% gave 4 or 5 stars." : "No client ratings were submitted in this period.";
} else {
    $summary[] = "{$U['total']} account(s) in the system ({$U['clients']} clients, {$U['staff']} vet staff, {$U['doctors']} veterinarians, {$U['admins']} admin, {$U['owners']} clinic owner); {$U['new_in_period']} created in this period. $pets_total pets are registered.";
}

// 4. CSV EXPORT — the same rows and rules as the screen
if (isset($_GET['export_report'])) {
    $type = isset($tabs[$_GET['export_report']]) ? $_GET['export_report'] : $active_tab;
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pragmavet_' . $type . '_report_' . str_replace('-', '', $start_date) . '-' . str_replace('-', '', $end_date) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names and ₱ correctly
    fputcsv($out, ['PragmaVet Animal Clinic — ' . $tabs[$type][2]]);
    fputcsv($out, ['Period', $filter_label . ' (' . $start_date . ' to ' . $end_date . ')']);
    fputcsv($out, ['Generated', date('Y-m-d H:i'), 'By', $_SESSION['user'] ?? '']);
    fputcsv($out, []);
    if ($type === 'appointments') {
        fputcsv($out, ['Summary', 'Total', $A['total'], 'Completed', $A['completed'], 'Approved / in progress', $A['active'], 'Pending', $A['pending'], 'Cancelled / rejected', $A['cancelled'], 'No-show', $A['noshow']]);
        fputcsv($out, []);
        fputcsv($out, ['Appt ID', 'Date', 'Time', 'Client', 'Pet', 'Service', 'Veterinarian', 'Status']);
        foreach ($appt_rows as $r) fputcsv($out, [$r['id'], $r['appointment_date'], $r['appointment_time'] ? date('h:i A', strtotime($r['appointment_time'])) : '', $r['client'], $r['pet_name'], $r['service_type'], $r['vet'], $r['status']]);
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
                  ['Verified accounts', $U['verified']], ['Locked accounts', $U['locked']], ['Accounts created in period', $U['new_in_period']], ['Registered pets', $pets_total], ['Database size (KB)', round($db_kb, 1)]] as $row) fputcsv($out, $row);
        fputcsv($out, []);
        fputcsv($out, ['Table', 'Rows', 'Size (KB)']);
        foreach ($tables_info as $t) fputcsv($out, $t);
    }
    fclose($out);
    exit();
}

$qs = fn($extra = []) => http_build_query(array_merge(['tab' => $active_tab, 'period' => $period] + ($period === 'custom' ? ['start_date' => $start_date, 'end_date' => $end_date] : []), $extra));
$status_cls = function ($s) {
    if ($s === 'Completed' || $s === 'Paid') return 'ok';
    if (in_array($s, ['Approved', 'Checked In', 'In Progress', 'Partial'], true)) return 'info';
    if (in_array($s, ['Cancelled', 'Rejected', 'No Show', 'Unpaid'], true)) return 'bad';
    return 'warn';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports &amp; Analytics | PragmaVet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root { --sidebar-width: 260px; --blue: #2563eb; --blue-d: #1d4ed8; --ink: #0f172a; --ink2: #475569; --ink3: #94a3b8; --line: #e8edf3; }
        body { font-family: 'Inter', sans-serif; background: #f6f8fb; color: #1e293b; margin: 0; }
        /* Sidebar (same look as the other admin pages) */
        .sidebar { background: #fff !important; width: var(--sidebar-width) !important; height: 100vh !important; position: fixed !important; top: 0 !important; left: 0 !important; padding: 28px 20px 24px !important; z-index: 1000 !important; display: flex !important; flex-direction: column !important; border-right: 1px solid #eef2f6 !important; overflow-y: auto !important; }
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
        .main-content { margin-left: var(--sidebar-width); padding: 28px 32px; min-height: 100vh; }

        /* Header */
        .rp-eyebrow { display: inline-flex; align-items: center; gap: 6px; background: #eff6ff; color: var(--blue); font-size: 11px; font-weight: 800; letter-spacing: .4px; text-transform: uppercase; padding: 4px 12px; border-radius: 99px; margin-bottom: 8px; }
        .rp-title { font-size: 26px; font-weight: 800; color: var(--ink); margin: 0; letter-spacing: -.4px; }
        .rp-sub { color: #64748b; font-size: 13.5px; margin: 2px 0 0; }
        .rp-btn { display: inline-flex; align-items: center; gap: 8px; border-radius: 99px; padding: 10px 18px; font-weight: 700; font-size: 13.5px; text-decoration: none; cursor: pointer; transition: background .15s, transform .15s; white-space: nowrap; }
        .rp-btn-main { background: var(--blue) !important; color: #fff !important; border: 0 !important; box-shadow: 0 6px 16px rgba(37,99,235,.25) !important; }
        .rp-btn-main:hover { background: var(--blue-d) !important; color: #fff !important; }
        .rp-btn-alt { background: #fff !important; color: var(--blue-d) !important; border: 1px solid #bfdbfe !important; box-shadow: none !important; }
        .rp-btn-alt:hover { background: #eff6ff !important; }
        .rp-btn-alt .fa-file-excel { color: #16a34a; }

        /* Period filter */
        .rp-filter { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 14px 18px; display: flex; flex-wrap: wrap; gap: 12px 18px; align-items: center; justify-content: space-between; box-shadow: 0 2px 10px rgba(15,23,42,.03); margin: 20px 0 16px; }
        .rp-pills { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .rp-pills .lbl { font-size: 12.5px; font-weight: 700; color: #64748b; margin-right: 4px; }
        .rp-pill { padding: 7px 14px; border-radius: 99px; font-size: 13px; font-weight: 700; color: #475569; background: #f1f5f9; text-decoration: none; border: 1px solid transparent; }
        .rp-pill:hover { background: #e2e8f0; color: #0f172a; }
        .rp-pill.on { background: var(--blue); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,.22); }
        .rp-range { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .rp-range { flex-wrap: nowrap; }
        .rp-range input[type=date] { border: 1px solid #e2e8f0 !important; border-radius: 99px !important; padding: 6px 12px !important; height: 36px !important; min-height: 0 !important; font-size: 13px !important; box-shadow: none !important; background: #fff !important; width: 150px !important; max-width: 150px !important; flex: 0 0 150px !important; display: inline-block !important; margin: 0 !important; }
        .rp-range input[type=date]:focus { border-color: var(--blue) !important; outline: 0; }
        .rp-range span { color: #94a3b8; font-size: 12.5px; }
        .rp-range button { height: 36px; padding: 0 16px; }
        .rp-active { font-size: 12.5px; color: #64748b; width: 100%; border-top: 1px dashed #e2e8f0; padding-top: 10px; }
        .rp-active b { color: var(--ink); }

        /* Tabs */
        .rp-tabs { display: flex; gap: 6px; flex-wrap: wrap; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 6px; margin-bottom: 18px; }
        .rp-tab { display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 11px; font-size: 13.5px; font-weight: 700; color: #475569; text-decoration: none; }
        .rp-tab:hover { background: #f1f5f9; color: #0f172a; }
        .rp-tab.on { background: var(--blue); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,.22); }

        /* Cards */
        .rp-grid4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .rp-kpi { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 16px 18px; box-shadow: 0 2px 10px rgba(15,23,42,.03); }
        .rp-kpi-top { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .rp-kpi-l { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .04em; }
        .rp-ic { width: 36px; height: 36px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
        .rp-kpi-v { font-size: 26px; font-weight: 800; color: var(--ink); letter-spacing: -.5px; margin: 8px 0 2px; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .rp-kpi-v small { font-size: 14px; color: #94a3b8; font-weight: 700; }
        .rp-kpi-s { font-size: 12.5px; color: #64748b; }
        .rp-bar { height: 6px; background: #eef2f6; border-radius: 6px; overflow: hidden; margin-top: 10px; }
        .rp-bar span { display: block; height: 100%; border-radius: 6px; }
        .rp-card { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 18px 20px; box-shadow: 0 2px 10px rgba(15,23,42,.03); height: 100%; }
        .rp-card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .rp-card-head h5 { font-size: 15.5px; font-weight: 800; margin: 0; color: var(--ink); }
        .rp-card-head .hint { font-size: 12px; color: #94a3b8; }
        .rp-summary { background: #fff; border: 1px solid var(--line); border-left: 4px solid var(--blue); border-radius: 16px; padding: 14px 18px; margin-bottom: 18px; }
        .rp-summary h6 { font-size: 13px; font-weight: 800; color: var(--ink); margin: 0 0 6px; display: flex; align-items: center; gap: 8px; }
        .rp-summary h6 i { color: var(--blue); }
        .rp-summary p { font-size: 13.5px; color: #334155; margin: 0 0 4px; line-height: 1.55; }
        .rp-chart { position: relative; height: 260px; }
        .rp-chart.sm { height: 220px; }
        .rp-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .rp-table th { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #64748b; background: #f8fafc; padding: 10px 12px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
        .rp-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #1e293b; }
        .rp-table tr:last-child td { border-bottom: 0; }
        .rp-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .rp-table .ctr { text-align: center; }
        .rp-scroll { max-height: 420px; overflow-y: auto; border: 1px solid var(--line); border-radius: 12px; }
        .rp-scroll .rp-table th { position: sticky; top: 0; z-index: 1; }
        .rp-st { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
        .rp-st.ok { background: #dcfce7; color: #166534; } .rp-st.info { background: #dbeafe; color: #1d4ed8; }
        .rp-st.bad { background: #fee2e2; color: #991b1b; } .rp-st.warn { background: #fef3c7; color: #92400e; }
        .rp-empty { text-align: center; color: #94a3b8; padding: 30px 10px; font-size: 13.5px; }
        .rp-legend { list-style: none; padding: 0; margin: 0; }
        .rp-legend li { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 13.5px; }
        .rp-legend li:last-child { border-bottom: 0; }
        .rp-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
        .rp-legend .n { margin-left: auto; font-weight: 700; color: var(--ink); white-space: nowrap; }
        .rp-legend .p { color: #94a3b8; font-size: 12px; width: 52px; text-align: right; }
        .rp-rd-row { display: grid; grid-template-columns: 110px 1fr 80px; gap: 12px; align-items: center; margin-bottom: 11px; }
        .rp-rd-k { font-weight: 800; font-size: 13.5px; color: var(--ink); white-space: nowrap; }
        .rp-rd-k small { font-weight: 600; color: #94a3b8; }
        .rp-rd-track { height: 11px; background: #f1f5f9; border-radius: 99px; overflow: hidden; }
        .rp-rd-track span { display: block; height: 100%; border-radius: 99px; }
        .rp-rd-n { text-align: right; font-size: 13px; }
        .rp-rd-n small { color: #94a3b8; margin-left: 3px; }
        .rp-stars, .rp-stars i { color: #f59e0b; letter-spacing: 1px; white-space: nowrap; }
        .rp-stars i.rp-star-off { color: #e2e8f0; }
        .rp-star-off { color: #e2e8f0; }
        .rp-mini { width: 70px; height: 6px; background: #eef2f6; border-radius: 6px; overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 6px; }
        .rp-mini span { display: block; height: 100%; background: #16a34a; }

        /* Print-only letterhead and sign-off */
        .rp-print-head, .rp-print-foot { display: none; }
        @media (max-width: 1200px) { .rp-grid4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 992px) { .main-content { margin-left: 0; padding: 18px 16px; } }
        @media (max-width: 576px) { .rp-grid4 { grid-template-columns: 1fr; } }

        @media print {
            @page { size: A4 portrait; margin: 14mm 12mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            body { background: #fff !important; }
            .sidebar, .no-print, .rp-filter, .rp-tabs, .position-fixed, .pv-nav-btn, .pv-nav-backdrop, #pvChatFab, .chat-widget, iframe { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; }
            .rp-print-head { display: block; border-bottom: 3px double #1e3a8a; padding-bottom: 10px; margin-bottom: 14px; }
            .rp-print-head .cl { font-size: 20px; font-weight: 800; color: #1e3a8a; text-transform: uppercase; letter-spacing: .5px; }
            .rp-print-head .ad { font-size: 11px; color: #475569; }
            .rp-print-head .ti { font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 10px; }
            .rp-print-head .me { font-size: 11.5px; color: #334155; }
            .rp-print-foot { display: flex; justify-content: space-between; gap: 30px; margin-top: 30px; font-size: 11px; color: #475569; page-break-inside: avoid; }
            .rp-print-foot div { flex: 1; text-align: center; border-top: 1px solid #0f172a; padding-top: 5px; }
            .rp-kpi, .rp-card, .rp-summary { box-shadow: none !important; break-inside: avoid; page-break-inside: avoid; }
            .rp-grid4 { grid-template-columns: repeat(4, 1fr) !important; gap: 8px; }
            .rp-kpi { padding: 10px 12px; } .rp-kpi-v { font-size: 18px; }
            .row > [class*="col-"] { flex: 0 0 100% !important; max-width: 100% !important; }
            .rp-card { height: auto !important; margin-bottom: 12px; }
            .rp-chart { height: 230px !important; }
            .rp-scroll { max-height: none !important; overflow: visible !important; border: 0; }
            .rp-scroll .rp-table th { position: static; }
            .rp-scroll, .table-responsive, .pv-table-scroll { max-height: none !important; overflow: visible !important; border: 0 !important; }
            .rp-table { font-size: 9.5px; width: 100% !important; table-layout: auto; } .rp-table th, .rp-table td { padding: 4px 5px; white-space: normal !important; }
            .rp-table th { font-size: 8.5px; letter-spacing: 0; }
            .rp-table .num, .rp-table .text-nowrap { white-space: nowrap !important; }
            .rp-st { padding: 1px 6px; font-size: 9px; }
            .rp-table tr { break-inside: avoid; }
            .rp-table tfoot { display: table-row-group; } /* grand total only once, at the end */
            .rp-card.rp-list { break-inside: auto !important; page-break-inside: auto !important; }
            a { color: inherit !important; text-decoration: none !important; }
        }
    </style>
</head>
<body>
    <?php include("includes/admin_sidebar.php"); ?>

    <div class="main-content">
        <!-- Printed letterhead -->
        <div class="rp-print-head">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="cl">PragmaVet Animal Clinic</div>
                    <div class="ad">0234 Unit B Palico St, Palico 4, Imus, Cavite · Tel: 046-8851-221 | 0954-285-3053 · pragmavet@gmail.com</div>
                </div>
                <div class="me text-end">Printed <?= date('M j, Y g:i A') ?></div>
            </div>
            <div class="ti"><?= htmlspecialchars($tabs[$active_tab][2]) ?></div>
            <div class="me">Period: <b><?= htmlspecialchars($filter_label) ?></b> (<?= $range_text ?>)<?= $active_tab === 'system' ? ' · account figures are a current snapshot' : '' ?></div>
        </div>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 no-print">
            <div>
                <span class="rp-eyebrow"><i class="fa fa-chart-pie"></i> Reports &amp; Analytics</span>
                <h1 class="rp-title">Reports &amp; Analytics</h1>
                <p class="rp-sub">Numbers and charts for any period. Pick a period and a report, then print it or export it to Excel (CSV).</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="rp-btn rp-btn-main" onclick="pvPrintReport()"><i class="fa fa-print"></i> Print Report</button>
                <a class="rp-btn rp-btn-alt" href="admin_reports.php?<?= $qs(['export_report' => $active_tab]) ?>"><i class="fa fa-file-excel"></i> Export CSV</a>
            </div>
        </div>

        <!-- Period filter -->
        <div class="rp-filter">
            <div class="rp-pills">
                <span class="lbl"><i class="fa fa-calendar-alt me-1" style="color:#2563eb;"></i>Period</span>
                <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year', 'all' => 'All Records'] as $pk => $pl): ?>
                    <a class="rp-pill <?= $period === $pk ? 'on' : '' ?>" href="admin_reports.php?tab=<?= $active_tab ?>&period=<?= $pk ?>"><?= $pl ?></a>
                <?php endforeach; ?>
            </div>
            <form method="GET" class="rp-range">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                <input type="hidden" name="period" value="custom">
                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required aria-label="From">
                <span>to</span>
                <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required aria-label="To">
                <button type="submit" class="rp-btn rp-btn-main">Apply</button>
            </form>
            <div class="rp-active">Showing <b><?= htmlspecialchars($filter_label) ?></b> · <?= $range_text ?><?= $active_tab === 'system' ? ' · account figures are a current snapshot' : '' ?></div>
        </div>

        <!-- Report tabs -->
        <div class="rp-tabs">
            <?php foreach ($tabs as $tk => [$tl, $ti]): ?>
                <a class="rp-tab <?= $active_tab === $tk ? 'on' : '' ?>" href="admin_reports.php?<?= http_build_query(['tab' => $tk, 'period' => $period] + ($period === 'custom' ? ['start_date' => $start_date, 'end_date' => $end_date] : [])) ?>"><i class="fa <?= $ti ?>"></i> <?= $tl ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Summary -->
        <div class="rp-summary">
            <h6><i class="fa fa-clipboard-list"></i> Summary</h6>
            <?php foreach ($summary as $line): ?><p><?= htmlspecialchars($line) ?></p><?php endforeach; ?>
        </div>

        <?php if ($active_tab === 'appointments'): ?>
            <div class="rp-grid4">
                <?php foreach ([
                    ['Total Appointments', $A['total'], 'Scheduled in this period', 'fa-calendar', '#2563eb', '#eff6ff', null],
                    ['Completed', $A['completed'], $pct($A['completed'], $A['total']) . '% of appointments', 'fa-check-double', '#15803d', '#f0fdf4', $pct($A['completed'], $A['total'])],
                    ['Approved / In Progress', $A['active'], $A['pending'] . ' still pending', 'fa-stethoscope', '#0e7490', '#ecfeff', null],
                    ['Cancelled / No-Show', $A['cancelled'] + $A['noshow'], $A['cancelled'] . ' cancelled or rejected · ' . $A['noshow'] . ' no-show', 'fa-user-slash', '#be123c', '#fff1f2', $pct($A['cancelled'] + $A['noshow'], $A['total'])],
                ] as [$l, $v, $s, $ic, $c, $bg, $bar]): ?>
                <div class="rp-kpi">
                    <div class="rp-kpi-top"><span class="rp-kpi-l"><?= $l ?></span><span class="rp-ic" style="background:<?= $bg ?>;color:<?= $c ?>;"><i class="fa <?= $ic ?>"></i></span></div>
                    <div class="rp-kpi-v"><?= number_format($v) ?></div>
                    <div class="rp-kpi-s"><?= htmlspecialchars($s) ?></div>
                    <?php if ($bar !== null): ?><div class="rp-bar"><span style="width:<?= min(100, $bar) ?>%;background:<?= $c ?>;"></span></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-chart-line"></i></span><div><h5>Appointment Volume</h5><span class="hint">Appointments <?= $bucket_word ?>, cancelled and rejected not counted</span></div></div>
                        <div class="rp-chart"><canvas id="chApptVolume"></canvas></div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-chart-pie"></i></span><div><h5>Services</h5><span class="hint">Share of appointments by service</span></div></div>
                        <?php if ($service_total): ?>
                            <div class="rp-chart sm"><canvas id="chServices"></canvas></div>
                            <ul class="rp-legend mt-2">
                                <?php $svc_cols = ['#2563eb', '#7c3aed', '#0891b2', '#ea580c', '#15803d', '#be123c', '#d97706', '#4f46e5']; $si = 0; foreach ($services as $sn => $sc): ?>
                                    <li><span class="rp-dot" style="background:<?= $svc_cols[$si++ % 8] ?>;"></span><?= htmlspecialchars($sn) ?><span class="n"><?= $sc ?></span><span class="p"><?= $pct($sc, $service_total) ?>%</span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?><div class="rp-empty">No appointments in this period.</div><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-7">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-user-doctor"></i></span><div><h5>Veterinarian Caseload</h5><span class="hint">Active = approved, checked in or in progress · Not seen = cancelled, rejected or no-show</span></div></div>
                        <div class="table-responsive">
                            <table class="rp-table">
                                <thead><tr><th>Veterinarian</th><th class="ctr">Assigned</th><th class="ctr">Completed</th><th class="ctr" title="Approved, checked in or in progress">Active</th><th class="ctr" title="Cancelled, rejected or no-show">Not Seen</th><th>Completion</th></tr></thead>
                                <tbody>
                                <?php foreach ($vets as $v): $rate = $pct($v['completed'], $v['assigned']); ?>
                                    <tr>
                                        <td class="fw-bold text-nowrap"><?= htmlspecialchars($v['name']) ?></td>
                                        <td class="ctr fw-bold"><?= $v['assigned'] ?></td>
                                        <td class="ctr" style="color:#15803d;font-weight:700;"><?= $v['completed'] ?></td>
                                        <td class="ctr" style="color:#1d4ed8;font-weight:600;"><?= $v['active'] ?></td>
                                        <td class="ctr" style="color:#be123c;font-weight:600;"><?= $v['lost'] ?></td>
                                        <td class="text-nowrap"><span class="rp-mini"><span style="width:<?= $rate ?>%;"></span></span><b style="font-size:12.5px;"><?= $rate ?>%</b></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$vets): ?><tr><td colspan="6" class="rp-empty">No veterinarians found.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#fff7ed;color:#ea580c;"><i class="fa fa-clock"></i></span><div><h5>Appointments by Hour</h5><span class="hint">Busiest hour highlighted (open 24/7)</span></div></div>
                        <div class="rp-chart"><canvas id="chApptHours"></canvas></div>
                    </div>
                </div>
            </div>

            <div class="rp-card rp-list mb-3" style="height:auto;">
                <div class="rp-card-head"><span class="rp-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-list"></i></span><div><h5>Appointment List</h5><span class="hint"><?= $A['total'] ?> appointment(s) · the CSV export has the same list</span></div></div>
                <?php if ($appt_rows): ?>
                <div class="rp-scroll">
                    <table class="rp-table">
                        <thead><tr><th>Date</th><th>Time</th><th>Client</th><th>Pet</th><th>Service</th><th>Veterinarian</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($appt_rows as $r): ?>
                            <tr><td class="text-nowrap"><?= date('M j, Y', strtotime($r['appointment_date'])) ?></td><td class="text-nowrap"><?= $r['appointment_time'] ? date('g:i A', strtotime($r['appointment_time'])) : '—' ?></td>
                                <td><?= htmlspecialchars($r['client']) ?></td><td><?= htmlspecialchars($r['pet_name']) ?></td><td><?= htmlspecialchars($r['service_type']) ?></td><td><?= htmlspecialchars($r['vet']) ?></td>
                                <td><span class="rp-st <?= $status_cls($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?><div class="rp-empty">No appointments in this period.</div><?php endif; ?>
            </div>

        <?php elseif ($active_tab === 'boarding'): ?>
            <div class="rp-grid4">
                <?php foreach ([
                    ['Total Stays', $B['total'], 'Check-ins in this period', 'fa-hotel', '#2563eb', '#eff6ff', null],
                    ['Completed', $B['completed'], $B['active'] . ' approved or boarding now', 'fa-circle-check', '#15803d', '#f0fdf4', $pct($B['completed'], $B['total'])],
                    ['Average Stay', $B['avg_nights'], 'nights per confirmed stay', 'fa-moon', '#0e7490', '#ecfeff', null],
                    ['Cage Occupancy', $B['occupancy'] . '%', $occ_nights . ' of ' . ($cage_count * $period_days) . ' cage-nights', 'fa-table-cells', '#d97706', '#fffbeb', $B['occupancy']],
                ] as [$l, $v, $s, $ic, $c, $bg, $bar]): ?>
                <div class="rp-kpi">
                    <div class="rp-kpi-top"><span class="rp-kpi-l"><?= $l ?></span><span class="rp-ic" style="background:<?= $bg ?>;color:<?= $c ?>;"><i class="fa <?= $ic ?>"></i></span></div>
                    <div class="rp-kpi-v"><?= is_numeric($v) ? number_format($v, $l === 'Average Stay' ? 1 : 0) : $v ?></div>
                    <div class="rp-kpi-s"><?= htmlspecialchars($s) ?></div>
                    <?php if ($bar !== null): ?><div class="rp-bar"><span style="width:<?= min(100, $bar) ?>%;background:<?= $c ?>;"></span></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-chart-column"></i></span><div><h5>Stays Checked In</h5><span class="hint">Confirmed stays <?= $bucket_word ?></span></div></div>
                        <div class="rp-chart"><canvas id="chBoardVolume"></canvas></div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-chart-pie"></i></span><div><h5>Stay Status</h5><span class="hint">All stays in this period</span></div></div>
                        <?php if ($B['total']): ?>
                            <div class="rp-chart sm"><canvas id="chBoardStatus"></canvas></div>
                            <ul class="rp-legend mt-2">
                                <?php foreach ([['Completed', $B['completed'], '#15803d'], ['Approved / boarding', $B['active'], '#2563eb'], ['Pending', $B['pending'], '#d97706'], ['Cancelled / rejected', $B['cancelled'], '#be123c']] as [$sl, $sc, $col]): ?>
                                    <li><span class="rp-dot" style="background:<?= $col ?>;"></span><?= $sl ?><span class="n"><?= $sc ?></span><span class="p"><?= $pct($sc, $B['total']) ?>%</span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?><div class="rp-empty">No boarding stays in this period.</div><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="rp-card rp-list mb-3" style="height:auto;">
                <div class="rp-card-head"><span class="rp-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-list"></i></span><div><h5>Boarding Stays</h5><span class="hint"><?= $B['total'] ?> stay(s) · the CSV export has the same list</span></div></div>
                <?php if ($board_rows): ?>
                <div class="rp-scroll">
                    <table class="rp-table">
                        <thead><tr><th>Stay #</th><th>Client</th><th>Pet</th><th>Cage</th><th>Check-in</th><th>Check-out</th><th class="ctr">Nights</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($board_rows as $r): ?>
                            <tr><td>#<?= (int)$r['id'] ?></td><td><?= htmlspecialchars($r['client']) ?></td><td><?= htmlspecialchars($r['pet_name']) ?></td>
                                <td><?= $r['cage_number'] ? 'Cage ' . str_pad((int)$r['cage_number'], 2, '0', STR_PAD_LEFT) : '—' ?></td>
                                <td class="text-nowrap"><?= date('M j, Y', strtotime($r['appointment_date'])) ?></td><td class="text-nowrap"><?= date('M j, Y', strtotime($r['end_date'])) ?></td>
                                <td class="ctr"><?= $r['nights'] ?></td><td><span class="rp-st <?= $status_cls($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?><div class="rp-empty">No boarding stays in this period.</div><?php endif; ?>
            </div>

        <?php elseif ($active_tab === 'payments'): ?>
            <div class="rp-grid4">
                <?php foreach ([
                    ['Total Billed', $peso($P['billed']), $P['invoices'] . ' valid invoice(s)', 'fa-receipt', '#2563eb', '#eff6ff', null],
                    ['Collected', $peso($P['collected']), $P['rate'] . '% of billed', 'fa-circle-check', '#15803d', '#f0fdf4', $P['rate']],
                    ['Unpaid Balance', $peso($P['owed']), $P['unpaid'] . ' invoice(s) with a balance', 'fa-hourglass-half', '#b45309', '#fffbeb', null],
                    ['Fully Paid', $P['paid'], 'of ' . $P['invoices'] . ' invoice(s)', 'fa-file-circle-check', '#0e7490', '#ecfeff', $pct($P['paid'], $P['invoices'])],
                ] as [$l, $v, $s, $ic, $c, $bg, $bar]): ?>
                <div class="rp-kpi">
                    <div class="rp-kpi-top"><span class="rp-kpi-l"><?= $l ?></span><span class="rp-ic" style="background:<?= $bg ?>;color:<?= $c ?>;"><i class="fa <?= $ic ?>"></i></span></div>
                    <div class="rp-kpi-v" style="<?= $l === 'Collected' ? 'color:#15803d;' : ($l === 'Unpaid Balance' ? 'color:#b45309;' : '') ?>"><?= is_numeric($v) ? number_format($v) : $v ?></div>
                    <div class="rp-kpi-s"><?= htmlspecialchars($s) ?></div>
                    <?php if ($bar !== null): ?><div class="rp-bar"><span style="width:<?= min(100, $bar) ?>%;background:<?= $c ?>;"></span></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-chart-column"></i></span><div><h5>Billed vs Collected</h5><span class="hint">Peso amounts <?= $bucket_word ?>, by invoice date</span></div></div>
                        <div class="rp-chart"><canvas id="chPay"></canvas></div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-wallet"></i></span><div><h5>Payment Methods</h5><span class="hint">Money received in this period</span></div></div>
                        <table class="rp-table">
                            <thead><tr><th>Method</th><th class="ctr">Payments</th><th class="num">Amount</th></tr></thead>
                            <tbody>
                            <?php foreach ($methods as $mn => [$mc, $mt]): ?><tr><td class="fw-bold"><?= htmlspecialchars($mn) ?></td><td class="ctr"><?= $mc ?></td><td class="num fw-bold"><?= $peso($mt) ?></td></tr><?php endforeach; ?>
                            <?php if (!$methods): ?><tr><td colspan="3" class="rp-empty">No payments received in this period.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                        <div class="rp-card-head mt-3 mb-2"><h5 style="font-size:14px;">By Category</h5></div>
                        <table class="rp-table">
                            <thead><tr><th>Category</th><th class="num">Billed</th><th class="num">Collected</th></tr></thead>
                            <tbody><?php foreach ($categories as $cn => [$cc, $cb, $cp]): ?><tr><td class="fw-bold"><?= $cn ?> <span class="text-muted fw-normal" style="font-size:12px;">(<?= $cc ?>)</span></td><td class="num"><?= $peso($cb) ?></td><td class="num" style="color:#15803d;font-weight:700;"><?= $peso($cp) ?></td></tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="rp-card rp-list mb-3" style="height:auto;">
                <div class="rp-card-head"><span class="rp-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-list"></i></span><div><h5>Invoices</h5><span class="hint"><?= $P['invoices'] ?> valid invoice(s) · the CSV export has the same list</span></div></div>
                <?php if ($bills): ?>
                <div class="rp-scroll">
                    <table class="rp-table">
                        <thead><tr><th>Invoice #</th><th>Date</th><th>Client</th><th>Pet</th><th>Category</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th><th>Method</th></tr></thead>
                        <tbody>
                        <?php foreach ($bills as $b): $pl = $pay_label($b); ?>
                            <tr><td class="fw-bold text-nowrap"><?= htmlspecialchars($b['invoice_no'] ?: 'INV-' . str_pad($b['id'], 5, '0', STR_PAD_LEFT)) ?></td><td class="text-nowrap"><?= date('M j, Y', strtotime($b['_date'])) ?></td>
                                <td><?= htmlspecialchars($b['client']) ?></td><td><?= htmlspecialchars($b['_pet']) ?></td><td><?= htmlspecialchars($bill_category($b)) ?></td>
                                <td class="num"><?= $peso($b['total_amount']) ?></td><td class="num" style="color:#15803d;"><?= $peso($b['_paid']) ?></td><td class="num" style="<?= $b['_owed'] > 0 ? 'color:#be123c;font-weight:700;' : 'color:#94a3b8;' ?>"><?= $peso($b['_owed']) ?></td>
                                <td><span class="rp-st <?= $status_cls($pl) ?>"><?= $pl ?></span></td><td><?= $b['_paid'] > 0 ? htmlspecialchars($b['payment_method'] ?: 'Cash') : '—' ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><td colspan="5" class="fw-bold" style="background:#f8fafc;">Total (<?= $P['invoices'] ?> invoices)</td><td class="num fw-bold" style="background:#f8fafc;"><?= $peso($P['billed']) ?></td><td class="num fw-bold" style="background:#f8fafc;color:#15803d;"><?= $peso($P['collected']) ?></td><td class="num fw-bold" style="background:#f8fafc;color:#be123c;"><?= $peso($P['owed']) ?></td><td colspan="2" style="background:#f8fafc;"></td></tr></tfoot>
                    </table>
                </div>
                <?php else: ?><div class="rp-empty">No invoices in this period.</div><?php endif; ?>
            </div>

        <?php elseif ($active_tab === 'feedback'): ?>
            <div class="rp-grid4">
                <div class="rp-kpi">
                    <div class="rp-kpi-top"><span class="rp-kpi-l">Average Rating</span><span class="rp-ic" style="background:#fffbeb;color:#d97706;"><i class="fa fa-star"></i></span></div>
                    <div class="rp-kpi-v"><?= number_format($F['avg'], 1) ?> <small>/ 5</small></div>
                    <div class="rp-kpi-s"><span class="rp-stars"><?php for ($s = 1; $s <= 5; $s++): ?><i class="fa fa-star<?= $F['avg'] >= $s - 0.25 ? '' : ($F['avg'] >= $s - 0.75 ? '-half-stroke' : ' rp-star-off') ?>"></i><?php endfor; ?></span></div>
                </div>
                <div class="rp-kpi">
                    <div class="rp-kpi-top"><span class="rp-kpi-l">Ratings</span><span class="rp-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-comments"></i></span></div>
                    <div class="rp-kpi-v"><?= $F['count'] ?></div><div class="rp-kpi-s">Submitted in this period</div>
                </div>
                <div class="rp-kpi">
                    <div class="rp-kpi-top"><span class="rp-kpi-l">Positive (4–5★)</span><span class="rp-ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-face-smile"></i></span></div>
                    <div class="rp-kpi-v" style="color:#15803d;"><?= $F['positive'] ?>%</div><div class="rp-kpi-s"><?= $rating_dist[4] + $rating_dist[5] ?> of <?= $F['count'] ?> rating(s)</div>
                    <div class="rp-bar"><span style="width:<?= min(100, $F['positive']) ?>%;background:#16a34a;"></span></div>
                </div>
                <div class="rp-kpi">
                    <div class="rp-kpi-top"><span class="rp-kpi-l">Overall</span><span class="rp-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-award"></i></span></div>
                    <div class="rp-kpi-v" style="font-size:20px;"><?= $F['verdict'] ?></div><div class="rp-kpi-s">Based on the average rating</div>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-xl-5">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#fffbeb;color:#d97706;"><i class="fa fa-ranking-star"></i></span><div><h5>Rating Distribution</h5><span class="hint">Number of ratings per star</span></div></div>
                        <?php $rd_col = [1 => '#ef4444', 2 => '#f97316', 3 => '#f59e0b', 4 => '#22c55e', 5 => '#15803d']; $rd_lbl = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent']; $rd_max = max($rating_dist) ?: 1;
                        foreach ([5, 4, 3, 2, 1] as $s): ?>
                            <div class="rp-rd-row">
                                <span class="rp-rd-k"><?= $s ?> <i class="fa fa-star" style="color:<?= $rd_col[$s] ?>;"></i> <small><?= $rd_lbl[$s] ?></small></span>
                                <span class="rp-rd-track"><span style="width:<?= round($rating_dist[$s] * 100 / $rd_max) ?>%;background:<?= $rd_col[$s] ?>;"></span></span>
                                <span class="rp-rd-n"><b><?= $rating_dist[$s] ?></b><small><?= $pct($rating_dist[$s], $F['count']) ?>%</small></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-xl-7">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#ecfeff;color:#0e7490;"><i class="fa fa-comment-dots"></i></span><div><h5>Client Comments</h5><span class="hint"><?= $F['count'] ?> rating(s) · the CSV export has the same list</span></div></div>
                        <?php if ($fb_rows): ?>
                        <div class="rp-scroll" style="max-height:330px;">
                            <table class="rp-table">
                                <thead><tr><th>Date</th><th>Client</th><th>Pet / Service</th><th>Rating</th><th>Comment</th></tr></thead>
                                <tbody>
                                <?php foreach ($fb_rows as $r): $rt = (int)$r['rating']; ?>
                                    <tr><td class="text-nowrap"><?= date('M j, Y', strtotime($r['created_at'])) ?></td><td class="fw-bold text-nowrap"><?= htmlspecialchars($r['client']) ?></td>
                                        <td><?= htmlspecialchars(trim(($r['pet_name'] ?? '') . ($r['service_type'] ? ' · ' . $r['service_type'] : ''), ' ·')) ?: '—' ?></td>
                                        <td><span class="rp-stars"><?php for ($s = 1; $s <= 5; $s++): ?><i class="fa fa-star<?= $s <= $rt ? '' : ' rp-star-off' ?>"></i><?php endfor; ?></span></td>
                                        <td><?= trim((string)$r['comments']) !== '' ? htmlspecialchars($r['comments']) : '<span class="text-muted fst-italic">No comment</span>' ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?><div class="rp-empty">No ratings submitted in this period.</div><?php endif; ?>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="rp-grid4">
                <?php foreach ([
                    ['Total Accounts', $U['total'], $U['new_in_period'] . ' created in this period', 'fa-users', '#2563eb', '#eff6ff', null],
                    ['Verified', $pct($U['verified'], $U['total']) . '%', $U['verified'] . ' verified account(s)', 'fa-user-check', '#15803d', '#f0fdf4', $pct($U['verified'], $U['total'])],
                    ['Locked Accounts', $U['locked'], 'Locked after failed sign-ins', 'fa-user-lock', '#be123c', '#fff1f2', null],
                    ['Registered Pets', $pets_total, 'Active pet records', 'fa-paw', '#d97706', '#fffbeb', null],
                ] as [$l, $v, $s, $ic, $c, $bg, $bar]): ?>
                <div class="rp-kpi">
                    <div class="rp-kpi-top"><span class="rp-kpi-l"><?= $l ?></span><span class="rp-ic" style="background:<?= $bg ?>;color:<?= $c ?>;"><i class="fa <?= $ic ?>"></i></span></div>
                    <div class="rp-kpi-v"><?= is_numeric($v) ? number_format($v) : $v ?></div>
                    <div class="rp-kpi-s"><?= htmlspecialchars($s) ?></div>
                    <?php if ($bar !== null): ?><div class="rp-bar"><span style="width:<?= min(100, $bar) ?>%;background:<?= $c ?>;"></span></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-xl-5">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-id-badge"></i></span><div><h5>Accounts by Role</h5><span class="hint">Current snapshot</span></div></div>
                        <ul class="rp-legend">
                            <?php foreach ([['Clients / pet owners', $U['clients'], '#2563eb'], ['Vet staff', $U['staff'], '#0891b2'], ['Veterinarians', $U['doctors'], '#15803d'], ['Administrators', $U['admins'], '#7c3aed'], ['Clinic owners', $U['owners'], '#d97706']] as [$rl, $rc, $col]): ?>
                                <li><span class="rp-dot" style="background:<?= $col ?>;"></span><?= $rl ?><span class="n"><?= (int)$rc ?></span><span class="p"><?= $pct($rc, $U['total']) ?>%</span></li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="rp-card-head mt-3 mb-2"><h5 style="font-size:14px;">Environment</h5></div>
                        <table class="rp-table">
                            <tr><td class="text-muted">Application</td><td class="num fw-bold">PragmaVet Veterinary Management System</td></tr>
                            <tr><td class="text-muted">PHP version</td><td class="num fw-bold"><?= phpversion() ?></td></tr>
                            <tr><td class="text-muted">Database server</td><td class="num fw-bold"><?= htmlspecialchars($conn->server_info) ?></td></tr>
                            <tr><td class="text-muted">Database size</td><td class="num fw-bold"><?= number_format($db_kb, 1) ?> KB · <?= count($tables_info) ?> tables</td></tr>
                            <tr><td class="text-muted">Server time</td><td class="num fw-bold"><?= date('M j, Y g:i A') ?></td></tr>
                        </table>
                    </div>
                </div>
                <div class="col-xl-7">
                    <div class="rp-card">
                        <div class="rp-card-head"><span class="rp-ic" style="background:#f1f5f9;color:#475569;"><i class="fa fa-database"></i></span><div><h5>Database Tables</h5><span class="hint">Records stored per table</span></div></div>
                        <div class="rp-scroll" style="max-height:360px;">
                            <table class="rp-table">
                                <thead><tr><th>Table</th><th class="num">Rows</th><th class="num">Size</th></tr></thead>
                                <tbody><?php foreach ($tables_info as [$tn, $tr, $tk]): ?><tr><td style="font-family:monospace;color:#1d4ed8;font-weight:600;"><?= htmlspecialchars($tn) ?></td><td class="num"><?= number_format($tr) ?></td><td class="num text-muted"><?= $tk ?> KB</td></tr><?php endforeach; ?></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Printed sign-off -->
        <div class="rp-print-foot">
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
        function mk(id, cfg) { const el = document.getElementById(id); return el ? new Chart(el, cfg) : null; }

        <?php if ($active_tab === 'appointments'): ?>
        mk('chApptVolume', { type: 'bar', data: { labels: LABELS, datasets: [{ label: 'Appointments', data: <?= json_encode(array_values($appt_volume)) ?>, backgroundColor: '#2563eb', borderRadius: 5, maxBarThickness: 28 }] },
            options: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } }, y: intAxis } } });
        mk('chServices', { type: 'doughnut', data: { labels: <?= json_encode(array_keys($services)) ?>, datasets: [{ data: <?= json_encode(array_values($services)) ?>, backgroundColor: ['#2563eb','#7c3aed','#0891b2','#ea580c','#15803d','#be123c','#d97706','#4f46e5'], borderColor: '#fff', borderWidth: 2 }] },
            options: { cutout: '64%', plugins: { legend: { display: false } } } });
        const HOURS = <?= json_encode(array_values($appt_hours)) ?>, hMax = Math.max(...HOURS);
        mk('chApptHours', { type: 'bar', data: { labels: HOURS.map((_, h) => h === 0 ? '12am' : h < 12 ? h + 'am' : h === 12 ? '12pm' : (h - 12) + 'pm'), datasets: [{ label: 'Appointments', data: HOURS, backgroundColor: HOURS.map(v => v === hMax && v > 0 ? '#ea580c' : 'rgba(234,88,12,.28)'), borderRadius: 5 }] },
            options: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } }, y: intAxis } } });
        <?php elseif ($active_tab === 'boarding'): ?>
        mk('chBoardVolume', { type: 'bar', data: { labels: LABELS, datasets: [{ label: 'Stays', data: <?= json_encode(array_values($board_volume)) ?>, backgroundColor: '#2563eb', borderRadius: 5, maxBarThickness: 28 }] },
            options: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } }, y: intAxis } } });
        mk('chBoardStatus', { type: 'doughnut', data: { labels: ['Completed', 'Approved / boarding', 'Pending', 'Cancelled / rejected'], datasets: [{ data: [<?= $B['completed'] ?>, <?= $B['active'] ?>, <?= $B['pending'] ?>, <?= $B['cancelled'] ?>], backgroundColor: ['#15803d', '#2563eb', '#d97706', '#be123c'], borderColor: '#fff', borderWidth: 2 }] },
            options: { cutout: '64%', plugins: { legend: { display: false } } } });
        <?php elseif ($active_tab === 'payments'): ?>
        mk('chPay', { type: 'bar', data: { labels: LABELS, datasets: [
                { label: 'Billed', data: <?= json_encode(array_map(fn($v) => round($v, 2), array_values($pay_billed))) ?>, backgroundColor: '#2563eb', borderRadius: 5, maxBarThickness: 24 },
                { label: 'Collected', data: <?= json_encode(array_map(fn($v) => round($v, 2), array_values($pay_collected))) ?>, backgroundColor: '#16a34a', borderRadius: 5, maxBarThickness: 24 }] },
            options: { plugins: { legend: { position: 'top', align: 'end' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + peso(c.raw) } } },
                scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } }, y: { beginAtZero: true, grid, border: { display: false }, ticks: { callback: v => '₱' + Number(v).toLocaleString() } } } } });
        <?php endif; ?>

        // Printing: charts redraw at paper width, animations off so nothing prints half-drawn
        function pvFitCharts() { Object.values(Chart.instances).forEach(c => { c.options.animation = false; c.resize(); }); }
        window.addEventListener('beforeprint', pvFitCharts);
        window.addEventListener('afterprint', () => Object.values(Chart.instances).forEach(c => c.resize()));
        function pvPrintReport() { pvFitCharts(); setTimeout(() => window.print(), 150); }
    </script>
</body>
</html>
