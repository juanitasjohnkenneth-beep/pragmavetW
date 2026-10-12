<?php
session_start();
include("dbconnection.php");
include_once("includes/rbac_guard.php");
include_once("includes/pet_data_helper.php");
include_once("includes/activity_log.php");

// RBAC Access Guard
$user_role = rbac_get_role();
if (!isset($_SESSION['user_id']) || !in_array($user_role, ['admin', 'clinic_owner', 'owner'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}
al_ensure($conn);

$view = ($_GET['view'] ?? 'log') === 'archive' ? 'archive' : 'log';

// ── POST: Unarchive (move back to appointments) ───────────────────────────────
if (isset($_POST['unarchive'])) {
    $aid = (int)$_POST['apt_id'];
    $conn->query("UPDATE appointments SET is_archived=0 WHERE id=$aid");
    $_SESSION['message']  = "Appointment #$aid unarchived and moved back to Appointments.";
    $_SESSION['msg_type'] = "success";
    header("Location: admin_history.php?view=archive"); exit();
}

// ── Activity log: filters, export, page ──────────────────────────────────────
$al_f = [
    'module' => $_GET['module'] ?? '',
    'actor'  => trim($_GET['actor'] ?? ''),
    'q'      => trim($_GET['q'] ?? ''),
    'from'   => $_GET['from'] ?? '',
    'to'     => $_GET['to'] ?? '',
];
if (!isset(AL_MODULES[$al_f['module']])) $al_f['module'] = '';

if ($view === 'log' && ($_GET['export'] ?? '') === 'csv') {
    [$all] = al_query($conn, $al_f, 0);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity-log-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date & Time', 'Module', 'Action', 'Details', 'Done By', 'Source']);
    foreach ($all as $e) {
        fputcsv($out, [date('Y-m-d H:i:s', strtotime($e['created_at'])), $e['module'], $e['action'],
                       str_replace(' -> ', ' → ', (string)$e['details']), $e['actor'] ?: 'System', $e['source'] === 'history' ? 'From records' : 'Logged']);
    }
    fclose($out);
    exit();
}

$al_per  = 50;
$al_page = max(1, (int)($_GET['page'] ?? 1));
[$al_rows, $al_total] = al_query($conn, $al_f, $al_per, ($al_page - 1) * $al_per);
$al_pages = max(1, (int)ceil($al_total / $al_per));

// Module counts under the current filters (except the module itself)
$al_mod_counts = [];
$al_mod_f = $al_f; $al_mod_f['module'] = '';
foreach (AL_MODULES as $m => $_) { $tmp = $al_mod_f; $tmp['module'] = $m; $al_mod_counts[$m] = al_query($conn, $tmp, 1)[1]; }
$al_all_count = array_sum($al_mod_counts);

// Summary cards
$feed = al_feed_sql();
$al_today  = (int)$conn->query("SELECT COUNT(*) c FROM ($feed) x WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'];
$al_week   = (int)$conn->query("SELECT COUNT(*) c FROM ($feed) x WHERE created_at >= CURDATE() - INTERVAL 6 DAY")->fetch_assoc()['c'];
$al_people = (int)$conn->query("SELECT COUNT(DISTINCT actor) c FROM ($feed) x WHERE created_at >= CURDATE() - INTERVAL 6 DAY AND actor NOT IN ('System', 'Clinic staff', 'Payment')")->fetch_assoc()['c'];
$al_pays   = (int)$conn->query("SELECT COUNT(*) c FROM ($feed) x WHERE created_at >= CURDATE() - INTERVAL 6 DAY AND action IN ('Payment received', 'Reservation fee paid', 'Downpayment received')")->fetch_assoc()['c'];

// People list for the "Done by" filter
$al_actors = [];
$ar = $conn->query("SELECT actor, COUNT(*) c FROM ($feed) x WHERE actor IS NOT NULL AND actor <> '' GROUP BY actor ORDER BY c DESC LIMIT 60");
if ($ar) while ($a = $ar->fetch_assoc()) $al_actors[] = $a['actor'];
if ($al_f['actor'] !== '' && !in_array($al_f['actor'], $al_actors, true)) $al_actors[] = $al_f['actor'];

function al_url($over = []) {
    global $al_f;
    $p = array_filter(array_merge(['view' => 'log'], $al_f, $over), fn($v) => $v !== '' && $v !== null);
    return 'admin_history.php?' . http_build_query($p);
}
function al_text($t) {
    $t = htmlspecialchars(str_replace(' -> ', ' → ', (string)$t));
    return preg_replace('/(^|[\s(:·])P(\d[\d,]*\.\d{2})/', '$1₱$2', $t);
}
function al_day_label($d) {
    if ($d === date('Y-m-d')) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('l, M d, Y', strtotime($d));
}

// ── Archived appointments ────────────────────────────────────────────────────
$search = $_GET['search'] ?? '';
$searchSQL = '';
if (!empty($search)) {
    $s = $conn->real_escape_string($search);
    $searchSQL = " AND (u.fullname LIKE '%$s%' OR a.pet_name LIKE '%$s%' OR a.service_type LIKE '%$s%' OR a.status LIKE '%$s%')";
}
$archive = $conn->query("SELECT a.*, u.fullname as client_name, IFNULL(d.fullname,'Unassigned') as doctor_name FROM appointments a JOIN users u ON a.user_id=u.id LEFT JOIN users d ON a.doctor_id=d.id WHERE a.is_archived=1 $searchSQL ORDER BY a.appointment_date DESC, a.appointment_time DESC");
$rows = [];
if ($archive) while ($r = $archive->fetch_assoc()) $rows[] = $r;

$cnt_completed = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE is_archived=1 AND status='Completed'")->fetch_assoc()['c'] ?? 0;
$cnt_cancelled = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE is_archived=1 AND status IN('Cancelled','Rejected')")->fetch_assoc()['c'] ?? 0;
$cnt_archived  = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE is_archived=1")->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Activity Logs | VetAdmin</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --primary:#2563eb; --secondary:#3b82f6; --dark:#1e40af; --accent:#3b82f6; --light:#E6EEF8; --sidebar-width:260px; }
* { box-sizing:border-box; }
body { background:#f0f4f8; font-family:'Inter',sans-serif; color:#2D3748; margin:0; }

.sidebar { background:#0f172a; width:var(--sidebar-width); height:100vh; position:fixed; top:0; left:0; padding:24px 16px; z-index:1000; display:flex; flex-direction:column; box-shadow:5px 0 20px rgba(0,0,0,.2); overflow-y:auto; }
.sidebar h4 { color:#ffffff; font-weight:800; font-size:22px; margin-bottom:30px; letter-spacing:0.5px; display:flex; align-items:center; gap:10px; }
.sidebar a { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:8px; color:#94a3b8; text-decoration:none; margin-bottom:4px; font-weight:600; font-size:15px; transition:.2s; }
.sidebar a i { width:18px; text-align:center; }
.sidebar a:hover, .sidebar a.active { background:#2563eb; color:#ffffff; }
.sidebar-logout { margin-top:auto; }
.sidebar-logout a { color:#ff7070 !important; }
.sidebar-logout a:hover { background:rgba(255,112,112,.15) !important; }

.main-content { margin-left: 260px !important; width: calc(100% - 260px) !important; max-width: calc(100% - 260px) !important; padding: 24px 28px !important; min-height: 100vh; box-sizing: border-box !important; }
@media (max-width: 1200px) { .main-content { padding: 20px 20px !important; } }
@media (max-width: 768px) { .main-content { margin-left: 0 !important; width: 100% !important; max-width: 100% !important; padding: 16px !important; } .sidebar { position: relative !important; width: 100% !important; height: auto !important; } }

.stat-card { background:#fff; border-radius:14px; padding:18px 22px; box-shadow:0 2px 12px rgba(0,0,0,.05); display:flex; align-items:center; gap:14px; }
.stat-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }

.card-main { background:#fff; border-radius:18px; box-shadow:0 4px 24px rgba(0,0,0,.06); overflow:hidden; }
.card-toolbar { padding:20px 24px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }

.search-w { position:relative; }
.search-w input { padding:9px 14px 9px 36px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; font-family:inherit; background:#E6EEF8; outline:none; transition:.2s; min-width:240px; }
.search-w input:focus { border-color:var(--accent); background:#fff; box-shadow:0 0 0 3px rgba(74, 127, 196,.15); }
.search-w .si { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; pointer-events:none; }

.ftab { padding:7px 14px; border-radius:20px; border:1.5px solid #e2e8f0; background:#E6EEF8; font-size:12px; font-weight:700; color:#64748b; cursor:pointer; transition:.15s; font-family:inherit; display:inline-flex; align-items:center; gap:5px; }
.ftab:hover, .ftab.active { background:var(--secondary); border-color:var(--secondary); color:#fff; }
.ftab .cnt { background:rgba(255,255,255,.25); border-radius:20px; padding:1px 7px; font-size:11px; }
.ftab:not(.active) .cnt { background:#e2e8f0; color:#64748b; }

.appt-table { width:100%; border-collapse:collapse; }
.appt-table thead th { padding:11px 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; border-bottom:2px solid #f1f5f9; white-space:nowrap; background:#fafbfc; }
.appt-table tbody tr { cursor:pointer; transition:background .12s; }
.appt-table tbody tr:hover { background:#E6EEF8; }
.appt-table tbody td { padding:14px 16px; vertical-align:middle; border-bottom:1px solid #f1f5f9; font-size:13px; }
.appt-table tbody tr:last-child td { border-bottom:none; }

.av { width:40px; height:40px; border-radius:11px; background:linear-gradient(135deg,#e0f2fe,#bae6fd); display:flex; align-items:center; justify-content:center; font-size:17px; color:#0369a1; flex-shrink:0; }

.bs { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:20px; font-size:12px; font-weight:700; border:1.5px solid transparent; }
.bs-pending   { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
.bs-approved  { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; }
.bs-completed { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
.bs-cancelled { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
.bs-archived  { background:#f1f5f9; color:#475569; border-color:#e2e8f0; }

.toast-wrap { position:fixed; top:22px; right:28px; z-index:99999; min-width:300px; border-radius:14px; padding:15px 18px; font-size:14px; font-weight:500; box-shadow:0 8px 32px rgba(0,0,0,.14); animation:toastIn .35s ease; display:flex; align-items:flex-start; gap:10px; }
.toast-wrap.success { background:#f0fdf4; border:1.5px solid #bbf7d0; color:#15803d; }
.toast-wrap.danger  { background:#fff1f2; border:1.5px solid #fecdd3; color:#be123c; }
@keyframes toastIn { from{opacity:0;transform:translateY(-14px) scale(.96)} to{opacity:1;transform:none} }

.dmodal-bd { position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:99998; display:none; align-items:center; justify-content:center; padding:16px; opacity:0; transition:opacity .18s; }
.dmodal-bd.open { display:flex; }
.dmodal { background:#fff; border-radius:20px; width:100%; max-width:520px; max-height:88vh; display:flex; flex-direction:column; box-shadow:0 24px 64px rgba(0,0,0,.2); overflow:hidden; transform:scale(.95) translateY(10px); transition:transform .2s,opacity .2s; opacity:0; }
.dmodal-bd.open .dmodal { transform:none; opacity:1; }
.dmodal-hdr { padding:20px 24px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.dmdl-close { width:32px; height:32px; border-radius:50%; border:1.5px solid #e2e8f0; background:#E6EEF8; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:15px; transition:.15s; }
.dmdl-close:hover { background:#fee2e2; border-color:#fca5a5; color:#e11d48; }
.dmodal-body { overflow-y:auto; padding:22px 24px; flex:1; }
.dmodal-foot { padding:16px 24px; border-top:1px solid #f1f5f9; display:flex; gap:8px; flex-wrap:wrap; }

.info-g { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
.info-i label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; display:block; margin-bottom:3px; }
.info-i .v { font-size:14px; font-weight:600; color:#0f172a; }
.info-i.full { grid-column:1/-1; }
.hero-svc { text-align:center; background:linear-gradient(135deg,#E6EEF8,#e2e8f0); border-radius:14px; padding:16px; margin-bottom:16px; }
.hero-svc .sn { font-size:20px; font-weight:800; color:#1e3a5f; }
.hero-svc .sd { font-size:13px; color:#64748b; margin-top:2px; }
.btn-act { padding:10px 20px; border-radius:50px; border:none; font-weight:700; font-size:13px; font-family:inherit; cursor:pointer; transition:.2s; }
.btn-act:hover { opacity:.88; transform:translateY(-1px); }
.btn-act.g { background:#16a34a; color:#fff; }
.btn-act.r { background:#e11d48; color:#fff; }
.btn-act.gray { background:#f1f5f9; color:#475569; border:1.5px solid #e2e8f0; }
.btn-act.b { background:var(--secondary); color:#fff; }

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

/* ====== ACTIVITY LOG ====== */
.al-head h2 { font-weight:800; font-size:26px; color:#0f172a; margin:0; letter-spacing:-.6px; -webkit-text-fill-color:#0f172a; background:none; }
.al-head p { color:#64748b; font-size:14px; margin:4px 0 0; }
.al-tabs { display:inline-flex; background:#e9eef5; border-radius:14px; padding:4px; gap:4px; }
.al-tabs a { padding:9px 16px; border-radius:11px; font-weight:700; font-size:13px; color:#475569; text-decoration:none; display:inline-flex; align-items:center; gap:7px; transition:.15s; }
.al-tabs a:hover { color:#0f172a; }
.al-tabs a.on { background:#fff; color:#2563eb; box-shadow:0 2px 8px rgba(15,23,42,.08); }
.al-tabs a .n { background:#e2e8f0; color:#475569; border-radius:20px; padding:1px 8px; font-size:11px; }
.al-tabs a.on .n { background:#dbeafe; color:#1d4ed8; }

.al-stat { background:#fff; border-radius:16px; padding:18px 20px; box-shadow:0 2px 12px rgba(15,23,42,.05); display:flex; align-items:center; gap:14px; border:1px solid #eef2f6; height:100%; }
.al-stat .ic { width:46px; height:46px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
.al-stat .v { font-size:24px; font-weight:800; color:#0f172a; line-height:1.1; }
.al-stat .l { font-size:12px; color:#64748b; font-weight:600; margin-top:2px; }

.al-filters { padding:18px 22px; border-bottom:1px solid #f1f5f9; display:grid; grid-template-columns:minmax(220px,1.6fr) minmax(170px,1fr) 150px 150px auto; gap:10px; align-items:end; }
.al-filters label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; display:block; margin-bottom:4px; }
.al-filters .form-control, .al-filters .form-select { border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; padding:8px 12px; background-color:#f8fafc; }
.al-filters .form-control:focus, .al-filters .form-select:focus { border-color:#3b82f6; background-color:#fff; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
.al-filters .srch { position:relative; }
.al-filters .srch i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; }
.al-filters .srch input { padding-left:34px; }
.al-btn { border:none; border-radius:10px; padding:9px 16px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:7px; text-decoration:none; white-space:nowrap; cursor:pointer; }
.al-btn.pri { background:#2563eb !important; color:#fff !important; }
.al-btn.pri:hover { background:#1d4ed8 !important; }
.al-btn.gh { background:#fff !important; color:#475569 !important; border:1.5px solid #e2e8f0; }
.al-btn.gh:hover { border-color:#cbd5e1; color:#0f172a !important; }

.al-chips { padding:12px 22px; display:flex; gap:7px; flex-wrap:wrap; border-bottom:1px solid #f1f5f9; background:#fbfcfe; }
.al-chip { display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:20px; border:1.5px solid #e2e8f0; background:#fff; font-size:12.5px; font-weight:700; color:#475569; text-decoration:none; transition:.15s; }
.al-chip i { font-size:12px; }
.al-chip:hover { border-color:#cbd5e1; color:#0f172a; transform:translateY(-1px); }
.al-chip .n { background:#f1f5f9; color:#64748b; border-radius:20px; padding:0 7px; font-size:11px; }
.al-chip.on { color:#fff; border-color:transparent; }
.al-chip.on i, .al-chip.on { color:#fff !important; }
.al-chip.on .n { background:rgba(255,255,255,.25); color:#fff; }

.al-day { padding:12px 22px 8px; font-size:12px; font-weight:800; color:#0f172a; text-transform:uppercase; letter-spacing:.06em; display:flex; align-items:center; gap:10px; position:sticky; top:0; background:#fff; z-index:2; }
.al-day span { color:#94a3b8; font-weight:600; text-transform:none; letter-spacing:0; }
.al-day::after { content:''; flex:1; height:1px; background:#eef2f6; }
.al-row { display:grid; grid-template-columns:82px 40px minmax(0,1fr) 230px; gap:14px; align-items:center; padding:11px 22px; border-bottom:1px solid #f6f8fb; transition:background .12s; }
.al-row:hover { background:#f8fbff; }
.al-time { font-size:12.5px; font-weight:700; color:#334155; font-variant-numeric:tabular-nums; }
.al-ic { width:38px; height:38px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:15px; position:relative; }
.al-act { font-weight:700; color:#0f172a; font-size:13.5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.al-mod { font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:6px; text-transform:uppercase; letter-spacing:.04em; }
.al-rec { font-size:10.5px; color:#94a3b8; font-weight:600; border:1px dashed #cbd5e1; border-radius:6px; padding:1px 6px; }
.al-det { font-size:12.5px; color:#64748b; margin-top:2px; overflow-wrap:anywhere; }
.al-who { display:flex; align-items:center; gap:9px; min-width:0; }
.al-av { width:30px; height:30px; border-radius:50%; background:#eef2ff; color:#4f46e5; font-weight:800; font-size:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.al-av.sys { background:#f1f5f9; color:#64748b; }
.al-who .nm { font-size:12.5px; font-weight:700; color:#334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.al-who .rl { font-size:11px; color:#94a3b8; font-weight:600; text-transform:capitalize; }
.al-empty { text-align:center; padding:60px 20px; color:#94a3b8; }
.al-empty i { font-size:42px; opacity:.3; display:block; margin-bottom:12px; }
.al-foot { padding:14px 22px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; font-size:13px; color:#64748b; }
.al-pg { display:flex; gap:6px; }
.al-pg a, .al-pg span { min-width:36px; height:36px; border-radius:10px; border:1.5px solid #e2e8f0; display:inline-flex; align-items:center; justify-content:center; padding:0 10px; font-weight:700; color:#475569; text-decoration:none; background:#fff; }
.al-pg a:hover { border-color:#2563eb; color:#2563eb; }
.al-pg span.cur { background:#2563eb; border-color:#2563eb; color:#fff; }
.al-pg span.dis { opacity:.4; }
@media (max-width: 1100px) { .al-filters { grid-template-columns:1fr 1fr; } .al-row { grid-template-columns:70px 36px minmax(0,1fr); } .al-who { grid-column:3; } }
@media print {
    .sidebar, .al-tabs, .al-filters, .al-chips, .al-pg, .no-print, .toast-wrap { display:none !important; }
    .main-content { margin:0 !important; width:100% !important; max-width:100% !important; padding:0 !important; }
    .card-main, .al-stat { box-shadow:none !important; border:1px solid #e2e8f0; }
    .al-day { position:static; }
    body { background:#fff !important; }
}
</style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<?php if (isset($_SESSION['message'])): ?>
<div class="toast-wrap <?php echo $_SESSION['msg_type']==='success'?'success':'danger'; ?>" id="toastMsg">
    <i class="fa <?php echo $_SESSION['msg_type']==='success'?'fa-check-circle':'fa-exclamation-circle'; ?>" style="font-size:17px;margin-top:1px;flex-shrink:0;"></i>
    <div><?php echo $_SESSION['message']; unset($_SESSION['message'],$_SESSION['msg_type']); ?></div>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;margin-left:auto;font-size:15px;color:inherit;opacity:.5;">&#x2715;</button>
</div>
<?php endif; ?>

<!-- SIDEBAR -->
<?php include("includes/admin_sidebar.php"); ?>

<div class="main-content">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4 al-head">
        <div>
            <h2><i class="fa fa-clock-rotate-left text-primary me-2" style="font-size:22px;-webkit-text-fill-color:#2563eb;"></i>Activity Logs &amp; Audit Trail</h2>
            <p><?= $view === 'log' ? 'Who did what in the system, and when. Entries are recorded automatically and can\'t be edited.' : 'Completed, cancelled and archived appointments.' ?></p>
        </div>
        <div class="al-tabs">
            <a href="admin_history.php" class="<?= $view === 'log' ? 'on' : '' ?>"><i class="fa fa-list-ul"></i> Activity Log</a>
            <a href="admin_history.php?view=archive" class="<?= $view === 'archive' ? 'on' : '' ?>"><i class="fa fa-box-archive"></i> Archived Appointments <span class="n"><?= (int)$cnt_archived ?></span></a>
        </div>
    </div>

<?php if ($view === 'log'): ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="al-stat"><div class="ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-bolt"></i></div><div><div class="v"><?= number_format($al_today) ?></div><div class="l">Events today</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="al-stat"><div class="ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-calendar-week"></i></div><div><div class="v"><?= number_format($al_week) ?></div><div class="l">Events in the last 7 days</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="al-stat"><div class="ic" style="background:#fdf2f8;color:#db2777;"><i class="fa fa-users"></i></div><div><div class="v"><?= number_format($al_people) ?></div><div class="l">People active (7 days)</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="al-stat"><div class="ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-coins"></i></div><div><div class="v"><?= number_format($al_pays) ?></div><div class="l">Payments recorded (7 days)</div></div></div></div>
    </div>

    <div class="card-main">
        <form class="al-filters" method="get" action="admin_history.php">
            <input type="hidden" name="view" value="log">
            <?php if ($al_f['module'] !== ''): ?><input type="hidden" name="module" value="<?= htmlspecialchars($al_f['module']) ?>"><?php endif; ?>
            <div>
                <label>Search</label>
                <div class="srch"><i class="fa fa-search"></i><input type="text" name="q" class="form-control" value="<?= htmlspecialchars($al_f['q']) ?>" placeholder="Action, pet, invoice, item, name…"></div>
            </div>
            <div>
                <label>Done by</label>
                <select name="actor" class="form-select" onchange="this.form.submit()">
                    <option value="">Everyone</option>
                    <?php foreach ($al_actors as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $a === $al_f['actor'] ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>From</label><input type="date" name="from" class="form-control" value="<?= htmlspecialchars($al_f['from']) ?>" max="<?= date('Y-m-d') ?>"></div>
            <div><label>To</label><input type="date" name="to" class="form-control" value="<?= htmlspecialchars($al_f['to']) ?>" max="<?= date('Y-m-d') ?>"></div>
            <div class="d-flex gap-2">
                <button type="submit" class="al-btn pri"><i class="fa fa-filter"></i> Apply</button>
                <?php if (array_filter($al_f)): ?><a href="admin_history.php" class="al-btn gh" title="Clear filters"><i class="fa fa-xmark"></i></a><?php endif; ?>
                <a href="<?= htmlspecialchars(al_url(['export' => 'csv'])) ?>" class="al-btn gh" title="Download the filtered log as CSV"><i class="fa fa-file-csv"></i></a>
                <button type="button" class="al-btn gh" onclick="window.print()" title="Print"><i class="fa fa-print"></i></button>
            </div>
        </form>

        <div class="al-chips">
            <a href="<?= htmlspecialchars(al_url(['module' => '', 'page' => ''])) ?>" class="al-chip <?= $al_f['module'] === '' ? 'on' : '' ?>" style="<?= $al_f['module'] === '' ? 'background:#0f172a;' : '' ?>"><i class="fa fa-layer-group"></i> All <span class="n"><?= number_format($al_all_count) ?></span></a>
            <?php foreach (AL_MODULES as $m => [$ic, $col]): $on = $al_f['module'] === $m; ?>
                <a href="<?= htmlspecialchars(al_url(['module' => $m, 'page' => ''])) ?>" class="al-chip <?= $on ? 'on' : '' ?>" style="<?= $on ? "background:$col;" : '' ?>">
                    <i class="fa <?= $ic ?>" style="color:<?= $col ?>;"></i> <?= $m ?> <span class="n"><?= number_format($al_mod_counts[$m]) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($al_rows): ?>
            <?php $al_prev_day = null;
            foreach ($al_rows as $e):
                $day = date('Y-m-d', strtotime($e['created_at']));
                [$ic, $col] = AL_MODULES[$e['module']] ?? ['fa-circle', '#64748b'];
                $actor = trim((string)($e['actor'] ?: 'System'));
                $role = '';
                if (preg_match('/^(.*?)\s*\(([^()]*)\)$/', $actor, $mm)) { $who = $mm[1]; $role = $mm[2]; } else $who = $actor;
                $is_sys = in_array($actor, ['System', 'Clinic staff', 'Payment', 'Veterinarian'], true);
                if ($day !== $al_prev_day): $al_prev_day = $day; ?>
                    <div class="al-day"><?= al_day_label($day) ?> <?php if (in_array(al_day_label($day), ['Today', 'Yesterday'], true)): ?><span><?= date('M d, Y', strtotime($day)) ?></span><?php endif; ?></div>
                <?php endif; ?>
                <div class="al-row">
                    <div class="al-time"><?= date('g:i A', strtotime($e['created_at'])) ?></div>
                    <div class="al-ic" style="background:<?= $col ?>14;color:<?= $col ?>;"><i class="fa <?= $ic ?>"></i></div>
                    <div style="min-width:0;">
                        <div class="al-act">
                            <?= htmlspecialchars($e['action']) ?>
                            <span class="al-mod" style="background:<?= $col ?>14;color:<?= $col ?>;"><?= htmlspecialchars($e['module']) ?></span>
                            <?php if ($e['source'] === 'history'): ?><span class="al-rec" title="Reconstructed from dates saved in the record (before automatic logging started)">from records</span><?php endif; ?>
                        </div>
                        <?php if (trim((string)$e['details']) !== ''): ?><div class="al-det"><?= al_text($e['details']) ?></div><?php endif; ?>
                    </div>
                    <div class="al-who">
                        <div class="al-av <?= $is_sys ? 'sys' : '' ?>"><?= $is_sys ? '<i class="fa fa-gear"></i>' : htmlspecialchars(mb_strtoupper(mb_substr(ltrim(preg_replace('/^(Dr\.?\s*)/i', '', $who)), 0, 1))) ?></div>
                        <div style="min-width:0;">
                            <div class="nm" title="<?= htmlspecialchars($actor) ?>"><?= htmlspecialchars($who) ?></div>
                            <?php if ($role !== ''): ?><div class="rl"><?= htmlspecialchars(str_replace('_', ' ', $role)) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="al-foot">
                <div>Showing <b><?= number_format(($al_page - 1) * $al_per + 1) ?>–<?= number_format(min($al_total, $al_page * $al_per)) ?></b> of <b><?= number_format($al_total) ?></b> events</div>
                <?php if ($al_pages > 1): ?>
                <div class="al-pg">
                    <?php if ($al_page > 1): ?><a href="<?= htmlspecialchars(al_url(['page' => $al_page - 1])) ?>"><i class="fa fa-chevron-left"></i></a><?php else: ?><span class="dis"><i class="fa fa-chevron-left"></i></span><?php endif; ?>
                    <?php for ($p = max(1, $al_page - 2); $p <= min($al_pages, $al_page + 2); $p++): ?>
                        <?php if ($p === $al_page): ?><span class="cur"><?= $p ?></span><?php else: ?><a href="<?= htmlspecialchars(al_url(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($al_page < $al_pages): ?><a href="<?= htmlspecialchars(al_url(['page' => $al_page + 1])) ?>"><i class="fa fa-chevron-right"></i></a><?php else: ?><span class="dis"><i class="fa fa-chevron-right"></i></span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="al-empty"><i class="fa fa-clock-rotate-left"></i><div style="font-weight:700;font-size:15px;color:#475569;">No activity found</div><div style="font-size:13px;">Try a different search, person, module or date range.</div></div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#f0fdf4;"><i class="fa fa-star" style="color:#16a34a;"></i></div>
                <div><div style="font-size:22px;font-weight:800;color:#0f172a;"><?php echo $cnt_completed; ?></div><div style="font-size:12px;color:#94a3b8;font-weight:600;">Completed</div></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff1f2;"><i class="fa fa-times-circle" style="color:#be123c;"></i></div>
                <div><div style="font-size:22px;font-weight:800;color:#0f172a;"><?php echo $cnt_cancelled; ?></div><div style="font-size:12px;color:#94a3b8;font-weight:600;">Cancelled / Rejected</div></div>
            </div>
        </div>
    </div>

    <!-- Main card -->
    <div class="card-main">
        <div class="card-toolbar">
            <h5 style="font-weight:800;font-size:15px;color:#0f172a;margin:0;flex-shrink:0;"><i class="fa fa-box-archive text-secondary me-2"></i> All Records</h5>
            <div class="search-w" style="flex:1;">
                <span class="si"><i class="fa fa-search"></i></span>
                <input type="text" id="archiveSearch" placeholder="Search client, pet, service…" style="width:100%;">
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="ftab active" data-filter="all">All <span class="cnt"><?php echo count($rows); ?></span></button>
                <button class="ftab" data-filter="completed">Completed <span class="cnt"><?php echo $cnt_completed; ?></span></button>
                <button class="ftab" data-filter="cancelled">Cancelled <span class="cnt"><?php echo $cnt_cancelled; ?></span></button>
            </div>
        </div>

        <div style="overflow-x:auto;">
        <?php if (count($rows) > 0): ?>
        <table class="appt-table" id="archiveTable">
            <thead>
                <tr>
                    <th data-sort-method="number" style="cursor:pointer;"># <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Client / Pet <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Service <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Schedule <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Doctor <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Status <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th style="cursor:pointer;">Booked On <i class="fa fa-sort ms-1" style="font-size:10px;opacity:.5;"></i></th>
                    <th data-sort-method="none">Actions</th>
                </tr>
            </thead>
            <tbody id="archiveBody">
            <?php foreach ($rows as $row):
                $status = $row['status'];
                $isArch = (bool)$row['is_archived'];
                $bsCls = match(true) {
                    $status === 'Completed' => 'bs-completed',
                    $status === 'Approved'  => 'bs-approved',
                    str_contains($status,'Cancel') || str_contains($status,'Reject') => 'bs-cancelled',
                    default => 'bs-archived'
                };
                $dot = match(true) {
                    $status === 'Completed'                                          => '#2563eb',
                    $status === 'Approved'                                           => '#16a34a',
                    str_contains($status,'Cancel') || str_contains($status,'Reject') => '#be123c',
                    default => '#94a3b8'
                };
                $filterVal = match(true) {
                    $status === 'Completed'                                          => 'completed',
                    str_contains($status,'Reject')                                  => 'rejected',
                    str_contains($status,'Cancel')                                  => 'cancelled',
                    default => 'other'
                };
                $rowData = htmlspecialchars(json_encode($row, JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES);
            ?>
            <tr class="arch-row" data-filter="<?php echo $filterVal; ?>" onclick="openModal(<?php echo $rowData; ?>)">
                <td style="font-size:12px;color:#94a3b8;font-weight:600;">#<?php echo $row['id']; ?></td>
                <td>
                    <?php
$arch_pet_display = htmlspecialchars($row['pet_name'] ?? '-');
// Try to get enriched pet data from junction table
if (function_exists('getAppointmentPets')) {
    $arch_pets = getAppointmentPets($conn, $row['id']);
    if (!empty($arch_pets)) {
        $arch_names = array_column($arch_pets, 'display_name');
        $arch_pet_display = htmlspecialchars(implode(', ', $arch_names));
    }
}
$arch_is_multi = !empty($row['is_multi_pet']) && $row['is_multi_pet'] == 1;
?>
<div style="display:flex;align-items:center;gap:10px;">
    <div class="av" style="background:linear-gradient(135deg,#e0f2fe,#bae6fd);"><i class="fa fa-paw" style="color:#0369a1;"></i></div>
    <div>
        <div style="font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($row['client_name']); ?></div>
        <div style="font-size:12px;color:#64748b;">
            <?php echo $arch_pet_display; ?>
            <?php if ($arch_is_multi): ?>
                <span style="background:#eff6ff;color:#2563eb;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px;">multi-pet</span>
            <?php endif; ?>
        </div>
    </div>
</div>
                </td>
                <td style="font-weight:600;color:#334155;max-width:160px;"><?php echo htmlspecialchars($row['service_type'] ?? 'General Checkup'); ?></td>
                <td>
                    <div style="font-weight:600;color:#0f172a;font-size:13px;"><?php echo date("M d, Y", strtotime($row['appointment_date'])); ?></div>
                    <div style="font-size:12px;color:#64748b;"><?php echo date("h:i A", strtotime($row['appointment_time'])); ?></div>
                </td>
                <td style="font-size:13px;color:#334155;font-weight:500;"><?php echo htmlspecialchars($row['doctor_name']); ?></td>
                <td>
                    <?php if ($isArch): ?>
                    <span style="background:#f1f5f9;color:#475569;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;border:1px solid #e2e8f0;"><i class="fa fa-archive me-1"></i>Archived</span>
                    <?php endif; ?>
                    <span class="bs <?php echo $bsCls; ?>">
                        <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $dot; ?>;flex-shrink:0;"></span>
                        <?php echo htmlspecialchars($status); ?>
                    </span>
                </td>
                <td style="font-size:12px;color:#94a3b8;"><?php echo date("M d, Y", strtotime($row['created_at'])); ?></td>
                <td onclick="event.stopPropagation();">
                    <div style="display:flex;gap:4px;">
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size:11px;font-weight:700;" onclick="submitRowAction('unarchive', <?php echo $row['id']; ?>)">
                            <i class="fa fa-undo me-1"></i>Restore
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div id="noArchResult" style="display:none;text-align:center;padding:48px;color:#94a3b8;">
            <i class="fa fa-search" style="font-size:36px;display:block;margin-bottom:10px;opacity:.3;"></i>
            No records match your search or filter.
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:60px 20px;color:#94a3b8;">
            <i class="fa fa-archive" style="font-size:48px;display:block;margin-bottom:14px;opacity:.3;"></i>
            <div style="font-weight:700;font-size:16px;color:#475569;margin-bottom:6px;">Archive is Empty</div>
            <div style="font-size:13px;">Completed and cancelled appointments will appear here automatically.</div>
        </div>
        <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>

<!-- DETAIL MODAL -->
<div class="dmodal-bd" id="archBd">
    <div class="dmodal">
        <div class="dmodal-hdr">
            <h5 style="font-weight:800;font-size:16px;color:#0f172a;margin:0;display:flex;align-items:center;gap:9px;">
                <span style="width:34px;height:34px;border-radius:9px;background:#f1f5f9;display:inline-flex;align-items:center;justify-content:center;color:#475569;font-size:14px;"><i class="fa fa-archive"></i></span>
                Archive Record
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
                <div class="info-i"><label><i class="fa fa-user-md me-1"></i>Doctor</label><div class="v" id="dm-doctor"></div></div>
                <div class="info-i"><label><i class="fa fa-map-marker-alt me-1"></i>Location</label><div class="v" id="dm-loc"></div></div>
                <div class="info-i"><label><i class="fa fa-calendar me-1"></i>Booked On</label><div class="v" id="dm-created"></div></div>
                <div class="info-i"><label><i class="fa fa-id-badge me-1"></i>Appt ID</label><div class="v" id="dm-id"></div></div>
                <div class="info-i full" id="dm-admin-msg-w" style="display:none;">
                    <label><i class="fa fa-comment me-1"></i>Admin Note</label>
                    <div class="v" id="dm-admin-msg" style="font-size:13px;font-weight:500;background:#fff7ed;padding:10px;border-radius:8px;color:#9a3412;"></div>
                </div>
            </div>
        </div>
        <div class="dmodal-foot" id="dm-foot">
            <button class="btn-act gray" onclick="closeModal()">Close</button>
            <button class="btn-act g" id="dm-restore-btn" style="display:none;" onclick="submitAction('unarchive')"><i class="fa fa-undo me-1"></i> Restore to Appointments</button>
        </div>
        <form method="POST" id="modalActionForm" style="display:none;">
            <input type="hidden" name="apt_id" id="dm-modal-apt-id">
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Toast
(function(){ var t=document.getElementById('toastMsg'); if(t) setTimeout(function(){ t.style.opacity='0'; t.style.transform='translateY(-14px)'; setTimeout(function(){t.remove();},400); },4500); })();

// Live search + filter
(function(){
    var inp  = document.getElementById('archiveSearch');
    var body = document.getElementById('archiveBody');
    var noRes = document.getElementById('noArchResult');
    var af = 'all';

    function matchFilter(rowFilter, active) {
        if (active === 'all') return true;
        if (active === 'cancelled') return rowFilter === 'cancelled' || rowFilter === 'rejected';
        return rowFilter === active;
    }

    function apply(){
        if (!body) return;
        var q = inp ? inp.value.toLowerCase().trim() : '';
        var rows = body.querySelectorAll('.arch-row');
        var vis = 0;
        rows.forEach(function(r){
            var fm = matchFilter(r.dataset.filter, af);
            var tm = !q || r.innerText.toLowerCase().includes(q);
            var show = fm && tm;
            r.style.display = show ? '' : 'none';
            if (show) vis++;
        });
        if (noRes) noRes.style.display = vis === 0 ? 'block' : 'none';
    }

    if (inp) inp.addEventListener('input', apply);
    document.querySelectorAll('.ftab').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.ftab').forEach(function(b){ b.classList.remove('active'); });
            btn.classList.add('active');
            af = btn.dataset.filter;
            apply();
        });
    });
})();

// Helpers
function esc(s){ return s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'):'-'; }
function fmtDate(d){ if(!d) return '-'; var o=new Date(d+'T00:00:00'); return o.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function fmtTime(t){ if(!t) return ''; var p=t.split(':'); var h=parseInt(p[0]),m=p[1],ap=h>=12?'PM':'AM'; h=h%12||12; return h+':'+m+' '+ap; }
function fmtFull(s){ if(!s) return '-'; return new Date(s).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true}); }

function statusBadge(s){
    var cls='bs-archived',dot='#94a3b8';
    if(s==='Completed'){cls='bs-completed';dot='#2563eb';}
    else if(s==='Approved'){cls='bs-approved';dot='#16a34a';}
    else if(s.includes('Cancel')||s.includes('Reject')){cls='bs-cancelled';dot='#be123c';}
    return '<span class="bs '+cls+'" style="font-size:14px;padding:7px 18px;"><span style="width:9px;height:9px;border-radius:50%;background:'+dot+';flex-shrink:0;"></span>'+esc(s)+'</span>';
}

var _currentId = null;

function openModal(d){
    _currentId = d.id;
    document.getElementById('dm-svc').textContent     = d.service_type || 'Appointment';
    document.getElementById('dm-dt').textContent      = fmtDate(d.appointment_date) + (d.appointment_time ? ' at '+fmtTime(d.appointment_time) : '');
    document.getElementById('dm-status-w').innerHTML  = statusBadge(d.status);
    document.getElementById('dm-client').textContent  = d.client_name || '-';
    document.getElementById('dm-pet').textContent     = d.pet_name || '-';
    document.getElementById('dm-doctor').textContent  = d.doctor_name || 'Unassigned';
    document.getElementById('dm-loc').textContent     = d.service_location || '-';
    document.getElementById('dm-created').textContent = fmtFull(d.created_at);
    document.getElementById('dm-id').textContent      = '#' + d.id;
    document.getElementById('dm-modal-apt-id').value  = d.id;

    var amW = document.getElementById('dm-admin-msg-w');
    if(d.admin_message){ amW.style.display='block'; document.getElementById('dm-admin-msg').textContent=d.admin_message; }
    else amW.style.display='none';

    var restoreBtn = document.getElementById('dm-restore-btn');
    restoreBtn.style.display = parseInt(d.is_archived) ? 'inline-flex' : 'none';

    var bd = document.getElementById('archBd');
    bd.classList.add('open');
    setTimeout(function(){ bd.style.opacity='1'; },10);
}

function closeModal(){
    var bd = document.getElementById('archBd');
    bd.style.opacity='0';
    setTimeout(function(){ bd.classList.remove('open'); bd.style.opacity=''; },180);
}

function submitAction(action){
    if(action==='unarchive') {
        Swal.fire({
            title: 'Restore Appointment?',
            text: 'This will move the record back to the Active list.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Yes, Restore'
        }).then((result) => {
            if (result.isConfirmed) {
                var f = document.getElementById('modalActionForm');
                var inp = document.createElement('input');
                inp.type='hidden'; inp.name=action; inp.value='1';
                f.appendChild(inp);
                f.submit();
            }
        });
    }
}

document.getElementById('archBd').addEventListener('click',function(e){ if(e.target===this) closeModal(); });

function submitRowAction(action, id) {
    if (action === 'unarchive') {
        Swal.fire({
            title: 'Restore Appointment?',
            text: 'This will move the record back to the Active list.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Yes, Restore'
        }).then((result) => {
            if (result.isConfirmed) {
                var f = document.createElement('form'); f.method='POST'; f.style.display='none';
                f.innerHTML = '<input name="unarchive" value="1"><input name="apt_id" value="'+id+'">';
                document.body.appendChild(f);
                f.submit();
            }
        });
    }
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tablesort/5.2.1/tablesort.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/tablesort/5.2.1/sorts/tablesort.number.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var t = document.getElementById('archiveTable');
    if(t) new Tablesort(t, { descending: true });
});
</script>
</body>
</html>
