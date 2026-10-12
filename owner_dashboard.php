<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("dbconnection.php");

// ── Access: clinic owner only (view-only reports) ──────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['user'] ?? 'Clinic Owner';
if ($user_role !== 'clinic_owner' && $user_role !== 'owner') {
    if ($user_role === 'admin') header("Location: admin.php");
    elseif ($user_role === 'doctor') header("Location: doctor.php");
    elseif ($user_role === 'staff') header("Location: staff_dashboard.php");
    else header("Location: dashboard.php");
    exit();
}

include_once("includes/owner_report_data.php");
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --sidebar-width: 260px;
            --surface: #ffffff; --bg: #f4f7fb; --line: #e8edf3;
            --ink: #0f172a; --ink-2: #475569; --ink-3: #94a3b8;
            --s1: #2a78d6; --s2: #eb6834; --s3: #1baf7a; --s4: #eda100;
            --good: #16a34a; --bad: #dc2626; --warn: #d97706;
        }
        * { box-sizing: border-box; }
        body { background: var(--bg); font-family: 'Inter', sans-serif; color: var(--ink); margin: 0; }

        /* Sidebar */
        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh; background: #fff; border-right: 1px solid #eef2f6; padding: 28px 20px 24px; display: flex; flex-direction: column; z-index: 1000; overflow-y: auto; }
        .sidebar-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; text-decoration: none; }
        .sidebar-brand-icon { width: 44px; height: 44px; border-radius: 13px; background: #2563eb; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: 0 4px 14px rgba(37,99,235,.28); }
        .sidebar-brand span { font-weight: 800; color: #182032; font-size: 22px; letter-spacing: -.4px; }
        .sidebar-user { margin-bottom: 22px; padding: 15px 18px; border-radius: 14px; background: #f8fafc; border: 1px solid #eef2f6; }
        .sidebar-user .welcome { font-size: 11px; font-weight: 800; letter-spacing: .08em; color: #64748b; text-transform: uppercase; }
        .sidebar-user .uname { font-weight: 700; color: #0f172a; font-size: 15px; margin-top: 4px; }
        .sidebar-user .urole { display: inline-flex; gap: 5px; align-items: center; margin-top: 6px; font-size: 11px; font-weight: 700; color: #15803d; background: #dcfce7; border-radius: 999px; padding: 2px 10px; }
        .sidebar a.nav-i { display: flex; align-items: center; gap: 13px; padding: 11px 16px; border-radius: 12px; color: #334155; text-decoration: none; margin-bottom: 3px; font-weight: 600; font-size: 14.5px; transition: .15s; }
        .sidebar a.nav-i i { width: 20px; text-align: center; color: #64748b; }
        .sidebar a.nav-i:hover { background: #f8fafc; color: #0f172a; }
        .sidebar a.nav-i.active { background: #2563eb; color: #fff; box-shadow: 0 4px 14px rgba(37,99,235,.28); }
        .sidebar a.nav-i.active i { color: #fff; }
        .sidebar .logout { margin-top: auto; padding-top: 18px; border-top: 1px solid #f1f5f9; }
        .sidebar .logout a { color: #dc2626; font-weight: 600; text-decoration: none; display: flex; gap: 13px; align-items: center; padding: 11px 16px; border-radius: 12px; }
        .sidebar .logout a:hover { background: #fef2f2; }

        .main { margin-left: var(--sidebar-width); padding: 28px 32px 48px; }
        .card-r { background: var(--surface); border: 1px solid var(--line); border-radius: 18px; padding: 20px 22px; box-shadow: 0 2px 12px rgba(15,23,42,.04); }
        .sec { scroll-margin-top: 16px; margin-top: 26px; }
        .sec-h { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
        .sec-h h2 { font-size: 18px; font-weight: 800; margin: 0; letter-spacing: -.3px; }
        .sec-h h2 i { color: #2563eb; margin-right: 8px; }
        .sec-h p { margin: 0; color: var(--ink-2); font-size: 13px; }

        .rpt-hero { background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #fff; border-radius: 20px; padding: 24px 28px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .rpt-hero .tag { display: inline-flex; gap: 6px; align-items: center; background: rgba(255,255,255,.16); border-radius: 999px; padding: 3px 12px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .rpt-hero h1 { font-size: 26px; font-weight: 800; margin: 10px 0 4px; letter-spacing: -.5px; color: #fff; -webkit-text-fill-color: #fff; background: none; }
        .rpt-hero p { margin: 0; color: #dbeafe; font-size: 14px; }
        .btn-print { border: none; background: #fff !important; background-image: none !important; color: #1e3a8a !important; font-weight: 800; border-radius: 999px; padding: 11px 22px; box-shadow: 0 8px 20px rgba(15,23,42,.18) !important; display: inline-flex; gap: 8px; align-items: center; }
        .btn-print:hover { transform: translateY(-1px); }

        .filters { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 18px; }
        .chips { display: inline-flex; background: #e9eef5; border-radius: 12px; padding: 4px; gap: 2px; flex-wrap: wrap; }
        .chips a { padding: 7px 13px; border-radius: 9px; font-size: 13px; font-weight: 700; color: #475569; text-decoration: none; }
        .chips a.on { background: #fff; color: #2563eb; box-shadow: 0 1px 4px rgba(15,23,42,.1); }
        .range { display: flex; gap: 6px; align-items: center; }
        .range input { border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 7px 10px; font-size: 13px; background: #fff; }
        .range button { border: none; background: #2563eb !important; background-image: none !important; color: #fff !important; border-radius: 10px; padding: 8px 14px; font-weight: 700; font-size: 13px; box-shadow: none !important; }

        .kpi { height: 100%; }
        .kpi .l { font-size: 12px; font-weight: 700; color: var(--ink-2); text-transform: uppercase; letter-spacing: .05em; display: flex; justify-content: space-between; }
        .kpi .l i { color: var(--ink-3); }
        .kpi .v { font-size: 26px; font-weight: 800; letter-spacing: -.5px; margin: 6px 0 2px; font-variant-numeric: tabular-nums; }
        .kpi .s { font-size: 12.5px; color: var(--ink-2); }
        .kpi .bar { height: 6px; border-radius: 6px; background: #eef2f6; overflow: hidden; margin-top: 10px; }
        .kpi .bar span { display: block; height: 100%; border-radius: 6px; }

        .highlights { margin: 0; padding-left: 0; list-style: none; }
        .highlights li { position: relative; padding: 7px 0 7px 26px; border-top: 1px dashed #edf1f6; font-size: 14px; color: #1e293b; line-height: 1.5; }
        .highlights li:first-child { border-top: none; }
        .highlights li::before { content: '\f058'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; left: 0; top: 8px; color: #2563eb; }

        .chart-box { position: relative; height: 280px; }
        .chart-box.sm { height: 230px; }
        .ttl { font-size: 14px; font-weight: 800; margin-bottom: 2px; }
        .sub { font-size: 12px; color: var(--ink-3); margin-bottom: 10px; }
        .mini { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .mini div { background: #f8fafc; border: 1px solid #eef2f6; border-radius: 12px; padding: 10px 12px; }
        .mini b { display: block; font-size: 19px; font-weight: 800; }
        .mini span { font-size: 12px; color: var(--ink-2); }
        table.rt { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.rt th { text-align: left; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-2); padding: 8px 10px; border-bottom: 1px solid var(--line); background: #f8fafc; }
        table.rt td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        table.rt .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pill { display: inline-block; font-size: 11px; font-weight: 700; border-radius: 999px; padding: 2px 9px; }
        .legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }
        .note { font-size: 12px; color: var(--ink-3); margin-top: 10px; }

        .print-head { display: none; }
        @media (max-width: 992px) { .sidebar { display: none; } .main { margin-left: 0; padding: 18px 16px 40px; } }

        @media print {
            @page { size: A4; margin: 12mm; }
            body { background: #fff !important; }
            .sidebar, .filters, .btn-print, .no-print { display: none !important; }
            .main { margin: 0 !important; padding: 0 !important; }
            .rpt-hero { display: none !important; }
            .print-head { display: block !important; border-bottom: 3px double #1e3a8a; padding-bottom: 10px; margin-bottom: 14px; }
            .card-r { box-shadow: none !important; border: 1px solid #d8dee8; break-inside: avoid; page-break-inside: avoid; }
            .sec { break-inside: avoid-page; margin-top: 16px; }
            .sec.page-break { break-before: page; page-break-before: always; }
            .chart-box { height: 230px; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            a { text-decoration: none; color: inherit; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <a href="owner_dashboard.php" class="sidebar-brand"><div class="sidebar-brand-icon"><i class="fa fa-paw"></i></div><span>PragmaVet</span></a>
    <div class="sidebar-user">
        <div class="welcome">Executive Portal</div>
        <div class="uname"><?= $h($user_name) ?></div>
        <div class="urole"><i class="fa fa-shield-halved"></i> Clinic Owner (View-Only)</div>
    </div>
    <a class="nav-i active" href="#overview"><i class="fa fa-chart-pie"></i> Overview</a>
    <a class="nav-i" href="#revenue"><i class="fa fa-peso-sign"></i> Revenue</a>
    <a class="nav-i" href="#appointments"><i class="fa fa-calendar-check"></i> Appointments &amp; Services</a>
    <a class="nav-i" href="#vets"><i class="fa fa-user-doctor"></i> Veterinarians</a>
    <a class="nav-i" href="#clients"><i class="fa fa-users"></i> Clients &amp; Pets</a>
    <a class="nav-i" href="#boarding"><i class="fa fa-hotel"></i> Boarding</a>
    <a class="nav-i" href="#inventory"><i class="fa fa-boxes-stacked"></i> Inventory</a>
    <a class="nav-i" href="#receivables"><i class="fa fa-file-invoice-dollar"></i> Receivables</a>
    <div class="logout"><a href="logout.php"><i class="fa fa-sign-out-alt"></i> Sign Out</a></div>
</aside>

<main class="main">

    <!-- Printed report header -->
    <div class="print-head">
        <div style="display:flex;justify-content:space-between;align-items:flex-end;">
            <div>
                <div style="font-size:20px;font-weight:800;color:#1e3a8a;letter-spacing:.5px;">PRAGMAVET ANIMAL CLINIC</div>
                <div style="font-size:11px;color:#475569;">0234 Unit B Palico St, Palico 4, Imus, Cavite · 046-8851-221 · pragmavet@gmail.com</div>
            </div>
            <div style="text-align:right;font-size:11px;color:#475569;">Generated <?= date('M j, Y g:i A', strtotime($conn->query("SELECT NOW() n")->fetch_assoc()['n'])) ?><br>Prepared for: <?= $h($user_name) ?></div>
        </div>
        <div style="font-size:17px;font-weight:800;margin-top:10px;">Reports &amp; Analytics — <?= $h($plabel) ?></div>
    </div>

    <div class="rpt-hero no-print">
        <div>
            <span class="tag"><i class="fa fa-eye"></i> Executive view-only</span>
            <h1>Reports &amp; Analytics</h1>
            <p>What happened in the clinic during <b><?= $h($plabel) ?></b>: revenue, visits, vets, clients, boarding and stock.</p>
        </div>
        <a class="btn-print" href="owner_report_print.php?<?= $h($period_qs) ?>" target="_blank" style="text-decoration:none;"><i class="fa fa-print"></i> Print report</a>
    </div>

    <div class="filters">
        <div class="chips">
            <?php foreach ($period_btns as $k => $lbl): ?>
                <a href="owner_dashboard.php?period=<?= $k ?>" class="<?= $period === $k ? 'on' : '' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
        <form class="range" method="get" action="owner_dashboard.php">
            <input type="date" name="start_date" value="<?= $period === 'custom' ? $h($ps) : '' ?>" required aria-label="From">
            <span class="text-muted small">to</span>
            <input type="date" name="end_date" value="<?= $period === 'custom' ? $h($pe) : '' ?>" required aria-label="To">
            <button type="submit"><i class="fa fa-filter me-1"></i>Apply</button>
        </form>
    </div>

    <!-- ── OVERVIEW ─────────────────────────────────────────────────────── -->
    <section class="sec" id="overview">
        <div class="row g-3">
            <div class="col-6 col-xl-3"><div class="card-r kpi">
                <div class="l">Billed <i class="fa fa-file-invoice"></i></div>
                <div class="v"><?= $peso($billed) ?></div>
                <div class="s"><?= count($bills) ?> invoice<?= count($bills) === 1 ? '' : 's' ?> · avg <?= $peso($avg_invoice) ?></div>
            </div></div>
            <div class="col-6 col-xl-3"><div class="card-r kpi">
                <div class="l">Collected <i class="fa fa-hand-holding-dollar"></i></div>
                <div class="v" style="color:var(--good);"><?= $peso($collected) ?></div>
                <div class="s"><?= $collection_rate ?>% of billed · <?= $n_paid ?> paid, <?= $n_partial ?> part-paid</div>
                <div class="bar"><span style="width:<?= min(100, $collection_rate) ?>%;background:var(--good);"></span></div>
            </div></div>
            <div class="col-6 col-xl-3"><div class="card-r kpi">
                <div class="l">Still unpaid <i class="fa fa-clock-rotate-left"></i></div>
                <div class="v" style="color:<?= $owed > 0 ? 'var(--bad)' : 'var(--ink)' ?>;"><?= $peso($owed) ?></div>
                <div class="s">from this period's invoices · <?= $n_unpaid ?> not paid at all</div>
            </div></div>
            <div class="col-6 col-xl-3"><div class="card-r kpi">
                <div class="l">Appointments <i class="fa fa-calendar-check"></i></div>
                <div class="v"><?= $a_total ?></div>
                <div class="s"><?= $a_completed ?> completed · no-show <?= $noshow_rate ?>% · cancelled <?= $cancel_rate ?>%</div>
            </div></div>
        </div>
        <div class="card-r mt-3">
            <div class="ttl"><i class="fa fa-lightbulb text-warning me-2"></i>Highlights</div>
            <ul class="highlights mt-2"><?php foreach ($highlights as $line): ?><li><?= $line ?></li><?php endforeach; ?></ul>
            <?php if ($excluded_cnt): ?><div class="note"><i class="fa fa-circle-info me-1"></i>Figures use valid invoices only. Left out: <?= $excluded_cnt ?> invoice<?= $excluded_cnt === 1 ? '' : 's' ?> worth <?= $peso($excluded_amt) ?> (<?= $excluded['duplicate'][0] ?> duplicate boarding bill<?= $excluded['duplicate'][0] === 1 ? '' : 's' ?>, <?= $excluded['cancelled'][0] ?> for cancelled bookings, <?= $excluded['missing'][0] ?> for deleted bookings, <?= $excluded['void'][0] ?> void).</div><?php endif; ?>
        </div>
    </section>

    <!-- ── REVENUE ──────────────────────────────────────────────────────── -->
    <section class="sec" id="revenue">
        <div class="sec-h"><h2><i class="fa fa-peso-sign"></i>Revenue</h2><p>Invoices issued in the period; "collected" is what has been paid on them so far.</p></div>
        <div class="row g-3">
            <div class="col-lg-8"><div class="card-r">
                <div class="ttl">Billed vs collected, <?= $daily ? 'by day' : 'by month' ?></div>
                <div class="sub">Same peso scale for both</div>
                <div class="chart-box"><canvas id="chTrend" role="img" aria-label="Billed and collected over time"></canvas></div>
            </div></div>
            <div class="col-lg-4"><div class="card-r h-100">
                <div class="ttl">Where revenue came from</div>
                <div class="sub">Share of billed amount</div>
                <div class="chart-box sm"><canvas id="chStream" role="img" aria-label="Revenue by stream"></canvas></div>
                <table class="rt mt-2"><?php $si = 0; foreach ($stream as $k => $v): $si++; ?>
                    <tr><td><span class="legend-dot" style="background:var(--s<?= $si === 1 ? 1 : 3 ?>);"></span><?= $k ?></td><td class="num"><?= $peso($v) ?></td><td class="num text-muted"><?= $pct($v, $billed) ?>%</td></tr>
                <?php endforeach; ?></table>
            </div></div>
        </div>
        <div class="row g-3 mt-0">
            <div class="col-lg-7"><div class="card-r h-100">
                <div class="ttl">Revenue by service / item</div>
                <div class="sub">From invoice line items</div>
                <?php if (!$svc_rev): ?><div class="text-muted small py-3">No invoices in this period.</div><?php else: ?>
                <table class="rt"><thead><tr><th>Service / item</th><th class="num">Amount</th><th class="num">Share</th></tr></thead><tbody>
                <?php foreach (array_slice($svc_rev, 0, 8, true) as $k => $v): ?>
                    <tr><td><?= $h($k) ?></td><td class="num"><?= $peso($v) ?></td><td class="num" style="width:150px;"><div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;"><div style="flex:1;max-width:80px;height:6px;background:#eef2f6;border-radius:6px;overflow:hidden;"><div style="width:<?= $pct($v, max($svc_rev)) ?>%;height:100%;background:var(--s1);border-radius:6px;"></div></div><span class="text-muted"><?= $pct($v, array_sum($svc_rev)) ?>%</span></div></td></tr>
                <?php endforeach; ?>
                <?php if (count($svc_rev) > 8): ?><tr><td class="text-muted">Other (<?= count($svc_rev) - 8 ?>)</td><td class="num text-muted"><?= $peso(array_sum(array_slice($svc_rev, 8))) ?></td><td></td></tr><?php endif; ?>
                </tbody></table><?php endif; ?>
            </div></div>
            <div class="col-lg-5"><div class="card-r h-100">
                <div class="ttl">How clients paid</div>
                <div class="sub">Amount collected by payment method</div>
                <?php if (!$methods): ?><div class="text-muted small py-3">No payments recorded for this period's invoices.</div><?php else: ?>
                <table class="rt"><thead><tr><th>Method</th><th class="num">Collected</th><th class="num">Share</th></tr></thead><tbody>
                <?php foreach ($methods as $k => $v): ?><tr><td><?= $h($k) ?></td><td class="num"><?= $peso($v) ?></td><td class="num text-muted"><?= $pct($v, $collected) ?>%</td></tr><?php endforeach; ?>
                </tbody></table><?php endif; ?>
            </div></div>
        </div>
    </section>

    <!-- ── APPOINTMENTS & SERVICES ──────────────────────────────────────── -->
    <section class="sec page-break" id="appointments">
        <div class="sec-h"><h2><i class="fa fa-calendar-check"></i>Appointments &amp; services</h2><p>Clinic visits dated in the period (boarding is reported separately).</p></div>
        <div class="row g-3">
            <div class="col-lg-5"><div class="card-r h-100">
                <div class="ttl">Appointment outcomes</div>
                <div class="sub"><?= $a_total ?> appointment<?= $a_total === 1 ? '' : 's' ?> in the period</div>
                <div class="chart-box sm"><canvas id="chOutcome" role="img" aria-label="Appointment outcomes"></canvas></div>
                <div class="mini mt-3">
                    <div><b><?= $pct($a_completed, $a_total) ?>%</b><span>completed</span></div>
                    <div><b><?= $noshow_rate ?>%</b><span>no-show rate (of due visits)</span></div>
                    <div><b><?= $cancel_rate ?>%</b><span>cancelled or rejected</span></div>
                    <div><b><?= $busiest_dow ?: '—' ?></b><span>busiest day</span></div>
                </div>
            </div></div>
            <div class="col-lg-7"><div class="card-r h-100">
                <div class="ttl">Most booked services</div>
                <div class="sub">Number of appointments (cancelled ones not counted)</div>
                <div class="chart-box"><canvas id="chServices" role="img" aria-label="Appointments by service"></canvas></div>
            </div></div>
        </div>
    </section>

    <!-- ── VETERINARIANS ────────────────────────────────────────────────── -->
    <section class="sec" id="vets">
        <div class="sec-h"><h2><i class="fa fa-user-doctor"></i>Veterinarians</h2><p>Caseload and billed revenue from each vet's appointments in the period.</p></div>
        <div class="card-r">
            <table class="rt"><thead><tr><th>Veterinarian</th><th class="num">Assigned</th><th class="num">Completed</th><th class="num">No-show</th><th class="num">Cancelled</th><th class="num">Completion</th><th class="num">Billed revenue</th></tr></thead><tbody>
            <?php if (!$vets): ?><tr><td colspan="7" class="text-muted">No veterinarians registered.</td></tr><?php endif; ?>
            <?php foreach ($vets as $v): $due = (int)$v['completed'] + (int)$v['noshow']; ?>
                <tr><td class="fw-semibold"><?= $h($v['fullname']) ?></td><td class="num"><?= (int)$v['assigned'] ?></td><td class="num"><?= (int)$v['completed'] ?></td><td class="num"><?= (int)$v['noshow'] ?></td><td class="num"><?= (int)$v['cancelled'] ?></td>
                    <td class="num"><?= $due ? $pct((int)$v['completed'], $due) . '%' : '—' ?></td><td class="num fw-semibold"><?= $peso($v['revenue']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if ($unassigned): ?><div class="note"><i class="fa fa-circle-info me-1"></i><?= $unassigned ?> active appointment<?= $unassigned === 1 ? '' : 's' ?> in this period had no vet assigned.</div><?php endif; ?>
        </div>
    </section>

    <!-- ── CLIENTS & PETS ───────────────────────────────────────────────── -->
    <section class="sec" id="clients">
        <div class="sec-h"><h2><i class="fa fa-users"></i>Clients &amp; pets</h2><p>Registered pet owners and their pets.</p></div>
        <div class="row g-3">
            <div class="col-lg-7"><div class="card-r h-100">
                <div class="mini" style="grid-template-columns:repeat(3,1fr);">
                    <div><b><?= $c_total ?></b><span>registered clients</span></div>
                    <div><b><?= $c_new ?></b><span>new in this period</span></div>
                    <div><b><?= $c_active ?></b><span>booked visits in period</span></div>
                    <div><b><?= $c_repeat ?></b><span>returning (2+ completed visits)</span></div>
                    <div><b><?= $p_total ?></b><span>registered pets (<?= $p_new ?> new)</span></div>
                    <div><b><?= $vax_due ?></b><span>vaccinations due in 30 days</span></div>
                </div>
            </div></div>
            <div class="col-lg-5"><div class="card-r h-100">
                <div class="ttl">Pets by species</div>
                <div class="sub">All registered pets</div>
                <div class="chart-box sm"><canvas id="chSpecies" role="img" aria-label="Pets by species"></canvas></div>
            </div></div>
        </div>
    </section>

    <!-- ── BOARDING ─────────────────────────────────────────────────────── -->
    <section class="sec page-break" id="boarding">
        <div class="sec-h"><h2><i class="fa fa-hotel"></i>Boarding</h2><p>Stays that overlap the period (approved, checked in or completed).</p></div>
        <div class="card-r">
            <div class="mini" style="grid-template-columns:repeat(5,1fr);">
                <div><b><?= $stays ?></b><span>stays</span></div>
                <div><b><?= $nights_in ?></b><span>cage-nights in period</span></div>
                <div><b><?= $occupancy ?>%</b><span>of <?= $cage_count ?> cages × <?= $days_in_period ?> day<?= $days_in_period === 1 ? '' : 's' ?></span></div>
                <div><b><?= $avg_stay ?></b><span>average nights per stay</span></div>
                <div><b><?= $in_now ?> / <?= $cage_count ?></b><span>cages occupied today</span></div>
            </div>
            <div class="mt-3"><div class="d-flex justify-content-between small text-muted mb-1"><span>Capacity used in period</span><span><?= $occupancy ?>%</span></div>
                <div style="height:10px;background:#eef2f6;border-radius:8px;overflow:hidden;"><div style="width:<?= min(100, $occupancy) ?>%;height:100%;background:var(--s3);border-radius:8px;"></div></div></div>
            <div class="note">Boarding revenue in the period: <b><?= $peso($stream['Boarding']) ?></b> (<?= $pct($stream['Boarding'], $billed) ?>% of billed).</div>
        </div>
    </section>

    <!-- ── INVENTORY ────────────────────────────────────────────────────── -->
    <section class="sec" id="inventory">
        <div class="sec-h"><h2><i class="fa fa-boxes-stacked"></i>Inventory</h2><p>Stock today, and medicines used on appointments in the period.</p></div>
        <div class="row g-3">
            <div class="col-lg-7"><div class="card-r h-100">
                <div class="mini" style="grid-template-columns:repeat(3,1fr);">
                    <div><b><?= $inv['items'] ?></b><span>items in stock list</span></div>
                    <div><b><?= $peso($inv['cost']) ?></b><span>stock value at cost</span></div>
                    <div><b><?= $peso($inv['retail']) ?></b><span>stock value at selling price</span></div>
                    <div><b style="color:<?= $inv['low'] + $inv['out'] ? 'var(--warn)' : 'inherit' ?>;"><?= $inv['low'] ?> / <?= $inv['out'] ?></b><span>low stock / out of stock</span></div>
                    <div><b style="color:<?= $inv['expiring'] ? 'var(--warn)' : 'inherit' ?>;"><?= $inv['expiring'] ?></b><span>expiring within 60 days</span></div>
                    <div><b style="color:<?= $inv['expired'] ? 'var(--bad)' : 'inherit' ?>;"><?= $inv['expired'] ?></b><span>expired, still listed</span></div>
                </div>
            </div></div>
            <div class="col-lg-5"><div class="card-r h-100">
                <div class="ttl">Most used medicines &amp; supplies</div>
                <div class="sub">Declared by vet staff when completing appointments</div>
                <?php if (!$used): ?><div class="text-muted small py-2">No medicines were recorded as used in this period.</div><?php else: ?>
                <table class="rt"><tbody><?php foreach ($used as $k => $v): ?><tr><td><?= $h($k) ?></td><td class="num fw-semibold"><?= $v ?> used</td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
            </div></div>
        </div>
    </section>

    <!-- ── RECEIVABLES ──────────────────────────────────────────────────── -->
    <section class="sec" id="receivables">
        <div class="sec-h"><h2><i class="fa fa-file-invoice-dollar"></i>Receivables</h2><p>All valid invoices still owing as of today (any period).</p></div>
        <div class="card-r">
            <div class="d-flex gap-4 flex-wrap mb-2">
                <div><span class="text-muted small">Total unpaid</span><div class="fw-bold" style="font-size:20px;color:var(--bad);"><?= $peso($recv_total) ?></div></div>
                <div><span class="text-muted small">Invoices owing</span><div class="fw-bold" style="font-size:20px;"><?= count($recv) ?></div></div>
                <div><span class="text-muted small">Older than 30 days</span><div class="fw-bold" style="font-size:20px;"><?= $recv_old ?></div></div>
            </div>
            <?php if (!$recv): ?><div class="text-muted small">Nothing is owed — every valid invoice is paid.</div><?php else: ?>
            <table class="rt"><thead><tr><th>Invoice</th><th>Client</th><th>Pet</th><th>For</th><th class="num">Issued</th><th class="num">Age</th><th class="num">Total</th><th class="num">Balance</th></tr></thead><tbody>
            <?php foreach (array_slice($recv, 0, 10) as $b): $age = (int)floor((strtotime($db_today) - strtotime($b['_date'])) / 86400); ?>
                <tr><td class="fw-semibold"><?= $h($b['invoice_no'] ?: 'INV-' . str_pad($b['id'], 5, '0', STR_PAD_LEFT)) ?></td><td><?= $h($b['client']) ?></td><td><?= $h($b['_pet']) ?></td>
                    <td><?= (int)$b['is_boarding'] === 1 ? 'Boarding' : $h($b['a_service'] ?: 'Clinic visit') ?></td><td class="num"><?= date('M j, Y', strtotime($b['_date'])) ?></td>
                    <td class="num"><span class="pill" style="background:<?= $age > 30 ? '#fee2e2' : '#f1f5f9' ?>;color:<?= $age > 30 ? '#b91c1c' : '#475569' ?>;"><?= $age ?>d</span></td>
                    <td class="num"><?= $peso($b['total_amount']) ?></td><td class="num fw-bold" style="color:var(--bad);"><?= $peso($b['_owed']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if (count($recv) > 10): ?><div class="note">Showing the 10 largest balances of <?= count($recv) ?>.</div><?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="note text-center mt-4">PragmaVet Reports &amp; Analytics · <?= $h($plabel) ?> · figures are descriptive and computed from records in the system.</div>
    </section>
</main>

<script>
(function () {
    var css = getComputedStyle(document.documentElement);
    var C = { s1: css.getPropertyValue('--s1').trim(), s2: css.getPropertyValue('--s2').trim(), s3: css.getPropertyValue('--s3').trim(), s4: css.getPropertyValue('--s4').trim(),
              ink2: '#475569', grid: '#eef2f6', surface: '#ffffff' };
    var peso = function (v) { return '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.color = C.ink2;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.tooltip.padding = 10;
    var charts = [];
    // Long category names: shorten on the axis (full name in the tooltip)
    var shortLbl = function (v) { var t = this.getLabelForValue(v); return t.length > 20 ? t.slice(0, 19) + "…" : t; };
    function emptyNote(id, msg) { var c = document.getElementById(id); if (!c) return; var p = document.createElement('div'); p.className = 'text-muted small d-flex align-items-center justify-content-center h-100'; p.textContent = msg; c.replaceWith(p); }

    // Billed vs collected (one peso axis)
    var trend = <?= $json(array_values($trend)) ?>;
    if (trend.some(function (t) { return t[1] > 0; })) {
        charts.push(new Chart(document.getElementById('chTrend'), {
            data: { labels: trend.map(function (t) { return t[0]; }), datasets: [
                { type: 'bar', label: 'Billed', data: trend.map(function (t) { return t[1]; }), backgroundColor: C.s1, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 28, order: 2 },
                { type: 'line', label: 'Collected', data: trend.map(function (t) { return t[2]; }), borderColor: C.s2, backgroundColor: C.s2, borderWidth: 2, pointRadius: 4, pointBorderColor: C.surface, pointBorderWidth: 2, tension: .25, order: 1 } ] },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top', align: 'start' }, tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + peso(c.parsed.y); } } } },
                scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } }, y: { beginAtZero: true, grid: { color: C.grid }, border: { display: false }, ticks: { callback: function (v) { return '₱' + Number(v).toLocaleString(); } } } } }
        }));
    } else emptyNote('chTrend', 'No invoices in this period.');

    // Revenue streams
    var stream = <?= $json(array_values($stream)) ?>;
    if (stream[0] + stream[1] > 0) {
        charts.push(new Chart(document.getElementById('chStream'), {
            type: 'doughnut',
            data: { labels: <?= $json(array_keys($stream)) ?>, datasets: [{ data: stream, backgroundColor: [C.s1, C.s3], borderColor: C.surface, borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '64%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + c.label + ': ' + peso(c.parsed); } } } } }
        }));
    } else emptyNote('chStream', 'No revenue in this period.');

    // Appointment outcomes (horizontal bars, one series)
    var outcomes = <?= $json($outcomes) ?>;
    if (Object.values(outcomes).some(function (v) { return v > 0; })) {
        charts.push(new Chart(document.getElementById('chOutcome'), {
            type: 'bar',
            data: { labels: Object.keys(outcomes), datasets: [{ label: 'Appointments', data: Object.values(outcomes), backgroundColor: C.s1, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, layout: { padding: { left: 6 } }, plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: C.grid }, border: { display: false } }, y: { grid: { display: false }, ticks: { autoSkip: false, callback: shortLbl } } } }
        }));
    } else emptyNote('chOutcome', 'No appointments in this period.');

    // Services by volume
    var svc = <?= $json($svc_vol) ?>;
    if (Object.keys(svc).length) {
        charts.push(new Chart(document.getElementById('chServices'), {
            type: 'bar',
            data: { labels: Object.keys(svc), datasets: [{ label: 'Appointments', data: Object.values(svc), backgroundColor: C.s1, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, layout: { padding: { left: 6 } }, plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: C.grid }, border: { display: false } }, y: { grid: { display: false }, ticks: { autoSkip: false, callback: shortLbl } } } }
        }));
    } else emptyNote('chServices', 'No booked services in this period.');

    // Species
    var sp = <?= $json($species) ?>;
    if (Object.values(sp).some(function (v) { return v > 0; })) {
        charts.push(new Chart(document.getElementById('chSpecies'), {
            type: 'doughnut',
            data: { labels: Object.keys(sp), datasets: [{ data: Object.values(sp), backgroundColor: [C.s1, C.s2, C.s3], borderColor: C.surface, borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right' } } }
        }));
    } else emptyNote('chSpecies', 'No pets registered.');

    // Printing: charts redraw at print size
    window.printReport = function () { charts.forEach(function (c) { c.resize(); }); setTimeout(function () { window.print(); }, 150); };
    window.addEventListener('beforeprint', function () { charts.forEach(function (c) { c.resize(); }); });
    window.addEventListener('afterprint', function () { charts.forEach(function (c) { c.resize(); }); });

    // Sidebar: highlight the section in view
    var links = document.querySelectorAll('.sidebar a.nav-i');
    var obs = new IntersectionObserver(function (es) {
        es.forEach(function (e) { if (e.isIntersecting) links.forEach(function (l) { l.classList.toggle('active', l.getAttribute('href') === '#' + e.target.id); }); });
    }, { rootMargin: '-40% 0px -55% 0px' });
    document.querySelectorAll('section.sec').forEach(function (s) { obs.observe(s); });
})();
</script>
<?php include_once __DIR__ . '/includes/pv_responsive.php'; ?>
</body>
</html>
