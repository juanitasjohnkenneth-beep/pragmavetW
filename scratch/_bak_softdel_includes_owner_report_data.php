<?php
/**
 * Owner Reports & Analytics — all figures for one period, shared by the dashboard (owner_dashboard.php)
 * and the print-ready report (owner_report_print.php). Expects $conn; reads ?period / ?start_date / ?end_date.
 */
include_once __DIR__ . '/report_data.php';
include_once __DIR__ . '/inventory_helper.php';

$peso = fn($v) => '₱' . number_format((float)$v, 2);
$pct  = fn($part, $whole) => $whole > 0 ? round($part * 100 / $whole, 1) : 0;
$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── Report period (clinic dates come from the database clock) ─────────────
$db_today = $conn->query("SELECT CURDATE() d")->fetch_assoc()['d'];
$period   = $_GET['period'] ?? 'month';
$in_start = trim($_GET['start_date'] ?? '');
$in_end   = trim($_GET['end_date'] ?? '');
$first_q  = $conn->query("SELECT LEAST(IFNULL((SELECT MIN(DATE(created_at)) FROM billing), CURDATE()), IFNULL((SELECT MIN(appointment_date) FROM appointments), CURDATE())) d");
$first_day = $first_q ? $first_q->fetch_assoc()['d'] : $db_today;

if ($in_start && $in_end && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in_start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in_end)) $period = 'custom';
switch ($period) {
    case 'today':      $ps = $pe = $db_today; $plabel = 'Today, ' . date('F j, Y', strtotime($db_today)); break;
    case 'week':       $ps = date('Y-m-d', strtotime('monday this week', strtotime($db_today))); $pe = date('Y-m-d', strtotime($ps . ' +6 days'));
                       $plabel = 'This week, ' . date('M j', strtotime($ps)) . ' – ' . date('M j, Y', strtotime($pe)); break;
    case 'last_month': $ps = date('Y-m-01', strtotime('first day of last month', strtotime($db_today))); $pe = date('Y-m-t', strtotime($ps));
                       $plabel = 'Last month, ' . date('F Y', strtotime($ps)); break;
    case 'year':       $ps = date('Y-01-01', strtotime($db_today)); $pe = date('Y-12-31', strtotime($db_today)); $plabel = 'This year, ' . date('Y', strtotime($db_today)); break;
    case 'all':        $ps = $first_day; $pe = $db_today; $plabel = 'All time, ' . date('M j, Y', strtotime($ps)) . ' – ' . date('M j, Y', strtotime($pe)); break;
    case 'custom':     $ps = min($in_start, $in_end); $pe = max($in_start, $in_end); $plabel = date('M j, Y', strtotime($ps)) . ' – ' . date('M j, Y', strtotime($pe)); break;
    default:           $period = 'month'; $ps = date('Y-m-01', strtotime($db_today)); $pe = date('Y-m-t', strtotime($db_today)); $plabel = 'This month, ' . date('F Y', strtotime($db_today));
}
$days_in_period = (int)round((strtotime($pe) - strtotime($ps)) / 86400) + 1;
$in_range = fn($d) => $d >= $ps && $d <= $pe;

// ── 1. Revenue (valid invoices issued in the period) ─────────────────────
[$all_bills, $excluded] = rpt_valid_bills($conn);
$bills = array_values(array_filter($all_bills, fn($b) => $in_range($b['_date'])));
$billed = $collected = $owed = 0.0;
$n_paid = $n_partial = $n_unpaid = 0;
$stream = ['Clinic services' => 0.0, 'Boarding' => 0.0];
$methods = [];
$svc_rev = [];
foreach ($bills as $b) {
    $amt = (float)$b['total_amount'];
    $billed += $amt; $collected += $b['_paid']; $owed += $b['_owed'];
    if ($b['payment_status'] === 'Paid') $n_paid++; elseif ($b['_paid'] > 0) $n_partial++; else $n_unpaid++;
    $stream[(int)$b['is_boarding'] === 1 ? 'Boarding' : 'Clinic services'] += $amt;
    if ($b['_paid'] > 0) {
        $m = $b['payment_status'] === 'Paid' ? ($b['payment_method'] ?: 'Not recorded') : 'Deposit / reservation fee';
        if ($m === 'Reservation Fee') $m = 'Deposit / reservation fee';
        $methods[$m] = ($methods[$m] ?? 0) + $b['_paid'];
    }
    $items = json_decode($b['items_json'] ?? '', true);
    if (is_array($items) && $items) {
        foreach ($items as $it) {
            $name = trim(preg_split('/ — | \(/u', (string)($it['name'] ?? 'Other'))[0]) ?: 'Other';
            $svc_rev[$name] = ($svc_rev[$name] ?? 0) + (float)($it['qty'] ?? 1) * (float)($it['price'] ?? 0);
        }
    } else {
        $name = (int)$b['is_boarding'] === 1 ? 'Pet Boarding' : ($b['a_service'] ?: 'Clinic service');
        $svc_rev[$name] = ($svc_rev[$name] ?? 0) + $amt;
    }
}
arsort($methods); arsort($svc_rev);
$collection_rate = $pct($collected, $billed);
$avg_invoice = count($bills) ? $billed / count($bills) : 0;

