<?php
// Print-ready Reports & Analytics (A4). Same figures as owner_dashboard.php for the chosen period.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("dbconnection.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['user'] ?? 'Clinic Owner';
if ($user_role !== 'clinic_owner' && $user_role !== 'owner') {
    header("Location: index.php");
    exit();
}

include_once("includes/owner_report_data.php");

$now_db    = $conn->query("SELECT NOW() n")->fetch_assoc()['n'];
$report_no = 'RPT-' . date('Ymd-Hi', strtotime($now_db));
$generated = date('F j, Y g:i A', strtotime($now_db));
$n_bills   = count($bills);
$trend_rows = array_values(array_filter($trend, fn($t) => $t[1] > 0 || $t[2] > 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports &amp; Analytics — <?= $h($plabel) ?> | PragmaVet</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
    @page {
        size: A4;
        margin: 16mm 14mm 18mm;
        @bottom-left  { content: "PragmaVet Animal Clinic · <?= $h($report_no) ?> · Confidential"; font: 8pt Inter, Arial, sans-serif; color: #64748b; }
        @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 8pt Inter, Arial, sans-serif; color: #64748b; }
    }
    :root { --ink: #0f172a; --ink2: #334155; --muted: #64748b; --line: #cbd5e1; --soft: #f1f5f9; --brand: #1e3a8a; --s1: #2a78d6; --s2: #eb6834; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e2e8f0; font-family: Inter, Arial, sans-serif; color: var(--ink); font-size: 10pt; line-height: 1.45; }

    /* Screen toolbar */
    .toolbar { position: sticky; top: 0; z-index: 10; background: #0f172a; color: #e2e8f0; padding: 10px 20px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
    .toolbar .tip { font-size: 12.5px; color: #94a3b8; }
    .toolbar a, .toolbar button { border: none; border-radius: 999px; padding: 8px 16px; font: 700 13px Inter, sans-serif; text-decoration: none; cursor: pointer; display: inline-flex; gap: 7px; align-items: center; }
    .toolbar .back { background: #1e293b; color: #e2e8f0; }
    .toolbar .print { background: #2563eb; color: #fff; }

    /* A4 sheet on screen */
    .sheet { width: 210mm; min-height: 297mm; margin: 18px auto 40px; background: #fff; padding: 16mm 14mm 18mm; box-shadow: 0 10px 40px rgba(15,23,42,.18); }

    .lh { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 3px double var(--brand); padding-bottom: 8px; }
    .lh .name { font-size: 16pt; font-weight: 800; color: var(--brand); letter-spacing: .5px; }
    .lh .addr { font-size: 8.5pt; color: var(--muted); }
    .lh .logo { width: 40px; height: 40px; border-radius: 10px; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .title { text-align: center; margin: 14px 0 10px; }
    .title h1 { font-size: 15pt; margin: 0; letter-spacing: 1.5px; text-transform: uppercase; }
    .title div { color: var(--ink2); font-size: 10pt; margin-top: 2px; }

    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 3px 8px; font-size: 9pt; border: 1px solid var(--line); }
    .meta td.k { background: var(--soft); color: var(--ink2); font-weight: 600; width: 22%; }

    h2 { font-size: 11.5pt; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid var(--ink); text-transform: uppercase; letter-spacing: .5px; break-after: avoid; page-break-after: avoid; }
    h3 { font-size: 10pt; margin: 12px 0 5px; color: var(--ink2); break-after: avoid; page-break-after: avoid; }
    .data th { background: var(--soft); text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .3px; color: var(--ink2); padding: 5px 7px; border: 1px solid var(--line); }
    .data td { padding: 4.5px 7px; border: 1px solid var(--line); font-size: 9.5pt; vertical-align: top; }
    .data .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .data tr.total td { font-weight: 800; background: #f8fafc; }
    .data tr { break-inside: avoid; page-break-inside: avoid; }
    .kpis td { width: 25%; border: 1px solid var(--line); padding: 8px 10px; vertical-align: top; }
    .kpis .l { font-size: 8pt; text-transform: uppercase; letter-spacing: .4px; color: var(--muted); font-weight: 700; }
    .kpis .v { font-size: 14pt; font-weight: 800; margin-top: 2px; }
    .kpis .s { font-size: 8.5pt; color: var(--ink2); }
    ul.hl { margin: 6px 0 0; padding-left: 18px; }
    ul.hl li { margin: 3px 0; }
    .two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .chart { border: 1px solid var(--line); padding: 8px; break-inside: avoid; page-break-inside: avoid; }
    .chart .ct { font-size: 9pt; font-weight: 700; margin-bottom: 4px; }
    .chart .cv { position: relative; height: 58mm; }
    .note { font-size: 8.5pt; color: var(--muted); margin-top: 5px; }
    .block { break-inside: avoid; page-break-inside: avoid; }
    .page-break { break-before: page; page-break-before: always; }
    .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; margin-top: 34px; break-inside: avoid; page-break-inside: avoid; }
    .sign div { text-align: center; font-size: 9pt; }
    .sign .line { border-top: 1px solid var(--ink); margin-top: 38px; padding-top: 4px; font-weight: 700; }
    .sign .role { color: var(--muted); font-size: 8.5pt; }
    .end { text-align: center; color: var(--muted); font-size: 8.5pt; margin-top: 22px; }

    @media print {
        body { background: #fff; }
        .toolbar { display: none !important; }
        .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a class="back" href="owner_dashboard.php?<?= $h($period_qs) ?>"><i class="fa fa-arrow-left"></i> Back to dashboard</a>
    <span class="tip"><i class="fa fa-circle-info"></i> In the print window choose paper <b>A4</b> and turn off <b>Headers and footers</b>. Pick “Save as PDF” to keep a copy.</span>
    <button class="print" onclick="window.print()"><i class="fa fa-print"></i> Print / Save as PDF</button>
</div>

<div class="sheet">
    <!-- Letterhead -->
    <div class="lh">
        <div style="display:flex;gap:10px;align-items:center;">
            <div class="logo"><i class="fa fa-paw"></i></div>
            <div>
                <div class="name">PRAGMAVET ANIMAL CLINIC</div>
                <div class="addr">0234 Unit B Palico St, Palico 4, Imus, Cavite, Philippines<br>Tel: 046-8851-221 · 0954-285-3053 · pragmavet@gmail.com</div>
            </div>
        </div>
        <div class="addr" style="text-align:right;white-space:nowrap;">Report No. <b><?= $h($report_no) ?></b><br><?= $h($generated) ?></div>
    </div>

    <div class="title">
        <h1>Reports &amp; Analytics</h1>
        <div>Clinic performance report · <?= $h($plabel) ?></div>
    </div>

    <table class="meta">
        <tr><td class="k">Report period</td><td><?= date('F j, Y', strtotime($ps)) ?> to <?= date('F j, Y', strtotime($pe)) ?> (<?= $days_in_period ?> day<?= $days_in_period === 1 ? '' : 's' ?>)</td>
            <td class="k">Date generated</td><td><?= $h($generated) ?></td></tr>
        <tr><td class="k">Prepared for</td><td><?= $h($user_name) ?>, Clinic Owner</td>
            <td class="k">Records used</td><td><?= $n_bills ?> invoice<?= $n_bills === 1 ? '' : 's' ?>, <?= $a_total ?> appointment<?= $a_total === 1 ? '' : 's' ?>, <?= $stays ?> boarding stay<?= $stays === 1 ? '' : 's' ?></td></tr>
    </table>

    <!-- 1. Executive summary -->
    <h2>1. Executive summary</h2>
    <table class="kpis"><tr>
        <td><div class="l">Total billed</div><div class="v"><?= $peso($billed) ?></div><div class="s"><?= $n_bills ?> invoices · avg <?= $peso($avg_invoice) ?></div></td>
        <td><div class="l">Total collected</div><div class="v"><?= $peso($collected) ?></div><div class="s"><?= $collection_rate ?>% of billed</div></td>
        <td><div class="l">Unpaid (this period)</div><div class="v"><?= $peso($owed) ?></div><div class="s"><?= $n_unpaid ?> not paid · <?= $n_partial ?> part-paid</div></td>
        <td><div class="l">Appointments</div><div class="v"><?= $a_total ?></div><div class="s"><?= $a_completed ?> completed (<?= $pct($a_completed, $a_total) ?>%)</div></td>
    </tr></table>
    <h3>Key findings</h3>
    <ul class="hl"><?php foreach ($highlights as $line): ?><li><?= $line ?></li><?php endforeach; ?></ul>

    <!-- 2. Financial report -->
    <h2>2. Financial report</h2>
    <h3>2.1 Billed and collected, <?= $daily ? 'by day' : 'by month' ?></h3>
    <?php if (!$trend_rows): ?><p class="note">No invoices were issued in this period.</p><?php else: ?>
    <table class="data">
        <thead><tr><th><?= $daily ? 'Date' : 'Month' ?></th><th class="num">Billed</th><th class="num">Collected</th><th class="num">Unpaid</th><th class="num">Collection rate</th></tr></thead>
        <tbody>
        <?php foreach ($trend_rows as $t): ?>
            <tr><td><?= $h($t[0]) ?></td><td class="num"><?= $peso($t[1]) ?></td><td class="num"><?= $peso($t[2]) ?></td><td class="num"><?= $peso(max(0, $t[1] - $t[2])) ?></td><td class="num"><?= $pct($t[2], $t[1]) ?>%</td></tr>
        <?php endforeach; ?>
            <tr class="total"><td>Total</td><td class="num"><?= $peso($billed) ?></td><td class="num"><?= $peso($collected) ?></td><td class="num"><?= $peso($owed) ?></td><td class="num"><?= $collection_rate ?>%</td></tr>
        </tbody>
    </table>
    <p class="note"><?= $daily ? 'Only days with invoices are listed.' : 'Only months with invoices are listed.' ?> “Collected” is what has been paid on the period's invoices so far.</p>
    <?php endif; ?>

    <div class="two" style="margin-top:10px;">
        <div class="block">
            <h3>2.2 Revenue by source</h3>
            <table class="data"><thead><tr><th>Source</th><th class="num">Billed</th><th class="num">Share</th></tr></thead><tbody>
                <?php foreach ($stream as $k => $v): ?><tr><td><?= $k ?></td><td class="num"><?= $peso($v) ?></td><td class="num"><?= $pct($v, $billed) ?>%</td></tr><?php endforeach; ?>
                <tr class="total"><td>Total</td><td class="num"><?= $peso($billed) ?></td><td class="num">100%</td></tr>
            </tbody></table>
        </div>
        <div class="block">
            <h3>2.3 Payments by method</h3>
            <table class="data"><thead><tr><th>Method</th><th class="num">Collected</th><th class="num">Share</th></tr></thead><tbody>
                <?php if (!$methods): ?><tr><td colspan="3" class="note">No payments recorded.</td></tr><?php endif; ?>
                <?php foreach ($methods as $k => $v): ?><tr><td><?= $h($k) ?></td><td class="num"><?= $peso($v) ?></td><td class="num"><?= $pct($v, $collected) ?>%</td></tr><?php endforeach; ?>
                <?php if ($methods): ?><tr class="total"><td>Total</td><td class="num"><?= $peso($collected) ?></td><td class="num">100%</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>

    <h3>2.4 Revenue by service / item</h3>
    <?php if (!$svc_rev): ?><p class="note">No invoices in this period.</p><?php else: ?>
    <table class="data"><thead><tr><th style="width:8%;">#</th><th>Service / item</th><th class="num">Billed</th><th class="num">Share</th></tr></thead><tbody>
        <?php $i = 0; foreach (array_slice($svc_rev, 0, 12, true) as $k => $v): $i++; ?><tr><td><?= $i ?></td><td><?= $h($k) ?></td><td class="num"><?= $peso($v) ?></td><td class="num"><?= $pct($v, array_sum($svc_rev)) ?>%</td></tr><?php endforeach; ?>
        <?php if (count($svc_rev) > 12): ?><tr><td></td><td>Other (<?= count($svc_rev) - 12 ?> items)</td><td class="num"><?= $peso(array_sum(array_slice($svc_rev, 12))) ?></td><td class="num"><?= $pct(array_sum(array_slice($svc_rev, 12)), array_sum($svc_rev)) ?>%</td></tr><?php endif; ?>
        <tr class="total"><td></td><td>Total</td><td class="num"><?= $peso(array_sum($svc_rev)) ?></td><td class="num">100%</td></tr>
    </tbody></table>
    <?php endif; ?>

    <div class="block">
        <h3>2.5 Receivables as of <?= date('F j, Y', strtotime($db_today)) ?> (all periods)</h3>
        <table class="data"><thead><tr><th>Age of invoice</th><th class="num">Invoices</th><th class="num">Amount owed</th><th class="num">Share</th></tr></thead><tbody>
            <?php foreach ($aging as $k => [$cnt, $amt]): ?><tr><td><?= $k ?></td><td class="num"><?= $cnt ?></td><td class="num"><?= $peso($amt) ?></td><td class="num"><?= $pct($amt, $recv_total) ?>%</td></tr><?php endforeach; ?>
            <tr class="total"><td>Total</td><td class="num"><?= count($recv) ?></td><td class="num"><?= $peso($recv_total) ?></td><td class="num"><?= $recv_total > 0 ? '100%' : '—' ?></td></tr>
        </tbody></table>
    </div>
    <?php if ($recv): ?>
    <h3>2.6 Largest unpaid invoices</h3>
    <table class="data"><thead><tr><th>Invoice</th><th>Client</th><th>For</th><th class="num">Issued</th><th class="num">Age</th><th class="num">Balance</th></tr></thead><tbody>
        <?php foreach (array_slice($recv, 0, 10) as $b): $age = (int)floor((strtotime($db_today) - strtotime($b['_date'])) / 86400); ?>
            <tr><td><?= $h($b['invoice_no'] ?: 'INV-' . str_pad($b['id'], 5, '0', STR_PAD_LEFT)) ?></td><td><?= $h($b['client']) ?><?= $b['_pet'] ? ' (' . $h($b['_pet']) . ')' : '' ?></td>
                <td><?= (int)$b['is_boarding'] === 1 ? 'Boarding' : $h($b['a_service'] ?: 'Clinic visit') ?></td><td class="num"><?= date('M j, Y', strtotime($b['_date'])) ?></td><td class="num"><?= $age ?> days</td><td class="num"><?= $peso($b['_owed']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php if (count($recv) > 10): ?><p class="note">Top 10 of <?= count($recv) ?> unpaid invoices by balance.</p><?php endif; ?>
    <?php endif; ?>

    <!-- Charts (support the tables above) -->
    <div class="two" style="margin-top:12px;">
        <div class="chart"><div class="ct">Figure 1. Billed vs collected</div><div class="cv"><canvas id="fTrend"></canvas></div></div>
        <div class="chart"><div class="ct">Figure 2. Appointment outcomes</div><div class="cv"><canvas id="fOutcome"></canvas></div></div>
    </div>

    <!-- 3. Operations report -->
    <h2 class="page-break">3. Operations report</h2>
    <div class="two">
        <div class="block">
            <h3>3.1 Appointment outcomes</h3>
            <table class="data"><thead><tr><th>Outcome</th><th class="num">Count</th><th class="num">Share</th></tr></thead><tbody>
                <?php foreach ($outcomes as $k => $v): ?><tr><td><?= $k ?></td><td class="num"><?= $v ?></td><td class="num"><?= $pct($v, $a_total) ?>%</td></tr><?php endforeach; ?>
                <tr class="total"><td>Total</td><td class="num"><?= $a_total ?></td><td class="num"><?= $a_total ? '100%' : '—' ?></td></tr>
            </tbody></table>
            <p class="note">No-show rate <?= $noshow_rate ?>% (of visits that were due) · cancellation rate <?= $cancel_rate ?>%<?= $busiest_dow ? ' · busiest day: ' . $busiest_dow : '' ?>.</p>
        </div>
        <div class="block">
            <h3>3.2 Most booked services</h3>
            <table class="data"><thead><tr><th>Service</th><th class="num">Appointments</th></tr></thead><tbody>
                <?php if (!$svc_vol): ?><tr><td colspan="2" class="note">No booked services.</td></tr><?php endif; ?>
                <?php foreach ($svc_vol as $k => $v): ?><tr><td><?= $h($k) ?></td><td class="num"><?= $v ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <p class="note">Cancelled and rejected bookings are not counted.</p>
        </div>
    </div>

    <div class="block">
        <h3>3.3 Veterinarian caseload</h3>
        <table class="data"><thead><tr><th>Veterinarian</th><th class="num">Assigned</th><th class="num">Completed</th><th class="num">No-show</th><th class="num">Cancelled</th><th class="num">Completion</th><th class="num">Billed revenue</th></tr></thead><tbody>
            <?php if (!$vets): ?><tr><td colspan="7" class="note">No veterinarians registered.</td></tr><?php endif; ?>
            <?php foreach ($vets as $v): $due = (int)$v['completed'] + (int)$v['noshow']; ?>
                <tr><td><?= $h($v['fullname']) ?></td><td class="num"><?= (int)$v['assigned'] ?></td><td class="num"><?= (int)$v['completed'] ?></td><td class="num"><?= (int)$v['noshow'] ?></td><td class="num"><?= (int)$v['cancelled'] ?></td><td class="num"><?= $due ? $pct((int)$v['completed'], $due) . '%' : '—' ?></td><td class="num"><?= $peso($v['revenue']) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php if ($unassigned): ?><p class="note"><?= $unassigned ?> active appointment<?= $unassigned === 1 ? '' : 's' ?> in the period had no veterinarian assigned.</p><?php endif; ?>
    </div>

    <div class="two" style="margin-top:6px;">
        <div class="block">
            <h3>3.4 Clients and pets</h3>
            <table class="data"><tbody>
                <tr><td>Registered clients</td><td class="num"><?= $c_total ?></td></tr>
                <tr><td>New clients in the period</td><td class="num"><?= $c_new ?></td></tr>
                <tr><td>Clients with visits in the period</td><td class="num"><?= $c_active ?></td></tr>
                <tr><td>Returning clients (2+ completed visits)</td><td class="num"><?= $c_repeat ?></td></tr>
                <tr><td>Registered pets (new in period)</td><td class="num"><?= $p_total ?> (<?= $p_new ?>)</td></tr>
                <tr><td>Dogs / cats / other</td><td class="num"><?= $species['Dogs'] ?> / <?= $species['Cats'] ?> / <?= $species['Other'] ?></td></tr>
                <tr><td>Vaccinations due in the next 30 days</td><td class="num"><?= $vax_due ?></td></tr>
            </tbody></table>
        </div>
        <div class="block">
            <h3>3.5 Boarding</h3>
            <table class="data"><tbody>
                <tr><td>Stays overlapping the period</td><td class="num"><?= $stays ?></td></tr>
                <tr><td>Cage-nights used in the period</td><td class="num"><?= $nights_in ?></td></tr>
                <tr><td>Capacity used (<?= $cage_count ?> cages × <?= $days_in_period ?> days)</td><td class="num"><?= $occupancy ?>%</td></tr>
                <tr><td>Average length of stay</td><td class="num"><?= $avg_stay ?> nights</td></tr>
                <tr><td>Cages occupied today</td><td class="num"><?= $in_now ?> of <?= $cage_count ?></td></tr>
                <tr><td>Boarding revenue in the period</td><td class="num"><?= $peso($stream['Boarding']) ?></td></tr>
            </tbody></table>
        </div>
    </div>

    <div class="block">
        <h3>3.6 Inventory (stock as of <?= date('F j, Y', strtotime($db_today)) ?>)</h3>
        <div class="two">
            <table class="data"><tbody>
                <tr><td>Items in the stock list</td><td class="num"><?= $inv['items'] ?></td></tr>
                <tr><td>Stock value at cost</td><td class="num"><?= $peso($inv['cost']) ?></td></tr>
                <tr><td>Stock value at selling price</td><td class="num"><?= $peso($inv['retail']) ?></td></tr>
                <tr><td>Low stock / out of stock</td><td class="num"><?= $inv['low'] ?> / <?= $inv['out'] ?></td></tr>
                <tr><td>Expiring within 60 days / expired</td><td class="num"><?= $inv['expiring'] ?> / <?= $inv['expired'] ?></td></tr>
            </tbody></table>
            <table class="data"><thead><tr><th>Most used on appointments</th><th class="num">Qty</th></tr></thead><tbody>
                <?php if (!$used): ?><tr><td colspan="2" class="note">None recorded in the period.</td></tr><?php endif; ?>
                <?php foreach ($used as $k => $v): ?><tr><td><?= $h($k) ?></td><td class="num"><?= $v ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
    </div>

    <!-- 4. Notes -->
    <div class="block">
        <h2>4. Notes on the data</h2>
        <ul class="hl" style="font-size:9pt;">
            <li>All figures are computed from records in the PragmaVet system at the time this report was generated.</li>
            <li>Only valid invoices are counted.<?php if ($excluded_cnt): ?> Left out: <?= $excluded_cnt ?> invoice<?= $excluded_cnt === 1 ? '' : 's' ?> worth <?= $peso($excluded_amt) ?> (<?= $excluded['duplicate'][0] ?> duplicate boarding bill<?= $excluded['duplicate'][0] === 1 ? '' : 's' ?>, <?= $excluded['cancelled'][0] ?> for cancelled bookings, <?= $excluded['missing'][0] ?> for deleted bookings, <?= $excluded['void'][0] ?> void).<?php endif; ?></li>
            <li>Revenue is counted by invoice date; “collected” is the amount paid on those invoices, including deposits and reservation fees.</li>
            <li>Appointments are counted by their scheduled date; boarding is reported separately from clinic visits.</li>
            <li>Inventory values depend on the prices, costs and quantities entered in the stock list.</li>
        </ul>
    </div>

    <!-- Sign-off -->
    <div class="sign">
        <div><div class="line">&nbsp;</div><div class="role">Prepared by</div></div>
        <div><div class="line">&nbsp;</div><div class="role">Reviewed by</div></div>
        <div><div class="line"><?= $h($user_name) ?></div><div class="role">Approved by · Clinic Owner</div></div>
    </div>
    <div class="end">— End of report · <?= $h($report_no) ?> —</div>
</div>

<script>
(function () {
    Chart.defaults.font.family = 'Inter, Arial, sans-serif';
    Chart.defaults.font.size = 10;
    Chart.defaults.color = '#334155';
    Chart.defaults.animation = false;
    Chart.defaults.devicePixelRatio = 2;
    var peso = function (v) { return '₱' + Number(v).toLocaleString(); };
    var charts = [];
    function empty(id, msg) { var c = document.getElementById(id); var p = document.createElement('div'); p.style.cssText = 'height:100%;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:9pt;'; p.textContent = msg; c.replaceWith(p); }

    var trend = <?= $json(array_values($trend)) ?>;
    if (trend.some(function (t) { return t[1] > 0; })) {
        charts.push(new Chart(document.getElementById('fTrend'), {
            data: { labels: trend.map(function (t) { return t[0]; }), datasets: [
                { type: 'bar', label: 'Billed', data: trend.map(function (t) { return t[1]; }), backgroundColor: '#2a78d6', borderRadius: 3, maxBarThickness: 18, order: 2 },
                { type: 'line', label: 'Collected', data: trend.map(function (t) { return t[2]; }), borderColor: '#eb6834', backgroundColor: '#eb6834', borderWidth: 2, pointRadius: 2.5, order: 1 } ] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'start', labels: { boxWidth: 10, usePointStyle: true } } },
                scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 8 } }, y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { callback: peso } } } }
        }));
    } else empty('fTrend', 'No invoices in this period');

    var outcomes = <?= $json($outcomes) ?>;
    if (Object.values(outcomes).some(function (v) { return v > 0; })) {
        charts.push(new Chart(document.getElementById('fOutcome'), {
            type: 'bar',
            data: { labels: Object.keys(outcomes), datasets: [{ label: 'Appointments', data: Object.values(outcomes), backgroundColor: '#2a78d6', borderRadius: 3, maxBarThickness: 16 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, layout: { padding: { left: 4 } }, plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e2e8f0' } }, y: { grid: { display: false } } } }
        }));
    } else empty('fOutcome', 'No appointments in this period');

    window.addEventListener('beforeprint', function () { charts.forEach(function (c) { c.resize(); }); });
    window.addEventListener('afterprint', function () { charts.forEach(function (c) { c.resize(); }); });
})();
</script>
</body>
</html>
