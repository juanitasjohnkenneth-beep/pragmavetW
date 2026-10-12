<?php
session_start();
include("dbconnection.php");
include_once('assets/saas_calendar_component.php');

if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}
include_once("includes/rbac_guard.php");
// RBAC: Client Portal access control
$user_role = rbac_get_role();
if (!in_array($user_role, ['user', 'client'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}


$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user'];

/* EFFICIENT COUNTS SINGLE QUERY */
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        IFNULL(SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END), 0) as pending,
        IFNULL(SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END), 0) as approved
    FROM appointments WHERE user_id=?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

$total = $stats['total'];
$pending = $stats['pending'];
$approved = $stats['approved'];

// Fetch upcoming appointments for calendar
$my_appointments = $conn->query("SELECT a.*, a.appointment_date, a.appointment_time, a.pet_name, a.service_type, a.status, a.user_id, a.doctor_id FROM appointments a WHERE a.user_id=$user_id ORDER BY a.appointment_date ASC, a.appointment_time ASC");

// Active Public Announcements for Dashboard
$active_announcements = $conn->query("SELECT * FROM announcements WHERE is_active=1 ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<script src="theme.js?v=<?=time()?>"></script>
<link rel="stylesheet" href="theme.css?v=<?=time()?>">

<title>Dashboard | PragmaVet</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --primary: #2563eb;
    --secondary: #3b82f6;
    --dark: #1e40af;
    --light-bg: #F4F9FB;
    --sidebar-width:260px;

    /* Theme Tokens */
    --bg-color: #F4F9FB;
    --card-bg: #ffffff;
    --text-main: #333333;
    --text-heading: #1e40af;
    --border-color: #edf2f6;
    --input-bg: #fafcff;
}



body {
    background: var(--bg-color);
    font-family: 'Inter', sans-serif;
    color: var(--text-main);
    overflow-x: hidden;
    transition: background-color 0.4s ease, color 0.4s ease;
}

