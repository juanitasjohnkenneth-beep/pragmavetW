<?php
session_start();
include("dbconnection.php");

// Auth guard - Admin and Owner (Reports & Analytics)
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

// ═════════════════════════════════════════════════════════════════════════
// DESCRIPTIVE ANALYTICS — figures are computed only from valid records
// ═════════════════════════════════════════════════════════════════════════
$today_date   = date('Y-m-d');
$current_year = (int)date('Y');
$peso = function ($v) { return '₱' . number_format((float)$v, 2); };
$pct  = function ($part, $whole) { return $whole > 0 ? round($part / $whole * 100, 1) : 0; };

// ── 1. Billing: keep only valid invoices ──────────────────────────────────
// Excluded: void invoices, invoices whose booking was cancelled/rejected,
// invoices whose booking no longer exists, and duplicate invoices for the same boarding stay.
$bill_q = $conn->query("
    SELECT b.*, a.id AS a_id, a.status AS a_status,
           br.id AS br_id, br.status AS br_status,
           COALESCE(b.boarding_res_id, b.appointment_id) AS res_key
    FROM billing b
    LEFT JOIN appointments a ON a.id = b.appointment_id AND b.is_boarding = 0
    LEFT JOIN boarding_reservations br ON br.id = COALESCE(b.boarding_res_id, b.appointment_id) AND b.is_boarding = 1
    ORDER BY b.id ASC
");
$excluded = ['void' => [0, 0.0], 'cancelled' => [0, 0.0], 'missing' => [0, 0.0], 'duplicate' => [0, 0.0]];
$valid_bills = [];
$boarding_pick = []; // res_key => index in $valid_bills
$bill_rank = function ($b) {
    if ($b['payment_status'] === 'Paid') return 2;
    if ($b['downpayment_status'] === 'Paid') return 1;
    return 0;
};
$bad_status = ['Cancelled', 'Rejected'];
if ($bill_q) while ($b = $bill_q->fetch_assoc()) {
    $amt = (float)$b['total_amount'];
    if (in_array($b['payment_status'], ['Void', 'Cancelled'], true)) { $excluded['void'][0]++; $excluded['void'][1] += $amt; continue; }
    if ((int)$b['is_boarding'] === 1) {
        if (!$b['br_id'])                                { $excluded['missing'][0]++;   $excluded['missing'][1]   += $amt; continue; }
        if (in_array($b['br_status'], $bad_status, true)) { $excluded['cancelled'][0]++; $excluded['cancelled'][1] += $amt; continue; }
        $k = $b['res_key'];
        if (isset($boarding_pick[$k])) {
            // Same stay billed twice: keep the one with the most payment progress (then the earliest)
            $prev = $valid_bills[$boarding_pick[$k]];
            if ($bill_rank($b) > $bill_rank($prev)) { $valid_bills[$boarding_pick[$k]] = $b; $amt = (float)$prev['total_amount']; }
            $excluded['duplicate'][0]++; $excluded['duplicate'][1] += $amt;
            continue;
        }
        $boarding_pick[$k] = count($valid_bills);
    } else {
        if ($b['appointment_id'] && !$b['a_id'])        { $excluded['missing'][0]++;   $excluded['missing'][1]   += $amt; continue; }
        if (in_array($b['a_status'], $bad_status, true)) { $excluded['cancelled'][0]++; $excluded['cancelled'][1] += $amt; continue; }
    }
    $valid_bills[] = $b;
}

$total_billed = $total_collected = $outstanding_balance = 0.0;
$unpaid_invoices = 0;
$monthly_billed = array_fill(1, 12, 0.0);
$monthly_collected = array_fill(1, 12, 0.0);
$weekly_labels = $weekly_billed = $weekly_collected = [];
$week_ranges = [];
for ($i = 4; $i >= 0; $i--) {
    $ws = date('Y-m-d', strtotime("-$i weeks sunday"));
    $we = date('Y-m-d', strtotime($ws . ' +6 days'));
    $week_ranges[] = [$ws, $we];
    $weekly_labels[] = 'Wk of ' . date('M d', strtotime($ws));
    $weekly_billed[] = 0.0; $weekly_collected[] = 0.0;
}
foreach ($valid_bills as $b) {
    $amt = (float)$b['total_amount'];
    if ($b['payment_status'] === 'Paid') {
        $paid = (float)$b['amount_paid'] > 0 ? (float)$b['amount_paid'] : $amt;
    } elseif ($b['downpayment_status'] === 'Paid') {
        $paid = (float)$b['amount_paid'] > 0 ? (float)$b['amount_paid'] : (float)$b['downpayment_amount'];
    } else {
        $paid = 0.0;
    }
    $paid = min($paid, $amt);
    $owed = $b['payment_status'] === 'Paid' ? 0.0 : max(0.0, $amt - $paid);
    $total_billed += $amt; $total_collected += $paid; $outstanding_balance += $owed;
    if ($owed > 0) $unpaid_invoices++;

    $d = substr($b['created_at'], 0, 10);
    if ((int)substr($d, 0, 4) === $current_year) {
        $m = (int)substr($d, 5, 2);
        $monthly_billed[$m] += $amt; $monthly_collected[$m] += $paid;
    }
    foreach ($week_ranges as $wi => [$ws, $we]) {
        if ($d >= $ws && $d <= $we) { $weekly_billed[$wi] += $amt; $weekly_collected[$wi] += $paid; }
    }
}
$total_invoices_cnt = count($valid_bills);
$collection_rate = $pct($total_collected, $total_billed);
$excluded_cnt = array_sum(array_column($excluded, 0));
$excluded_amt = array_sum(array_column($excluded, 1));

// ── 2. Appointments (all time, clinic services only) ──────────────────────
$appt_status = [];
$sq = $conn->query("SELECT status, COUNT(*) c FROM appointments WHERE service_type <> 'Pet Boarding Reservation' GROUP BY status");
if ($sq) while ($r = $sq->fetch_assoc()) $appt_status[$r['status']] = (int)$r['c'];
$total_appts     = array_sum($appt_status);
$completed_count = $appt_status['Completed'] ?? 0;
$cancelled_count = ($appt_status['Cancelled'] ?? 0) + ($appt_status['Rejected'] ?? 0);
$noshow_count    = $appt_status['No Show'] ?? 0;
$pending_count   = $appt_status['Pending'] ?? 0;
$active_count    = ($appt_status['Approved'] ?? 0) + ($appt_status['Checked In'] ?? 0) + ($appt_status['In Progress'] ?? 0);
$resched_count   = ($appt_status['Reschedule Proposed'] ?? 0) + ($appt_status['Reschedule Requested'] ?? 0);
$today_count = (int)($conn->query("SELECT COUNT(*) c FROM appointments WHERE appointment_date = '$today_date' AND status NOT IN ('Cancelled','Rejected')")->fetch_assoc()['c'] ?? 0);

// ── 3. Service volume: confirmed or delivered services only ───────────────
$appt_labels = $appt_data = [];
$vq = $conn->query("
    SELECT IFNULL(NULLIF(service_type, ''), 'General Checkup') AS type, COUNT(*) AS cnt
    FROM appointments
    WHERE status IN ('Approved','Checked In','In Progress','Completed') AND service_type <> 'Pet Boarding Reservation'
    GROUP BY type ORDER BY cnt DESC
");
if ($vq) while ($r = $vq->fetch_assoc()) { $appt_labels[] = $r['type']; $appt_data[] = (int)$r['cnt']; }
$boarding_cnt = (int)($conn->query("SELECT COUNT(*) c FROM boarding_reservations WHERE status IN ('Approved','Completed')")->fetch_assoc()['c'] ?? 0);
if ($boarding_cnt > 0) { $appt_labels[] = 'Pet Boarding'; $appt_data[] = $boarding_cnt; }
$service_total = array_sum($appt_data);
// Keep the doughnut sorted by volume
if ($appt_data) { array_multisort($appt_data, SORT_DESC, $appt_labels); }

// ── 4. Client feedback ────────────────────────────────────────────────────
$rating_dist = array_fill(1, 5, 0);
$rq = $conn->query("SELECT rating, COUNT(*) c FROM feedbacks WHERE rating BETWEEN 1 AND 5 GROUP BY rating");
if ($rq) while ($r = $rq->fetch_assoc()) $rating_dist[(int)$r['rating']] = (int)$r['c'];
$feedback_count   = array_sum($rating_dist);
$max_rating_count = max($rating_dist) ?: 1;
$computed_avg     = $feedback_count ? array_sum(array_map(fn($r, $c) => $r * $c, array_keys($rating_dist), $rating_dist)) / $feedback_count : 0;
$avg_rating       = $computed_avg;
$positive_share   = $pct($rating_dist[4] + $rating_dist[5], $feedback_count);
$recent_feedback  = $conn->query("SELECT f.*, u.fullname as client_name FROM feedbacks f JOIN users u ON f.user_id = u.id ORDER BY f.created_at DESC LIMIT 6");

// ── 5. 30-day appointment volume (excluding cancelled) ────────────────────
$trend_data = array_fill(0, 30, 0);
$trend_labels = [];
for ($i = 29; $i >= 0; $i--) $trend_labels[] = date('M j', strtotime("-$i days"));
$tq = $conn->query("SELECT appointment_date d, COUNT(*) c FROM appointments
                    WHERE appointment_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND CURDATE()
                      AND status NOT IN ('Cancelled','Rejected') GROUP BY d");
if ($tq) while ($t = $tq->fetch_assoc()) {
    $idx = 29 - (int)round((strtotime($today_date) - strtotime($t['d'])) / 86400);
    if ($idx >= 0 && $idx < 30) $trend_data[$idx] = (int)$t['c'];
}

// ── 6. Appointment hours (last 90 days, excluding cancelled) ──────────────
$peak_hours = array_fill(0, 24, 0);
$phq = $conn->query("SELECT HOUR(appointment_time) h, COUNT(*) c FROM appointments
                     WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND status NOT IN ('Cancelled','Rejected') GROUP BY h");
if ($phq) while ($ph = $phq->fetch_assoc()) $peak_hours[(int)$ph['h']] = (int)$ph['c'];

// ── 7. Status snapshot ────────────────────────────────────────────────────
$vacc_overdue  = (int)($conn->query("SELECT COUNT(*) c FROM pets WHERE deleted_at IS NULL AND next_vaccination IS NOT NULL AND next_vaccination < CURDATE()")->fetch_assoc()['c'] ?? 0);
$vacc_due_week = (int)($conn->query("SELECT COUNT(*) c FROM pets WHERE deleted_at IS NULL AND next_vaccination BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'] ?? 0);
$occ_count = (int)($conn->query("SELECT COUNT(DISTINCT cage_number) occ FROM boarding_reservations WHERE status='Approved' AND appointment_date <= CURDATE() AND end_date >= CURDATE()")->fetch_assoc()['occ'] ?? 0);
$total_cages_q = $conn->query("SELECT setting_value FROM clinic_settings WHERE setting_key = 'boarding_cage_count'");
$total_cages = ($total_cages_q && $total_cages_q->num_rows > 0) ? (int)$total_cages_q->fetch_assoc()['setting_value'] : 5;

// ── 8. Plain-language descriptions (what happened) ────────────────────────
$month_names = ['', 'January','February','March','April','May','June','July','August','September','October','November','December'];
$year_billed = array_sum($monthly_billed);
$year_collected = array_sum($monthly_collected);
$billed_months = array_filter($monthly_billed, fn($v) => $v > 0);
if ($billed_months) {
    $peak_m = array_search(max($billed_months), $monthly_billed);
    $low_m  = array_search(min($billed_months), $monthly_billed);
    $desc_billing = "In $current_year, {$peso($year_billed)} was billed across " . count($billed_months) . " month(s) and {$peso($year_collected)} of it was collected ({$pct($year_collected, $year_billed)}%). "
        . "The highest month was {$month_names[$peak_m]} ({$peso($monthly_billed[$peak_m])})"
        . ($low_m !== $peak_m ? " and the lowest billed month was {$month_names[$low_m]} ({$peso($monthly_billed[$low_m])})" : "")
        . ". Average per billed month: {$peso($year_billed / count($billed_months))}.";
} else {
    $desc_billing = "No valid invoices were recorded in $current_year.";
}

if ($service_total > 0) {
    $desc_services = "$service_total confirmed or completed service(s) in total. The most common was {$appt_labels[0]} ({$appt_data[0]}, {$pct($appt_data[0], $service_total)}%)"
        . (isset($appt_labels[1]) ? ", followed by {$appt_labels[1]} ({$appt_data[1]}, {$pct($appt_data[1], $service_total)}%)" : "")
        . ". Cancelled, rejected and pending requests are not counted.";
} else {
    $desc_services = "No confirmed or completed services yet.";
}

if ($feedback_count > 0) {
    $mode_rating = array_search(max($rating_dist), $rating_dist);
    $desc_feedback = "$feedback_count review(s) with an average of " . number_format($computed_avg, 1) . " out of 5. "
        . "$positive_share% rated 4 or 5 stars; the most frequent rating was $mode_rating star(s) ({$rating_dist[$mode_rating]} review(s)).";
} else {
    $desc_feedback = "No client reviews have been submitted yet.";
}

$trend_total = array_sum($trend_data);
if ($trend_total > 0) {
    $busy_idx = array_search(max($trend_data), $trend_data);
    $active_days = count(array_filter($trend_data));
    $desc_trend = "$trend_total appointment(s) in the last 30 days (excluding cancelled), on $active_days different day(s) — an average of "
        . number_format($trend_total / 30, 1) . " per day. The busiest day was {$trend_labels[$busy_idx]} with {$trend_data[$busy_idx]}.";
} else {
    $desc_trend = "No appointments in the last 30 days.";
}

$peak_total = array_sum($peak_hours);
if ($peak_total > 0) {
    $ph = array_search(max($peak_hours), $peak_hours);
    $ph_label = date('g A', mktime($ph, 0, 0));
    $desc_peak = "$peak_total appointment(s) in the last 90 days. The most booked hour was $ph_label with {$peak_hours[$ph]} ({$pct($peak_hours[$ph], $peak_total)}% of the period).";
} else {
    $desc_peak = "No appointments in the last 90 days.";
}

// Key findings summary
$key_findings = [];
$key_findings[] = "$total_appts clinic appointment(s) on record: $completed_count completed ({$pct($completed_count, $total_appts)}%), $cancelled_count cancelled or rejected ({$pct($cancelled_count, $total_appts)}%), $noshow_count no-show(s), $pending_count pending and $active_count approved/in progress.";
$key_findings[] = "{$peso($total_billed)} billed on $total_invoices_cnt valid invoice(s); {$peso($total_collected)} collected ($collection_rate%) and {$peso($outstanding_balance)} still outstanding on $unpaid_invoices invoice(s).";
if ($service_total > 0) $key_findings[] = "Top service: {$appt_labels[0]} ({$pct($appt_data[0], $service_total)}% of confirmed services).";
if ($feedback_count > 0) $key_findings[] = "Client satisfaction: " . number_format($computed_avg, 1) . " / 5 from $feedback_count review(s), $positive_share% positive.";
$key_findings[] = "Vaccinations: $vacc_overdue pet(s) overdue and $vacc_due_week due within the next 7 days. Boarding: $occ_count of $total_cages cage(s) occupied today.";

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script src="theme.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="theme.css?v=<?= time() ?>">
    <title>Admin Dashboard | Reports & Analytics - PragmaVet</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        @keyframes sweepRight {
            from { clip-path: inset(0 100% 0 0); }
            to { clip-path: inset(0 0 0 0); }
        }

        .chart-swipe {
            animation: sweepRight 1.2s ease-in-out forwards;
        }

        :root {
            --primary: #2563eb;
            --secondary: #3b82f6;
            --dark: #1e40af;
            --light-bg: #F4F9FB;
            --sidebar-width: 260px;
        }

        body {
            background: var(--light-bg);
            font-family: 'Inter', sans-serif;
            color: #2D3748;
            overflow-x: hidden;
        }

        .main-content {
            margin-left: var(--sidebar-width) !important;
            width: calc(100% - var(--sidebar-width)) !important;
            max-width: calc(100% - var(--sidebar-width)) !important;
            padding: 24px 28px !important;
            min-height: 100vh;
            box-sizing: border-box !important;
        }

        .card-premium {
            background: white;
            border: none;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
            margin-bottom: 24px;
        }

        /* Summary Cards */
        .sum-card {
            background: #fff;
            border-radius: 16px;
            padding: 20px 22px;
            box-shadow: 0 4px 16px rgba(0,0,0,.04);
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none !important;
            height: 100%;
        }
        .sum-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0,0,0,.08);
            border-color: #cbd5e1;
        }
        .sum-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .sum-value {
            font-size: 22px;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 3px;
            color: #0f172a;
        }
        .sum-label {
            font-size: 11.5px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        /* ====== WHITE SIDEBAR THEME ====== */
        .sidebar {
            background: #ffffff !important;
            width: var(--sidebar-width, 260px) !important;
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
            flex-shrink: 0 !important;
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
            line-height: 1.3 !important;
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
            letter-spacing: -0.1px !important;
        }

        .sidebar a i {
            width: 22px !important;
            text-align: center !important;
            font-size: 16px !important;
            color: #64748b !important;
            transition: color 0.2s !important;
            flex-shrink: 0 !important;
        }

        .sidebar a:hover {
            background: #f8fafc !important;
            color: #0f172a !important;
        }

        .sidebar a:hover i {
            color: #2563eb !important;
        }

        .sidebar a.active {
            background: #2563eb !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28) !important;
        }

        .sidebar a.active i {
            color: #ffffff !important;
        }

        .sidebar .sidebar-logout {
            margin-top: auto !important;
            padding-top: 20px !important;
            border-top: 1px solid #f1f5f9 !important;
        }

        .sidebar .sidebar-logout a {
            color: #dc2626 !important;
            font-weight: 600 !important;
            margin-bottom: 0 !important;
        }

        .sidebar .sidebar-logout a:hover {
            background: #fef2f2 !important;
            color: #b91c1c !important;
        }

        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.06); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }

        /* ============ PRINT STYLES - HIDE DASHBOARD, SHOW MEDICAL REPORT ============ */
        @media print {
            body { background: white !important; font-family: 'Inter', Arial, sans-serif; color: #1A2332; }
            @page { margin: 20mm 15mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            
            .sidebar, .no-print, .btn, .btn-primary, .btn-success,
            .alert-container, .alert, .position-fixed,
            .main-content > .row, .main-content > div:not(#print-reports-section),
            .main-content > .d-flex,
            .sum-card, .card-premium, .chart-swipe,
            #timeFilter, #revenueChart, #typeChart,
            .fb-bar-val, .feedback-bar,
            #performanceSummary, #appointmentSummary, #feedbackSummary,
            .chart-container, canvas,
            svg:not(.print-keep) { display: none !important; }

            .main-content { margin-left: 0 !important; width: 100% !important; max-width: 100% !important; padding: 0 !important; }
            #print-reports-section { display: block !important; }
            
            .doc-header { text-align: center; border-bottom: 3px double #0E5A6B; padding-bottom: 15px; margin-bottom: 20px; }
            .doc-header h1 { font-size: 22px; color: #0E5A6B; margin: 0 0 5px; text-transform: uppercase; letter-spacing: 1px; }
            .doc-header p { margin: 2px 0; color: #475569; font-size: 12px; }
            .doc-ref { float: right; background: #f1f5f9; padding: 8px 14px; border: 1px solid #e2e8f0; font-size: 11px; }
            .doc-ref strong { color: #0E5A6B; font-size: 14px; }
            .doc-title { text-align: center; font-size: 18px; font-weight: 800; color: #0E5A6B; margin: 15px 0 5px; border: 2px solid #0E5A6B; display: inline-block; padding: 8px 30px; }
            .doc-title-wrapper { text-align: center; margin-bottom: 20px; }

            .patient-summary { border: 1px solid #cbd5e1; padding: 12px 16px; margin-bottom: 18px; page-break-inside: avoid; }
            .patient-summary table { width: 100%; border-collapse: collapse; font-size: 12px; }
            .patient-summary td { padding: 4px 10px; vertical-align: top; }
            .patient-summary .label { color: #64748B; font-weight: 600; width: 18%; }
            .patient-summary .value { color: #1A2332; font-weight: 500; }

            .record-entry { border: 1px solid #e2e8f0; margin-bottom: 14px; page-break-inside: avoid; }
            .record-header { background: #0E5A6B !important; color: white !important; padding: 8px 14px; font-size: 13px; font-weight: 700; display: flex; justify-content: space-between; }
            .record-body { padding: 10px 14px; font-size: 11.5px; line-height: 1.6; }
            .record-body table { width: 100%; border-collapse: collapse; }
            .record-body td { padding: 3px 8px; vertical-align: top; }
            .record-body .field-label { color: #64748B; font-weight: 600; width: 20%; }
            .record-body .field-value { color: #1A2332; }
            .record-divider { border: none; border-top: 1px dashed #d1d5db; margin: 8px 0; }

            .med-table { width: 100%; border-collapse: collapse; font-size: 11px; margin: 8px 0; }
            .med-table th { background: #0E5A6B !important; color: white !important; padding: 6px 10px; text-align: left; font-weight: 600; }
            .med-table td { padding: 5px 10px; border-bottom: 1px solid #e2e8f0; }
            .med-table tr:nth-child(even) { background: #f8fafc !important; }

            .sec-title { font-size: 15px; font-weight: 800; color: #0E5A6B; margin: 22px 0 8px; border-bottom: 2px solid #0E5A6B; padding-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }

            .doc-footer { margin-top: 30px; border-top: 1px solid #cbd5e1; padding-top: 12px; font-size: 10px; color: #94a3b8; text-align: center; }
            .signature-area { display: flex; justify-content: space-between; margin-top: 25px; font-size: 11px; }
            .signature-box { text-align: center; width: 30%; }
            .signature-line { border-top: 1px solid #1A2332; padding-top: 4px; margin-top: 30px; font-weight: 600; color: #1A2332; }

            .status-completed { color: #059669; font-weight: 700; }
            .status-pending { color: #d97706; font-weight: 700; }
            .status-approved { color: #2563eb; font-weight: 700; }
        }
        #print-reports-section { display: none; }

        /* ====== Reports dashboard UI ====== */
        .adm-screen { --ink:#0f172a; --ink2:#475569; --ink3:#94a3b8; --line:#e8edf3; }
        .adm-hero { background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 60%,#3b82f6 100%); border-radius:22px; padding:26px 30px; color:#fff; display:flex; justify-content:space-between; align-items:center; gap:18px; flex-wrap:wrap; position:relative; overflow:hidden; margin-bottom:22px; box-shadow:0 14px 34px rgba(37,99,235,.22); }
        .adm-hero::after { content:'\f201'; font-family:'Font Awesome 6 Free'; font-weight:900; position:absolute; right:24px; bottom:-30px; font-size:150px; opacity:.08; pointer-events:none; }
        .adm-tag { display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.16); border-radius:999px; padding:4px 12px; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .adm-hero h1 { font-size:26px; font-weight:800; margin:10px 0 4px; letter-spacing:-.5px; color:#fff !important; -webkit-text-fill-color:#fff !important; background:none !important; }
        .adm-hero p { margin:0; color:#dbeafe; font-size:13.5px; }
        .adm-print { border:none; background:#fff !important; background-image:none !important; color:#1e3a8a !important; font-weight:800; border-radius:999px; padding:11px 22px; display:inline-flex; gap:8px; align-items:center; box-shadow:0 8px 20px rgba(15,23,42,.2) !important; position:relative; z-index:1; transition:transform .15s; }
        .adm-print:hover { transform:translateY(-2px); }

        .adm-card { background:#fff; border:1px solid #e8edf3; border-radius:18px; padding:20px 22px; box-shadow:0 2px 14px rgba(15,23,42,.04); }
        .adm-card-head { display:flex; align-items:center; gap:12px; margin-bottom:16px; }
        .adm-card-head h5 { font-size:16px; font-weight:800; margin:0; color:#0f172a; }
        .adm-card-head span { font-size:12px; color:#94a3b8; }
        .adm-ic { width:40px; height:40px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
        .adm-ic.sm { width:34px; height:34px; border-radius:10px; font-size:14px; }

        .adm-findings { border-left:4px solid #2563eb; margin-bottom:8px; }
        .adm-kf { display:grid; grid-template-columns:1fr 1fr; gap:8px 18px; }
        .adm-kf-row { display:flex; gap:10px; align-items:flex-start; font-size:13.5px; color:#334155; line-height:1.55; padding:8px 10px; border-radius:12px; background:#f8fafc; }
        .adm-kf-ic { width:26px; height:26px; border-radius:8px; background:#eff6ff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; }
        .adm-note { display:flex; gap:9px; align-items:flex-start; margin-top:14px; padding:10px 12px; border-radius:12px; background:#fffbeb; border:1px solid #fde68a; color:#92400e; font-size:12.5px; }
        .adm-note i { margin-top:3px; }

        .adm-sec { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin:22px 0 10px; display:flex; align-items:center; gap:8px; }
        .adm-sec i { color:#2563eb; }
        .adm-sec span { text-transform:none; letter-spacing:0; font-weight:600; color:#94a3b8; }
        .adm-grid4 { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px; }

        .adm-kpi { background:#fff; border:1px solid #e8edf3; border-radius:16px; padding:16px 18px; box-shadow:0 2px 10px rgba(15,23,42,.04); transition:transform .15s, box-shadow .15s; display:block; color:inherit; text-decoration:none; }
        .adm-kpi:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(15,23,42,.08); color:inherit; }
        .adm-kpi-top { display:flex; justify-content:space-between; align-items:center; gap:10px; }
        .adm-kpi-l { font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; }
        .adm-kpi-v { font-size:26px; font-weight:800; color:#0f172a; letter-spacing:-.5px; margin:8px 0 2px; line-height:1.1; font-variant-numeric:tabular-nums; }
        .adm-kpi-v small { font-size:14px; color:#94a3b8; font-weight:700; }
        .adm-kpi-s { font-size:12.5px; color:#64748b; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .adm-link { color:#2563eb; font-weight:700; }
        .adm-bar { height:6px; background:#eef2f6; border-radius:6px; overflow:hidden; margin-top:10px; }
        .adm-bar span { display:block; height:100%; border-radius:6px; }
        .adm-stars { color:#f59e0b; font-size:12px; letter-spacing:1px; }
        .adm-star-off { color:#e2e8f0 !important; }

        .adm-status { background:#fff; border:1px solid #e8edf3; border-radius:14px; padding:12px 14px; display:flex; gap:12px; align-items:center; font-size:13px; color:#334155; }
        .adm-status b { color:#0f172a; font-size:15px; }
        .adm-status small { display:block; color:#94a3b8; font-size:11.5px; margin-top:1px; }

        .adm-select { width:auto !important; flex:0 0 auto; border:1.5px solid #e2e8f0; border-radius:10px; padding:6px 10px; font-size:13px; font-weight:700; color:#334155; background:#f8fafc; outline:none; }
        .adm-select:focus { border-color:#3b82f6; }
        .adm-desc { display:flex; gap:9px; align-items:flex-start; margin-top:14px; padding-top:12px; border-top:1px solid #f1f5f9; }
        .adm-desc i { color:#2563eb; margin-top:3px; font-size:13px; }
        .adm-desc p { font-size:12.5px; color:#475569; line-height:1.6; margin:0; }

        .adm-fb-list { max-height:360px; overflow-y:auto; margin-right:-6px; padding-right:6px; }
        .adm-fb { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid #f1f5f9; }
        .adm-fb:last-child { border-bottom:none; }
        .adm-fb-av { width:38px; height:38px; border-radius:50%; color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .adm-fb strong { font-size:14px; color:#0f172a; }
        .adm-fb-stars { color:#f59e0b; font-size:12px; letter-spacing:1px; white-space:nowrap; }
        .adm-fb-date { font-size:11.5px; color:#94a3b8; }
        .adm-fb p { font-size:13px; color:#475569; margin:4px 0 0; }
        .adm-empty { text-align:center; color:#94a3b8; padding:40px 10px; }
        .adm-empty i { display:block; font-size:30px; opacity:.4; margin-bottom:8px; }

        .adm-verdict { display:inline-flex; align-items:center; gap:7px; background:#f0fdf4; border:1.5px solid #bbf7d0; color:#15803d; border-radius:999px; padding:5px 12px; font-size:12.5px; font-weight:700; white-space:nowrap; }
        .adm-rd { display:flex; flex-direction:column; gap:12px; padding:6px 0; }
        .adm-rd-row { display:grid; grid-template-columns:120px 1fr 72px; gap:12px; align-items:center; }
        .adm-rd-k { font-weight:800; font-size:14px; color:#0f172a; white-space:nowrap; }
        .adm-rd-k small { font-weight:600; color:#94a3b8; font-size:11.5px; margin-left:2px; }
        .adm-rd-track { height:12px; background:#f1f5f9; border-radius:999px; overflow:hidden; }
        .adm-rd-track span { display:block; height:100%; border-radius:999px; transition:width .6s ease; }
        .adm-rd-n { text-align:right; font-size:13px; color:#0f172a; }
        .adm-rd-n small { color:#94a3b8; margin-left:3px; }

        @media (max-width: 1200px) { .adm-grid4 { grid-template-columns:repeat(2, minmax(0,1fr)); } .adm-kf { grid-template-columns:1fr; } }
        @media (max-width: 576px) { .adm-grid4 { grid-template-columns:1fr; } .adm-hero { padding:20px; } .adm-hero h1 { font-size:21px; } }
        @media print { .adm-screen { display:none !important; } }
    </style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>

<body>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="position-fixed top-0 end-0 p-3" style="z-index: 9999">
            <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show shadow-sm">
                <?php echo $_SESSION['message'];
                unset($_SESSION['message'], $_SESSION['msg_type']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <!-- SIDEBAR -->
    <?php include("includes/admin_sidebar.php"); ?>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="adm-screen">

        <!-- ── HEADER ──────────────────────────────────────────────────────── -->
        <div class="adm-hero">
            <div>
                <span class="adm-tag"><i class="fa fa-chart-line"></i> Reports &amp; Analytics</span>
                <h1>Admin Reports &amp; Analytics Dashboard</h1>
                <p>Descriptive summary of clinic appointments, billing and client feedback recorded in the system · as of <?= date('F j, Y') ?></p>
            </div>
            <button type="button" onclick="window.print()" class="adm-print"><i class="fa fa-print"></i> Print Reports</button>
        </div>

        <!-- ── KEY FINDINGS ───────────────────────────────────────────────── -->
        <div class="adm-card adm-findings">
            <div class="adm-card-head">
                <div class="adm-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-clipboard-list"></i></div>
                <div><h5>Key Findings</h5><span>What the records show at a glance</span></div>
            </div>
            <?php $kf_icons = ['fa-calendar-check', 'fa-peso-sign', 'fa-stethoscope', 'fa-star', 'fa-syringe', 'fa-circle-info']; ?>
            <div class="adm-kf">
                <?php foreach ($key_findings as $i => $kf): ?>
                <div class="adm-kf-row"><span class="adm-kf-ic"><i class="fa <?= $kf_icons[$i] ?? 'fa-circle-info' ?>"></i></span><span><?= htmlspecialchars($kf) ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php if ($excluded_cnt > 0): ?>
            <div class="adm-note">
                <i class="fa fa-filter"></i>
                <span>Billing figures exclude <b><?= $excluded_cnt ?> invoice(s)</b> worth <b><?= $peso($excluded_amt) ?></b>:
                <?= $excluded['missing'][0] ?> linked to bookings that no longer exist,
                <?= $excluded['duplicate'][0] ?> duplicate(s) of the same boarding stay,
                <?= $excluded['cancelled'][0] ?> for cancelled/rejected bookings<?= $excluded['void'][0] ? ', ' . $excluded['void'][0] . ' void' : '' ?>.</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── APPOINTMENTS ───────────────────────────────────────────────── -->
        <div class="adm-sec"><i class="fa fa-calendar-check"></i> Appointments</div>
        <div class="adm-grid4">
            <?php
            $c_pct = $pct($completed_count, $total_appts);
            $x_pct = $pct($cancelled_count + $noshow_count, $total_appts);
            $appt_tiles = [
                ["Today's Appointments", 'Scheduled for today', 'fa-calendar-day', '#2563eb', '#eff6ff', $today_count, null],
                ['Total Appointments', 'All time', 'fa-calendar', '#0e7490', '#ecfeff', $total_appts, null],
                ['Completed', $c_pct . '% of all appointments', 'fa-check-double', '#15803d', '#f0fdf4', $completed_count, $c_pct],
                ['Cancelled / No-Show', $x_pct . '% of all appointments', 'fa-user-slash', '#be123c', '#fff1f2', $cancelled_count + $noshow_count, $x_pct],
            ];
            foreach ($appt_tiles as [$lbl, $sub, $ic, $col, $bg, $val, $bar]): ?>
            <div class="adm-kpi">
                <div class="adm-kpi-top">
                    <span class="adm-kpi-l"><?= htmlspecialchars($lbl) ?></span>
                    <span class="adm-ic sm" style="background:<?= $bg ?>;color:<?= $col ?>;"><i class="fa <?= $ic ?>"></i></span>
                </div>
                <div class="adm-kpi-v"><?= (int)$val ?></div>
                <div class="adm-kpi-s"><?= htmlspecialchars($sub) ?></div>
                <?php if ($bar !== null): ?><div class="adm-bar"><span style="width:<?= min(100, (float)$bar) ?>%;background:<?= $col ?>;"></span></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── BILLING & FEEDBACK ─────────────────────────────────────────── -->
        <div class="adm-sec"><i class="fa fa-file-invoice-dollar"></i> Billing &amp; client feedback <span>· valid invoices, all time</span></div>
        <div class="adm-grid4">
            <a href="admin_billing.php" class="adm-kpi adm-kpi-link">
                <div class="adm-kpi-top"><span class="adm-kpi-l">Total Billed</span><span class="adm-ic sm" style="background:#ecfdf5;color:#059669;"><i class="fa fa-file-invoice-dollar"></i></span></div>
                <div class="adm-kpi-v" style="color:#047857;"><?= $peso($total_billed) ?></div>
                <div class="adm-kpi-s"><?= $total_invoices_cnt ?> invoices · <span class="adm-link">open Billing <i class="fa fa-arrow-right"></i></span></div>
            </a>
            <div class="adm-kpi">
                <div class="adm-kpi-top"><span class="adm-kpi-l">Collected</span><span class="adm-ic sm" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-circle-check"></i></span></div>
                <div class="adm-kpi-v" style="color:#15803d;"><?= $peso($total_collected) ?></div>
                <div class="adm-kpi-s"><?= $collection_rate ?>% of billed</div>
                <div class="adm-bar"><span style="width:<?= min(100, (float)$collection_rate) ?>%;background:#16a34a;"></span></div>
            </div>
            <div class="adm-kpi">
                <div class="adm-kpi-top"><span class="adm-kpi-l">Outstanding</span><span class="adm-ic sm" style="background:#fffbeb;color:#d97706;"><i class="fa fa-hourglass-half"></i></span></div>
                <div class="adm-kpi-v" style="color:#b45309;"><?= $peso($outstanding_balance) ?></div>
                <div class="adm-kpi-s"><?= $unpaid_invoices ?> unpaid invoice<?= $unpaid_invoices !== 1 ? 's' : '' ?></div>
            </div>
            <div class="adm-kpi">
                <div class="adm-kpi-top"><span class="adm-kpi-l">Client Rating</span><span class="adm-ic sm" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-star"></i></span></div>
                <div class="adm-kpi-v" style="color:#1d4ed8;"><?= number_format($avg_rating, 1) ?> <small>/ 5.0</small></div>
                <div class="adm-kpi-s">
                    <span class="adm-stars"><?php for ($st = 1; $st <= 5; $st++): ?><i class="fa fa-star<?= $avg_rating >= $st - 0.25 ? '' : ($avg_rating >= $st - 0.75 ? '-half-stroke' : ' adm-star-off') ?>"></i><?php endfor; ?></span>
                    <?= $feedback_count ?> review<?= $feedback_count !== 1 ? 's' : '' ?>
                </div>
            </div>
        </div>

        <!-- ── STATUS TODAY ───────────────────────────────────────────────── -->
        <div class="adm-sec"><i class="fa fa-bell"></i> Needs attention today</div>
        <div class="adm-grid4">
            <div class="adm-status"><span class="adm-ic sm" style="background:#fffbeb;color:#d97706;"><i class="fa fa-syringe"></i></span>
                <div><b><?= $vacc_overdue ?></b> pet(s) overdue for vaccination<small><?= $vacc_due_week ?> more due within 7 days</small></div></div>
            <div class="adm-status"><span class="adm-ic sm" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-clock-rotate-left"></i></span>
                <div><b><?= $resched_count ?></b> awaiting reschedule reply<small>new dates sent to clients</small></div></div>
            <div class="adm-status"><span class="adm-ic sm" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-hotel"></i></span>
                <div><b><?= $occ_count ?> of <?= $total_cages ?></b> boarding cages occupied<small>as of today</small></div></div>
            <div class="adm-status"><span class="adm-ic sm" style="background:#f1f5f9;color:#475569;"><i class="fa fa-hourglass-start"></i></span>
                <div><b><?= $pending_count ?></b> appointment request(s) pending<small>waiting for vet staff</small></div></div>
        </div>

        <!-- ── BILLING TREND & SERVICE VOLUME ─────────────────────────────── -->
        <div class="row g-4 mt-1">
            <div class="col-xl-7">
                <div class="adm-card h-100">
                    <div class="adm-card-head">
                        <div class="adm-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-chart-column"></i></div>
                        <div class="flex-grow-1"><h5>Billed vs Collected</h5><span>Peso amounts on valid invoices</span></div>
                        <select id="timeFilter" class="adm-select">
                            <option value="month" selected>Per Month (<?= $current_year ?>)</option>
                            <option value="week">Per Week (last 5)</option>
                        </select>
                    </div>
                    <div style="position: relative; height: 300px; width: 100%;">
                        <div class="chart-swipe" style="width: 100%; height: 100%;"><canvas id="revenueChart"></canvas></div>
                    </div>
                    <div class="adm-desc"><i class="fa fa-circle-info"></i><p id="performanceSummary"><?= htmlspecialchars($desc_billing) ?></p></div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="adm-card h-100">
                    <div class="adm-card-head">
                        <div class="adm-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-chart-pie"></i></div>
                        <div><h5>Service Volume Breakdown</h5><span>Confirmed &amp; completed services, all time</span></div>
                    </div>
                    <div style="position: relative; height: 300px; width: 100%;">
                        <canvas id="typeChart"></canvas>
                    </div>
                    <div class="adm-desc"><i class="fa fa-circle-info"></i><p id="appointmentSummary"><?= htmlspecialchars($desc_services) ?></p></div>
                </div>
            </div>
        </div>

        <!-- ── CLIENT FEEDBACK & RATING DISTRIBUTION ──────────────────────── -->
        <div class="row g-4 mt-1">
            <div class="col-xl-6">
                <div class="adm-card h-100">
                    <div class="adm-card-head">
                        <div class="adm-ic" style="background:#ecfeff;color:#0e7490;"><i class="fa fa-comments"></i></div>
                        <div><h5>Client Feedback Stream</h5><span>Latest reviews from pet owners</span></div>
                    </div>
                    <?php if ($recent_feedback && $recent_feedback->num_rows > 0): ?>
                        <div class="adm-fb-list">
                            <?php $fb_cols = ['#2563eb', '#7c3aed', '#0e7490', '#ea580c', '#15803d', '#be123c']; $fi = 0;
                            while ($row = $recent_feedback->fetch_assoc()): $fi++; $r = (int)$row['rating']; ?>
                                <div class="adm-fb">
                                    <span class="adm-fb-av" style="background:<?= $fb_cols[$fi % count($fb_cols)] ?>;"><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($row['client_name']), 0, 1))) ?></span>
                                    <div class="flex-grow-1" style="min-width:0;">
                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                            <strong><?php echo htmlspecialchars($row['client_name']); ?></strong>
                                            <span class="adm-fb-stars" title="<?= $r ?> of 5"><?php for ($st = 1; $st <= 5; $st++): ?><i class="fa fa-star<?= $st <= $r ? '' : ' adm-star-off' ?>"></i><?php endfor; ?></span>
                                        </div>
                                        <?php if (!empty($row['created_at'])): ?><div class="adm-fb-date"><?= date('M j, Y', strtotime($row['created_at'])) ?></div><?php endif; ?>
                                        <p><?php echo trim((string)$row['comments']) !== '' ? '“' . htmlspecialchars($row['comments']) . '”' : '<span class="fst-italic">No comment left</span>'; ?></p>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="adm-empty"><i class="fa fa-comment-slash"></i>No reviews recorded yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="adm-card h-100">
                    <div class="adm-card-head">
                        <div class="adm-ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-ranking-star"></i></div>
                        <div class="flex-grow-1"><h5>Feedback Rating Distribution</h5><span>Number of reviews per star rating</span></div>
                        <span class="adm-verdict"><i class="fa fa-circle-check"></i>
                            <?php echo number_format($computed_avg, 1); ?> / 5 ·
                            <?php
                                if ($computed_avg >= 4.5)      echo 'Excellent';
                                elseif ($computed_avg >= 3.5)  echo 'Very Satisfactory';
                                elseif ($computed_avg >= 2.5)  echo 'Satisfactory';
                                elseif ($computed_avg >= 1.5)  echo 'Fair';
                                else                           echo 'Poor';
                            ?>
                        </span>
                    </div>
                    <?php
                    $rd_total = array_sum($rating_dist);
                    $rd_col = [1 => '#ef4444', 2 => '#f97316', 3 => '#f59e0b', 4 => '#22c55e', 5 => '#15803d'];
                    $rd_lbl = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent'];
                    ?>
                    <div class="adm-rd">
                        <?php foreach ([5, 4, 3, 2, 1] as $rs): $rc = (int)($rating_dist[$rs] ?? 0); $rp = $rd_total ? round($rc * 100 / $rd_total) : 0; ?>
                        <div class="adm-rd-row">
                            <span class="adm-rd-k"><?= $rs ?> <i class="fa fa-star" style="color:<?= $rd_col[$rs] ?>;"></i> <small><?= $rd_lbl[$rs] ?></small></span>
                            <span class="adm-rd-track"><span style="width:<?= $max_rating_count ? round($rc * 100 / $max_rating_count) : 0 ?>%;background:<?= $rd_col[$rs] ?>;"></span></span>
                            <span class="adm-rd-n"><b><?= $rc ?></b> <small><?= $rp ?>%</small></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="adm-desc"><i class="fa fa-circle-info"></i><p id="feedbackSummary"><?= htmlspecialchars($desc_feedback) ?></p></div>
                </div>
            </div>
        </div>

        <!-- ── 30-DAY TREND & PEAK HOURS ──────────────────────────────────── -->
        <div class="row g-4 mt-1">
            <div class="col-xl-6">
                <div class="adm-card h-100">
                    <div class="adm-card-head">
                        <div class="adm-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-chart-line"></i></div>
                        <div><h5>Appointment Volume</h5><span>Last 30 days, cancelled excluded</span></div>
                    </div>
                    <canvas id="trendChart" height="110"></canvas>
                    <div class="adm-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc_trend) ?></p></div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="adm-card h-100">
                    <div class="adm-card-head">
                        <div class="adm-ic" style="background:#fff7ed;color:#ea580c;"><i class="fa fa-clock"></i></div>
                        <div><h5>Appointments by Hour</h5><span>Last 90 days · busiest hour highlighted</span></div>
                    </div>
                    <canvas id="peakChart" height="110"></canvas>
                    <div class="adm-desc"><i class="fa fa-circle-info"></i><p><?= htmlspecialchars($desc_peak) ?></p></div>
                </div>
            </div>
        </div>

        </div><!-- /adm-screen -->

        <!-- ============ VETERINARY MEDICAL DOCUMENT (SHOWS ONLY WHEN PRINTING) ============ -->
        <div id="print-reports-section">
            <div class="doc-header">
                <div style="float:left; width:60px; height:60px; background:#0E5A6B; border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; font-size:28px; margin-right:15px;">
                    <i class="fa fa-paw"></i>
                </div>
                <div class="doc-ref">
                    Report No: <strong>VET-<?php echo date('Ymd-His'); ?></strong><br>
                    Date: <?php echo date('F d, Y'); ?>
                </div>
                <h1>PragmaVet Animal Clinic</h1>
                <p>0234 Unit B Palico St, Palico 4, Imus, Cavite, Philippines</p>
                <p>Tel: 046-8851-221 &bull; 0954-285-3053 &bull; Email: pragmavet@gmail.com</p>
            </div>
            <div class="doc-title-wrapper">
                <div class="doc-title">VETERINARY CLINICAL & FINANCIAL REPORT</div>
                <p style="font-size:12px; color:#64748b; margin-top:5px;">Executive Practice Summary & Consultation History</p>
            </div>

            <!-- Key Findings -->
            <div class="sec-title">I. Key Findings</div>
            <ul style="font-size:11.5px;line-height:1.7;margin:0 0 18px;padding-left:18px;">
                <?php foreach ($key_findings as $kf): ?><li><?= htmlspecialchars($kf) ?></li><?php endforeach; ?>
            </ul>

            <!-- Appointment Summary -->
            <div class="sec-title">II. Appointment Summary (All Time)</div>
            <table class="med-table" style="margin-bottom:18px;"><thead><tr><th>Status</th><th style="text-align:right;">Count</th><th style="text-align:right;">Share</th></tr></thead><tbody>
                <?php foreach (['Completed' => $completed_count, 'Approved / In Progress' => $active_count, 'Pending' => $pending_count, 'Reschedule in Progress' => $resched_count, 'Cancelled / Rejected' => $cancelled_count, 'No Show' => $noshow_count] as $lbl => $cnt): ?>
                <tr><td><?= $lbl ?></td><td style="text-align:right;"><?= $cnt ?></td><td style="text-align:right;"><?= $pct($cnt, $total_appts) ?>%</td></tr>
                <?php endforeach; ?>
                <tr><td><strong>Total</strong></td><td style="text-align:right;"><strong><?= $total_appts ?></strong></td><td style="text-align:right;">100%</td></tr>
            </tbody></table>

            <!-- Financial Summary Statement -->
            <div class="sec-title">III. Billing & Collections Summary</div>
            <div style="page-break-inside:avoid;margin-bottom:22px;">
                <table style="width:100%;border-collapse:collapse;font-size:11px;font-family:'Courier New',monospace;">
                    <tr>
                        <td style="padding:5px 10px;border-bottom:1px solid #e2e8f0;width:60%;font-weight:600;color:#475569;">Total Billed (valid invoices, all time)</td>
                        <td style="padding:5px 10px;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:700;font-size:13px;">₱ <?php echo number_format($total_billed,2);?></td>
                    </tr>
                    <tr>
                        <td style="padding:5px 10px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#059669;">Total Collected (<?= $collection_rate ?>% of billed)</td>
                        <td style="padding:5px 10px;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:700;color:#059669;">₱ <?php echo number_format($total_collected,2);?></td>
                    </tr>
                    <tr>
                        <td style="padding:5px 10px;border-bottom:2px solid #1A2332;font-weight:600;color:#d97706;">Outstanding (<?= $unpaid_invoices ?> unpaid invoice(s))</td>
                        <td style="padding:5px 10px;border-bottom:2px solid #1A2332;text-align:right;font-weight:700;color:#d97706;">₱ <?php echo number_format($outstanding_balance,2);?></td>
                    </tr>
                </table>
                <p style="font-size:10.5px;color:#475569;margin:6px 10px 0;"><?= htmlspecialchars($desc_billing) ?></p>
                <?php if ($excluded_cnt > 0): ?>
                <p style="font-size:9.5px;color:#94a3b8;margin:4px 10px 0;">Excludes <?= $excluded_cnt ?> invoice(s) worth <?= $peso($excluded_amt) ?> (missing bookings, duplicate boarding bills, cancelled/rejected bookings, void).</p>
                <?php endif; ?>
            </div>

            <!-- Service Volume -->
            <div class="sec-title">IV. Service Volume (Confirmed & Completed)</div>
            <table class="med-table" style="margin-bottom:6px;"><thead><tr><th>Service</th><th style="text-align:right;">Count</th><th style="text-align:right;">Share</th></tr></thead><tbody>
                <?php foreach ($appt_labels as $i => $lbl): ?>
                <tr><td><?= htmlspecialchars($lbl) ?></td><td style="text-align:right;"><?= $appt_data[$i] ?></td><td style="text-align:right;"><?= $pct($appt_data[$i], $service_total) ?>%</td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <p style="font-size:10.5px;color:#475569;margin:0 0 18px;"><?= htmlspecialchars($desc_services) ?></p>

            <!-- Client Feedback Section in Print -->
            <div class="sec-title">V. Client Satisfaction & Ratings</div>
            <p style="font-size:10.5px;color:#475569;margin:0 0 6px;"><?= htmlspecialchars($desc_feedback) ?></p>
            <?php $pf = $conn->query("SELECT f.*, u.fullname as client_name FROM feedbacks f JOIN users u ON f.user_id = u.id ORDER BY f.created_at DESC LIMIT 10"); ?>
            <?php if ($pf && $pf->num_rows > 0): ?>
            <table class="med-table"><thead><tr><th>Client</th><th>Rating</th><th>Comment</th><th>Date</th></tr></thead><tbody>
            <?php while($fb = $pf->fetch_assoc()): ?>
            <tr><td><strong><?php echo htmlspecialchars($fb['client_name']); ?></strong></td><td><span style="background:<?php echo $fb['rating']>=4?'#059669':($fb['rating']>=3?'#d97706':'#dc2626');?>;color:white;padding:1px 8px;border-radius:10px;font-weight:700;font-size:11px;"><?php echo $fb['rating'];?>.0</span></td><td style="font-style:italic;"><?php echo $fb['comments']?'"'.htmlspecialchars($fb['comments']).'"':'<span style="color:#94a3b8;">—</span>';?></td><td><?php echo date('M d, Y', strtotime($fb['created_at']));?></td></tr>
            <?php endwhile; ?>
            </tbody></table>
            <?php endif; ?>

            <div class="doc-footer">
                <div class="signature-area">
                    <div class="signature-box"><div class="signature-line">Attending Veterinarian</div><p style="font-size:10px;color:#64748b;margin-top:4px;">License No.: ______________</p></div>
                    <div class="signature-box"><div class="signature-line">Clinic Administrator</div><p style="font-size:10px;color:#64748b;margin-top:4px;">Authorized Signature</p></div>
                    <div class="signature-box"><div class="signature-line">Date Issued</div><p style="font-size:11px;color:#1A2332;margin-top:4px;"><?php echo date('F d, Y'); ?></p></div>
                </div>
                <p style="margin-top:15px;"><strong>PragmaVet Veterinary Management System</strong> — Report VET-<?php echo date('Ymd-His'); ?> | <?php echo date('F d, Y \a\t h:i A'); ?></p>
                <p>&copy; <?php echo date('Y'); ?> PragmaVet Animal Clinic. All rights reserved.</p>
            </div>
        </div>

    </div><!-- /main-content -->

    <!-- Chart JS Implementation -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const monthlyLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const monthlyBilled = <?php echo json_encode(array_values($monthly_billed)); ?>;
        const monthlyCollected = <?php echo json_encode(array_values($monthly_collected)); ?>;
        const weeklyLabels = <?php echo json_encode($weekly_labels); ?>;
        const weeklyBilled = <?php echo json_encode($weekly_billed); ?>;
        const weeklyCollected = <?php echo json_encode($weekly_collected); ?>;

        const apptLabels = <?php echo json_encode($appt_labels ?: ['No confirmed services yet']); ?>;
        const apptData = <?php echo json_encode($appt_data ?: [1]); ?>;

        // Shared chart look
        Chart.defaults.font.family = 'Inter, sans-serif';
        Chart.defaults.color = '#64748b';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 10;

        // Billed vs Collected
        const ctx = document.getElementById('revenueChart').getContext('2d');
        let revenueChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [
                    { label: 'Billed', data: monthlyBilled, backgroundColor: '#2a78d6', borderRadius: 6, maxBarThickness: 26 },
                    { label: 'Collected', data: monthlyCollected, backgroundColor: '#1baf7a', borderRadius: 6, maxBarThickness: 26 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top', align: 'end' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ₱' + Number(c.raw).toLocaleString(undefined, {minimumFractionDigits: 2}) } } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef2f6' }, ticks: { callback: v => '₱' + Number(v).toLocaleString() } }
                }
            }
        });

        // Toggle Month / Week
        document.getElementById('timeFilter').addEventListener('change', function() {
            const weekly = this.value === 'week';
            revenueChart.data.labels = weekly ? weeklyLabels : monthlyLabels;
            revenueChart.data.datasets[0].data = weekly ? weeklyBilled : monthlyBilled;
            revenueChart.data.datasets[1].data = weekly ? weeklyCollected : monthlyCollected;
            revenueChart.update();
        });

        // Appointment Doughnut
        new Chart(document.getElementById('typeChart'), {
            type: 'doughnut',
            data: {
                labels: apptLabels,
                datasets: [{
                    data: apptData,
                    backgroundColor: ['#2563eb','#7c3aed','#0891b2','#ea580c','#15803d','#be123c','#d97706','#4f46e5'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: { legend: { position: 'right', labels: { padding: 12, font: { size: 12 } } } }
            }
        });

        // 30-Day Trend
        const TREND_LABELS = <?=json_encode($trend_labels)?>;
        const TREND_DATA   = <?=json_encode(array_values($trend_data))?>;
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: TREND_LABELS,
                datasets:[{
                    label:'Appointments',
                    data: TREND_DATA,
                    borderColor:'#2563eb',
                    backgroundColor:'rgba(37,99,235,.08)',
                    fill:true,
                    tension:.4,
                    pointRadius:2,
                    borderWidth:2
                }]
            },
            options:{
                responsive:true,
                plugins:{legend:{display:false}},
                scales:{
                    x:{ticks:{font:{size:9},maxTicksLimit:8},grid:{display:false}},
                    y:{ticks:{font:{size:10},stepSize:1,precision:0},grid:{color:'rgba(0,0,0,.04)'}}
                }
            }
        });

        // Charts re-measure once the entrance animation has finished (keeps them full width)
        window.addEventListener('load', function () { setTimeout(function () { Object.values(Chart.instances).forEach(function (c) { c.resize(); }); }, 700); });

        // Peak Hours Bar
        const PEAK_DATA = <?=json_encode(array_values($peak_hours))?>;
        const peakLabels = [], peakVals = [];
        for (let h=7; h<=19; h++) {
            peakLabels.push(h<12?h+'am':(h===12?'12pm':(h-12)+'pm'));
            peakVals.push(PEAK_DATA[h]||0);
        }
        new Chart(document.getElementById('peakChart'), {
            type: 'bar',
            data:{
                labels: peakLabels,
                datasets:[{
                    label:'Visits',
                    data: peakVals,
                    backgroundColor: peakVals.map(v => v===Math.max(...peakVals)?'#ea580c':'rgba(234,88,12,.25)'),
                    borderRadius:6
                }]
            },
            options:{
                responsive:true,
                plugins:{legend:{display:false}},
                scales:{
                    x:{ticks:{font:{size:9}},grid:{display:false}},
                    y:{ticks:{font:{size:10},stepSize:1,precision:0},grid:{color:'rgba(0,0,0,.04)'}}
                }
            }
        });
    </script>
</body>

</html>