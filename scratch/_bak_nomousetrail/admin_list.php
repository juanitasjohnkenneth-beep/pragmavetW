<?php
session_start();
include("dbconnection.php");
include_once("includes/rbac_guard.php");
include_once("includes/pet_data_helper.php");
include_once("includes/activity_log.php");
include_once("includes/doctor_weekly_schedule.php");

// RBAC Access Guard
$user_role = rbac_get_role();
if (!isset($_SESSION['user_id']) || !in_array($user_role, ['admin', 'staff', 'clinic_owner', 'owner'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}

// View-only: vet staff manage appointments (approve, reschedule, check in, assign, complete) in their own dashboard
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $_SESSION['message']  = "Appointment Monitoring is view-only. Vet staff manage appointments from their dashboard.";
    $_SESSION['msg_type'] = "danger";
    header("Location: admin_list.php"); exit();
}
al_ensure($conn);

// Housekeeping: completed / cancelled appointments older than 30 days move to the archive (logged as System)
$conn->query("SET @pv_prev_actor = @pv_actor, @pv_actor = 'System (auto-archive)'");
$conn->query("UPDATE appointments SET is_archived = 1 WHERE is_archived = 0 AND status IN ('Completed', 'Cancelled', 'Rejected') AND appointment_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$conn->query("SET @pv_actor = @pv_prev_actor");

// Clinic "today" comes from the database clock (the PHP and MySQL time zones differ)
$today      = $conn->query("SELECT CURDATE() d")->fetch_assoc()['d'];
$week_start = date('Y-m-d', strtotime('monday this week', strtotime($today)));
$week_end   = date('Y-m-d', strtotime($week_start . ' +6 days'));

const ML_ACTIVE = ['Pending', 'Approved', 'Checked In', 'In Progress', 'Reschedule Proposed', 'Reschedule Requested'];
const ML_CLOSED = ['Completed', 'No Show', 'Cancelled', 'Rejected'];

/** "Dr. Smith (Doctor)" stays as is; plain names get "Dr." once. */
function ml_vet_name($name) {
    $name = trim((string)$name);
    if ($name === '' || $name === 'Unassigned') return '';
    return preg_match('/^dr\.?\s/i', $name) ? $name : 'Dr. ' . $name;
}

// ── Appointments + boarding (not archived) ──────────────────────────────────
$rows = [];
$appts = $conn->query("SELECT a.*, u.fullname as client_name, IFNULL(d.fullname,'Unassigned') as doctor_name, TIMESTAMPDIFF(HOUR, a.created_at, NOW()) AS age_h
                       FROM appointments a JOIN users u ON a.user_id = u.id LEFT JOIN users d ON a.doctor_id = d.id
                       WHERE a.is_archived = 0 AND a.service_type != 'Pet Boarding Reservation' ORDER BY a.appointment_date DESC, a.appointment_time DESC");
if ($appts) while ($r = $appts->fetch_assoc()) { $r['_type'] = 'Appointment'; $rows[] = $r; }
$boards = $conn->query("SELECT b.*, u.fullname as client_name, TIMESTAMPDIFF(HOUR, b.created_at, NOW()) AS age_h
                        FROM boarding_reservations b JOIN users u ON b.user_id = u.id
                        WHERE b.status NOT IN ('Completed', 'Cancelled', 'Rejected') OR COALESCE(b.end_date, b.appointment_date) >= CURDATE() - INTERVAL 30 DAY
                        ORDER BY b.appointment_date DESC");
if ($boards) while ($r = $boards->fetch_assoc()) {
    $r['_type'] = 'Boarding';
    $r['doctor_name'] = 'Clinic (Boarding)';
    $r['service_type'] = 'Pet Boarding (Cage ' . str_pad($r['cage_number'] ?? '?', 2, '0', STR_PAD_LEFT) . ')';
    $r['service_location'] = 'On-Site';
    $r['appointment_time'] = $r['appointment_time'] ?? '12:00:00';
    $rows[] = $r;
}
usort($rows, fn($a, $b) => strtotime($b['appointment_date'] . ' ' . $b['appointment_time']) - strtotime($a['appointment_date'] . ' ' . $a['appointment_time']));

// Flags the admin should notice
$attn = ['overdue' => 0, 'stale' => 0, 'unassigned' => 0, 'mismatch' => 0];
$chip = array_fill_keys(array_merge(ML_ACTIVE, ML_CLOSED), 0);
$chip_active = 0; $chip_closed = 0; $cnt_today = 0; $cnt_week = 0;
foreach ($rows as &$r) {
    $st = (string)$r['status'];
    $active = in_array($st, ML_ACTIVE, true);
    $flags = [];
    if ($active && $r['appointment_date'] < $today) $flags[] = 'overdue';
    if ($st === 'Pending' && (int)$r['age_h'] >= 24) $flags[] = 'stale';
    if ($active && $r['_type'] === 'Appointment' && empty($r['doctor_id'])) $flags[] = 'unassigned';
    if ($st === 'Completed' && $r['appointment_date'] > $today) $flags[] = 'mismatch';
    foreach ($flags as $f) $attn[$f]++;
    $r['_flags']  = $flags;
    $r['_group']  = $active ? 'active' : (in_array($st, ML_CLOSED, true) ? 'closed' : 'other');
    $r['_vet']    = $r['_type'] === 'Boarding' ? 'Clinic (Boarding)' : (ml_vet_name($r['doctor_name']) ?: 'Unassigned');
    $r['_vet_id'] = $r['_type'] === 'Boarding' ? 'boarding' : (empty($r['doctor_id']) ? 'none' : (string)(int)$r['doctor_id']);
    if (isset($chip[$st])) $chip[$st]++;
    if ($active) $chip_active++; elseif ($r['_group'] === 'closed') $chip_closed++;
    if (!in_array($st, ['Cancelled', 'Rejected'], true)) {
        if ($r['appointment_date'] === $today) $cnt_today++;
        if ($r['appointment_date'] >= $week_start && $r['appointment_date'] <= $week_end) $cnt_week++;
    }
}
unset($r);
$chip_cancel = $chip['Cancelled'] + $chip['Rejected'];
$chip_resched = $chip['Reschedule Proposed'] + $chip['Reschedule Requested'];
$chip_checked = $chip['Checked In'] + $chip['In Progress'];

// No-show and cancellation rates — appointments dated in the last 30 days (archived ones included)
$rt = $conn->query("SELECT COUNT(*) total, SUM(status='Completed') done, SUM(status='No Show') noshow, SUM(status IN ('Cancelled','Rejected')) cancelled
                    FROM appointments WHERE service_type != 'Pet Boarding Reservation' AND appointment_date BETWEEN CURDATE() - INTERVAL 29 DAY AND CURDATE()")->fetch_assoc();
$rt_total = (int)$rt['total']; $rt_done = (int)$rt['done']; $rt_ns = (int)$rt['noshow']; $rt_cx = (int)$rt['cancelled'];
$noshow_rate = ($rt_done + $rt_ns) > 0 ? round($rt_ns * 100 / ($rt_done + $rt_ns)) : null;
$cancel_rate = $rt_total > 0 ? round($rt_cx * 100 / $rt_total) : null;

// Vet caseload (today / this week) with today's working hours from the weekly schedule
$vets = [];
$dq = $conn->query("SELECT id, fullname FROM users WHERE role='doctor' ORDER BY fullname ASC");
if ($dq) while ($d = $dq->fetch_assoc()) {
    $id = (int)$d['id'];
    $day = dws_get_day($conn, $id, $today);
    $vets[$id] = ['name' => ml_vet_name($d['fullname']), 'today' => 0, 'week' => 0, 'pending' => 0,
                  'working' => !empty($day['is_working']),
                  'hours' => !empty($day['is_working']) ? date('g:i A', strtotime($day['work_start'])) . ' – ' . date('g:i A', strtotime($day['work_end'])) : 'Day off'];
}
foreach ($rows as $r) {
    $vid = (int)($r['doctor_id'] ?? 0);
    if ($r['_type'] !== 'Appointment' || !isset($vets[$vid]) || in_array($r['status'], ['Cancelled', 'Rejected'], true)) continue;
    if ($r['appointment_date'] === $today) $vets[$vid]['today']++;
    if ($r['appointment_date'] >= $week_start && $r['appointment_date'] <= $week_end) $vets[$vid]['week']++;
    if (in_array($r['status'], ML_ACTIVE, true)) $vets[$vid]['pending']++;
}
$max_week = max(1, ...array_map(fn($v) => $v['week'], $vets ?: [['week' => 1]]));

// Activity history per appointment (audit log) for the details window
$hist = [];
$apt_ids = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['_type'] === 'Appointment'));
$brd_ids = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['_type'] === 'Boarding'));
if ($apt_ids) {
    $hq = $conn->query("SELECT record_id, action, details, actor, source, created_at FROM activity_log
                        WHERE (module = 'Appointments' OR (module = 'Medical' AND action = 'Consultation recorded')) AND record_id IN (" . implode(',', $apt_ids) . ")
                        ORDER BY created_at ASC, id ASC");
    if ($hq) while ($h = $hq->fetch_assoc()) $hist['A' . $h['record_id']][] = ['at' => $h['created_at'], 'action' => $h['action'], 'details' => str_replace(' -> ', ' → ', (string)$h['details']), 'actor' => $h['actor'] ?: 'System', 'rec' => $h['source'] === 'history'];
}
if ($brd_ids) {
    $hq = $conn->query("SELECT reservation_id, action, details, actor, created_at FROM boarding_audit_log WHERE reservation_id IN (" . implode(',', $brd_ids) . ") ORDER BY created_at ASC, id ASC");
    if ($hq) while ($h = $hq->fetch_assoc()) $hist['B' . $h['reservation_id']][] = ['at' => $h['created_at'], 'action' => $h['action'], 'details' => str_replace(' -> ', ' → ', (string)$h['details']), 'actor' => $h['actor'] ?: 'System', 'rec' => false];
}

// Fetch pet EMR details for quick view modal
$pets_emr_list = [];
$pemr_q = $conn->query("SELECT p.*, u.fullname as owner_name FROM pets p JOIN users u ON p.user_id = u.id WHERE p.deleted_at IS NULL");
if ($pemr_q) {
    while ($p = $pemr_q->fetch_assoc()) {
        $pets_emr_list[strtolower($p['user_id'] . '_' . trim($p['name']))] = $p;
        $pets_emr_list['id_' . $p['id']] = $p;
    }
}

$FLAG_LABEL = ['overdue' => ['Overdue', 'fa-clock'], 'stale' => ['Waiting 24h+', 'fa-hourglass-half'], 'unassigned' => ['No vet', 'fa-user-doctor'], 'mismatch' => ['Done before date', 'fa-calendar-xmark']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Appointment Monitoring | VetAdmin</title>
<meta charset="UTF-8">
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --primary:#2563eb; --secondary:#3b82f6; --dark:#1e40af; --accent:#3b82f6; --light:#E6EEF8; --sidebar-width:260px; }
* { box-sizing:border-box; }
body { background:#f0f4f8; font-family:'Inter',sans-serif; color:#2D3748; margin:0; }

/* SIDEBAR */
.sidebar { background:#0f172a; width:var(--sidebar-width); height:100vh; position:fixed; top:0; left:0; padding:24px 16px; z-index:1000; display:flex; flex-direction:column; box-shadow:5px 0 20px rgba(0,0,0,.2); overflow-y:auto; }
.sidebar h4 { color:#ffffff; font-weight:800; font-size:22px; margin-bottom:30px; letter-spacing:0.5px; display:flex; align-items:center; gap:10px; }
.sidebar a { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:8px; color:#94a3b8; text-decoration:none; margin-bottom:4px; font-weight:600; font-size:15px; transition:.2s; }
.sidebar a i { width:18px; text-align:center; }
.sidebar a:hover, .sidebar a.active { background:#2563eb; color:#ffffff; }
.sidebar-logout { margin-top:auto; }
.sidebar-logout a { color:#ff7070 !important; }
.sidebar-logout a:hover { background:rgba(255,112,112,.15) !important; }

/* MAIN */
.main-content { margin-left: 260px !important; width: calc(100% - 260px) !important; max-width: calc(100% - 260px) !important; padding: 24px 28px !important; min-height: 100vh; box-sizing: border-box !important; }

@media (max-width: 1200px) {
    .main-content { padding: 20px 20px !important; }
}

@media (max-width: 768px) {
    .main-content { margin-left: 0 !important; width: 100% !important; max-width: 100% !important; padding: 16px !important; }
    .sidebar { position: relative !important; width: 100% !important; height: auto !important; }
}

/* STAT CARDS */
.stat-card { background:#fff; border-radius:14px; padding:18px 22px; box-shadow:0 2px 12px rgba(0,0,0,.05); display:flex; align-items:center; gap:14px; }
.stat-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }

/* MAIN CARD */
.card-main { background:#fff; border-radius:18px; box-shadow:0 4px 24px rgba(0,0,0,.06); overflow:hidden; }
.card-toolbar { padding:20px 24px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }

/* SEARCH */
.search-w { position:relative; }
.search-w input { padding:9px 14px 9px 36px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; font-family:inherit; background:#E6EEF8; outline:none; transition:.2s; min-width:240px; }
.search-w input:focus { border-color:var(--accent); background:#fff; box-shadow:0 0 0 3px rgba(74, 127, 196,.15); }
.search-w .si { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; pointer-events:none; }

/* FILTER TABS */
.ftab { padding:7px 14px; border-radius:20px; border:1.5px solid #e2e8f0; background:#E6EEF8; font-size:12px; font-weight:700; color:#64748b; cursor:pointer; transition:.15s; font-family:inherit; display:inline-flex; align-items:center; gap:5px; }
.ftab:hover, .ftab.active { background:var(--secondary); border-color:var(--secondary); color:#fff; }
.ftab .cnt { background:rgba(255,255,255,.25); border-radius:20px; padding:1px 7px; font-size:11px; }
.ftab:not(.active) .cnt { background:#e2e8f0; color:#64748b; }

/* TABLE */
.appt-table { width:100%; border-collapse:collapse; }
.appt-table thead th { padding:11px 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; border-bottom:2px solid #f1f5f9; white-space:nowrap; background:#fafbfc; }
.appt-table tbody tr { cursor:pointer; transition:background .12s; }
.appt-table tbody tr:hover { background:#E6EEF8; }
.appt-table tbody td { padding:14px 16px; vertical-align:middle; border-bottom:1px solid #f1f5f9; font-size:13px; }
.appt-table tbody tr:last-child td { border-bottom:none; }

/* AVATAR */
.av { width:40px; height:40px; border-radius:11px; background:linear-gradient(135deg,#e0f2fe,#bae6fd); display:flex; align-items:center; justify-content:center; font-size:17px; color:#0369a1; flex-shrink:0; }

/* STATUS */
.bs { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:20px; font-size:12px; font-weight:700; border:1.5px solid transparent; }
.bs-pending     { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
.bs-approved    { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; }
.bs-checkin     { background:#ecfeff; color:#0e7490; border-color:#a5f3fc; }
.bs-inprogress  { background:#f5f3ff; color:#6d28d9; border-color:#ddd6fe; }
.bs-completed   { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
.bs-noshow      { background:#fff1f2; color:#9f1239; border-color:#fda4af; }
.bs-cancelled   { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
.bs-proposed    { background:#fdf4ff; color:#7e22ce; border-color:#e9d5ff; }
.bs-requested   { background:#f5f3ff; color:#7c3aed; border-color:#ddd6fe; }
.bs-default     { background:#E6EEF8; color:#64748b; border-color:#e2e8f0; }

/* TOAST */
.toast-wrap { position:fixed; top:22px; right:28px; z-index:99999; min-width:300px; border-radius:14px; padding:15px 18px; font-size:14px; font-weight:500; box-shadow:0 8px 32px rgba(0,0,0,.14); animation:toastIn .35s ease; display:flex; align-items:flex-start; gap:10px; }
.toast-wrap.success { background:#f0fdf4; border:1.5px solid #bbf7d0; color:#15803d; }
.toast-wrap.danger  { background:#fff1f2; border:1.5px solid #fecdd3; color:#be123c; }
@keyframes toastIn { from{opacity:0;transform:translateY(-14px) scale(.96)} to{opacity:1;transform:none} }

/* DETAIL MODAL */
.dmodal-bd { position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:99998; display:none; align-items:center; justify-content:center; padding:16px; opacity:0; transition:opacity .18s; }
.dmodal-bd.open { display:flex; }
.dmodal { background:#fff; border-radius:20px; width:100%; max-width:560px; max-height:88vh; display:flex; flex-direction:column; box-shadow:0 24px 64px rgba(0,0,0,.2); overflow:hidden; transform:scale(.95) translateY(10px); transition:transform .2s,opacity .2s; opacity:0; }
.dmodal-bd.open .dmodal { transform:none; opacity:1; }
.dmodal-hdr { padding:20px 24px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.dmdl-close { width:32px; height:32px; border-radius:50%; border:1.5px solid #e2e8f0; background:#E6EEF8; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:15px; }
.dmdl-close:hover { background:#fee2e2; border-color:#fca5a5; color:#e11d48; }
.dmodal-body { overflow-y:auto; padding:22px 24px; flex:1; }
.dmodal-foot { padding:16px 24px; border-top:1px solid #f1f5f9; }
.info-g { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
.info-i label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; display:block; margin-bottom:3px; }
.info-i .v { font-size:14px; font-weight:600; color:#0f172a; }
.info-i.full { grid-column:1/-1; }
.hero-svc { text-align:center; background:linear-gradient(135deg,#eff6ff,#dbeafe); border-radius:14px; padding:16px; margin-bottom:16px; }
.hero-svc .sn { font-size:20px; font-weight:800; color:var(--secondary); }
.hero-svc .sd { font-size:13px; color:#64748b; margin-top:2px; }
.btn-act { padding:10px 18px; border-radius:50px; border:none; font-weight:700; font-size:13px; font-family:inherit; cursor:pointer; transition:.2s; }
.btn-act:hover { opacity:.88; transform:translateY(-1px); }
.btn-act.g { background:#16a34a; color:#fff; }
.btn-act.b { background:var(--secondary); color:#fff; }
.btn-act.r { background:#e11d48; color:#fff; }
.btn-act.o { background:#ea580c; color:#fff; }
.btn-act.gray { background:#f1f5f9; color:#475569; border:1.5px solid #e2e8f0; }

.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }

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

        .sidebar .sidebar-logout a i {
            color: #dc2626 !important;
        }

        .sidebar .sidebar-logout a:hover {
            background: #fef2f2 !important;
            color: #b91c1c !important;
        }

        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.06); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }

/* ====== MONITORING (view-only) ====== */
.ml-head h2 { font-weight:800; font-size:26px; color:#0f172a; margin:0; letter-spacing:-.6px; -webkit-text-fill-color:#0f172a; background:none; }
.ml-head p { color:#64748b; font-size:14px; margin:4px 0 0; }
.ml-ro { display:inline-flex; align-items:center; gap:6px; background:#f1f5f9; color:#475569; border-radius:20px; padding:3px 10px; font-size:11.5px; font-weight:700; margin-left:8px; vertical-align:middle; }
.ml-btn { border:1.5px solid #e2e8f0; border-radius:12px; padding:9px 16px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:8px; background:#fff !important; color:#334155 !important; cursor:pointer; }
.ml-btn:hover { border-color:#2563eb; color:#2563eb !important; }
.ml-btn.on { background:#2563eb !important; border-color:#2563eb; color:#fff !important; }
.ml-sec { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin:0 0 10px; }

.ml-attn { background:#fff; border:1.5px solid #eef2f6; border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px; cursor:pointer; transition:.15s; height:100%; text-align:left; width:100%; }
.ml-attn:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(15,23,42,.07); }
.ml-attn.zero { cursor:default; opacity:.65; }
.ml-attn.zero:hover { transform:none; box-shadow:none; }
.ml-attn.sel { border-color:currentColor; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
.ml-attn .ic { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.ml-attn .v { font-size:22px; font-weight:800; color:#0f172a; line-height:1.1; }
.ml-attn .l { font-size:12.5px; color:#334155; font-weight:700; }
.ml-attn .s { font-size:11.5px; color:#94a3b8; font-weight:500; }

.ml-kpi { background:#fff; border-radius:16px; border:1px solid #eef2f6; padding:14px 16px; box-shadow:0 2px 10px rgba(15,23,42,.04); }
.ml-kpi .v { font-size:22px; font-weight:800; color:#0f172a; line-height:1.1; }
.ml-kpi .l { font-size:12px; color:#64748b; font-weight:600; }
.ml-kpi .s { font-size:11px; color:#94a3b8; margin-top:2px; }
.ml-bar { height:6px; border-radius:6px; background:#f1f5f9; overflow:hidden; margin-top:8px; }
.ml-bar span { display:block; height:100%; border-radius:6px; }

.ml-vets { background:#fff; border-radius:16px; border:1px solid #eef2f6; padding:14px 16px; box-shadow:0 2px 10px rgba(15,23,42,.04); }
.ml-vet { display:grid; grid-template-columns:minmax(0,1fr) 54px 54px; gap:10px; align-items:center; padding:8px 0; border-top:1px solid #f1f5f9; }
.ml-vet:first-of-type { border-top:none; }
.ml-vet .nm { font-weight:700; font-size:13px; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ml-vet .vhrs { font-size:11.5px; color:#64748b; }
.ml-vet .vhrs .off { color:#be123c; font-weight:700; }
.ml-vet .num { text-align:center; font-weight:800; font-size:15px; color:#0f172a; }
.ml-vet .num small { display:block; font-size:10px; color:#94a3b8; font-weight:700; text-transform:uppercase; }
.ml-vet .wk { grid-column:1 / -1; }

.ml-tools { padding:16px 22px; border-bottom:1px solid #f1f5f9; display:grid; grid-template-columns:minmax(200px,1.6fr) minmax(150px,1fr) 145px 145px auto; gap:10px; align-items:end; }
.ml-tools label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; display:block; margin-bottom:4px; }
.ml-tools .form-control, .ml-tools .form-select { border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; padding:8px 12px; background-color:#f8fafc; }
.ml-tools .srch { position:relative; }
.ml-tools .srch i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; }
.ml-tools .srch input { padding-left:34px; }
.ml-seg { display:inline-flex; background:#f1f5f9; border-radius:11px; padding:3px; gap:2px; }
.ml-seg button { border:none; background:transparent !important; color:#475569 !important; font-weight:700; font-size:12.5px; padding:7px 11px; border-radius:9px; white-space:nowrap; box-shadow:none !important; }
.ml-seg button.on { background:#fff !important; color:#2563eb !important; box-shadow:0 1px 4px rgba(15,23,42,.1) !important; }

.ml-chips { padding:12px 22px; display:flex; align-items:center; gap:7px; flex-wrap:wrap; border-bottom:1px solid #f1f5f9; background:#fbfcfe; }
.ml-chips .grp { font-size:10.5px; font-weight:800; color:#94a3b8; text-transform:uppercase; letter-spacing:.07em; margin:0 2px 0 8px; }
.ml-chips .sep { width:1px; height:22px; background:#e2e8f0; margin:0 4px; }
.ftab .cnt { min-width:20px; text-align:center; }
.ml-flagpill { display:none; align-items:center; gap:7px; background:#fef2f2; color:#b91c1c; border:1.5px solid #fecaca; border-radius:20px; padding:5px 10px 5px 12px; font-size:12px; font-weight:700; margin-left:auto; }
.ml-flagpill button { border:none; background:transparent !important; color:#b91c1c !important; padding:0 2px; font-size:13px; }

.ml-flag { display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:6px; font-size:10.5px; font-weight:700; margin-top:4px; margin-right:3px; }
.ml-flag.overdue { background:#fef2f2; color:#b91c1c; }
.ml-flag.stale { background:#fff7ed; color:#c2410c; }
.ml-flag.unassigned { background:#f5f3ff; color:#6d28d9; }
.ml-flag.mismatch { background:#fefce8; color:#a16207; }
.ml-vettxt { font-size:12.5px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:6px; }
.ml-vettxt.none { color:#94a3b8; font-style:italic; font-weight:500; }

.ml-tl { list-style:none; padding:0; margin:0; position:relative; }
.ml-tl::before { content:''; position:absolute; left:13px; top:6px; bottom:6px; width:2px; background:#eef2f6; }
.ml-tl li { position:relative; padding:0 0 12px 38px; }
.ml-tl .dot { position:absolute; left:4px; top:2px; width:20px; height:20px; border-radius:50%; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:9px; border:2px solid #fff; }
.ml-tl .t { font-weight:700; font-size:13px; color:#0f172a; }
.ml-tl .t span { font-weight:500; font-size:11.5px; color:#94a3b8; margin-left:6px; }
.ml-tl .d { font-size:12.5px; color:#475569; }
.ml-tl .a { font-size:11.5px; color:#64748b; }
.ml-tl .rec { font-size:10px; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:5px; padding:0 5px; margin-left:4px; }

@media (max-width: 1200px) { .ml-tools { grid-template-columns:1fr 1fr; } }
@media print {
    .sidebar, .no-print, .ml-tools, .ml-chips, .toast-wrap, .appt-table th:last-child, .appt-table td:last-child { display:none !important; }
    .main-content { margin:0 !important; width:100% !important; max-width:100% !important; padding:0 !important; }
    .card-main { box-shadow:none !important; }
    body { background:#fff !important; }
    .print-only { display:block !important; }
}
.print-only { display:none; }
</style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<?php if (isset($_SESSION['message'])): ?>
<div class="toast-wrap <?php echo $_SESSION['msg_type']==='success'?'success':'danger'; ?>" id="toastMsg">
    <i class="fa <?php echo $_SESSION['msg_type']==='success'?'fa-check-circle':'fa-exclamation-circle'; ?>" style="font-size:17px;margin-top:1px;flex-shrink:0;"></i>
    <div><?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message'],$_SESSION['msg_type']); ?></div>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;margin-left:auto;font-size:15px;color:inherit;opacity:.5;">&#x2715;</button>
</div>
<?php endif; ?>

<!-- SIDEBAR -->
<?php include("includes/admin_sidebar.php"); ?>

<div class="main-content">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3 ml-head">
        <div>
            <h2>Appointment Monitoring <span class="ml-ro"><i class="fa fa-eye"></i> View only</span></h2>
            <p>Requests, schedules, vet caseload and status of every appointment. Vet staff handle approvals, check-ins and completion.</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <button type="button" onclick="window.print()" class="ml-btn"><i class="fa fa-print"></i> Print Desk Sheet</button>
            <button type="button" id="btnToggleCalendarView" onclick="toggleAdminCalendarView()" class="ml-btn"><i class="fa fa-calendar-alt"></i> Calendar View</button>
        </div>
    </div>
    <div class="print-only" style="margin-bottom:10px;font-size:12px;color:#475569;">Printed <?= date('M d, Y g:i A') ?> · <span id="printFilterNote">All appointments</span></div>

    <!-- Needs attention -->
    <div class="no-print">
        <p class="ml-sec"><i class="fa fa-triangle-exclamation me-1"></i>Needs attention</p>
        <div class="row g-3 mb-4">
            <?php
            $attn_cards = [
                'overdue'    => ['Overdue', 'Date passed, still not closed', 'fa-clock', '#fef2f2', '#dc2626'],
                'stale'      => ['Pending over 24h', 'Requests not yet answered', 'fa-hourglass-half', '#fff7ed', '#ea580c'],
                'unassigned' => ['No vet assigned', 'Active appointments', 'fa-user-doctor', '#f5f3ff', '#7c3aed'],
                'mismatch'   => ['Done before date', 'Completed ahead of schedule', 'fa-calendar-xmark', '#fefce8', '#ca8a04'],
            ];
            foreach ($attn_cards as $k => [$lbl, $sub, $ic, $bg, $col]): $n = $attn[$k]; ?>
            <div class="col-6 col-xl-3">
                <button type="button" class="ml-attn <?= $n ? '' : 'zero' ?>" data-flag="<?= $k ?>" style="color:<?= $col ?>;" <?= $n ? "onclick=\"mlSetFlag('$k')\"" : 'disabled' ?> title="<?= $n ? 'Show these appointments' : 'Nothing to check' ?>">
                    <div class="ic" style="background:<?= $bg ?>;color:<?= $col ?>;"><i class="fa <?= $ic ?>"></i></div>
                    <div><div class="v"><?= $n ?></div><div class="l"><?= $lbl ?></div><div class="s"><?= $sub ?></div></div>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-5">
                <div class="row g-3">
                    <div class="col-6"><div class="ml-kpi"><div class="v"><?= $cnt_today ?></div><div class="l">Appointments today</div><div class="s"><?= date('l, M d', strtotime($today)) ?></div></div></div>
                    <div class="col-6"><div class="ml-kpi"><div class="v"><?= $cnt_week ?></div><div class="l">This week</div><div class="s"><?= date('M d', strtotime($week_start)) ?> – <?= date('M d', strtotime($week_end)) ?></div></div></div>
                    <div class="col-6"><div class="ml-kpi">
                        <div class="v"><?= $noshow_rate === null ? '—' : $noshow_rate . '%' ?></div><div class="l">No-show rate</div>
                        <div class="s"><?= $rt_ns ?> of <?= $rt_done + $rt_ns ?> due visits · last 30 days</div>
                        <div class="ml-bar"><span style="width:<?= (int)$noshow_rate ?>%;background:#be123c;"></span></div>
                    </div></div>
                    <div class="col-6"><div class="ml-kpi">
                        <div class="v"><?= $cancel_rate === null ? '—' : $cancel_rate . '%' ?></div><div class="l">Cancellation rate</div>
                        <div class="s"><?= $rt_cx ?> of <?= $rt_total ?> bookings · last 30 days</div>
                        <div class="ml-bar"><span style="width:<?= (int)$cancel_rate ?>%;background:#ea580c;"></span></div>
                    </div></div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="ml-vets">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <p class="ml-sec m-0"><i class="fa fa-user-doctor me-1"></i>Vet caseload</p>
                        <div class="d-flex gap-3" style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;"><span style="width:54px;text-align:center;">Today</span><span style="width:54px;text-align:center;">Week</span></div>
                    </div>
                    <?php if (!$vets): ?><div class="text-muted small py-3">No veterinarians registered.</div><?php endif; ?>
                    <?php foreach ($vets as $vid => $v): ?>
                    <div class="ml-vet">
                        <div style="min-width:0;">
                            <div class="nm"><?= htmlspecialchars($v['name']) ?></div>
                            <div class="vhrs"><i class="fa fa-clock me-1 opacity-50"></i><?= $v['working'] ? 'Today ' . $v['hours'] : '<span class="off">Day off today</span>' ?><?= $v['pending'] ? ' · ' . $v['pending'] . ' active' : '' ?></div>
                        </div>
                        <div class="num"><?= $v['today'] ?></div>
                        <div class="num"><?= $v['week'] ?></div>
                        <div class="wk"><div class="ml-bar" style="margin-top:0;"><span style="width:<?= round($v['week'] * 100 / $max_week) ?>%;background:#2563eb;"></span></div></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($attn['unassigned']): ?><div class="small mt-2" style="color:#6d28d9;font-weight:600;"><i class="fa fa-circle-info me-1"></i><?= $attn['unassigned'] ?> active appointment<?= $attn['unassigned'] > 1 ? 's have' : ' has' ?> no vet yet.</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- VISUAL FULLCALENDAR CONTAINER (Hidden by default) -->
    <div id="adminCalendarWrapper" class="card-main mb-4" style="display:none;">
        <div class="cal-head">
            <div>
                <h5><i class="fa fa-calendar-days me-2"></i>Appointment Calendar</h5>
                <div class="cal-sub">Click an appointment to see its details and history.</div>
            </div>
            <div class="cal-legend">
                <span><i style="background:#ea580c"></i>Pending</span>
                <span><i style="background:#16a34a"></i>Approved</span>
                <span><i style="background:#0891b2"></i>Checked in</span>
                <span><i style="background:#a855f7"></i>Awaiting client</span>
                <span><i style="background:#2563eb"></i>Completed</span>
                <span><i style="background:#9f1239"></i>No show</span>
                <span><i style="background:#94a3b8"></i>Cancelled</span>
                <span><i class="ring"></i>Overdue</span>
            </div>
        </div>
        <div id="adminFullCalendar"></div>
    </div>

    <!-- Main appointments card -->
    <div class="card-main" id="adminTableWrapper">
        <div class="ml-tools">
            <div>
                <label>Search</label>
                <div class="srch"><i class="fa fa-search"></i><input type="text" id="apptSearch" class="form-control" placeholder="Client, pet, service, #ID…"></div>
            </div>
            <div>
                <label>Vet</label>
                <select id="vetFilter" class="form-select">
                    <option value="">All vets</option>
                    <?php foreach ($vets as $vid => $v): ?><option value="<?= $vid ?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
                    <option value="none">No vet assigned</option>
                    <option value="boarding">Boarding (clinic)</option>
                </select>
            </div>
            <div><label>From</label><input type="date" id="dateFrom" class="form-control"></div>
            <div><label>To</label><input type="date" id="dateTo" class="form-control"></div>
            <div>
                <label>Quick</label>
                <div class="ml-seg" id="quickSeg">
                    <button type="button" class="on" data-q="">Any date</button>
                    <button type="button" data-q="today">Today</button>
                    <button type="button" data-q="week">This week</button>
                    <button type="button" data-q="upcoming">Upcoming</button>
                </div>
            </div>
        </div>

        <div class="ml-chips">
            <button class="ftab active" data-filter="all">All <span class="cnt"><?= count($rows) ?></span></button>
            <span class="sep"></span><span class="grp">Active</span>
            <button class="ftab" data-filter="g:active">All active <span class="cnt"><?= $chip_active ?></span></button>
            <button class="ftab" data-filter="Pending">Pending <span class="cnt"><?= $chip['Pending'] ?></span></button>
            <button class="ftab" data-filter="Approved">Approved <span class="cnt"><?= $chip['Approved'] ?></span></button>
            <button class="ftab" data-filter="s:checked">Checked in <span class="cnt"><?= $chip_checked ?></span></button>
            <button class="ftab" data-filter="s:resched" title="New date proposed, waiting for the client's answer">Awaiting client <span class="cnt"><?= $chip_resched ?></span></button>
            <span class="sep"></span><span class="grp">Closed</span>
            <button class="ftab" data-filter="g:closed">All closed <span class="cnt"><?= $chip_closed ?></span></button>
            <button class="ftab" data-filter="Completed">Completed <span class="cnt"><?= $chip['Completed'] ?></span></button>
            <button class="ftab" data-filter="No Show">No show <span class="cnt"><?= $chip['No Show'] ?></span></button>
            <button class="ftab" data-filter="s:cancel">Cancelled <span class="cnt"><?= $chip_cancel ?></span></button>
            <span class="ml-flagpill" id="flagPill"><i class="fa fa-filter"></i><span id="flagPillTxt"></span><button type="button" onclick="mlSetFlag('')" title="Clear"><i class="fa fa-xmark"></i></button></span>
        </div>

        <div style="overflow-x:auto;">
        <?php if (count($rows) > 0): ?>
        <table class="appt-table" id="apptTable">
            <thead>
                <tr>
                    <th data-sort-method="number" style="cursor:pointer;"># <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Client / Pet <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Service <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Schedule <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Vet <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Location <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Status <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Booked <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th data-sort-method="none">View</th>
                </tr>
            </thead>
            <tbody id="apptBody">
            <?php foreach ($rows as $row):
                $status = $row['status'];
                $bsCls = match(true) {
                    $status === 'Pending'              => 'bs-pending',
                    $status === 'Approved'             => 'bs-approved',
                    $status === 'Checked In'           => 'bs-checkin',
                    $status === 'In Progress'          => 'bs-inprogress',
                    $status === 'Completed'            => 'bs-completed',
                    $status === 'No Show'              => 'bs-noshow',
                    $status === 'Reschedule Proposed'  => 'bs-proposed',
                    $status === 'Reschedule Requested' => 'bs-requested',
                    str_contains($status,'Cancel') || str_contains($status,'Reject') => 'bs-cancelled',
                    default => 'bs-default'
                };
                $dot = match(true) {
                    $status === 'Pending'              => '#ea580c',
                    $status === 'Approved'             => '#16a34a',
                    $status === 'Checked In'           => '#0891b2',
                    $status === 'In Progress'          => '#7c3aed',
                    $status === 'Completed'            => '#2563eb',
                    $status === 'No Show'              => '#9f1239',
                    $status === 'Reschedule Proposed'  => '#7e22ce',
                    $status === 'Reschedule Requested' => '#7c3aed',
                    str_contains($status,'Cancel') || str_contains($status,'Reject') => '#be123c',
                    default => '#94a3b8'
                };
                $status_label = $status === 'Reschedule Proposed' ? 'Awaiting client' : $status;
                $skey = in_array($status, ['Checked In', 'In Progress'], true) ? 'checked' : (str_starts_with($status, 'Reschedule') ? 'resched' : ((str_contains($status, 'Cancel') || str_contains($status, 'Reject')) ? 'cancel' : ''));
                $rowData = htmlspecialchars(json_encode($row, JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES);
            ?>
            <tr class="appt-row" data-status="<?= htmlspecialchars($status) ?>" data-skey="<?= $skey ?>" data-group="<?= $row['_group'] ?>" data-date="<?= htmlspecialchars($row['appointment_date']) ?>" data-vet="<?= htmlspecialchars($row['_vet_id']) ?>" data-flags="<?= implode(' ', $row['_flags']) ?>" onclick="openModal(<?= $rowData ?>)">
                <td style="font-size:12px;color:#94a3b8;font-weight:600;">#<?php echo $row['id']; ?><?= $row['_type'] === 'Boarding' ? '<div style="font-size:10px;">Boarding</div>' : '' ?></td>
                <td>
                    <?php
                    $appt_pets_html = '';
                    if ($row['_type'] === 'Appointment') {
                        include_once("includes/pet_data_helper.php");
                        $appt_pets = function_exists('getAppointmentPets') ? getAppointmentPets($conn, $row['id']) : [];
                        if (!empty($appt_pets)) {
                            $appt_pets_html = '<div style="display:flex;flex-direction:column;gap:3px;">';
                            foreach (array_slice($appt_pets, 0, 2) as $ap) {
                                $pic = !empty($ap['display_picture']) ? $ap['display_picture'] : null;
                                $appt_pets_html .= '<div style="display:flex;align-items:center;gap:6px;">';
                                if ($pic && file_exists('uploads/pets/' . $pic)) {
                                    $appt_pets_html .= '<img src="uploads/pets/' . htmlspecialchars($pic) . '" style="width:22px;height:22px;border-radius:50%;object-fit:cover;border:1.5px solid #e2e8f0;flex-shrink:0;">';
                                } else {
                                    $init = strtoupper(substr($ap['display_name'], 0, 1));
                                    $appt_pets_html .= '<div style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#e0f2fe,#bae6fd);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#2563eb;flex-shrink:0;">' . $init . '</div>';
                                }
                                $appt_pets_html .= '<a href="#" onclick="openQuickPetEmr(\'' . htmlspecialchars(addslashes($ap['display_name'])) . '\', ' . (int)$row['user_id'] . ', event)" class="fw-bold text-decoration-none text-primary" style="font-size:12px;" title="Click for Quick EMR Preview">' . htmlspecialchars($ap['display_name']) . '</a></div>';
                            }
                            if (count($appt_pets) > 2) {
                                $extra = count($appt_pets) - 2;
                                $appt_pets_html .= '<div style="font-size:11px;color:#94a3b8;padding-left:28px;">+' . $extra . ' more</div>';
                            }
                            $appt_pets_html .= '</div>';
                        } else {
                            $appt_pets_html = '<a href="#" onclick="openQuickPetEmr(\'' . htmlspecialchars(addslashes($row['pet_name'])) . '\', ' . (int)$row['user_id'] . ', event)" class="fw-bold text-decoration-none text-primary" style="font-size:12px;" title="Click for Quick EMR Preview"><i class="fa fa-paw me-1"></i>' . htmlspecialchars($row['pet_name']) . '</a>';
                        }
                    } else {
                        $appt_pets_html = '<a href="#" onclick="openQuickPetEmr(\'' . htmlspecialchars(addslashes($row['pet_name'] ?? 'Boarding')) . '\', ' . (int)$row['user_id'] . ', event)" class="fw-bold text-decoration-none text-primary" style="font-size:12px;" title="Click for Quick EMR Preview"><i class="fa fa-paw me-1"></i>' . htmlspecialchars($row['pet_name'] ?? 'Boarding') . '</a>';
                    }
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="av" style="background:linear-gradient(135deg,#e0f2fe,#bae6fd);"><i class="fa fa-paw" style="color:#2563eb;"></i></div>
                        <div>
                            <div style="font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($row['client_name']); ?></div>
                            <?php echo $appt_pets_html; ?>
                        </div>
                    </div>
                </td>
                <td style="font-weight:600;color:#334155;max-width:160px;"><?php echo htmlspecialchars($row['service_type']); ?></td>
                <td data-sort="<?= htmlspecialchars($row['appointment_date'] . ' ' . $row['appointment_time']) ?>">
                    <div style="font-weight:600;color:#0f172a;font-size:13px;"><i class="far fa-calendar-alt" style="color:#94a3b8;margin-right:4px;"></i><?php echo date("M d, Y", strtotime($row['appointment_date'])); ?></div>
                    <div style="font-size:12px;color:#64748b;"><i class="far fa-clock" style="margin-right:3px;"></i><?php echo date("h:i A", strtotime($row['appointment_time'])); ?></div>
                    <?php if (str_starts_with($status, 'Reschedule') && !empty($row['proposed_reschedule_date'])): ?>
                    <div style="margin-top:5px; background:#f5f3ff; color:#7c3aed; padding:3px 8px; border-radius:6px; font-size:10px; font-weight:800; border:1px solid #ddd6fe; display:inline-flex; align-items:center; gap:4px;">
                        <i class="fa fa-exchange-alt"></i> Proposed: <?php echo date("M d", strtotime($row['proposed_reschedule_date'])); ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($row['_type'] === 'Boarding'): ?>
                        <span class="ml-vettxt"><i class="fa fa-hotel text-secondary"></i>Clinic (Boarding)</span>
                    <?php elseif ($row['_vet_id'] === 'none'): ?>
                        <span class="ml-vettxt none"><i class="fa fa-user-slash"></i>Unassigned</span>
                    <?php else: ?>
                        <span class="ml-vettxt"><i class="fa fa-user-doctor text-primary"></i><?= htmlspecialchars($row['_vet']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($row['service_location'] === 'Home Service'): ?>
                    <span style="background:#e0f2fe;color:#0369a1;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;"><i class="fa fa-home me-1"></i>Home</span>
                    <?php else: ?>
                    <span style="background:#f1f5f9;color:#475569;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;"><i class="fa fa-hospital me-1"></i>On-Site</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="bs <?php echo $bsCls; ?>">
                        <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $dot; ?>;flex-shrink:0;"></span>
                        <?php echo htmlspecialchars($status_label); ?>
                    </span>
                    <?php if ($row['_flags']): ?><div><?php foreach ($row['_flags'] as $f): [$fl, $fi] = $FLAG_LABEL[$f]; ?><span class="ml-flag <?= $f ?>"><i class="fa <?= $fi ?>"></i><?= $fl ?></span><?php endforeach; ?></div><?php endif; ?>
                </td>
                <td style="font-size:12px;color:#94a3b8;" data-sort="<?= htmlspecialchars($row['created_at']) ?>"><?php echo date("M d, Y", strtotime($row['created_at'])); ?></td>

                <td onclick="event.stopPropagation();" class="text-center">
                    <button type="button" class="btn btn-sm rounded-circle" style="width:32px;height:32px;padding:0; background:#2563eb; color:#fff; border:none; box-shadow:0 4px 10px rgba(37,99,235,0.35); transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'" title="View details & history" onclick="openModal(<?php echo $rowData; ?>); return false;">
                        <i class="fa fa-eye"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div id="noResult" style="display:none;text-align:center;padding:48px;color:#94a3b8;">
            <i class="fa fa-search" style="font-size:36px;display:block;margin-bottom:10px;opacity:.3;"></i>
            No appointments match your search or filters.
        </div>
        <div class="d-flex justify-content-between align-items-center px-4 py-3 no-print" style="border-top:1px solid #f1f5f9;font-size:13px;color:#64748b;">
            <span>Showing <b id="visCount"><?= count($rows) ?></b> of <?= count($rows) ?></span>
            <span style="font-size:12px;"><i class="fa fa-box-archive me-1"></i>Closed appointments older than 30 days move to <a href="admin_history.php?view=archive">Archived Appointments</a>.</span>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:60px 20px;color:#94a3b8;">
            <i class="fa fa-calendar-times" style="font-size:48px;display:block;margin-bottom:14px;opacity:.3;"></i>
            <div style="font-weight:700;font-size:16px;color:#475569;margin-bottom:6px;">No Appointments Found</div>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- DETAIL MODAL (read-only) -->
<div class="dmodal-bd" id="detailBd">
    <div class="dmodal">
        <div class="dmodal-hdr">
            <h5 style="font-weight:800;font-size:16px;color:#0f172a;margin:0;display:flex;align-items:center;gap:9px;">
                <span style="width:34px;height:34px;border-radius:9px;background:#eff6ff;display:inline-flex;align-items:center;justify-content:center;color:#2563eb;font-size:14px;"><i class="fa fa-calendar-day"></i></span>
                Appointment Details
            </h5>
            <button class="dmdl-close" onclick="closeModal()">&#x2715;</button>
        </div>
        <div class="dmodal-body">
            <div class="hero-svc">
                <div class="sn" id="dm-svc"></div>
                <div class="sd" id="dm-dt"></div>
            </div>
            <div style="text-align:center;margin-bottom:16px;" id="dm-status-w"></div>
            <div class="info-g">
                <div class="info-i"><label><i class="fa fa-user me-1"></i>Client</label><div class="v" id="dm-client"></div></div>
                <div class="info-i"><label><i class="fa fa-paw me-1"></i>Pet</label><div class="v" id="dm-pet"></div></div>
                <div class="info-i"><label><i class="fa fa-user-md me-1"></i>Vet</label><div class="v" id="dm-doctor"></div></div>
                <div class="info-i"><label><i class="fa fa-map-marker-alt me-1"></i>Location</label><div class="v" id="dm-loc"></div></div>
                <div class="info-i"><label><i class="fa fa-tag me-1"></i>Booked On</label><div class="v" id="dm-created"></div></div>
                <div class="info-i"><label><i class="fa fa-id-badge me-1"></i>Reference</label><div class="v" id="dm-id"></div></div>
                <div class="info-i full" id="dm-addr-w" style="display:none;"><label><i class="fa fa-home me-1"></i>Home Address</label><div class="v" id="dm-addr" style="font-size:13px;font-weight:500;"></div></div>
                <div class="info-i full" id="dm-note-w" style="display:none;"><label><i class="fa fa-comment me-1"></i>Latest note</label><div class="v" id="dm-note" style="font-size:13px;font-weight:500;background:#f8fafc;padding:10px;border-radius:8px;white-space:pre-wrap;"></div></div>
            </div>
            <p class="ml-sec" style="margin-top:6px;"><i class="fa fa-clock-rotate-left me-1"></i>Activity history</p>
            <div id="dm-hist"></div>
        </div>
        <div class="dmodal-foot d-flex gap-2 justify-content-between align-items-center">
            <span style="font-size:12px;color:#94a3b8;"><i class="fa fa-eye me-1"></i>View only</span>
            <button class="btn-act gray" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/tablesort/5.2.1/tablesort.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/tablesort/5.2.1/sorts/tablesort.number.min.js"></script>
<script>
const ML_TODAY = <?= json_encode($today) ?>, ML_WEEK = [<?= json_encode($week_start) ?>, <?= json_encode($week_end) ?>];
const ML_HIST = <?= json_encode($hist, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const ML_FLAG_TXT = {overdue:'Overdue', stale:'Pending over 24h', unassigned:'No vet assigned', mismatch:'Done before date'};

// Toast auto-dismiss
(function(){ var t=document.getElementById('toastMsg'); if(t) setTimeout(function(){ t.style.opacity='0'; t.style.transform='translateY(-14px)'; setTimeout(function(){t.remove();},400); },4500); })();

document.addEventListener('DOMContentLoaded',function(){
    var t = document.getElementById('apptTable');
    if(t) new Tablesort(t, { descending: true });
});

// ── Filters: search, vet, dates, quick range, status chips, attention flag ──
var mlState = { status:'all', quick:'', flag:'' };
function mlApply(){
    var body = document.getElementById('apptBody'); if (!body) return;
    var q = document.getElementById('apptSearch').value.toLowerCase().trim();
    var vet = document.getElementById('vetFilter').value;
    var from = document.getElementById('dateFrom').value, to = document.getElementById('dateTo').value;
    var vis = 0;
    body.querySelectorAll('.appt-row').forEach(function(r){
        var d = r.dataset.date, s = mlState.status, ok = true;
        if (s !== 'all') {
            if (s.indexOf('g:') === 0) ok = r.dataset.group === s.slice(2);
            else if (s.indexOf('s:') === 0) ok = r.dataset.skey === s.slice(2);
            else ok = r.dataset.status === s;
        }
        if (ok && vet) ok = r.dataset.vet === vet;
        if (ok && from) ok = d >= from;
        if (ok && to) ok = d <= to;
        if (ok && mlState.quick === 'today') ok = d === ML_TODAY;
        if (ok && mlState.quick === 'week') ok = d >= ML_WEEK[0] && d <= ML_WEEK[1];
        if (ok && mlState.quick === 'upcoming') ok = d >= ML_TODAY && r.dataset.group === 'active';
        if (ok && mlState.flag) ok = (' ' + r.dataset.flags + ' ').indexOf(' ' + mlState.flag + ' ') !== -1;
        if (ok && q) ok = r.innerText.toLowerCase().indexOf(q) !== -1;
        r.style.display = ok ? '' : 'none';
        if (ok) vis++;
    });
    document.getElementById('noResult').style.display = vis ? 'none' : 'block';
    var vc = document.getElementById('visCount'); if (vc) vc.textContent = vis;
    var note = [];
    var chip = document.querySelector('.ftab.active'); if (chip && mlState.status !== 'all') note.push(chip.childNodes[0].textContent.trim());
    if (vet) note.push(document.getElementById('vetFilter').selectedOptions[0].text);
    if (mlState.quick) note.push(document.querySelector('#quickSeg .on').textContent);
    if (from || to) note.push((from || '…') + ' to ' + (to || '…'));
    if (mlState.flag) note.push(ML_FLAG_TXT[mlState.flag]);
    var pn = document.getElementById('printFilterNote'); if (pn) pn.textContent = note.length ? note.join(' · ') : 'All appointments';
}
function mlSetFlag(f){
    mlState.flag = (mlState.flag === f) ? '' : f;
    document.querySelectorAll('.ml-attn').forEach(function(b){ b.classList.toggle('sel', b.dataset.flag === mlState.flag); });
    var pill = document.getElementById('flagPill');
    pill.style.display = mlState.flag ? 'inline-flex' : 'none';
    document.getElementById('flagPillTxt').textContent = mlState.flag ? ML_FLAG_TXT[mlState.flag] : '';
    mlApply();
    if (mlState.flag) document.getElementById('adminTableWrapper').scrollIntoView({behavior:'smooth', block:'start'});
}
['apptSearch','dateFrom','dateTo'].forEach(function(id){ var el = document.getElementById(id); if (el) el.addEventListener('input', mlApply); });
document.getElementById('vetFilter').addEventListener('change', mlApply);
document.querySelectorAll('.ftab').forEach(function(btn){
    btn.addEventListener('click', function(){
        document.querySelectorAll('.ftab').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active'); mlState.status = btn.dataset.filter; mlApply();
    });
});
document.querySelectorAll('#quickSeg button').forEach(function(btn){
    btn.addEventListener('click', function(){
        document.querySelectorAll('#quickSeg button').forEach(function(b){ b.classList.remove('on'); });
        btn.classList.add('on'); mlState.quick = btn.dataset.q; mlApply();
    });
});

// Helpers
function esc(s){ return s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'):'-'; }
function fmtDate(d){ if(!d) return '-'; var o=new Date(d+'T00:00:00'); return o.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function fmtTime(t){ if(!t) return ''; var p=t.split(':'); var h=parseInt(p[0]),m=p[1],ap=h>=12?'PM':'AM'; h=h%12||12; return h+':'+m+' '+ap; }
function fmtFull(s){ if(!s) return '-'; return new Date(String(s).replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true}); }
function statusBadge(s){
    var map = {'Pending':['bs-pending','#ea580c'],'Approved':['bs-approved','#16a34a'],'Checked In':['bs-checkin','#0891b2'],'In Progress':['bs-inprogress','#7c3aed'],
               'Completed':['bs-completed','#2563eb'],'No Show':['bs-noshow','#9f1239'],'Reschedule Proposed':['bs-proposed','#7e22ce'],'Reschedule Requested':['bs-requested','#7c3aed']};
    var c = map[s] || ((s||'').match(/Cancel|Reject/) ? ['bs-cancelled','#be123c'] : ['bs-default','#94a3b8']);
    var label = s === 'Reschedule Proposed' ? 'Awaiting client' : s;
    return '<span class="bs '+c[0]+'" style="font-size:14px;padding:7px 18px;"><span style="width:9px;height:9px;border-radius:50%;background:'+c[1]+';flex-shrink:0;"></span>'+esc(label)+'</span>';
}
function peso(t){ return esc(t).replace(/(^|[\s(:·])P(\d[\d,]*\.\d{2})/g, '$1₱$2'); }

function openModal(d){
    var boarding = (d._type === 'Boarding');
    document.getElementById('dm-svc').textContent     = d.service_type || 'Appointment';
    document.getElementById('dm-dt').textContent      = fmtDate(d.appointment_date) + (boarding && d.end_date ? ' → ' + fmtDate(d.end_date) : (d.appointment_time ? ' at '+fmtTime(d.appointment_time) : ''));
    var flags = (d._flags || []).map(function(f){ return '<span class="ml-flag '+f+'" style="font-size:11.5px;">'+ML_FLAG_TXT[f]+'</span>'; }).join(' ');
    document.getElementById('dm-status-w').innerHTML  = statusBadge(d.status) + (flags ? '<div style="margin-top:6px;">'+flags+'</div>' : '');
    document.getElementById('dm-client').textContent  = d.client_name || '-';
    document.getElementById('dm-pet').textContent     = d.pet_name || '-';
    document.getElementById('dm-doctor').textContent  = d._vet || 'Unassigned';
    document.getElementById('dm-loc').textContent     = d.service_location || '-';
    document.getElementById('dm-created').textContent = fmtFull(d.created_at);
    document.getElementById('dm-id').textContent      = (boarding ? 'Boarding #' : 'Appointment #') + d.id;

    var addrW = document.getElementById('dm-addr-w');
    if(d.service_location==='Home Service' && d.home_address){ addrW.style.display='block'; document.getElementById('dm-addr').textContent=d.home_address; }
    else addrW.style.display='none';
    var noteW = document.getElementById('dm-note-w');
    if (d.admin_message && String(d.admin_message).trim()) { noteW.style.display='block'; document.getElementById('dm-note').textContent = d.admin_message; }
    else noteW.style.display='none';

    var h = ML_HIST[(boarding ? 'B' : 'A') + d.id] || [];
    var box = document.getElementById('dm-hist');
    if (!h.length) box.innerHTML = '<div class="text-muted small py-2"><i class="fa fa-circle-info me-1"></i>No activity recorded for this appointment yet.</div>';
    else box.innerHTML = '<ul class="ml-tl">' + h.slice().reverse().map(function(e){
        return '<li><span class="dot"><i class="fa fa-circle"></i></span><div class="t">'+esc(e.action)+'<span>'+fmtFull(e.at)+'</span></div>'
             + (e.details ? '<div class="d">'+peso(e.details)+'</div>' : '')
             + '<div class="a"><i class="fa fa-user me-1"></i>'+esc(e.actor)+(e.rec ? '<span class="rec" title="Reconstructed from saved dates">from records</span>' : '')+'</div></li>';
    }).join('') + '</ul>';

    var bd = document.getElementById('detailBd');
    bd.classList.add('open');
    setTimeout(function(){ bd.style.opacity='1'; },10);
}
function closeModal(){
    var bd = document.getElementById('detailBd');
    bd.style.opacity='0';
    setTimeout(function(){ bd.classList.remove('open'); bd.style.opacity=''; },180);
}
document.getElementById('detailBd').addEventListener('click',function(e){ if(e.target===this) closeModal(); });
document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeModal(); });

// Also expose triggerModal for compatibility with admin.php widgets
function triggerModal(id,title,client,pet,service,time,status,created,approved,date){
    openModal({id:id,client_name:client,pet_name:pet,service_type:service,appointment_time:time,status:status,created_at:created,appointment_date:date,doctor_name:'',service_location:'On-Site',home_address:'',is_archived:0});
}
// QUICK PET EMR PREVIEW
const PET_EMR_DATA = <?= json_encode($pets_emr_list) ?>;

function openQuickPetEmr(petName, userId, event) {
    if (event) event.stopPropagation();
    const key = (userId + '_' + petName.trim()).toLowerCase();
    const pet = PET_EMR_DATA[key] || null;

    document.getElementById('emrPetName').textContent = petName;
    document.getElementById('emrOwnerName').textContent = pet ? 'Owner: ' + pet.owner_name : 'Owner Verified';
    document.getElementById('emrSpeciesBreed').textContent = pet ? (pet.species + ' (' + (pet.breed || 'Mixed') + ')') : 'Canine / Feline';
    document.getElementById('emrGenderWeight').textContent = pet ? ((pet.gender || 'Unknown') + ' • ' + (pet.weight ? pet.weight + ' kg' : 'Standard')) : 'Recorded';
    document.getElementById('emrAllergies').textContent = (pet && pet.allergies && pet.allergies.trim()) ? pet.allergies : 'No known food or drug allergies reported.';
    document.getElementById('emrInstructions').textContent = (pet && pet.special_instructions && pet.special_instructions.trim()) ? pet.special_instructions : 'Standard clinic observation and checkup routine.';
    document.getElementById('emrLastVac').textContent = (pet && pet.last_vaccination) ? pet.last_vaccination : 'Up-to-date';
    document.getElementById('emrNextVac').textContent = (pet && pet.next_vaccination) ? pet.next_vaccination : 'Scheduled';

    const dlBtn = document.getElementById('emrDownloadPdfBtn');
    if (dlBtn) {
        if (pet && pet.id) {
            dlBtn.href = 'download_emr.php?pet_id=' + pet.id;
            dlBtn.classList.remove('disabled');
            dlBtn.style.display = 'inline-flex';
        } else {
            dlBtn.href = '#';
            dlBtn.classList.add('disabled');
        }
    }

    const modalEl = document.getElementById('quickPetEmrModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}
</script>

<!-- QUICK PET EMR MODAL -->
<div class="modal fade" id="quickPetEmrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-0 pb-0" style="background:#f8fafc; border-radius: 16px 16px 0 0; padding: 20px 24px;">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 14px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                        <i class="fa fa-file-medical"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark m-0" id="emrPetName" style="font-size: 18px;">Pet Medical Record</h5>
                        <span class="text-muted small" id="emrOwnerName">Owner: --</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3 border" style="border-color:#e2e8f0 !important;">
                            <span class="text-muted d-block small fw-bold" style="font-size: 10.5px;">SPECIES / BREED</span>
                            <span class="fw-bold text-dark" id="emrSpeciesBreed" style="font-size: 13.5px;">--</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3 border" style="border-color:#e2e8f0 !important;">
                            <span class="text-muted d-block small fw-bold" style="font-size: 10.5px;">GENDER / WEIGHT</span>
                            <span class="fw-bold text-dark" id="emrGenderWeight" style="font-size: 13.5px;">--</span>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold text-danger small text-uppercase mb-1.5" style="font-size: 11px; letter-spacing: 0.5px;"><i class="fa fa-triangle-exclamation me-1"></i> Allergies & Critical Notes</h6>
                    <div class="p-3 rounded-3 bg-danger bg-opacity-10 border border-danger border-opacity-25 text-danger fw-bold small" id="emrAllergies" style="font-size: 12.5px;">
                        No known allergies recorded.
                    </div>
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold text-dark small text-uppercase mb-1.5" style="font-size: 11px; letter-spacing: 0.5px;"><i class="fa fa-notes-medical text-primary me-1"></i> Special Care & Instructions</h6>
                    <div class="p-3 rounded-3 bg-light text-muted small border" id="emrInstructions" style="font-size: 12.5px; border-color:#e2e8f0 !important;">
                        Standard clinical care.
                    </div>
                </div>

                <div>
                    <h6 class="fw-bold text-dark small text-uppercase mb-1.5" style="font-size: 11px; letter-spacing: 0.5px;"><i class="fa fa-syringe text-success me-1"></i> Immunization Record</h6>
                    <div class="d-flex justify-content-between align-items-center p-3 rounded-3 bg-success bg-opacity-10 border border-success border-opacity-25 text-success small fw-bold" style="font-size: 12px;">
                        <span><i class="fa fa-check-circle me-1"></i> Last Given: <span id="emrLastVac">--</span></span>
                        <span>Next Due: <span id="emrNextVac">--</span></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light rounded-bottom-4 d-flex justify-content-between align-items-center">
                <a href="#" id="emrDownloadPdfBtn" target="_blank" class="btn btn-primary rounded-pill px-4 fw-bold">
                    <i class="fa fa-file-pdf me-1"></i> Download EMR (PDF)
                </a>
                <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
$calendar_events_json = [];
foreach ($rows as $r) {
    $st = (string)$r['status'];
    $color = match(true) {
        $st === 'Approved'             => '#16a34a',
        $st === 'Pending'              => '#ea580c',
        $st === 'Checked In'           => '#0891b2',
        $st === 'In Progress'          => '#7c3aed',
        $st === 'Completed'            => '#2563eb',
        $st === 'No Show'              => '#9f1239',
        str_starts_with($st, 'Reschedule') => '#a855f7',
        str_contains($st, 'Reject') || str_contains($st, 'Cancel') => '#94a3b8',
        default => '#64748b'
    };
    $ev = [
        'id'    => ($r['_type'] === 'Boarding' ? 'B' : 'A') . $r['id'],
        'title' => (string)($r['pet_name'] ?? 'Pet'),
        'color' => $color,
        'extendedProps' => [
            'rawData' => $r,
            'service' => $r['service_type'],
            'client'  => $r['client_name'],
            'vet'     => $r['_vet'],
            'status'  => $st === 'Reschedule Proposed' ? 'Awaiting client' : $st,
            'overdue' => in_array('overdue', $r['_flags'], true),
            'closed'  => in_array($st, ['Cancelled', 'Rejected'], true),
            'boarding'=> $r['_type'] === 'Boarding',
        ],
    ];
    if ($r['_type'] === 'Boarding' && !empty($r['end_date'])) {
        // stay spans its dates (end is exclusive in the calendar)
        $ev['start'] = $r['appointment_date'];
        $ev['end'] = date('Y-m-d', strtotime($r['end_date'] . ' +1 day'));
        $ev['allDay'] = true;
    } else {
        $ev['start'] = $r['appointment_date'] . 'T' . (!empty($r['appointment_time']) ? substr($r['appointment_time'], 0, 5) : '09:00');
    }
    $calendar_events_json[] = $ev;
}
?>
<style>
#adminCalendarWrapper { padding:0; overflow:hidden; }
.cal-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; padding:20px 24px 14px; border-bottom:1px solid #f1f5f9; }
.cal-head h5 { font-weight:800; font-size:17px; color:#0f172a; margin:0; }
.cal-head h5 i { color:#2563eb; }
.cal-sub { font-size:12.5px; color:#64748b; margin-top:2px; }
.cal-legend { display:flex; flex-wrap:wrap; gap:6px 14px; max-width:620px; justify-content:flex-end; }
.cal-legend span { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; color:#475569; }
.cal-legend i { width:10px; height:10px; border-radius:3px; display:inline-block; }
.cal-legend i.ring { background:#fff; border:2px solid #dc2626; border-radius:50%; }
#adminFullCalendar { padding:16px 24px 24px; --fc-border-color:#eef2f6; --fc-today-bg-color:#f5f9ff; --fc-neutral-bg-color:#f8fafc; --fc-page-bg-color:#fff; --fc-now-indicator-color:#dc2626; }

/* toolbar */
#adminFullCalendar .fc-header-toolbar { margin-bottom:14px !important; gap:10px; flex-wrap:wrap; }
#adminFullCalendar .fc-toolbar-title { font-size:20px; font-weight:800; color:#0f172a; letter-spacing:-.4px; }
#adminFullCalendar .fc-button { background:#fff !important; background-image:none !important; color:#334155 !important; border:1.5px solid #e2e8f0 !important; box-shadow:none !important;
    font-weight:700; font-size:13px; text-transform:capitalize; padding:7px 13px; border-radius:10px !important; text-shadow:none !important; transition:.15s; }
#adminFullCalendar .fc-button:hover { border-color:#2563eb !important; color:#2563eb !important; }
#adminFullCalendar .fc-button:disabled { opacity:.45; }
#adminFullCalendar .fc-button-group { gap:4px; }
#adminFullCalendar .fc-button-group > .fc-button { border-radius:10px !important; margin:0 !important; }
#adminFullCalendar .fc-button-active, #adminFullCalendar .fc-button-active:hover { background:#2563eb !important; border-color:#2563eb !important; color:#fff !important; }
#adminFullCalendar .fc-prev-button, #adminFullCalendar .fc-next-button { width:38px; padding:7px 0; }

/* grid */
#adminFullCalendar .fc-scrollgrid { border-radius:14px; overflow:hidden; border-color:#eef2f6 !important; }
#adminFullCalendar a, #adminFullCalendar a:hover { text-decoration:none !important; }
#adminFullCalendar .fc-col-header-cell { background:#f8fafc; padding:9px 0; }
#adminFullCalendar .fc-col-header-cell-cushion { font-size:11.5px; font-weight:800; color:#64748b !important; text-transform:uppercase; letter-spacing:.08em; }
#adminFullCalendar .fc-daygrid-day-frame { min-height:112px; padding:2px 3px; }
#adminFullCalendar .fc-daygrid-day-top { flex-direction:row; padding:4px 4px 2px; }
#adminFullCalendar .fc-daygrid-day-number { font-size:13px; font-weight:700; color:#334155 !important; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; padding:0; }
#adminFullCalendar .fc-day-other .fc-daygrid-day-number { color:#cbd5e1 !important; }
#adminFullCalendar .fc-day-other { background:#fcfdfe; }
#adminFullCalendar .fc-day-today .fc-daygrid-day-number { background:#2563eb; color:#fff !important; box-shadow:0 4px 10px rgba(37,99,235,.3); }
#adminFullCalendar .fc-day-sun:not(.fc-col-header-cell) { background:#fbfcfd; }
#adminFullCalendar .fc-daygrid-day:hover { background:#f8fbff; }
#adminFullCalendar .fc-daygrid-more-link { font-size:11.5px; font-weight:700; color:#2563eb !important; padding:2px 6px; border-radius:6px; }
#adminFullCalendar .fc-daygrid-more-link:hover { background:#eff6ff; }
#adminFullCalendar .fc-popover { border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 12px 32px rgba(15,23,42,.15); overflow:hidden; }
#adminFullCalendar .fc-popover-header { background:#f8fafc; font-weight:700; font-size:12.5px; padding:8px 10px; }

/* events */
#adminFullCalendar .fc-event { border:none !important; background:transparent !important; box-shadow:none !important; margin:1px 2px 3px !important; cursor:pointer; }
.cal-ev { display:flex; gap:7px; align-items:flex-start; width:100%; overflow:hidden; border-radius:8px; padding:4px 7px; border-left:3px solid var(--c); background:color-mix(in srgb, var(--c) 11%, #fff); transition:.12s; }
.cal-ev:hover { background:color-mix(in srgb, var(--c) 20%, #fff); transform:translateY(-1px); }
.cal-ev .tm { font-size:11px; font-weight:800; color:var(--c); white-space:nowrap; padding-top:1px; }
.cal-ev .bd { min-width:0; line-height:1.25; }
.cal-ev .pt { font-size:12px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cal-ev .sv { font-size:11px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cal-ev.closed { opacity:.6; }
.cal-ev.closed .pt { text-decoration:line-through; }
.cal-ev .od { width:7px; height:7px; border-radius:50%; background:#dc2626; box-shadow:0 0 0 2px #fee2e2; flex-shrink:0; margin-top:4px; margin-left:auto; }
.cal-ev.board { border-left:none; background:color-mix(in srgb, var(--c) 14%, #fff); }
#adminFullCalendar .fc-timegrid-event .cal-ev { height:100%; flex-direction:column; gap:1px; }
#adminFullCalendar .fc-timegrid-slot { height:2.6em; }
#adminFullCalendar .fc-timegrid-slot-label-cushion, #adminFullCalendar .fc-timegrid-axis-cushion { font-size:11.5px; color:#94a3b8; font-weight:600; }
#adminFullCalendar .fc-list-event:hover td { background:#f8fbff; }
@media (max-width: 768px) { #adminFullCalendar { padding:12px; } #adminFullCalendar .fc-daygrid-day-frame { min-height:80px; } .cal-ev .sv { display:none; } }
</style>
<script>
const ADMIN_CALENDAR_EVENTS = <?= json_encode($calendar_events_json, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function calEsc(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function calTime(d){ if (!d) return ''; var h = d.getHours(), m = d.getMinutes(); var ap = h >= 12 ? 'p' : 'a'; h = h % 12 || 12; return h + (m ? ':' + String(m).padStart(2,'0') : '') + ap; }

function toggleAdminCalendarView() {
    var wrapper = document.getElementById('adminCalendarWrapper');
    var tableWrapper = document.getElementById('adminTableWrapper');
    var btn = document.getElementById('btnToggleCalendarView');

    if (wrapper.style.display === 'none') {
        wrapper.style.display = 'block';
        if (tableWrapper) tableWrapper.style.display = 'none';
        btn.innerHTML = '<i class="fa fa-table"></i> Table View';
        btn.classList.add('on');

        if (!window.adminCalendarObj) {
            window.adminCalendarObj = new FullCalendar.Calendar(document.getElementById('adminFullCalendar'), {
                initialView: 'dayGridMonth',
                initialDate: ML_TODAY,
                now: ML_TODAY + 'T' + new Date().toTimeString().slice(0, 5),
                headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
                buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day', list: 'List' },
                height: 'auto',
                dayMaxEvents: 3,
                fixedWeekCount: false,
                nowIndicator: true,
                slotMinTime: '07:00:00',
                slotMaxTime: '19:00:00',
                allDayText: 'Stay',
                allDaySlot: ADMIN_CALENDAR_EVENTS.some(function(e){ return e.allDay; }),
                eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
                events: ADMIN_CALENDAR_EVENTS,
                eventContent: function(arg) {
                    var p = arg.event.extendedProps, c = arg.event.backgroundColor || '#2563eb';
                    if (arg.view.type.indexOf('list') === 0) {
                        return { html: '<b>' + calEsc(arg.event.title) + '</b> <span style="color:#64748b">· ' + calEsc(p.service) + ' · ' + calEsc(p.client) + '</span>'
                                     + ' <span style="color:' + c + ';font-weight:700;font-size:12px;margin-left:6px;">' + calEsc(p.status) + '</span>' + (p.overdue ? ' <span style="color:#dc2626;font-weight:700;font-size:12px;">· Overdue</span>' : '') };
                    }
                    var tm = (!arg.event.allDay && !p.boarding) ? '<span class="tm">' + calTime(arg.event.start) + '</span>' : '<span class="tm"><i class="fa fa-hotel"></i></span>';
                    var sub = arg.view.type === 'dayGridMonth' ? p.service : p.service + ' · ' + p.client + (p.vet && !p.boarding ? ' · ' + p.vet : '');
                    return { html: '<div class="cal-ev' + (p.closed ? ' closed' : '') + (p.boarding ? ' board' : '') + '" style="--c:' + c + '">' + tm
                                 + '<div class="bd"><div class="pt">' + calEsc(arg.event.title) + '</div><div class="sv">' + calEsc(sub) + '</div></div>'
                                 + (p.overdue ? '<span class="od" title="Overdue"></span>' : '') + '</div>' };
                },
                eventDidMount: function(info) {
                    var p = info.event.extendedProps;
                    info.el.title = info.event.title + ' — ' + p.service + '\n' + p.client + (p.vet ? ' · ' + p.vet : '') + '\n' + p.status + (p.overdue ? ' (overdue)' : '');
                },
                eventClick: function(info) {
                    info.jsEvent.preventDefault();
                    if (info.event.extendedProps && info.event.extendedProps.rawData) openModal(info.event.extendedProps.rawData);
                }
            });
        }
        window.adminCalendarObj.render();
    } else {
        wrapper.style.display = 'none';
        if (tableWrapper) tableWrapper.style.display = 'block';
        mlApply();
        btn.innerHTML = '<i class="fa fa-calendar-alt"></i> Calendar View';
        btn.classList.remove('on');
    }
}
</script>
<script src="mousetrail.js"></script>
</body>
</html>