/* SIDEBAR */
.sidebar { background:#0f172a; width:var(--sidebar-width); height:100vh; position:fixed; top:0; left:0; padding:24px 16px; z-index:1000; display:flex; flex-direction:column; box-shadow:5px 0 20px rgba(0,0,0,.2); overflow-y:auto; }

.sidebar h4 { color:#ffffff; font-weight:800; font-size:22px; margin-bottom:30px; letter-spacing:0.5px; display:flex; align-items:center; gap:10px; }

.sidebar-user {
    padding: 15px;
    background: rgba(255,255,255,0.05);
    border-radius: 12px;
    margin-bottom: 30px;
    margin-top: 20px;
}

.sidebar a { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:8px; color:#94a3b8; text-decoration:none; margin-bottom:4px; font-weight:600; font-size:15px; transition:.2s; }

.sidebar a i { width: 30px; font-size: 18px; }

.sidebar a:hover, .sidebar a.active { background:#2563eb; color:#ffffff; }

.sidebar .logout-link {
    position: absolute;
    bottom: 30px;
    width: calc(100% - 40px);
    color: #ff6b6b;
}

.sidebar .logout-link:hover {
    background: rgba(255, 107, 107, 0.1);
    color: #ff4757;
    transform: none;
}

/* CONTENT AREA */
.main-content {
    margin-left: var(--sidebar-width);
    padding: 40px 50px;
    min-height: 100vh;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 40px;
}

.page-header h2 {
    font-weight: 700;
    color: var(--text-heading);
    margin: 0;
}

/* STAT CARDS */
@keyframes slideUpEntrance {
    0% { opacity: 0; transform: translateY(30px); }
    100% { opacity: 1; transform: translateY(0); }
}

.stat-card {
    border-radius: 20px;
    padding: 30px;
    color: white;
    position: relative;
    overflow: hidden;
    transition: 0.3s;
    box-shadow: 0 10px 20px rgba(0,0,0,0.05);
    height: 100%;
    animation: slideUpEntrance 0.7s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
    opacity: 0;
}
.stat-card.delay-1 { animation-delay: 0.1s; }
.stat-card.delay-2 { animation-delay: 0.2s; }
.stat-card.delay-3 { animation-delay: 0.3s; }

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.1);
}

.stat-icon {
    position: absolute;
    right: -20px;
    bottom: -20px;
    font-size: 100px;
    opacity: 0.15;
    transform: rotate(-15deg);
}

.bg-primary-custom { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
.bg-success-custom { background: linear-gradient(135deg, #3b82f6, #268dae); }
.bg-warning-custom { background: linear-gradient(135deg, #405F6E, #284451); }

.stat-card h5 { font-size: 16px; opacity: 0.9; font-weight: 500; margin-bottom: 10px; }
.stat-card h2 { font-size: 42px; font-weight: 800; margin: 0; }

.card-premium {
    background: var(--card-bg);
    border: none;
    border-radius: 24px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.03);
    padding: 35px;
    height: 100%;
    transition: background-color 0.4s ease;
    animation: slideUpEntrance 0.7s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
    animation-delay: 0.4s;
    opacity: 0;
}

.card-premium h5 {
    font-weight: 700;
    color: var(--text-heading);
    margin-bottom: 25px;
    display: flex;
    align-items: center;
}
.card-premium h5 i { margin-right: 12px; color: var(--primary); }

/* FORMS */
.form-control {
    border-radius: 12px;
    padding: 14px 20px;
    border: 1px solid var(--border-color);
    font-size: 15px;
    background: var(--input-bg);
    color: var(--text-main);
    transition: 0.3s;
}

.form-control:focus {
    box-shadow: 0 0 0 4px rgba(74, 127, 196,0.15);
    border-color: var(--secondary);
    background: white;
}

.btn-primary {
    background: var(--primary);
    border: none;
    padding: 14px 20px;
    font-weight: 600;
    border-radius: 12px;
    transition: 0.3s;
}

.btn-primary:hover {
    background: var(--dark);
    transform: translateY(-2px);
    box-shadow: 0 8px 15px rgba(32, 44, 61, 0.2);
}

/* TABLES */
.table-custom {
    color: var(--text-main);
}
.table-custom thead th {
    border-bottom: 2px solid var(--border-color);
    color: #8898aa;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding-bottom: 15px;
    font-weight: 600;
}
.table-custom tbody tr {
    transition: 0.2s;
}
.table-custom tbody tr:hover {
    background: rgba(37, 99, 235, 0.05);
}
.table-custom tbody td {
    padding: 18px 10px;
    vertical-align: middle;
    border-bottom: 1px solid var(--border-color);
    font-weight: 500;
}

.badge-custom {
    padding: 6px 12px;
    border-radius: 30px;
    font-weight: 600;
    font-size: 12px;
}
.badge-pending { background: #fff5e6; color: #f59f00; border: 1px solid #fce8cc; }
.badge-approved { background: #e6fcf5; color: #0ca678; border: 1px solid #c3f2e3; }
.badge-cancelled { background: #fff0f0; color: #fa5252; border: 1px solid #ffe3e3; }

/* Alert Absolute */
.alert-container {
    position: fixed;
    top: 30px;
    right: 40px;
    z-index: 9999;
    min-width: 300px;
    animation: slideDown 0.5s ease-out forwards;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 992px) {
    .sidebar { background:#0f172a; width:var(--sidebar-width); height:100vh; position:fixed; top:0; left:0; padding:24px 16px; z-index:1000; display:flex; flex-direction:column; box-shadow:5px 0 20px rgba(0,0,0,.2); overflow-y:auto; }
    .main-content { margin-left: 0; padding: 20px; }
}

.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }

/* ====== WHITE SIDEBAR THEME (Reference Mockup Match) ====== */
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
    padding: 13px 18px !important;
    border-radius: 12px !important;
    color: #334155 !important;
    text-decoration: none !important;
    margin-bottom: 4px !important;
    font-weight: 600 !important;
    font-size: 15px !important;
    transition: all 0.2s ease !important;
    letter-spacing: -0.1px !important;
}

.sidebar a i {
    width: 22px !important;
    text-align: center !important;
    font-size: 17px !important;
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

.sidebar .sidebar-logout a:hover i {
    color: #b91c1c !important;
}

.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.06); border-radius: 10px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }

/* ====== Client dashboard UI ====== */
.cd-hero { background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 60%,#3b82f6 100%); border-radius:22px; padding:26px 30px; color:#fff; display:flex; justify-content:space-between; align-items:center; gap:18px; flex-wrap:wrap; position:relative; overflow:visible; margin-bottom:22px; box-shadow:0 14px 34px rgba(37,99,235,.22); }
.cd-hero::after { content:'\f1b0'; font-family:'Font Awesome 6 Free'; font-weight:900; position:absolute; right:260px; bottom:-18px; font-size:120px; opacity:.07; pointer-events:none; }
.cd-hero-text { min-width:0; flex:1; }
.cd-date { display:inline-flex; align-items:center; background:rgba(255,255,255,.16); border-radius:999px; padding:4px 12px; font-size:12px; font-weight:600; }
.cd-hero h2 { font-size:27px; font-weight:800; margin:10px 0 4px; letter-spacing:-.5px; color:#fff !important; -webkit-text-fill-color:#fff !important; background:none !important; }
.cd-hero p { margin:0; color:#dbeafe; font-size:14px; }
.cd-hero p b { color:#fff; }
.cd-hero-actions { display:flex; align-items:center; gap:12px; position:relative; z-index:5; }
.cd-bell { width:44px; height:44px; border-radius:50%; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; color:#fff !important; margin:0 !important; font-size:18px; }
.cd-bell:hover { background:rgba(255,255,255,.26); }
.cd-bell .notif-dropdown { color:#0f172a; text-align:left; }
.cd-cta { display:inline-flex; align-items:center; gap:8px; background:#fff; color:#1e3a8a !important; font-weight:800; border-radius:999px; padding:11px 20px; text-decoration:none; box-shadow:0 8px 20px rgba(15,23,42,.18); transition:transform .15s; white-space:nowrap; }
.cd-cta:hover { transform:translateY(-2px); }
.cd-cta.sm { background:#2563eb; color:#fff !important; padding:9px 18px; font-size:13.5px; box-shadow:0 6px 16px rgba(37,99,235,.25); }

.cd-ann { display:flex; gap:14px; background:#fff; border:1px solid #e8edf3; border-left:4px solid #f59e0b; border-radius:16px; padding:14px 18px; margin-bottom:22px; box-shadow:0 2px 12px rgba(15,23,42,.04); }
.cd-ann-ic { width:40px; height:40px; border-radius:12px; background:#fffbeb; color:#d97706; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:16px; }
.cd-ann-list { flex:1; min-width:0; max-height:130px; overflow-y:auto; }
.cd-label { font-size:11px; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:#94a3b8; margin-bottom:4px; }
.cd-ann-item { font-size:13.5px; color:#475569; padding:4px 0; border-top:1px dashed #eef2f6; }
.cd-ann-item:first-of-type { border-top:none; }
.cd-ann-item b { color:#0f172a; margin-right:6px; }
.cd-ann-item small { color:#94a3b8; margin-left:6px; white-space:nowrap; }

.cd-stats { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:16px; margin-bottom:22px; }
.cd-stat { background:#fff; border:1px solid #e8edf3; border-radius:18px; padding:18px 20px; display:flex; gap:16px; align-items:center; box-shadow:0 2px 12px rgba(15,23,42,.04); transition:transform .15s, box-shadow .15s; }
.cd-stat:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(15,23,42,.08); }
.cd-stat-ic { width:52px; height:52px; border-radius:15px; display:flex; align-items:center; justify-content:center; font-size:21px; flex-shrink:0; }
.cd-stat h2 { font-size:30px; font-weight:800; margin:0; color:#0f172a; line-height:1.05; -webkit-text-fill-color:#0f172a; background:none; }
.cd-stat h5 { font-size:13.5px; font-weight:700; margin:3px 0 0; color:#334155; }
.cd-stat small { font-size:12px; color:#94a3b8; }

.cd-card { background:#fff; border:1px solid #e8edf3; border-radius:20px; padding:20px 22px; box-shadow:0 2px 14px rgba(15,23,42,.04); }
.cd-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:14px; }
.cd-card-head h4 { font-size:17px; font-weight:800; margin:0; color:#0f172a; }
.cd-card-head span { font-size:12.5px; color:#94a3b8; }
.cd-link { font-size:13px; font-weight:700; color:#2563eb; text-decoration:none; white-space:nowrap; }
.cd-link:hover { text-decoration:underline; }
.cd-visits { display:flex; flex-direction:column; gap:10px; }
.cd-visit { display:flex; align-items:center; gap:14px; padding:12px 14px; border:1px solid #eef2f6; border-radius:14px; text-decoration:none; color:inherit; transition:background .15s, border-color .15s; }
.cd-visit:hover { background:#f8fbff; border-color:#bfdbfe; color:inherit; }
.cd-day { width:52px; text-align:center; border-radius:12px; background:#eff6ff; padding:6px 0; flex-shrink:0; }
.cd-day span { display:block; font-size:11px; font-weight:800; color:#2563eb; text-transform:uppercase; letter-spacing:.06em; }
.cd-day b { display:block; font-size:20px; font-weight:800; color:#1e3a8a; line-height:1.1; }
.cd-visit-main { flex:1; min-width:0; }
.cd-visit-pet { font-weight:800; color:#0f172a; font-size:15px; }
.cd-visit-pet i { color:#94a3b8; font-size:12px; }
.cd-visit-sub { font-size:12.5px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cd-badge { font-size:11.5px; font-weight:700; border-radius:999px; padding:4px 10px; white-space:nowrap; }
.cd-empty { text-align:center; padding:26px 10px; }
.cd-empty-ic { width:62px; height:62px; border-radius:18px; background:#eff6ff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; font-size:26px; margin-bottom:10px; }
.cd-actions { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.cd-action { display:flex; flex-direction:column; gap:3px; padding:16px; border:1px solid #eef2f6; border-radius:16px; text-decoration:none; color:inherit; transition:transform .15s, box-shadow .15s, border-color .15s; }
.cd-action:hover { transform:translateY(-2px); box-shadow:0 10px 22px rgba(15,23,42,.07); border-color:#dbeafe; color:inherit; }
.cd-action-ic { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:17px; margin-bottom:8px; }
.cd-action b { font-size:14.5px; color:#0f172a; }
.cd-action small { font-size:12px; color:#94a3b8; }

@media (max-width: 992px) { .cd-stats { grid-template-columns:1fr; } .cd-hero::after { display:none; } }
@media (max-width: 576px) { .cd-actions { grid-template-columns:1fr; } .cd-hero { padding:20px; } .cd-hero h2 { font-size:22px; } }
</style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<?php if(isset($_SESSION['message'])): ?>
<div class="alert-container">
    <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show shadow-sm" role="alert">
        <strong><?php echo $_SESSION['msg_type'] == 'success' ? '<i class="fa fa-check-circle"></i>' : '<i class="fa fa-exclamation-circle"></i>'; ?></strong> 
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php 
    unset($_SESSION['message']);
    unset($_SESSION['msg_type']);
endif; 
?>

<!-- SIDEBAR -->
<div class="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-icon"><i class="fa fa-paw"></i></div>
            <span>PragmaVet</span>
        </div>
        <div class="sidebar-user">
            <div class="welcome">Welcome back,</div>
            <div class="uname"><?php echo htmlspecialchars($user_name ?? ''); ?></div>
        </div>
        <a href="dashboard.php" class="active"><i class="fa fa-home"></i> <span>Dashboard</span></a>
        <a href="appointments.php"><i class="fa fa-calendar-alt"></i> <span>Appointments</span></a>
        <a href="boarding.php"><i class="fa fa-hotel"></i> <span>Pet Boarding</span></a>
        <a href="pets.php"><i class="fa fa-notes-medical"></i> <span>Profile & Pets</span></a>
        <a href="billing.php"><i class="fa fa-file-invoice-dollar"></i> <span>Billing</span></a>
        <a href="settings.php"><i class="fa fa-cog"></i> <span>Account Settings</span></a>
        <div class="sidebar-logout"><a href="logout.php"><i class="fa fa-sign-out-alt"></i> <span>Logout</span></a></div>
    </div>

<div class="main-content">
    <?php
    // Display only: upcoming visits from the appointments already loaded above
    $db_today = $conn->query("SELECT CURDATE() d")->fetch_assoc()['d'];
    $upcoming = [];
    if ($my_appointments) while ($ua = $my_appointments->fetch_assoc()) {
        if ($ua['appointment_date'] >= $db_today && in_array($ua['status'], ['Pending', 'Approved', 'Checked In', 'In Progress', 'Reschedule Proposed'], true)) $upcoming[] = $ua;
    }
    $next_visit = $upcoming[0] ?? null;
    $st_style = ['Pending' => ['#fff7ed', '#c2410c', 'Waiting for approval'], 'Approved' => ['#ecfdf5', '#047857', 'Confirmed'], 'Checked In' => ['#ecfeff', '#0e7490', 'Checked in'],
                 'In Progress' => ['#f5f3ff', '#6d28d9', 'In progress'], 'Reschedule Proposed' => ['#fdf4ff', '#a21caf', 'New date proposed']];
    $first_name = trim(explode(' ', trim((string)$user_name))[0] ?? '');
    ?>

    <!-- Welcome -->
    <div class="cd-hero">
        <div class="cd-hero-text">
            <div class="cd-date"><i class="far fa-calendar me-1"></i><?php echo date("l, F j, Y", strtotime($db_today)); ?></div>
            <h2><span id="cdGreeting">Welcome back</span>, <?php echo htmlspecialchars($first_name ?: 'there'); ?>!</h2>
            <p>
                <?php if ($next_visit): ?>
                    Your next visit: <b><?php echo htmlspecialchars($next_visit['pet_name']); ?></b> · <?php echo htmlspecialchars($next_visit['service_type']); ?> on
                    <b><?php echo date('M j', strtotime($next_visit['appointment_date'])); ?> at <?php echo date('g:i A', strtotime($next_visit['appointment_time'])); ?></b>.
                <?php else: ?>
                    You have no upcoming visits. Book one whenever your pet needs a check-up.
                <?php endif; ?>
            </p>
        </div>
        <div class="cd-hero-actions">
            <div class="notif-bell cd-bell" onclick="toggleNotifications()" title="Notifications">
                <i class="fa fa-bell"></i>
                <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        Notifications
                        <button class="btn btn-sm btn-light p-1" onclick="markAllRead(event)"><i class="fa fa-check-double text-success"></i></button>
                    </div>
                    <div class="notif-body" id="notifList">
                        <div class="text-center text-muted small p-3">Loading...</div>
                    </div>
                </div>
            </div>
            <!-- Dark Mode Toggle -->
            <button class="btn btn-sm btn-outline-secondary rounded-circle shadow-sm" id="themeToggle" style="width: 40px; height: 40px;">
                <i class="fa fa-moon" id="themeIcon"></i>
            </button>
            <a href="appointments.php" class="cd-cta"><i class="fa fa-calendar-plus"></i> Book Appointment</a>
        </div>
    </div>

    <!-- Announcements -->
    <?php if($active_announcements && $active_announcements->num_rows > 0): ?>
    <div class="cd-ann">
        <div class="cd-ann-ic"><i class="fa fa-bullhorn"></i></div>
        <div class="cd-ann-list">
            <div class="cd-label">Clinic announcements</div>
            <?php while($ann = $active_announcements->fetch_assoc()): ?>
                <div class="cd-ann-item">
                    <b><?php echo htmlspecialchars($ann['title']); ?></b>
                    <span><?php echo htmlspecialchars(str_replace(["\r", "\n"], ' ', $ann['message'])); ?></span>
                    <small><?php echo date("M d, Y", strtotime($ann['created_at'])); ?></small>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="cd-stats">
        <div class="cd-stat">
            <span class="cd-stat-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-calendar-day"></i></span>
            <div><h2 class="counter" data-target="<?php echo $total; ?>">0</h2><h5>Total Appointments</h5><small>All visits you've booked</small></div>
        </div>
        <div class="cd-stat">
            <span class="cd-stat-ic" style="background:#fff7ed;color:#ea580c;"><i class="fa fa-hourglass-half"></i></span>
            <div><h2 class="counter" data-target="<?php echo $pending; ?>">0</h2><h5>Pending Requests</h5><small>Waiting for the clinic to approve</small></div>
        </div>
        <div class="cd-stat">
            <span class="cd-stat-ic" style="background:#ecfdf5;color:#059669;"><i class="fa fa-check-double"></i></span>
            <div><h2 class="counter" data-target="<?php echo $approved; ?>">0</h2><h5>Approved Sessions</h5><small>Confirmed by the clinic</small></div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Upcoming visits -->
        <div class="col-xl-7">
            <div class="cd-card h-100">
                <div class="cd-card-head">
                    <div><h4><i class="fa fa-clock text-primary me-2"></i>Upcoming visits</h4><span>Your next scheduled appointments</span></div>
                    <a href="appointments.php" class="cd-link">View all <i class="fa fa-arrow-right"></i></a>
                </div>
                <?php if (!$upcoming): ?>
                    <div class="cd-empty">
                        <div class="cd-empty-ic"><i class="fa fa-calendar-plus"></i></div>
                        <div class="fw-bold text-dark">No upcoming visits</div>
                        <div class="small text-muted mb-3">Schedule a check-up, vaccination or grooming in a few steps.</div>
                        <a href="appointments.php" class="cd-cta sm"><i class="fa fa-plus"></i> Book Appointment</a>
                    </div>
                <?php else: ?>
                    <div class="cd-visits">
                        <?php foreach (array_slice($upcoming, 0, 4) as $v): [$bg, $fg, $lbl] = $st_style[$v['status']] ?? ['#f1f5f9', '#475569', $v['status']]; ?>
                        <a class="cd-visit" href="appointments.php">
                            <div class="cd-day"><span><?php echo date('M', strtotime($v['appointment_date'])); ?></span><b><?php echo date('d', strtotime($v['appointment_date'])); ?></b></div>
                            <div class="cd-visit-main">
                                <div class="cd-visit-pet"><i class="fa fa-paw"></i> <?php echo htmlspecialchars($v['pet_name']); ?></div>
                                <div class="cd-visit-sub"><?php echo htmlspecialchars($v['service_type']); ?> · <?php echo date('l, g:i A', strtotime($v['appointment_date'] . ' ' . $v['appointment_time'])); ?></div>
                            </div>
                            <span class="cd-badge" style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;"><?php echo htmlspecialchars($lbl); ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($upcoming) > 4): ?><div class="small text-muted mt-2">+ <?php echo count($upcoming) - 4; ?> more in Appointments</div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="col-xl-5">
            <div class="cd-card h-100">
                <div class="cd-card-head"><div><h4><i class="fa fa-bolt text-warning me-2"></i>Quick actions</h4><span>Jump to what you need</span></div></div>
                <div class="cd-actions">
                    <a href="appointments.php" class="cd-action"><span class="cd-action-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-calendar-plus"></i></span><b>Book Appointment</b><small>Schedule your next visit</small></a>
                    <a href="pets.php" class="cd-action"><span class="cd-action-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-paw"></i></span><b>My Pets</b><small>View or add pet records</small></a>
                    <a href="boarding.php" class="cd-action"><span class="cd-action-ic" style="background:#ecfeff;color:#0e7490;"><i class="fa fa-hotel"></i></span><b>Pet Boarding</b><small>Book a stay, watch live</small></a>
                    <a href="billing.php" class="cd-action"><span class="cd-action-ic" style="background:#ecfdf5;color:#059669;"><i class="fa fa-file-invoice-dollar"></i></span><b>Billing</b><small>Invoices and payments</small></a>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include("chat_widget.php"); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>


    

    // Greeting by time of day
    (function () { var h = new Date().getHours(), g = document.getElementById('cdGreeting'); if (g) g.textContent = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening'); })();

    // Number Counter Animation
    const counters = document.querySelectorAll('.counter');
    counters.forEach(counter => {
        const updateCount = () => {
            const target = +counter.getAttribute('data-target');
            const count = +counter.innerText;
            const inc = target / 20;

            if (count < target) {
                counter.innerText = Math.ceil(count + inc);
                setTimeout(updateCount, 40);
            } else {
                counter.innerText = target;
            }
        };
        updateCount();
    });

setTimeout(function() {
    let alerts = document.querySelectorAll('.alert-container');
    alerts.forEach(a => {
        a.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
        a.style.opacity = '0';
        a.style.transform = 'translateY(-20px)';
        setTimeout(() => a.remove(), 500);
    });
}, 4500);
</script>
    <?php include_once("includes/reminder_popup.php"); ?>
</body>
</html>
