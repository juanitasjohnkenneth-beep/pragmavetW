<?php
session_start();
include("dbconnection.php");
include_once("includes/report_data.php");

// Admin dashboard: what is happening at the clinic today. Period reports and charts live in Reports & Analytics.
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_owner = ($user_role === 'clinic_owner' || $user_role === 'owner');
if (!isset($_SESSION['user_id']) || (!$is_admin && !$is_owner)) {
    header("Location: " . ($user_role === 'doctor' ? 'doctor.php' : ($user_role === 'staff' ? 'staff_dashboard.php' : 'index.php')));
    exit();
}

$today = date('Y-m-d');
$peso = fn($v) => '₱' . number_format((float)$v, 2);
$cnt = fn($sql) => (int)($conn->query($sql)->fetch_assoc()['c'] ?? 0);

// ── Today's appointments ──
$today_rows = [];
$q = $conn->query("SELECT a.id, a.appointment_time, a.pet_name, a.service_type, a.status, COALESCE(u.fullname, 'Walk-in Client') AS client, COALESCE(d.fullname, 'Unassigned') AS vet
                   FROM appointments a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN users d ON d.id = a.doctor_id
                   WHERE a.appointment_date = '$today' AND a.service_type <> 'Pet Boarding Reservation'
                   ORDER BY a.appointment_time ASC");
if ($q) while ($r = $q->fetch_assoc()) $today_rows[] = $r;
$T = ['booked' => 0, 'done' => 0, 'in_clinic' => 0, 'waiting' => 0];
foreach ($today_rows as $r) {
    if (in_array($r['status'], ['Cancelled', 'Rejected'], true)) continue;
    $T['booked']++;
    if ($r['status'] === 'Completed') $T['done']++;
    elseif (in_array($r['status'], ['Checked In', 'In Progress'], true)) $T['in_clinic']++;
    elseif (in_array($r['status'], ['Approved', 'Pending', 'Reschedule Proposed', 'Reschedule Requested'], true)) $T['waiting']++;
}

// ── Requests waiting for the vet staff ──
$pending_appts  = $cnt("SELECT COUNT(*) c FROM appointments WHERE status = 'Pending' AND service_type <> 'Pet Boarding Reservation'");
$resched_appts  = $cnt("SELECT COUNT(*) c FROM appointments WHERE status IN ('Reschedule Proposed', 'Reschedule Requested')");
$pending_board  = $cnt("SELECT COUNT(*) c FROM boarding_reservations WHERE status = 'Pending'");
$pending_certs  = $cnt("SELECT COUNT(*) c FROM certificate_requests WHERE status = 'Pending'");

// ── Boarding today ──
$cage_count = (int)($conn->query("SELECT setting_value FROM clinic_settings WHERE setting_key = 'boarding_cage_count'")->fetch_assoc()['setting_value'] ?? 5) ?: 5;
$in_house = [];
$q = $conn->query("SELECT br.pet_name, br.cage_number, br.appointment_date, br.end_date, br.status, COALESCE(u.fullname, 'Client') AS client
                   FROM boarding_reservations br LEFT JOIN users u ON u.id = br.user_id
                   WHERE br.status = 'Checked In' OR (br.status = 'Approved' AND br.appointment_date = '$today')
                   ORDER BY br.status = 'Checked In' DESC, br.end_date ASC");
if ($q) while ($r = $q->fetch_assoc()) $in_house[] = $r;
$boarding_now = count(array_filter($in_house, fn($r) => $r['status'] === 'Checked In'));
$arriving     = count(array_filter($in_house, fn($r) => $r['status'] === 'Approved'));
$leaving      = count(array_filter($in_house, fn($r) => $r['status'] === 'Checked In' && $r['end_date'] === $today));
$overstay     = count(array_filter($in_house, fn($r) => $r['status'] === 'Checked In' && $r['end_date'] < $today));

// ── Money received today and still unpaid (valid invoices only, same rules as the reports) ──
[$valid_bills] = rpt_valid_bills($conn);
$collected_today = 0.0; $paid_today_n = 0; $owed_total = 0.0; $owed_n = 0;
foreach ($valid_bills as $b) {
    $owed_total += $b['_owed']; if ($b['_owed'] > 0) $owed_n++;
    $dp = (float)$b['downpayment_amount'];
    $dp_paid = in_array($b['downpayment_status'], ['Paid', 'Partial'], true);
    $dp_date = substr((string)($b['downpayment_paid_at'] ?? ''), 0, 10);
    $got = 0.0;
    if ($b['payment_status'] === 'Paid' && substr((string)$b['paid_at'], 0, 10) === $today) {
        $got = $b['_paid'] - (($dp_paid && $dp_date !== '' && $dp_date < $today) ? min($dp, $b['_paid']) : 0); // a deposit paid on an earlier day is not today's money
    } elseif ($b['payment_status'] !== 'Paid' && $dp_paid && $dp_date === $today) {
        $got = min($dp, $b['_paid']);
    }
    if ($got > 0) { $collected_today += $got; $paid_today_n++; }
}

// ── Things that need someone's attention ──
$vacc_overdue = $cnt("SELECT COUNT(*) c FROM pets WHERE deleted_at IS NULL AND next_vaccination IS NOT NULL AND next_vaccination < CURDATE()");
$vacc_soon    = $cnt("SELECT COUNT(*) c FROM pets WHERE deleted_at IS NULL AND next_vaccination BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY");
$stock_low    = $cnt("SELECT COUNT(*) c FROM inventory WHERE quantity <= COALESCE(reorder_level, 10)");
$stock_out    = $cnt("SELECT COUNT(*) c FROM inventory WHERE quantity <= 0");
$stock_exp    = $cnt("SELECT COUNT(*) c FROM inventory WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date <> '1970-01-01' AND expiry_date < CURDATE()");
$unverified   = $cnt("SELECT COUNT(*) c FROM users WHERE is_verified = 0");
$attention = [
    ['fa-calendar-plus', '#2563eb', '#eff6ff', $pending_appts, 'appointment request(s) waiting for vet staff approval', 'admin_list.php'],
    ['fa-clock-rotate-left', '#7c3aed', '#f5f3ff', $resched_appts, 'reschedule(s) waiting for a reply', 'admin_list.php'],
    ['fa-hotel', '#0891b2', '#ecfeff', $pending_board, 'boarding request(s) waiting for approval', 'admin_boarding.php'],
    ['fa-triangle-exclamation', '#be123c', '#fff1f2', $overstay, 'pet(s) still boarding past their check-out date', 'admin_boarding.php'],
    ['fa-file-medical', '#0e7490', '#ecfeff', $pending_certs, 'certificate request(s) waiting for vet review', 'admin_certificates.php'],
    ['fa-syringe', '#d97706', '#fffbeb', $vacc_overdue, "pet(s) overdue for vaccination ($vacc_soon more due within 7 days)", 'admin_list.php'],
    ['fa-box-open', '#be123c', '#fff1f2', $stock_low, "item(s) low on stock ($stock_out out of stock)", 'admin_inventory.php'],
    ['fa-calendar-xmark', '#b45309', '#fffbeb', $stock_exp, 'expired item(s) still in stock', 'admin_inventory.php'],
    ['fa-file-invoice-dollar', '#b45309', '#fffbeb', $owed_n, 'invoice(s) with an unpaid balance (' . $peso($owed_total) . ')', 'admin_billing.php?status=unpaid'],
    ['fa-user-clock', '#475569', '#f1f5f9', $unverified, 'account(s) not yet verified', 'admin_users.php'],
];
usort($attention, fn($a, $b) => ($b[3] > 0) <=> ($a[3] > 0));
$attention_open = count(array_filter($attention, fn($a) => $a[3] > 0));

$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first_name = trim($_SESSION['user'] ?? '') ?: 'Admin';
$status_cls = function ($s) {
    if ($s === 'Completed') return 'ok';
    if (in_array($s, ['Approved', 'Checked In', 'In Progress'], true)) return 'info';
    if (in_array($s, ['Cancelled', 'Rejected', 'No Show'], true)) return 'bad';
    return 'warn';
};
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

        .db-hero { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 60%, #3b82f6 100%); border-radius: 22px; padding: 24px 28px; color: #fff; display: flex; justify-content: space-between; align-items: center; gap: 18px; flex-wrap: wrap; position: relative; overflow: hidden; margin-bottom: 20px; box-shadow: 0 14px 34px rgba(37,99,235,.2); }
        .db-hero::after { content: '\f1b0'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 26px; bottom: -34px; font-size: 140px; opacity: .08; pointer-events: none; }
        .db-tag { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.16); border-radius: 99px; padding: 4px 12px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .db-hero h1 { font-size: 25px; font-weight: 800; margin: 10px 0 3px; letter-spacing: -.4px; color: #fff !important; -webkit-text-fill-color: #fff !important; background: none !important; }
        .db-hero p { margin: 0; color: #dbeafe; font-size: 13.5px; }
        .db-hero-btn { position: relative; z-index: 1; background: #fff !important; color: #1e3a8a !important; border: 0 !important; border-radius: 99px; padding: 11px 20px; font-weight: 800; font-size: 13.5px; display: inline-flex; gap: 8px; align-items: center; text-decoration: none; box-shadow: 0 8px 20px rgba(15,23,42,.2); transition: transform .15s; }
        .db-hero-btn:hover { transform: translateY(-2px); color: #1e3a8a !important; }

        .db-grid4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .db-kpi { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 16px 18px; box-shadow: 0 2px 10px rgba(15,23,42,.03); display: block; color: inherit; text-decoration: none; transition: transform .15s, box-shadow .15s; }
        .db-kpi:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(15,23,42,.07); color: inherit; }
        .db-kpi-top { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .db-kpi-l { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .04em; }
        .db-ic { width: 36px; height: 36px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
        .db-kpi-v { font-size: 27px; font-weight: 800; color: var(--ink); letter-spacing: -.5px; margin: 8px 0 2px; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .db-kpi-v small { font-size: 14px; color: #94a3b8; font-weight: 700; }
        .db-kpi-s { font-size: 12.5px; color: #64748b; }

        .db-card { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 18px 20px; box-shadow: 0 2px 10px rgba(15,23,42,.03); height: 100%; }
        .db-card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .db-card-head h5 { font-size: 15.5px; font-weight: 800; margin: 0; color: var(--ink); }
        .db-card-head .hint { font-size: 12px; color: #94a3b8; }
        .db-more { margin-left: auto; font-size: 12.5px; font-weight: 700; color: var(--blue); text-decoration: none; white-space: nowrap; }
        .db-more:hover { text-decoration: underline; }
        .db-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .db-table th { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #64748b; background: #f8fafc; padding: 9px 12px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
        .db-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .db-table tr:last-child td { border-bottom: 0; }
        .db-time { font-weight: 800; color: var(--ink); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .db-st { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
        .db-st.ok { background: #dcfce7; color: #166534; } .db-st.info { background: #dbeafe; color: #1d4ed8; } .db-st.bad { background: #fee2e2; color: #991b1b; } .db-st.warn { background: #fef3c7; color: #92400e; }
        .db-scroll { max-height: 400px; overflow-y: auto; }
        .db-scroll .db-table th { position: sticky; top: 0; z-index: 1; }
        .db-empty { text-align: center; color: #94a3b8; padding: 32px 10px; font-size: 13.5px; }
        .db-empty i { display: block; font-size: 26px; opacity: .45; margin-bottom: 8px; }

        .db-att { display: flex; align-items: center; gap: 12px; padding: 10px 8px; border-radius: 12px; text-decoration: none; color: #334155; font-size: 13.5px; }
        .db-att:hover { background: #f8fafc; color: #0f172a; }
        .db-att b { font-size: 16px; color: var(--ink); min-width: 22px; display: inline-block; }
        .db-att .go { margin-left: auto; color: #cbd5e1; font-size: 12px; }
        .db-att:hover .go { color: var(--blue); }
        .db-att.clear { opacity: .5; }
        .db-allclear { display: flex; align-items: center; gap: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 12px; padding: 10px 12px; font-size: 13px; font-weight: 600; margin-bottom: 6px; }

        .db-pet { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .db-pet:last-child { border-bottom: 0; }
        .db-cage { width: 46px; height: 40px; border-radius: 11px; background: #eff6ff; color: #1d4ed8; font-weight: 800; font-size: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.05; flex-shrink: 0; }
        .db-cage small { font-size: 9px; font-weight: 700; color: #60a5fa; text-transform: uppercase; }
        .db-pet strong { font-size: 14px; color: var(--ink); }
        .db-pet .sub { font-size: 12px; color: #64748b; }
        .db-pet .tag { margin-left: auto; }
        .db-links { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .db-link { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 14px; text-decoration: none; color: #334155; font-weight: 700; font-size: 13.5px; transition: border-color .15s, background .15s; }
        .db-link:hover { border-color: #bfdbfe; background: #f8fbff; color: var(--blue-d); }
        .db-link small { display: block; font-weight: 500; color: #94a3b8; font-size: 11.5px; }
        @media (max-width: 1200px) { .db-grid4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 992px) { .main-content { margin-left: 0 !important; width: 100% !important; padding: 18px 16px !important; } }
        @media (max-width: 576px) { .db-grid4, .db-links { grid-template-columns: 1fr; } .db-hero { padding: 20px; } .db-hero h1 { font-size: 21px; } }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['message'])): ?>
        <div class="position-fixed top-0 end-0 p-3" style="z-index: 9999">
            <div class="alert alert-<?= htmlspecialchars($_SESSION['msg_type'] ?? 'info') ?> alert-dismissible fade show shadow-sm">
                <?= htmlspecialchars($_SESSION['message']); unset($_SESSION['message'], $_SESSION['msg_type']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <?php include("includes/admin_sidebar.php"); ?>

    <div class="main-content">
        <div class="db-hero">
            <div>
                <span class="db-tag"><i class="fa fa-gauge-high"></i> Admin Dashboard</span>
                <h1><?= $greet ?>, <?= htmlspecialchars($first_name) ?></h1>
                <p>Here is what is happening at the clinic today · <?= date('l, F j, Y') ?></p>
            </div>
            <a href="admin_reports.php" class="db-hero-btn"><i class="fa fa-chart-pie"></i> Reports &amp; Analytics</a>
        </div>

        <div class="db-grid4">
            <a class="db-kpi" href="admin_list.php">
                <div class="db-kpi-top"><span class="db-kpi-l">Appointments Today</span><span class="db-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-calendar-day"></i></span></div>
                <div class="db-kpi-v"><?= $T['booked'] ?></div>
                <div class="db-kpi-s"><?= $T['done'] ?> done · <?= $T['in_clinic'] ?> in the clinic · <?= $T['waiting'] ?> to come</div>
            </a>
            <a class="db-kpi" href="admin_list.php">
                <div class="db-kpi-top"><span class="db-kpi-l">Waiting for Approval</span><span class="db-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-hourglass-half"></i></span></div>
                <div class="db-kpi-v"><?= $pending_appts + $pending_board ?></div>
                <div class="db-kpi-s"><?= $pending_appts ?> appointment(s) · <?= $pending_board ?> boarding request(s)</div>
            </a>
            <a class="db-kpi" href="admin_boarding.php">
                <div class="db-kpi-top"><span class="db-kpi-l">Boarding Now</span><span class="db-ic" style="background:#ecfeff;color:#0e7490;"><i class="fa fa-hotel"></i></span></div>
                <div class="db-kpi-v"><?= $boarding_now ?> <small>/ <?= $cage_count ?> cages</small></div>
                <div class="db-kpi-s"><?= $arriving ?> arriving · <?= $leaving ?> leaving today</div>
            </a>
            <a class="db-kpi" href="admin_billing.php">
                <div class="db-kpi-top"><span class="db-kpi-l">Collected Today</span><span class="db-ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-hand-holding-dollar"></i></span></div>
                <div class="db-kpi-v" style="color:#15803d;"><?= $peso($collected_today) ?></div>
                <div class="db-kpi-s"><?= $paid_today_n ?> payment(s) received by the vet staff</div>
            </a>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-8">
                <div class="db-card">
                    <div class="db-card-head">
                        <span class="db-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-list-check"></i></span>
                        <div><h5>Today's Schedule</h5><span class="hint"><?= count($today_rows) ?> appointment(s) on <?= date('M j') ?></span></div>
                        <a class="db-more" href="admin_list.php">Appointment Monitoring <i class="fa fa-arrow-right"></i></a>
                    </div>
                    <?php if ($today_rows): ?>
                    <div class="db-scroll">
                        <table class="db-table">
                            <thead><tr><th>Time</th><th>Pet &amp; Client</th><th>Service</th><th>Veterinarian</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($today_rows as $r): ?>
                                <tr>
                                    <td class="db-time"><?= $r['appointment_time'] ? date('g:i A', strtotime($r['appointment_time'])) : '—' ?></td>
                                    <td><div class="fw-bold"><?= htmlspecialchars($r['pet_name']) ?></div><div style="font-size:12px;color:#64748b;"><?= htmlspecialchars($r['client']) ?></div></td>
                                    <td><?= htmlspecialchars($r['service_type']) ?></td>
                                    <td><?= htmlspecialchars($r['vet']) ?></td>
                                    <td><span class="db-st <?= $status_cls($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="db-empty"><i class="fa fa-calendar-check"></i>No appointments scheduled for today.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="db-card">
                    <div class="db-card-head">
                        <span class="db-ic" style="background:#fffbeb;color:#d97706;"><i class="fa fa-bell"></i></span>
                        <div><h5>Needs Attention</h5><span class="hint"><?= $attention_open ?> item(s) to follow up</span></div>
                    </div>
                    <?php if (!$attention_open): ?><div class="db-allclear"><i class="fa fa-circle-check"></i> All clear — nothing needs attention right now.</div><?php endif; ?>
                    <?php $clear_n = 0; foreach ($attention as [$ic, $c, $bg, $n, $text, $link]): if (!$n) { $clear_n++; continue; } ?>
                        <a class="db-att" href="<?= $link ?>">
                            <span class="db-ic" style="width:32px;height:32px;font-size:13px;background:<?= $bg ?>;color:<?= $c ?>;"><i class="fa <?= $ic ?>"></i></span>
                            <span><b><?= $n ?></b> <?= htmlspecialchars($text) ?></span>
                            <i class="fa fa-chevron-right go"></i>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($clear_n && $attention_open): ?><div class="db-allclear mt-2" style="margin-bottom:0;"><i class="fa fa-circle-check"></i> <?= $clear_n ?> other check(s) are clear.</div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-6">
                <div class="db-card">
                    <div class="db-card-head">
                        <span class="db-ic" style="background:#ecfeff;color:#0e7490;"><i class="fa fa-paw"></i></span>
                        <div><h5>Boarding Today</h5><span class="hint">Pets staying now and arrivals today</span></div>
                        <a class="db-more" href="admin_boarding.php">Boarding Monitoring <i class="fa fa-arrow-right"></i></a>
                    </div>
                    <?php if ($in_house): ?>
                        <div class="db-scroll" style="max-height:320px;">
                        <?php foreach ($in_house as $r):
                            $tag = $r['status'] === 'Approved' ? ['Arriving today', 'info'] : ($r['end_date'] < $today ? ['Past check-out', 'bad'] : ($r['end_date'] === $today ? ['Leaving today', 'warn'] : ['Until ' . date('M j', strtotime($r['end_date'])), 'ok'])); ?>
                            <div class="db-pet">
                                <span class="db-cage"><small>Cage</small><?= $r['cage_number'] ? str_pad((int)$r['cage_number'], 2, '0', STR_PAD_LEFT) : '—' ?></span>
                                <div><strong><?= htmlspecialchars($r['pet_name']) ?></strong><div class="sub"><?= htmlspecialchars($r['client']) ?> · <?= date('M j', strtotime($r['appointment_date'])) ?> – <?= date('M j', strtotime($r['end_date'])) ?></div></div>
                                <span class="db-st <?= $tag[1] ?> tag"><?= $tag[0] ?></span>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="db-empty"><i class="fa fa-hotel"></i>No pets boarding today.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="db-card">
                    <div class="db-card-head">
                        <span class="db-ic" style="background:#f1f5f9;color:#475569;"><i class="fa fa-compass"></i></span>
                        <div><h5>Quick Links</h5><span class="hint">Go straight to a section</span></div>
                    </div>
                    <div class="db-links">
                        <a class="db-link" href="admin_reports.php"><span class="db-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-chart-pie"></i></span><span>Reports &amp; Analytics<small>Print and export reports</small></span></a>
                        <a class="db-link" href="admin_billing.php"><span class="db-ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-file-invoice-dollar"></i></span><span>Billing &amp; Payments<small>Invoices and receipts</small></span></a>
                        <a class="db-link" href="admin_users.php"><span class="db-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-users"></i></span><span>User &amp; Client Mgmt<small>Accounts and pets</small></span></a>
                        <a class="db-link" href="admin_inventory.php"><span class="db-ic" style="background:#fff1f2;color:#be123c;"><i class="fa fa-boxes-stacked"></i></span><span>Inventory &amp; Stock<small>Supplies and medicines</small></span></a>
                        <a class="db-link" href="admin_monitoring.php"><span class="db-ic" style="background:#ecfeff;color:#0e7490;"><i class="fa fa-video"></i></span><span>Live Feed<small>Boarding cage cameras</small></span></a>
                        <a class="db-link" href="admin_history.php"><span class="db-ic" style="background:#fffbeb;color:#d97706;"><i class="fa fa-clock-rotate-left"></i></span><span>Activity Logs<small>Who did what, and when</small></span></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