// Trend: daily for short periods, monthly otherwise
$daily = $days_in_period <= 62;
$trend = [];
if ($daily) { for ($d = $ps; $d <= $pe; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $trend[$d] = [date('M j', strtotime($d)), 0.0, 0.0]; }
else { for ($m = date('Y-m-01', strtotime($ps)); $m <= $pe; $m = date('Y-m-01', strtotime($m . ' +1 month'))) $trend[substr($m, 0, 7)] = [date('M Y', strtotime($m)), 0.0, 0.0]; }
foreach ($bills as $b) {
    $k = $daily ? $b['_date'] : substr($b['_date'], 0, 7);
    if (isset($trend[$k])) { $trend[$k][1] += (float)$b['total_amount']; $trend[$k][2] += $b['_paid']; }
}

// ── 2. Appointments (clinic services, dated in the period) ───────────────
$appt = [];
$aq = $conn->query("SELECT status, COUNT(*) c FROM appointments WHERE service_type <> 'Pet Boarding Reservation' AND appointment_date BETWEEN '$ps' AND '$pe' GROUP BY status");
if ($aq) while ($r = $aq->fetch_assoc()) $appt[$r['status']] = (int)$r['c'];
$a_total     = array_sum($appt);
$a_completed = $appt['Completed'] ?? 0;
$a_noshow    = $appt['No Show'] ?? 0;
$a_cancel    = ($appt['Cancelled'] ?? 0) + ($appt['Rejected'] ?? 0);
$a_upcoming  = ($appt['Pending'] ?? 0) + ($appt['Approved'] ?? 0) + ($appt['Checked In'] ?? 0) + ($appt['In Progress'] ?? 0) + ($appt['Reschedule Proposed'] ?? 0) + ($appt['Reschedule Requested'] ?? 0);
$noshow_rate = $pct($a_noshow, $a_completed + $a_noshow);
$cancel_rate = $pct($a_cancel, $a_total);
$outcomes = ['Completed' => $a_completed, 'Upcoming / in progress' => $a_upcoming, 'Cancelled / rejected' => $a_cancel, 'No show' => $a_noshow];

$svc_vol = [];
$sq = $conn->query("SELECT IFNULL(NULLIF(service_type, ''), 'General Checkup') s, COUNT(*) c FROM appointments
                    WHERE service_type <> 'Pet Boarding Reservation' AND appointment_date BETWEEN '$ps' AND '$pe' AND status NOT IN ('Cancelled','Rejected')
                    GROUP BY s ORDER BY c DESC LIMIT 8");
if ($sq) while ($r = $sq->fetch_assoc()) $svc_vol[$r['s']] = (int)$r['c'];

$dow = array_fill(1, 7, 0);
$wq = $conn->query("SELECT DAYOFWEEK(appointment_date) d, COUNT(*) c FROM appointments WHERE service_type <> 'Pet Boarding Reservation' AND appointment_date BETWEEN '$ps' AND '$pe' AND status NOT IN ('Cancelled','Rejected') GROUP BY d");
if ($wq) while ($r = $wq->fetch_assoc()) $dow[(int)$r['d']] = (int)$r['c'];
$dow_names = [1 => 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$busiest_dow = array_sum($dow) ? $dow_names[array_search(max($dow), $dow)] : null;

// ── 3. Veterinarians ─────────────────────────────────────────────────────
$vets = [];
$vq = $conn->query("SELECT u.id, u.fullname,
                           COUNT(a.id) assigned, SUM(a.status = 'Completed') completed, SUM(a.status = 'No Show') noshow, SUM(a.status IN ('Cancelled','Rejected')) cancelled
                    FROM users u LEFT JOIN appointments a ON a.doctor_id = u.id AND a.appointment_date BETWEEN '$ps' AND '$pe' AND a.service_type <> 'Pet Boarding Reservation'
                    WHERE u.role = 'doctor' GROUP BY u.id, u.fullname ORDER BY completed DESC, assigned DESC");
if ($vq) while ($r = $vq->fetch_assoc()) { $r['revenue'] = 0.0; $vets[(int)$r['id']] = $r; }
foreach ($bills as $b) if ((int)$b['is_boarding'] === 0 && $b['a_doctor'] && isset($vets[(int)$b['a_doctor']])) $vets[(int)$b['a_doctor']]['revenue'] += (float)$b['total_amount'];
$unassigned = (int)($conn->query("SELECT COUNT(*) c FROM appointments WHERE doctor_id IS NULL AND service_type <> 'Pet Boarding Reservation' AND appointment_date BETWEEN '$ps' AND '$pe' AND status NOT IN ('Cancelled','Rejected')")->fetch_assoc()['c'] ?? 0);

// ── 4. Clients & pets ────────────────────────────────────────────────────
$c_total   = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE role IN ('client','user')")->fetch_assoc()['c'] ?? 0);
$c_new     = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE role IN ('client','user') AND DATE(created_at) BETWEEN '$ps' AND '$pe'")->fetch_assoc()['c'] ?? 0);
$c_active  = (int)($conn->query("SELECT COUNT(DISTINCT user_id) c FROM appointments WHERE appointment_date BETWEEN '$ps' AND '$pe' AND status NOT IN ('Cancelled','Rejected')")->fetch_assoc()['c'] ?? 0);
$c_repeat  = (int)($conn->query("SELECT COUNT(*) c FROM (SELECT user_id FROM appointments WHERE status = 'Completed' GROUP BY user_id HAVING COUNT(*) >= 2) x")->fetch_assoc()['c'] ?? 0);
$p_total   = (int)($conn->query("SELECT COUNT(*) c FROM pets")->fetch_assoc()['c'] ?? 0);
$p_new     = (int)($conn->query("SELECT COUNT(*) c FROM pets WHERE DATE(created_at) BETWEEN '$ps' AND '$pe'")->fetch_assoc()['c'] ?? 0);
$species = ['Dogs' => 0, 'Cats' => 0, 'Other' => 0];
$spq = $conn->query("SELECT LOWER(IFNULL(species, '')) s, COUNT(*) c FROM pets GROUP BY s");
if ($spq) while ($r = $spq->fetch_assoc()) {
    $k = (strpos($r['s'], 'dog') !== false || strpos($r['s'], 'canine') !== false) ? 'Dogs' : ((strpos($r['s'], 'cat') !== false || strpos($r['s'], 'feline') !== false) ? 'Cats' : 'Other');
    $species[$k] += (int)$r['c'];
}
$vax_due = (int)($conn->query("SELECT COUNT(*) c FROM pets WHERE next_vaccination IS NOT NULL AND next_vaccination BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY")->fetch_assoc()['c'] ?? 0);

// ── 5. Boarding ──────────────────────────────────────────────────────────
$cage_count = (int)($conn->query("SELECT setting_value FROM clinic_settings WHERE setting_key = 'boarding_cage_count'")->fetch_assoc()['setting_value'] ?? 5) ?: 5;
$stays = 0; $nights_in = 0; $stay_len = 0;
$bq = $conn->query("SELECT appointment_date s, end_date e FROM boarding_reservations WHERE status IN ('Approved','Checked In','Completed') AND end_date IS NOT NULL AND appointment_date <= '$pe' AND end_date >= '$ps'");
if ($bq) while ($r = $bq->fetch_assoc()) {
    $stays++;
    $len = max(1, (int)round((strtotime($r['e']) - strtotime($r['s'])) / 86400));
    $stay_len += $len;
    $cs = max($r['s'], $ps); $ce = min($r['e'], date('Y-m-d', strtotime($pe . ' +1 day')));
    $nights_in += max(0, (int)round((strtotime($ce) - strtotime($cs)) / 86400));
}
$avg_stay = $stays ? round($stay_len / $stays, 1) : 0;
$occupancy = $pct($nights_in, $cage_count * $days_in_period);
$in_now = (int)($conn->query("SELECT COUNT(*) c FROM boarding_reservations WHERE status = 'Checked In'")->fetch_assoc()['c'] ?? 0);

// ── 6. Inventory (snapshot today + usage in the period) ──────────────────
$inv = ['items' => 0, 'cost' => 0.0, 'retail' => 0.0, 'low' => 0, 'out' => 0, 'expiring' => 0, 'expired' => 0];
$iq = $conn->query("SELECT * FROM inventory");
if ($iq) while ($r = $iq->fetch_assoc()) {
    $inv['items']++;
    $inv['cost'] += (int)$r['quantity'] * (float)$r['cost_price'];
    $inv['retail'] += (int)$r['quantity'] * (float)$r['price_per_unit'];
    [$k] = inv_stock_status($r, $db_today);
    if ($k === 'low') $inv['low']++; elseif ($k === 'out') $inv['out']++; elseif ($k === 'expiring') $inv['expiring']++; elseif ($k === 'expired') $inv['expired']++;
}
$used = [];
$uq = $conn->query("SELECT item_name, SUM(-qty_change) q FROM inventory_movements WHERE movement_type = 'Used' AND DATE(created_at) BETWEEN '$ps' AND '$pe' GROUP BY item_name ORDER BY q DESC LIMIT 5");
if ($uq) while ($r = $uq->fetch_assoc()) $used[$r['item_name']] = (int)$r['q'];

// ── 7. Receivables today (all valid invoices still owing) ────────────────
$recv = array_values(array_filter($all_bills, fn($b) => $b['_owed'] > 0));
usort($recv, fn($a, $b) => $b['_owed'] <=> $a['_owed']);
$recv_total = array_sum(array_column($recv, '_owed'));
$recv_old = count(array_filter($recv, fn($b) => (strtotime($db_today) - strtotime($b['_date'])) / 86400 > 30));

// ── 8. Plain-language highlights ─────────────────────────────────────────
$top_rev_svc = $svc_rev ? array_key_first($svc_rev) : null;
$top_vol_svc = $svc_vol ? array_key_first($svc_vol) : null;
$highlights = [];
if ($billed > 0) $highlights[] = "The clinic billed <b>{$peso($billed)}</b> across " . count($bills) . " invoice" . (count($bills) === 1 ? '' : 's') . " and collected <b>{$peso($collected)}</b> ({$collection_rate}% of billed).";
else $highlights[] = "No valid invoices were issued in this period.";
if ($top_rev_svc) $highlights[] = "<b>" . $h($top_rev_svc) . "</b> brought in the most revenue ({$peso($svc_rev[$top_rev_svc])}" . ($billed > 0 ? ", " . $pct($svc_rev[$top_rev_svc], $billed) . "% of billed" : '') . ").";
if ($a_total) $highlights[] = "Of <b>{$a_total}</b> appointments, <b>{$a_completed}</b> were completed, {$a_cancel} cancelled and {$a_noshow} no-show" . ($a_noshow === 1 ? '' : 's') . ($top_vol_svc ? "; the most booked service was <b>" . $h($top_vol_svc) . "</b>" : '') . ".";
if ($busiest_dow) $highlights[] = "<b>{$busiest_dow}</b> was the busiest day for appointments.";
if ($stays) $highlights[] = "{$stays} boarding stay" . ($stays === 1 ? '' : 's') . " used {$nights_in} cage-night" . ($nights_in === 1 ? '' : 's') . " (≈{$occupancy}% of capacity), averaging {$avg_stay} night" . ($avg_stay == 1 ? '' : 's') . " each.";
if ($recv_total > 0) $highlights[] = "<b>{$peso($recv_total)}</b> is still unpaid across " . count($recv) . " invoice" . (count($recv) === 1 ? '' : 's') . ($recv_old ? ", {$recv_old} of them older than 30 days" : '') . ".";
$excluded_cnt = array_sum(array_column($excluded, 0));
$excluded_amt = array_sum(array_column($excluded, 1));

$json = fn($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$period_btns = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year', 'all' => 'All time'];

// Receivables aging (days since the invoice was issued)
$aging = ['0–30 days' => [0, 0.0], '31–60 days' => [0, 0.0], '61–90 days' => [0, 0.0], 'Over 90 days' => [0, 0.0]];
foreach ($recv as $b) {
    $age = (int)floor((strtotime($db_today) - strtotime($b['_date'])) / 86400);
    $k = $age <= 30 ? '0–30 days' : ($age <= 60 ? '31–60 days' : ($age <= 90 ? '61–90 days' : 'Over 90 days'));
    $aging[$k][0]++; $aging[$k][1] += $b['_owed'];
}
// Query string for the current period (links between the dashboard and the printable report)
$period_qs = $period === 'custom' ? 'start_date=' . urlencode($ps) . '&end_date=' . urlencode($pe) : 'period=' . urlencode($period);
