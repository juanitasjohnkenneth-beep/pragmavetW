<?php
session_start();
include_once('assets/saas_calendar_component.php');
include("dbconnection.php");
include_once("includes/rbac_guard.php");
include_once("includes/doctor_weekly_schedule.php");

if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}

// RBAC: Restrict strictly to doctor role
$user_role = rbac_get_role();
if($user_role !== 'doctor') {
    header("Location: " . rbac_home_url($user_role));
    exit();
}

// Handle Action
// Accepting / rejecting appointment requests is done by vet staff (Appointment Requests), not on the vet's page.
// The old approve/reject action that used to be here is removed: such a request is refused and nothing changes.
if(isset($_POST['action']) && isset($_POST['apt_id']) && in_array($_POST['action'], ['approve', 'reject'], true)) {
    $_SESSION['message'] = "Appointment requests are accepted or declined by vet staff.";
    $_SESSION['msg_type'] = "warning";
    header("Location: doctor.php");
    exit();
}

// Add Leave Action
if (isset($_POST['add_leave'])) {
    $doctor_id = $_SESSION['user_id'];
    $leave_date = $_POST['leave_date'];
    $leave_end_date = !empty($_POST['leave_end_date']) ? $_POST['leave_end_date'] : $leave_date;
    $reason = $_POST['reason'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$leave_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$leave_end_date)
        || $leave_date < date('Y-m-d') || $leave_end_date < $leave_date) {
        $_SESSION['message'] = "Leave dates must be today or later, and the end date can't be before the start date.";
        $_SESSION['msg_type'] = "danger";
        header("Location: doctor.php");
        exit();
    }
    $start = new DateTime($leave_date);
    $end = new DateTime($leave_end_date);
    $end->modify('+1 day'); // Include end date in DatePeriod
    $period = new DatePeriod($start, new DateInterval('P1D'), $end);

    $added = 0;
    foreach ($period as $dt) {
        $curr_date = $dt->format("Y-m-d");

        $chk = $conn->prepare("SELECT id FROM doctor_unavailability WHERE doctor_id=? AND unavailable_date=?");
        $chk->bind_param("is", $doctor_id, $curr_date);
        $chk->execute();
        if ($chk->get_result()->num_rows == 0) {
            $ins = $conn->prepare("INSERT INTO doctor_unavailability(doctor_id, unavailable_date, reason) VALUES(?, ?, ?)");
            $ins->bind_param("iss", $doctor_id, $curr_date, $reason);
            if ($ins->execute()) {
                $added++;
            }
        }
    }

    if ($added > 0) {
        if ($leave_date == $leave_end_date) {
            $_SESSION['message'] = "Unavailability for $leave_date added successfully.";
        } else {
            $_SESSION['message'] = "Unavailability from $leave_date to $leave_end_date added successfully.";
        }
        $_SESSION['msg_type'] = "success";
        // Appointments already booked with this vet on those days need a new vet or a new date
        $ld1 = $conn->real_escape_string($leave_date); $ld2 = $conn->real_escape_string($leave_end_date);
        $affected = (int)($conn->query("SELECT COUNT(*) c FROM appointments WHERE doctor_id = $doctor_id AND appointment_date BETWEEN '$ld1' AND '$ld2'
                                        AND status IN ('Pending','Approved','Checked In','In Progress','Reschedule Requested','Reschedule Proposed')")->fetch_assoc()['c'] ?? 0);
        if ($affected > 0) {
            include_once("includes/client_notify.php");
            $dn = $_SESSION['user'] ?? 'A veterinarian';
            notify_staff($conn, "$dn is on leave " . ($leave_date == $leave_end_date ? "on " . date('M d, Y', strtotime($leave_date)) : "from " . date('M d', strtotime($leave_date)) . " to " . date('M d, Y', strtotime($leave_end_date)))
                . " but has $affected appointment" . ($affected === 1 ? '' : 's') . " booked then. Please assign another vet or reschedule.", 'staff_dashboard.php?tab=appointments&filter=all');
            $_SESSION['message'] .= " Note: you have $affected appointment" . ($affected === 1 ? '' : 's') . " booked on those days. The front desk has been asked to reassign or reschedule them.";
            $_SESSION['msg_type'] = "warning";
        }
    } else {
        $_SESSION['message'] = "You already have a leave scheduled for the selected dates.";
        $_SESSION['msg_type'] = "warning";
    }
    
    header("Location: doctor.php");
    exit();
}

// Save Weekly Schedule Action
if (isset($_POST['save_weekly_schedule'])) {
    dws_ensure_table($conn);
    $doctor_id = (int)$_SESSION['user_id'];
    $days      = $_POST['day'] ?? [];
    $is_time   = function($t) { return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); };
    $errors    = [];
    $rows      = [];

    foreach ([1, 2, 3, 4, 5, 6, 0] as $d) { // every day, Sunday included (clinic open 24/7)
        $in      = $days[$d] ?? [];
        $working = !empty($in['working']) ? 1 : 0;
        $ws = $in['start'] ?? '08:00';
        $we = $in['end'] ?? '17:00';
        $bs = trim($in['break_start'] ?? '');
        $be = trim($in['break_end'] ?? '');

        if ($working) {
            if (!$is_time($ws) || !$is_time($we) || $ws >= $we) {
                $errors[] = DWS_DAY_NAMES[$d] . ': end time must be after start time.';
                continue;
            }
            if ($bs !== '' || $be !== '') {
                if (!$is_time($bs) || !$is_time($be) || $bs >= $be || $bs < $ws || $be > $we) {
                    $errors[] = DWS_DAY_NAMES[$d] . ': break must be inside working hours.';
                    continue;
                }
            }
        }
        if (!$is_time($ws)) $ws = '08:00';
        if (!$is_time($we)) $we = '17:00';
        $rows[$d] = [$working, $ws . ':00', $we . ':00', $is_time($bs) ? $bs . ':00' : null, $is_time($be) ? $be . ':00' : null];
    }

    if ($errors) {
        $_SESSION['message']  = 'Schedule not saved. ' . implode(' ', $errors);
        $_SESSION['msg_type'] = 'danger';
    } else {
        $up = $conn->prepare("
            INSERT INTO doctor_weekly_schedule (doctor_id, day_of_week, is_working, work_start, work_end, break_start, break_end)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_working=VALUES(is_working), work_start=VALUES(work_start), work_end=VALUES(work_end),
                                    break_start=VALUES(break_start), break_end=VALUES(break_end)
        ");
        foreach ($rows as $d => $r) {
            $up->bind_param("iiissss", $doctor_id, $d, $r[0], $r[1], $r[2], $r[3], $r[4]);
            $up->execute();
        }

        // Warn about upcoming appointments that now fall outside the schedule
        $conflicts = 0;
        $week = dws_get_week($conn, $doctor_id);
        $cq = $conn->query("SELECT appointment_date, appointment_time FROM appointments
                            WHERE doctor_id = $doctor_id AND appointment_date >= CURDATE()
                              AND status IN ('Pending','Approved')");
        if ($cq) while ($c = $cq->fetch_assoc()) {
            $ds = $week[(int)date('w', strtotime($c['appointment_date']))];
            $t  = substr($c['appointment_time'], 0, 5);
            if (!$ds['is_working'] || $t < $ds['work_start'] || $t >= $ds['work_end']) $conflicts++;
        }

        $_SESSION['message']  = 'Weekly schedule saved. Clients will only see slots within your new hours.';
        if ($conflicts > 0) {
            include_once("includes/client_notify.php");
            notify_staff($conn, ($_SESSION['user'] ?? 'A veterinarian') . " changed their weekly hours; $conflicts upcoming appointment" . ($conflicts === 1 ? ' is' : 's are') . " now outside them. Please reschedule or assign another vet.", 'staff_dashboard.php?tab=appointments&filter=all');
        }
        $_SESSION['msg_type'] = 'success';
        if ($conflicts > 0) {
            $_SESSION['message'] .= " Note: $conflicts upcoming appointment(s) fall outside your new schedule — please review or reschedule them.";
            $_SESSION['msg_type'] = 'warning';
        }
    }
    header("Location: doctor.php");
    exit();
}

// Delete Leave Action
if (isset($_POST['delete_leave'])) {
    $leave_id = $_POST['leave_id'];
    $doctor_id = $_SESSION['user_id'];
    $del = $conn->prepare("DELETE FROM doctor_unavailability WHERE id=? AND doctor_id=?");
    $del->bind_param("ii", $leave_id, $doctor_id);
    if ($del->execute()) {
        $_SESSION['message'] = "Leave cancelled successfully.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: doctor.php");
    exit();
}


$curr_doc_id = (int)$_SESSION['user_id'];
$pending_query = "SELECT a.*, u.fullname as client_name FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.status='Pending' AND a.doctor_id = $curr_doc_id AND a.service_type != 'Pet Boarding Reservation' ORDER BY a.appointment_date ASC, a.appointment_time ASC";
$pending_apts = $conn->query($pending_query);

$all_approved = $conn->query("SELECT a.*, u.fullname as client_name FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.status IN ('Approved','Checked In','In Progress','Completed') AND a.doctor_id = $curr_doc_id AND a.service_type != 'Pet Boarding Reservation'");

$leaves_q = $conn->query("SELECT * FROM doctor_unavailability WHERE doctor_id=" . $curr_doc_id);

$my_week = dws_get_week($conn, $curr_doc_id);
$fmt_t = function($t) { return $t ? date("g:i A", strtotime($t)) : ''; };

// ── Workstation header & "today at a glance" (read-only) ──
$vw_name  = trim(preg_replace('/\s*\((doctor|vet|veterinarian)\)\s*$/i', '', $_SESSION['user'] ?? 'Doctor'));
$vw_hour  = (int)date('G');
$vw_greet = $vw_hour < 12 ? 'Good morning' : ($vw_hour < 18 ? 'Good afternoon' : 'Good evening');
$vw_active = "'Approved','Checked In','In Progress','Completed'";
$vw_today_rows = [];
$vq = $conn->query("SELECT a.id, a.pet_name, a.service_type, a.appointment_time, a.status, u.fullname client FROM appointments a JOIN users u ON u.id = a.user_id
                    WHERE a.doctor_id = $curr_doc_id AND a.appointment_date = CURDATE() AND a.status IN ($vw_active) ORDER BY a.appointment_time");
if ($vq) while ($r = $vq->fetch_assoc()) $vw_today_rows[] = $r;
$vw_today_left = count(array_filter($vw_today_rows, fn($r) => $r['status'] !== 'Completed'));
$vw_week  = (int)($conn->query("SELECT COUNT(*) c FROM appointments WHERE doctor_id = $curr_doc_id AND status IN ('Approved','Checked In','In Progress')
                                 AND appointment_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 6 DAY")->fetch_assoc()['c'] ?? 0);
$vw_notes = (int)($conn->query("SELECT COUNT(*) c FROM appointments a WHERE a.doctor_id = $curr_doc_id AND a.status IN ('Approved','Checked In','In Progress') AND a.service_type <> 'Pet Boarding Reservation'
                                 AND a.appointment_date <= CURDATE() AND NOT EXISTS (SELECT 1 FROM consultations c WHERE c.appointment_id = a.id)")->fetch_assoc()['c'] ?? 0);
$vw_next  = $conn->query("SELECT a.pet_name, a.service_type, a.appointment_date, a.appointment_time, a.status, u.fullname client FROM appointments a JOIN users u ON u.id = a.user_id
                          WHERE a.doctor_id = $curr_doc_id AND a.status IN ('Approved','Checked In','In Progress')
                            AND TIMESTAMP(a.appointment_date, a.appointment_time) >= NOW() - INTERVAL 30 MINUTE
                          ORDER BY a.appointment_date, a.appointment_time LIMIT 1")->fetch_assoc();
$vw_dow = (int)date('w');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Doctor Dashboard | PragmaVet</title>
<meta charset="UTF-8">
<script src="theme.js?v=<?=time()?>"></script>
<link rel="stylesheet" href="theme.css?v=<?=time()?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" type="text/css" href="https://npmcdn.com/flatpickr/dist/themes/material_blue.css">

<style>
:root { --primary: #2563eb; --secondary: #475569; --dark: #0f172a; --light-bg: #f8fafc; --sidebar-width:260px; }
body { background: #f8fafc !important; font-family: 'Inter', sans-serif; color: #334155; }

/* CONTENT AREA */
.main-content {
    margin-left: 260px !important;
    width: calc(100% - 260px) !important;
    max-width: calc(100% - 260px) !important;
    padding: 24px 28px !important;
    min-height: 100vh;
    box-sizing: border-box !important;
}

@media (max-width: 1200px) {
    .main-content { padding: 20px 20px !important; }
}

@media (max-width: 768px) {
    .main-content { margin-left: 0 !important; width: 100% !important; max-width: 100% !important; padding: 16px !important; }
    .sidebar { position: relative !important; width: 100% !important; height: auto !important; }
}

.card-premium { background: white; border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); padding: 25px; margin-bottom: 25px; }
.table-custom thead th { background: #f8fafc; color: #64748b; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; }

/* ── Doctor Workstation refresh ── */
.vw-eyebrow { font-size:12px; font-weight:700; color:#2563eb; text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; }
.vw-title { font-size:28px; font-weight:800; color:#0f172a; margin:0; letter-spacing:-.5px; }
.vw-sub { color:#64748b; margin:2px 0 0; font-size:14px; }
.vw-btn { display:inline-flex; align-items:center; gap:8px; border-radius:999px; padding:10px 18px; font-weight:700; font-size:13.5px; border:1.5px solid transparent; background-image:none !important; box-shadow:none !important; transition:all .15s; white-space:nowrap; }
.vw-btn-ghost { background:#fff !important; border-color:#dbeafe; color:#1d4ed8 !important; }
.vw-btn-ghost:hover { background:#eff6ff !important; }
.vw-btn-solid { background:#2563eb !important; color:#fff !important; box-shadow:0 6px 16px rgba(37,99,235,.25) !important; }
.vw-btn-solid:hover { background:#1d4ed8 !important; transform:translateY(-1px); }
.vw-icon-btn { width:42px; height:42px; display:inline-flex !important; align-items:center; justify-content:center; border-color:#e2e8f0 !important; background:#fff !important; }
.vw-glance { display:grid; grid-template-columns:minmax(280px, 2fr) repeat(3, minmax(150px, 1fr)); gap:14px; margin-bottom:18px; }
.vw-next { display:flex; align-items:center; gap:14px; padding:16px 18px; border-radius:18px; text-decoration:none; color:#fff; background:linear-gradient(135deg,#1d4ed8,#2563eb 55%,#3b82f6); box-shadow:0 10px 24px rgba(37,99,235,.22); transition:transform .15s; }
.vw-next:hover { transform:translateY(-2px); color:#fff; }
.vw-next-ic { width:46px; height:46px; border-radius:14px; background:rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
.vw-next .vw-k { color:rgba(255,255,255,.75); }
.vw-next-main { font-weight:800; font-size:16.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.vw-next-main span { font-weight:600; opacity:.85; font-size:14px; }
.vw-next-sub { font-size:12.5px; color:rgba(255,255,255,.85); margin-top:1px; }
.vw-next-go { opacity:.7; }
.vw-stat { display:flex; align-items:center; gap:12px; background:#fff; border:1px solid #eef2f6; border-radius:18px; padding:14px 16px; text-decoration:none; color:inherit; box-shadow:0 1px 3px rgba(15,23,42,.04); }
.vw-stat-link:hover { border-color:#fed7aa; color:inherit; }
.vw-stat-ic { width:42px; height:42px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.vw-k { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; }
.vw-v { font-size:22px; font-weight:800; color:#0f172a; line-height:1.15; }
.vw-v small { font-size:12px; font-weight:600; color:#94a3b8; }
.vw-pill { display:inline-block; font-size:10.5px; font-weight:800; padding:1px 8px; border-radius:999px; margin-left:4px; }
.vw-pill-green { background:rgba(255,255,255,.95); color:#047857; }
.min-w-0 { min-width:0; }
/* calendar side panel */
.vw-w { margin-bottom:22px; }
.vw-w-h { display:flex; justify-content:space-between; align-items:center; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin-bottom:10px; }
.vw-w-h a { text-transform:none; letter-spacing:0; font-size:11.5px; font-weight:700; color:#2563eb; text-decoration:none; }
.vw-count { background:#eff6ff; color:#2563eb; border-radius:999px; padding:1px 8px; font-size:11px; letter-spacing:0; }
.vw-appt { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:12px; margin-bottom:6px; background:#f8fafc; border:1px solid #f1f5f9; }
.vw-appt-t { min-width:44px; text-align:center; font-weight:800; color:#0f172a; font-size:13px; line-height:1.1; }
.vw-appt-t small { display:block; font-size:9.5px; color:#94a3b8; font-weight:700; }
.vw-appt-pet { font-weight:700; color:#0f172a; font-size:12.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.vw-appt-meta { font-size:11px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.vw-dot { width:8px; height:8px; border-radius:50%; margin-left:auto; flex-shrink:0; }
.vw-appt-up .vw-dot { background:#3b82f6; } .vw-appt-in .vw-dot { background:#f59e0b; } .vw-appt-done .vw-dot { background:#10b981; }
.vw-appt-in { background:#fffbeb; border-color:#fde68a; } .vw-appt-done { opacity:.7; }
.vw-empty { font-size:12px; color:#94a3b8; padding:10px 12px; background:#f8fafc; border-radius:12px; }
.vw-empty i { margin-right:6px; }
.vw-note { font-size:11.5px; color:#b45309; background:#fffbeb; border-radius:10px; padding:7px 10px; margin-top:6px; }
.vw-day { display:flex; justify-content:space-between; align-items:center; padding:6px 10px; border-radius:10px; font-size:12px; }
.vw-day:nth-child(odd) { background:#fafbfd; }
.vw-day-n { font-weight:700; color:#0f172a; }
.vw-day-n em { font-style:normal; font-size:9.5px; font-weight:800; color:#2563eb; background:#dbeafe; border-radius:999px; padding:1px 6px; margin-left:4px; }
.vw-day-h { color:#475569; font-weight:500; }
.vw-day-today { background:#eff6ff !important; box-shadow:inset 3px 0 0 #2563eb; }
.vw-off { font-size:10.5px; font-weight:700; color:#94a3b8; background:#f1f5f9; border-radius:999px; padding:2px 9px; }
.vw-leave { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid #fee2e2; background:#fff; border-radius:12px; margin-bottom:6px; }
.vw-leave-d { width:40px; text-align:center; background:#fef2f2; border-radius:10px; padding:4px 0; line-height:1.1; }
.vw-leave-d b { display:block; color:#dc2626; font-size:15px; } .vw-leave-d small { color:#ef4444; font-size:9.5px; font-weight:700; text-transform:uppercase; }
.vw-leave-r { font-weight:700; font-size:12px; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.vw-x { border:none; background:#f8fafc !important; background-image:none !important; color:#94a3b8 !important; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; padding:0; box-shadow:none !important; }
.vw-x:hover { background:#fee2e2 !important; color:#dc2626 !important; }
@media (max-width: 1200px) { .vw-glance { grid-template-columns:1fr 1fr; } }
@media (max-width: 576px) { .vw-glance { grid-template-columns:1fr; } .vw-title { font-size:22px; } }

/* FullCalendar Customizations */
.fc-toolbar-title { font-weight: 700 !important; color: #0f172a !important; font-size: 1.5rem !important; }
.fc-button-primary { background-color: #2563eb !important; border-color: #2563eb !important; }
.fc-event { border-radius: 6px; border: none; padding: 4px 8px; font-weight: 600; font-size: 12px; cursor: pointer; }
.fc-day-today { background: rgba(37, 99, 235, 0.05) !important; }

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
</style>
    <!-- React & ReactDOM -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <!-- Babel for JSX -->
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <!-- Tailwind CSS (protect Bootstrap) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            corePlugins: { preflight: false },
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<?php if(isset($_SESSION['message'])): ?>
<div class="position-fixed top-0 end-0 p-4" style="z-index: 9999; animation: slideIn 0.5s ease-out;" id="toastAlert">
    <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> shadow border-0" style="border-radius: 10px;">
        <i class="fa fa-info-circle me-2"></i> <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['msg_type']); ?>
    </div>
</div>
<style>
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
</style>
<script>
    setTimeout(function() {
        let el = document.getElementById('toastAlert');
        if(el) {
            el.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-20px)';
            setTimeout(() => el.remove(), 500);
        }
    }, 3000);
</script>
<?php endif; ?>

<div class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon"><i class="fa fa-user-md"></i></div>
        <span>PragmaVet</span>
    </div>
    <div class="sidebar-user">
        <div class="welcome">Welcome Doctor,</div>
        <div class="uname"><?php echo htmlspecialchars($_SESSION['user'] ?? $_SESSION['fullname'] ?? 'Veterinarian'); ?></div>
    </div>
    <a href="doctor.php" class="active"><i class="fa fa-calendar-check"></i> <span>Schedule & Availability</span></a>
    <a href="doctor_consultations.php" class=""><i class="fa fa-stethoscope"></i> <span>Active Consultations</span></a>
    <a href="doctor_patients.php" class=""><i class="fa fa-paw"></i> <span>Patient EMR Lookup</span></a>
    <a href="doctor_inventory.php" class=""><i class="fa fa-boxes"></i> <span>Pharmacy Stock</span></a>
    <?php $pv_cert_pending = (int)($conn->query("SELECT COUNT(*) c FROM certificate_requests WHERE status = 'Pending'")->fetch_assoc()['c'] ?? 0); ?>
    <a href="admin_certificates.php" class=""><i class="fa fa-file-contract"></i> <span>Cert Requests</span><?php if ($pv_cert_pending): ?><span style="margin-left:auto;background:#ef4444;color:#fff;font-size:11px;font-weight:800;min-width:22px;height:22px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;padding:0 7px;"><?= $pv_cert_pending ?></span><?php endif; ?></a>
    <div class="sidebar-logout">
        <a href="logout.php"><i class="fa fa-sign-out-alt"></i> <span>Logout</span></a>
    </div>
</div>

<?php include("includes/staff_bell.php"); ?>
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
        <div>
            <div class="vw-eyebrow"><i class="fa fa-stethoscope me-1"></i> Doctor Workstation · <?= date('l, F j, Y') ?></div>
            <h2 class="vw-title"><?= $vw_greet ?>, <?= htmlspecialchars($vw_name) ?></h2>
            <p class="vw-sub">Your schedule, availability and leave days in one place.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <button class="vw-btn vw-btn-ghost" data-bs-toggle="modal" data-bs-target="#weeklyScheduleModal">
                <i class="fa fa-business-time"></i> Weekly Schedule
            </button>
            <button class="vw-btn vw-btn-solid" data-bs-toggle="modal" data-bs-target="#addLeaveModal">
                <i class="fa fa-calendar-xmark"></i> Set Unavailable Date
            </button>
        </div>
    </div>

    <!-- Today at a glance -->
    <div class="vw-glance">
        <a href="doctor_consultations.php" class="vw-next">
            <div class="vw-next-ic"><i class="fa fa-user-clock"></i></div>
            <div class="flex-grow-1 min-w-0">
                <div class="vw-k">Next patient</div>
                <?php if ($vw_next): ?>
                    <div class="vw-next-main"><?= htmlspecialchars($vw_next['pet_name']) ?> <span>· <?= htmlspecialchars($vw_next['service_type']) ?></span></div>
                    <div class="vw-next-sub"><?= htmlspecialchars($vw_next['client']) ?> · <?= $vw_next['appointment_date'] === date('Y-m-d') ? 'Today' : date('D, M j', strtotime($vw_next['appointment_date'])) ?> at <?= date('g:i A', strtotime($vw_next['appointment_time'])) ?>
                        <?php if ($vw_next['status'] !== 'Approved'): ?><span class="vw-pill vw-pill-green"><?= htmlspecialchars($vw_next['status']) ?></span><?php endif; ?></div>
                <?php else: ?>
                    <div class="vw-next-main">No upcoming patients</div>
                    <div class="vw-next-sub">New appointments assigned to you will appear here.</div>
                <?php endif; ?>
            </div>
            <i class="fa fa-chevron-right vw-next-go"></i>
        </a>
        <div class="vw-stat"><div class="vw-stat-ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-calendar-day"></i></div><div><div class="vw-k">Today</div><div class="vw-v"><?= count($vw_today_rows) ?> <small><?= $vw_today_left ?> left</small></div></div></div>
        <div class="vw-stat"><div class="vw-stat-ic" style="background:#f5f3ff;color:#7c3aed;"><i class="fa fa-calendar-week"></i></div><div><div class="vw-k">Next 7 days</div><div class="vw-v"><?= $vw_week ?> <small>booked</small></div></div></div>
        <a href="doctor_consultations.php" class="vw-stat vw-stat-link"><div class="vw-stat-ic" style="background:#fff7ed;color:#ea580c;"><i class="fa fa-notes-medical"></i></div><div><div class="vw-k">Awaiting notes</div><div class="vw-v"><?= $vw_notes ?> <small>consultation<?= $vw_notes === 1 ? '' : 's' ?></small></div></div></a>
    </div>

    
    <div class="row layout-saas">
        <!-- MAIN CALENDAR UI -->
        <div class="col-xl-12">
            <div class="card-premium p-0" style="background: transparent; box-shadow: none; border: none; height: calc(100vh - 230px); min-height: 640px;">
                <?php
                $calendar_events = [];
                if(isset($all_approved)) {
                    $all_approved->data_seek(0);
                    while($r = $all_approved->fetch_assoc()){
                        $service = $r['service_type'] ?? 'General Checkup';
                        $color = 'blue';
                        if(stripos($service, 'vaccin') !== false) { $color = 'green'; }
                        elseif(stripos($service, 'surg') !== false) { $color = 'orange'; }
                        if($r['status'] == 'Completed') { $color = 'green'; }
                        elseif($r['status'] == 'Pending') { $color = 'orange'; }
                        $calendar_events[] = [
                            'type'     => $service,
                            'color'    => $color,
                            'status'   => $r['status'],
                            'time'     => date("h:i A", strtotime($r['appointment_time'])),
                            'raw_time' => $r['appointment_time'],
                            'title'    => $r['client_name'],
                            'pet'      => $r['pet_name'],
                            'ownerId'  => $r['user_id'],
                            'owner'    => $r['client_name'],
                            'doctorId' => $r['doctor_id'],
                            'doctor'   => 'Me',
                            'raw_date' => $r['appointment_date'],
                            'appt_id'  => $r['id'],
                            'location' => $r['service_location'] ?? '',
                            'notes'    => $r['notes'] ?? '',
                            'created_at'=> $r['created_at'] ?? ''
                        ];
                    }
                }
                if(isset($leaves_q)) {
                    $leaves_q->data_seek(0);
                    while($l = $leaves_q->fetch_assoc()){
                        $calendar_events[] = [
                            'type'     => 'Leave',
                            'color'    => 'red',
                            'status'   => 'Leave',
                            'time'     => 'All Day',
                            'title'    => 'On Leave',
                            'pet'      => 'N/A',
                            'ownerId'  => null,
                            'owner'    => 'N/A',
                            'doctorId' => $l['doctor_id'],
                            'doctor'   => 'Me',
                            'raw_date' => $l['unavailable_date'],
                            'appt_id'  => null
                        ];
                    }
                }

                // Build sidebar widgets HTML
                ob_start();
                ?>
                <!-- Today's patients -->
                <div class="vw-w">
                    <div class="vw-w-h"><span>Today's patients</span><span class="vw-count"><?= count($vw_today_rows) ?></span></div>
                    <?php if ($vw_today_rows): foreach ($vw_today_rows as $tr):
                        $tone = $tr['status'] === 'Completed' ? 'done' : (in_array($tr['status'], ['Checked In', 'In Progress'], true) ? 'in' : 'up'); ?>
                        <div class="vw-appt vw-appt-<?= $tone ?>">
                            <div class="vw-appt-t"><?= date('g:i', strtotime($tr['appointment_time'])) ?><small><?= date('A', strtotime($tr['appointment_time'])) ?></small></div>
                            <div class="min-w-0">
                                <div class="vw-appt-pet"><?= htmlspecialchars($tr['pet_name']) ?></div>
                                <div class="vw-appt-meta"><?= htmlspecialchars($tr['service_type']) ?> · <?= htmlspecialchars($tr['client']) ?></div>
                            </div>
                            <span class="vw-dot" title="<?= htmlspecialchars($tr['status']) ?>"></span>
                        </div>
                    <?php endforeach; else: ?>
                        <div class="vw-empty"><i class="fa fa-mug-hot"></i> No patients scheduled today.</div>
                    <?php endif; ?>
                    <?php if (isset($pending_apts) && $pending_apts->num_rows > 0): ?>
                        <div class="vw-note"><i class="fa fa-hourglass-half"></i> <?= $pending_apts->num_rows ?> request<?= $pending_apts->num_rows === 1 ? '' : 's' ?> for you waiting for staff approval</div>
                    <?php endif; ?>
                </div>

                <!-- My Weekly Schedule -->
                <div class="vw-w">
                    <div class="vw-w-h"><span>My weekly schedule</span>
                        <a href="#" data-bs-toggle="modal" data-bs-target="#weeklyScheduleModal"><i class="fa fa-pen me-1"></i>Edit</a></div>
                    <?php foreach ([1,2,3,4,5,6,0] as $d): $ds = $my_week[$d]; ?>
                        <div class="vw-day<?= $d === $vw_dow ? ' vw-day-today' : '' ?>">
                            <span class="vw-day-n"><?= substr(DWS_DAY_NAMES[$d], 0, 3) ?><?= $d === $vw_dow ? ' <em>Today</em>' : '' ?></span>
                            <?php if ($ds['is_working']): ?>
                                <span class="vw-day-h"><?= $fmt_t($ds['work_start']) ?> – <?= $fmt_t($ds['work_end']) ?></span>
                            <?php else: ?>
                                <span class="vw-off">Day off</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- My Leaves -->
                <div class="vw-w mb-0">
                    <div class="vw-w-h"><span>My leaves</span>
                        <a href="#" data-bs-toggle="modal" data-bs-target="#addLeaveModal"><i class="fa fa-plus me-1"></i>Add</a></div>
                    <?php
                    $now_date = date('Y-m-d');
                    $disp_leaves = $conn->query("SELECT * FROM doctor_unavailability WHERE doctor_id=" . (int)$_SESSION['user_id'] . " AND unavailable_date >= '$now_date' ORDER BY unavailable_date ASC");
                    if($disp_leaves && $disp_leaves->num_rows > 0):
                        while($ul = $disp_leaves->fetch_assoc()): ?>
                        <div class="vw-leave">
                            <div class="vw-leave-d"><b><?php echo date("d", strtotime($ul['unavailable_date'])); ?></b><small><?php echo date("M", strtotime($ul['unavailable_date'])); ?></small></div>
                            <div class="flex-grow-1 min-w-0">
                                <div class="vw-leave-r"><?php echo htmlspecialchars($ul['reason'] ?: 'Leave'); ?></div>
                                <div class="vw-appt-meta"><?php echo date("l", strtotime($ul['unavailable_date'])); ?></div>
                            </div>
                            <form method="POST" class="m-0">
                                <input type="hidden" name="leave_id" value="<?php echo $ul['id']; ?>">
                                <button type="submit" name="delete_leave" class="vw-x" title="Cancel this leave" onclick="return confirm('Cancel this leave?');"><i class="fa fa-xmark"></i></button>
                            </form>
                        </div>
                    <?php endwhile; else: ?>
                        <div class="vw-empty"><i class="fa fa-umbrella-beach"></i> No scheduled leaves.</div>
                    <?php endif; ?>
                </div>
                <?php
                $sidebarHTML = ob_get_clean();
                ?>
                <script>
                // Shade days the doctor does not work, based on their weekly schedule
                window.MY_WEEKLY_SCHEDULE = <?= json_encode($my_week) ?>;
                window.SAAS_CAL_DAY_DECORATOR = function(cellDiv, cell) {
                    var dow = new Date(cell.dateStr + 'T12:00:00').getDay();
                    var ds = window.MY_WEEKLY_SCHEDULE[dow];
                    if (ds && !ds.is_working) {
                        cellDiv.style.background = 'repeating-linear-gradient(135deg,#f8fafc,#f8fafc 6px,#f1f5f9 6px,#f1f5f9 12px)';
                        var tag = document.createElement('div');
                        tag.textContent = 'Day off';
                        tag.style.cssText = 'font-size:9.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;';
                        cellDiv.appendChild(tag);
                    }
                };
                </script>
                <?php
                echo render_saas_calendar($calendar_events, 'doctor', $_SESSION['user_id'], $sidebarHTML);
                ?>
            </div>
        </div>
    </div>
</div>


<!-- Weekly Schedule Modal -->
<div class="modal fade" id="weeklyScheduleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 pb-0 pt-4 px-4 bg-light">
                <div>
                    <h5 class="fw-bold text-dark m-0"><i class="fa fa-business-time text-primary me-2"></i> My Weekly Schedule</h5>
                    <p class="text-muted small m-0 mt-1">Set the days and hours you accept appointments. Clients can only book within these hours.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="weeklyScheduleForm">
                <div class="modal-body p-4 bg-light">
                    <div class="table-responsive bg-white rounded-3 border">
                        <table class="table align-middle mb-0" style="font-size:13px;">
                            <thead>
                                <tr class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;">
                                    <th class="ps-3">Day</th>
                                    <th>Working</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Break Start</th>
                                    <th>Break End</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): $ds = $my_week[$d]; ?>
                                <tr class="ws-row" data-day="<?= $d ?>">
                                    <td class="ps-3 fw-bold text-dark"><?= DWS_DAY_NAMES[$d] ?></td>
                                    <td>
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input ws-toggle" type="checkbox" role="switch" name="day[<?= $d ?>][working]" value="1" <?= $ds['is_working'] ? 'checked' : '' ?>>
                                        </div>
                                    </td>
                                    <td><input type="time" class="form-control form-control-sm ws-time" name="day[<?= $d ?>][start]" value="<?= htmlspecialchars($ds['work_start']) ?>" step="1800"></td>
                                    <td><input type="time" class="form-control form-control-sm ws-time" name="day[<?= $d ?>][end]" value="<?= htmlspecialchars($ds['work_end']) ?>" step="1800"></td>
                                    <td><input type="time" class="form-control form-control-sm ws-time" name="day[<?= $d ?>][break_start]" value="<?= htmlspecialchars($ds['break_start'] ?? '') ?>" step="1800"></td>
                                    <td><input type="time" class="form-control form-control-sm ws-time" name="day[<?= $d ?>][break_end]" value="<?= htmlspecialchars($ds['break_end'] ?? '') ?>" step="1800"></td>
                                </tr>
                            <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div class="text-muted" style="font-size:12px;"><i class="fa fa-circle-info me-1"></i> Leave the break empty if you have none. For one-off absences use <strong>Set Unavailable Date</strong>.</div>
                        <button type="button" class="btn btn-link btn-sm text-decoration-none fw-semibold p-0" onclick="wsCopyMonday()"><i class="fa fa-copy me-1"></i>Copy Monday to all</button>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-white" style="border-radius: 0 0 15px 15px;">
                    <button type="submit" name="save_weekly_schedule" class="btn btn-primary fw-bold shadow-sm px-4 w-100 py-3">
                        <i class="fa fa-save me-2"></i> Save Weekly Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Leave Modal -->
<div class="modal fade" id="addLeaveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 pb-0 pt-4 px-4 bg-light">
                <h5 class="fw-bold text-dark m-0"><i class="fa fa-calendar-times text-danger me-2"></i> Add Unavailability</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4 bg-light">
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label text-muted small fw-bold">From Date</label>
                            <input type="text" name="leave_date" class="form-control rounded-3 flatpickr-date bg-white" placeholder="Select Date" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-muted small fw-bold">To Date (Optional)</label>
                            <input type="text" name="leave_end_date" class="form-control rounded-3 flatpickr-date bg-white" placeholder="Select Date">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Reason</label>
                        <select name="reason" class="form-select rounded-3" required>
                            <option value="" disabled selected>Select a Reason</option>
                            <option value="Sick Leave">Sick Leave</option>
                            <option value="Vacation Leave">Vacation Leave</option>
                            <option value="Personal Appointment">Personal Appointment</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-white d-flex align-items-center" style="border-radius: 0 0 15px 15px;">
                    <button type="submit" name="add_leave" class="btn btn-danger fw-bold shadow-sm px-4 btn-sm w-100 py-3">
                        Submit Unavailability
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    flatpickr(".flatpickr-date", {
        dateFormat: "Y-m-d",
        minDate: "today"
    });

    // Weekly schedule: grey out time inputs on days off
    document.querySelectorAll('.ws-toggle').forEach(function(t) {
        t.addEventListener('change', function() { wsSyncRow(t.closest('.ws-row')); });
        wsSyncRow(t.closest('.ws-row'));
    });
});

function wsSyncRow(row) {
    var on = row.querySelector('.ws-toggle').checked;
    row.querySelectorAll('.ws-time').forEach(function(i) { i.readOnly = !on; i.style.opacity = on ? 1 : .4; });
}

function wsCopyMonday() {
    var rows = document.querySelectorAll('.ws-row');
    var src = rows[0].querySelectorAll('.ws-time');
    var srcOn = rows[0].querySelector('.ws-toggle').checked;
    rows.forEach(function(row, i) {
        if (i === 0) return;
        row.querySelector('.ws-toggle').checked = srcOn;
        row.querySelectorAll('.ws-time').forEach(function(inp, j) { inp.value = src[j].value; });
        wsSyncRow(row);
    });
}
</script>


    <script src="mousetrail.js"></script>
</body>
</html>
