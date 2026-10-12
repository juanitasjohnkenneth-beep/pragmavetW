<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("dbconnection.php");
include("paymongo_config.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
include_once("includes/rbac_guard.php");
include_once("includes/service_prices.php");
$boarding_rate = sp_boarding_rate($conn);
// RBAC: Client Portal access control
$user_role = rbac_get_role();
if (!in_array($user_role, ['user', 'client'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}


$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user'] ?? 'Valued Client';
include_once("includes/staff_notify.php");
include_once("includes/client_notify.php");

// Fetch user's pets for the booking modal dropdown
$stmt_pets = $conn->prepare("SELECT name, species FROM pets WHERE user_id=? ORDER BY name ASC");
$stmt_pets->bind_param("i", $user_id);
$stmt_pets->execute();
$pets_result = $stmt_pets->get_result();

// ── PayMongo Success Callback ──
if (isset($_GET['pm_success'], $_GET['res_id'])) {
    $res_id   = (int)$_GET['res_id'];
    $pay_type = $_GET['pm_success']; // 'downpayment', 'full', or 'reservation'
    $total    = (float)($_GET['total'] ?? 0);
    $dp       = (float)($_GET['dp'] ?? 0);
    $now      = date('Y-m-d H:i:s');

    // Ensure billing columns exist
    $needed = [
        'downpayment_amount'  => "DECIMAL(10,2) NOT NULL DEFAULT 0",
        'downpayment_status'  => "VARCHAR(20) NOT NULL DEFAULT 'Unpaid'",
        'downpayment_paid_at' => 'DATETIME DEFAULT NULL',
        'is_boarding'         => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    foreach ($needed as $col => $def) {
        $r = $conn->query("SHOW COLUMNS FROM billing LIKE '$col'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE billing ADD COLUMN `$col` $def");
        }
    }

    // Create or update billing record
    $existing = $conn->query("SELECT id FROM billing WHERE boarding_res_id = $res_id AND is_boarding = 1");
    if ($existing && $existing->num_rows > 0) {
        $bill_id = $existing->fetch_assoc()['id'];
    } else {
        $conn->query("INSERT INTO billing (boarding_res_id, appointment_id, total_amount, downpayment_amount, is_boarding, downpayment_status, payment_status)
                      VALUES ($res_id, NULL, $total, $dp, 1, 'Unpaid', 'Unpaid')");
        $bill_id = $conn->insert_id;
    }

    if ($pay_type === 'reservation') {
        // ₱500 reservation fee only — status stays Pending (staff must still approve)
        $conn->query("UPDATE billing SET downpayment_amount=500.00, downpayment_status='Partial', downpayment_paid_at='$now' WHERE id=$bill_id");
        $conn->query("UPDATE boarding_reservations SET status='Pending' WHERE id=$res_id AND user_id=$user_id");
        $_SESSION['message']  = "Reservation fee of ₱500 confirmed! Your cage is secured — staff will review and approve your stay shortly.";
        $_SESSION['msg_type'] = 'success';
    } elseif ($pay_type === 'downpayment') {
        $conn->query("UPDATE billing SET downpayment_status='Paid', downpayment_amount=$dp, downpayment_paid_at='$now' WHERE id=$bill_id");
        $conn->query("UPDATE boarding_reservations SET status='Approved' WHERE id=$res_id AND user_id=$user_id");
        $_SESSION['message']  = "Downpayment of ₱" . number_format($dp, 2) . " confirmed! Your pet boarding reservation is now officially approved.";
        $_SESSION['msg_type'] = 'success';
    } elseif ($pay_type === 'full') {
        $conn->query("UPDATE billing SET payment_status='Paid', downpayment_status='Paid', downpayment_amount=$dp, downpayment_paid_at='$now' WHERE id=$bill_id");
        $conn->query("UPDATE boarding_reservations SET status='Approved' WHERE id=$res_id AND user_id=$user_id");
        $_SESSION['message']  = "Full payment of ₱" . number_format($total, 2) . " confirmed! Your reservation is fully settled and approved.";
        $_SESSION['msg_type'] = 'success';
    }
    $nb = $conn->query("SELECT pet_name, appointment_date, end_date, cage_number FROM boarding_reservations WHERE id = $res_id AND user_id = $user_id")->fetch_assoc();
    if ($nb) {
        $stay = $nb['pet_name'] . ', ' . date('M d', strtotime($nb['appointment_date'])) . ' to ' . date('M d, Y', strtotime($nb['end_date'])) . ($nb['cage_number'] ? ', Cage ' . str_pad((int)$nb['cage_number'], 2, '0', STR_PAD_LEFT) : '');
        notify_staff($conn, $pay_type === 'reservation'
            ? "New boarding request from $user_name: $stay. Reservation fee paid, waiting for approval."
            : "New boarding booking from $user_name: $stay. " . ($pay_type === 'full' ? 'Paid in full.' : 'Deposit paid.'), staff_link_for('Boarding', $res_id));
    }
    header("Location: boarding.php");
    exit();
}

// ── PayMongo Cancel Callback ──
if (isset($_GET['pm_cancelled'])) {
    $res_id = (int)($_GET['res_id'] ?? 0);
    if ($res_id) {
        $conn->query("DELETE FROM boarding_reservations WHERE id=$res_id AND status='Pending' AND user_id=$user_id");
    }
    $_SESSION['message']  = "Payment was cancelled. Your reservation request was not finalized.";
    $_SESSION['msg_type'] = 'warning';
    header("Location: boarding.php");
    exit();
}

// ── Book Cage: Create reservation then redirect to demo/PayMongo checkout ──
if (isset($_POST['book_cage'])) {
    $pet      = trim($_POST['pet_name']);
    $cage_no  = (int)$_POST['cage_number'];
    $date     = $_POST['appointment_date'];
    $end_date = $_POST['end_date'];
    $time     = $_POST['appointment_time'] ?? '10:00 AM';
    $pay_type = $_POST['payment_type'] ?? 'downpayment';
    $service  = 'Pet Boarding Reservation';

    function js_redirect($url) {
        echo '<!DOCTYPE html><html><head>'
            . '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . '<script>window.location.replace(' . json_encode($url) . ');</script>'
            . '</head><body></body></html>';
        exit();
    }

    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    if ($date < $tomorrow) {
        $_SESSION['message']  = "Boarding check-in must be booked at least 24 hours in advance. Earliest available: $tomorrow";
        $_SESSION['msg_type'] = 'danger';
        js_redirect('boarding.php');
    }

    $days = max(1, round((strtotime($end_date) - strtotime($date)) / 86400));
    if (strtotime($end_date) <= strtotime($date)) {
        $_SESSION['message']  = 'Check-out date must be at least 1 day after check-in.';
        $_SESSION['msg_type'] = 'danger';
        js_redirect('boarding.php');
    }

    // Overlap validation
    $chk = $conn->prepare("SELECT id FROM boarding_reservations WHERE cage_number=? AND status NOT IN ('Completed','Cancelled','Rejected') AND appointment_date < ? AND end_date > ?");
    $chk->bind_param('iss', $cage_no, $end_date, $date);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $_SESSION['message']  = "Cage $cage_no is already booked for the selected date range.";
        $_SESSION['msg_type'] = 'danger';
        js_redirect('boarding.php');
    }

    // Pricing calculation
    $total  = round($days * $boarding_rate, 2);
    $dp     = round($total * 0.30, 2);
    if ($pay_type === 'full') {
        $charge = $total;
        $label  = "PragmaVet Pet Boarding (Full Payment - $days days) - $pet";
    } elseif ($pay_type === 'reservation') {
        $charge = 500.00; // Fixed reservation fee to secure the slot
        $label  = "PragmaVet Pet Boarding Reservation Fee - $pet";
    } else {
        $charge = $dp;
        $label  = "PragmaVet Pet Boarding (30% Deposit - $days days) - $pet";
    }

    $ins = $conn->prepare("INSERT INTO boarding_reservations (user_id, pet_name, service_type, appointment_date, appointment_time, end_date, cage_number, status) VALUES (?,?,?,?,?,?,?,'Pending')");
    $ins->bind_param('isssssi', $user_id, $pet, $service, $date, $time, $end_date, $cage_no);
    if (!$ins->execute()) {
        $_SESSION['message']  = 'Reservation error: ' . $conn->error;
        $_SESSION['msg_type'] = 'danger';
        js_redirect('boarding.php');
    }
    $res_id = $conn->insert_id;

    $protocol    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $domain      = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/pragmavet';
    $success_url = $domain . '/boarding.php?pm_success=' . urlencode($pay_type) . '&res_id=' . $res_id . '&total=' . $total . '&dp=' . $dp;
    $cancel_url  = $domain . '/boarding.php?pm_cancelled=1&res_id=' . $res_id;

    // Create PayMongo checkout session
    $result = paymongo_create_checkout(
        (float)$charge,
        $label,
        $success_url,
        $cancel_url,
        ['res_id' => (string)$res_id, 'pay_type' => $pay_type, 'type' => 'boarding']
    );

    if (isset($result['url'])) {
        js_redirect($result['url']);
    } else {
        // Delete the pending reservation on PayMongo failure so it doesn't block the cage
        $conn->query("DELETE FROM boarding_reservations WHERE id=$res_id AND status='Pending'");
        $err = $result['error'] ?? json_encode($result);
        $_SESSION['message']  = "Payment gateway error. Please try again. ($err)";
        $_SESSION['msg_type'] = 'danger';
        js_redirect('boarding.php');
    }
}

// ── Handle Reschedule Request ──
if (isset($_POST['reschedule_boarding_request'])) {
    $apt_id    = (int)$_POST['apt_id'];
    $new_start = $_POST['new_start_date'];
    $new_end   = $_POST['new_end_date'];
    $today     = date('Y-m-d');

    $curr_q = $conn->prepare("SELECT appointment_date, cage_number, status, pet_name FROM boarding_reservations WHERE id=? AND user_id=?");
    $curr_q->bind_param("ii", $apt_id, $user_id);
    $curr_q->execute();
    $curr = $curr_q->get_result()->fetch_assoc();

    if (!$curr) {
        $_SESSION['message']  = "Reservation not found.";
        $_SESSION['msg_type'] = "danger";
    } elseif (!in_array($curr['status'], ['Pending', 'Approved'], true)) {
        $_SESSION['message']  = "This reservation can no longer be rescheduled.";
        $_SESSION['msg_type'] = "warning";
    } elseif ($curr['appointment_date'] <= $today) {
        $_SESSION['message']  = "Stays starting today or already in progress cannot be rescheduled.";
        $_SESSION['msg_type'] = "warning";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$new_start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$new_end) || $new_start <= $today || $new_end <= $new_start) {
        $_SESSION['message']  = "Please choose a check-in date from tomorrow onwards and a check-out date after it.";
        $_SESSION['msg_type'] = "danger";
    } else {
        $cage_no = $curr['cage_number'];
        $chk = $conn->prepare("SELECT id FROM boarding_reservations WHERE id != ? AND cage_number=? AND status NOT IN ('Completed','Cancelled','Rejected') AND appointment_date < ? AND end_date > ?");
        $chk->bind_param('iiss', $apt_id, $cage_no, $new_end, $new_start);
        $chk->execute();
        
        if ($chk->get_result()->num_rows > 0) {
            $_SESSION['message']  = "The cage is occupied by another guest during those new dates.";
            $_SESSION['msg_type'] = "danger";
        } else {
            // Dates only change once the front desk proposes them and the client confirms (accept_reschedule_boarding)
            notify_clinic_appt($conn, "$user_name asked to move boarding reservation #$apt_id for {$curr['pet_name']} to " . date('M d', strtotime($new_start)) . " - " . date('M d, Y', strtotime($new_end)) . ". Propose the new dates from Pet Boarding Care.", 'Boarding', $apt_id);
            $_SESSION['message']  = "Reschedule request sent. The clinic will confirm the new dates with you.";
            $_SESSION['msg_type'] = "success";
        }
    }
    header("Location: boarding.php");
    exit();
}

// ── Process Cancellation ──
if (isset($_POST['cancel_boarding'])) {
    $apt_id = (int)$_POST['apt_id'];
    $stmt = $conn->prepare("SELECT status, appointment_date, pet_name FROM boarding_reservations WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $apt_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $why = board_cancel_problem($row);
        if (!$why) {
            $conn->query("UPDATE boarding_reservations SET status='Cancelled' WHERE id=$apt_id AND status IN ('Pending','Approved')");
            $_SESSION['message']  = "Boarding reservation successfully cancelled.";
            $_SESSION['msg_type'] = "success";
            notify_clinic_appt($conn, "$user_name cancelled boarding reservation #$apt_id for {$row['pet_name']} (check-in " . date('M d, Y', strtotime($row['appointment_date'])) . "). The cage is free again.", 'Boarding', $apt_id);
        } else {
            $_SESSION['message']  = $why;
            $_SESSION['msg_type'] = "danger";
        }
    }
    header("Location: boarding.php");
    exit();
}

// ── Process Reschedule Proposal Accept / Decline ──
if (isset($_POST['accept_reschedule_boarding'])) {
    $apt_id = (int)$_POST['reschedule_apt_id'];
    $stmt = $conn->prepare("UPDATE boarding_reservations SET appointment_date = proposed_reschedule_date, end_date = proposed_reschedule_end_date, status = 'Approved', client_action_needed = 0 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $apt_id, $user_id);
    if ($stmt->execute()) {
        $_SESSION['message']  = "Reschedule accepted! Your boarding is now approved for the updated dates.";
        $_SESSION['msg_type'] = "success";
        notify_staff($conn, "$user_name accepted the new boarding dates for reservation #$apt_id.", staff_link_for('Boarding', $apt_id));
    }
    header("Location: boarding.php");
    exit();
}

if (isset($_POST['decline_reschedule_boarding'])) {
    $apt_id = (int)$_POST['reschedule_apt_id'];
    $stmt = $conn->prepare("UPDATE boarding_reservations SET status = 'Cancelled', client_action_needed = 0 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $apt_id, $user_id);
    if ($stmt->execute()) {
        $_SESSION['message']  = "Reschedule declined. Boarding has been cancelled.";
        $_SESSION['msg_type'] = "danger";
        notify_staff($conn, "$user_name declined the new boarding dates for reservation #$apt_id, so it was cancelled.", staff_link_for('Boarding', $apt_id));
    }
    header("Location: boarding.php");
    exit();
}

// ── Fetch Cage Count ──
$count_q = $conn->query("SELECT setting_value FROM clinic_settings WHERE setting_key = 'boarding_cage_count'");
$cage_count = ($count_q && $count_q->num_rows > 0) ? (int)$count_q->fetch_assoc()['setting_value'] : 5;

// Prepare base cages
$cages = [];
$flatpickr_disable = [];
for ($i = 1; $i <= $cage_count; $i++) {
    $cages[$i] = [];
    $flatpickr_disable[$i] = [];
}

// Fetch all active Boarding bookings mapped onto cages
$sql = "SELECT id, cage_number, user_id, pet_name, appointment_date, end_date, appointment_time, status, service_type, created_at, admin_message, client_action_needed, cctv_link, cctv_enabled 
        FROM boarding_reservations 
        WHERE cage_number IS NOT NULL 
        AND status NOT IN ('Completed', 'Cancelled', 'Rejected')
        AND end_date >= CURDATE()
        ORDER BY appointment_date ASC";
$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $cNo = (int)$row['cage_number'];
        if (isset($cages[$cNo])) {
            $cages[$cNo][] = $row;
            $flatpickr_disable[$cNo][] = [
                'from' => $row['appointment_date'],
                'to'   => $row['end_date']
            ];
        }
    }
}

// ── Categorize User's Own Boarding Reservations & Strict CCTV Expiration Calculation ──
$user_reservations_q = $conn->prepare("
    SELECT id, pet_name, appointment_date, end_date, cage_number, status, cctv_link, cctv_enabled, created_at, admin_message, client_action_needed, proposed_reschedule_date, proposed_reschedule_end_date
    FROM boarding_reservations 
    WHERE user_id = ? 
    ORDER BY appointment_date DESC, id DESC
");
$user_reservations_q->bind_param("i", $user_id);
$user_reservations_q->execute();
$my_all_res = $user_reservations_q->get_result()->fetch_all(MYSQLI_ASSOC);
$paid_map = [];
if ($my_all_res) {
    $rid_list = implode(',', array_map(fn($x) => (int)$x['id'], $my_all_res));
    $pq = $conn->query("SELECT COALESCE(boarding_res_id, appointment_id) rid, MAX(IF(downpayment_status IN ('Paid','Partial'), downpayment_amount, 0)) paid
                        FROM billing WHERE is_boarding = 1 AND COALESCE(boarding_res_id, appointment_id) IN ($rid_list) GROUP BY rid");
    if ($pq) while ($pr = $pq->fetch_assoc()) $paid_map[(int)$pr['rid']] = (float)$pr['paid'];
}

$active_stays    = [];
$upcoming_stays  = [];
$pending_stays   = [];
$past_stays      = [];

$now_date = date('Y-m-d');
$now_time = time();

foreach ($my_all_res as &$r) {
    $start_time = strtotime($r['appointment_date']);
    $end_time   = strtotime($r['end_date'] . ' 23:59:59');
    $status     = $r['status'];

    // Strict CCTV Security & Expiration Check
    // A checked-in pet is boarding until staff check it out (even if pickup runs past the end date)
    $checked_in    = ($status === 'Checked In');
    $is_active_now = $checked_in || ($status === 'Approved' && $start_time <= $now_time && $end_time >= $now_time);
    $is_expired    = !$checked_in && ($end_time < $now_time || in_array($status, ['Completed', 'Cancelled', 'Rejected']));
    $is_upcoming   = ($status === 'Approved' && $start_time > $now_time);

    $r['is_active_now'] = $is_active_now;
    $r['is_expired']    = $is_expired;
    $r['is_upcoming']   = $is_upcoming;

    // CCTV Authorization State
    if ($is_active_now && !empty($r['cctv_link']) && (int)$r['cctv_enabled'] === 1) {
        $r['cctv_state'] = 'LIVE'; // Can watch live
    } elseif ($is_active_now && !empty($r['cctv_link']) && (int)$r['cctv_enabled'] === 0) {
        $r['cctv_state'] = 'STANDBY'; // Paused by clinic staff
    } elseif ($is_expired) {
        $r['cctv_state'] = 'EXPIRED'; // Access expired once stay concluded
    } elseif ($is_upcoming) {
        $r['cctv_state'] = 'UPCOMING'; // Will unlock upon check-in
    } else {
        $r['cctv_state'] = 'UNAVAILABLE';
    }

    if ($is_active_now) {
        $active_stays[] = $r;
    } elseif (in_array($status, ['Pending', 'Reschedule Proposed'])) {
        $pending_stays[] = $r;
    } elseif ($is_upcoming) {
        $upcoming_stays[] = $r;
    } else {
        $past_stays[] = $r;
    }
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pet Boarding & Live Cam | PragmaVet</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" type="text/css" href="https://npmcdn.com/flatpickr/dist/themes/material_blue.css">

    <style>
        :root {
            --sidebar-width: 260px;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --dark: #182032;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-color: #eef2f6;
            --body-bg: #f4f8fc;
            --radius-md: 16px;
            --radius-lg: 20px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--body-bg);
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, sans-serif;
            color: var(--text-dark);
            min-height: 100vh;
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

        /* ====== MAIN CONTENT ====== */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 36px 40px 60px;
            min-height: 100vh;
        }

        .boarding-container {
            max-width: 1280px;
            margin: 0 auto;
        }

        /* Active Stay Hero Card */
        .active-stay-card {
            background: #ffffff;
            border: 1.5px solid #2563eb;
            border-radius: var(--radius-lg);
            padding: 24px 28px;
            box-shadow: 0 10px 30px rgba(37, 99, 235, 0.12);
            position: relative;
            overflow: hidden;
            margin-bottom: 32px;
        }

        /* Pulsing Red Watch Live Button */
        .btn-watch-live {
            background: #dc2626;
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            padding: 10px 22px;
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            animation: pulseCam 2s infinite;
        }
        .btn-watch-live:hover {
            background: #b91c1c;
            color: #ffffff;
            transform: translateY(-1px);
        }
        @keyframes pulseCam {
            0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.5); }
            70% { box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
        }

        /* Cage Cards */
        .cage-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
            transition: all 0.25s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .cage-card:hover {
            box-shadow: 0 8px 30px rgba(37, 99, 235, 0.08);
            transform: translateY(-2px);
        }

        /* Tabs */
        .nav-tabs-custom {
            display: flex;
            gap: 8px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .nav-tabs-custom .tab-btn {
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 12px 18px;
            font-size: 14.5px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .nav-tabs-custom .tab-btn:hover {
            color: var(--primary);
        }
        .nav-tabs-custom .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        @media (max-width: 992px) {
            .sidebar { display: none !important; }
            .main-content { margin-left: 0; padding: 24px 16px; }
        }
    </style>
</head>
<body>

<?php if (isset($_SESSION['message'])): ?>
<div class="position-fixed top-0 end-0 p-4" style="z-index: 9999;">
    <div class="alert alert-<?= $_SESSION['msg_type'] ?> alert-dismissible fade show shadow-lg border-0 rounded-4 pe-5" role="alert">
        <i class="fa <?= $_SESSION['msg_type'] === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> me-2"></i>
        <?= $_SESSION['message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php unset($_SESSION['message'], $_SESSION['msg_type']); endif; ?>

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
    <a href="dashboard.php"><i class="fa fa-home"></i> <span>Dashboard</span></a>
    <a href="appointments.php"><i class="fa fa-calendar-alt"></i> <span>Appointments</span></a>
    <a href="boarding.php" class="active"><i class="fa fa-hotel"></i> <span>Pet Boarding</span></a>
    <a href="pets.php"><i class="fa fa-notes-medical"></i> <span>Profile & Pets</span></a>
    <a href="billing.php"><i class="fa fa-file-invoice-dollar"></i> <span>Billing</span></a>
    <a href="settings.php"><i class="fa fa-cog"></i> <span>Account Settings</span></a>
    <div class="sidebar-logout"><a href="logout.php"><i class="fa fa-sign-out-alt"></i> <span>Logout</span></a></div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <div class="boarding-container">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h2 class="fw-bold mb-1" style="color: #0f172a; font-size: 26px; letter-spacing: -0.4px;">Pet Boarding & Hotel</h2>
                <p class="text-muted mb-0" style="font-size: 14px;">Reserve kennel suites, track stay schedules, and monitor live pet video feeds</p>
            </div>
            <button class="btn btn-primary rounded-3 px-4 py-2 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#bookCageModal" data-cage="1" style="background:#2563eb; border:none;">
                <i class="fa fa-plus me-1"></i> Book a Stay (₱<?= number_format($boarding_rate, 0) ?>/day)
            </button>
        </div>

        <!-- ── ACTIVE STAY HERO BANNER WITH LIVE CCTV ── -->
        <?php if (!empty($active_stays)): ?>
            <?php foreach ($active_stays as $active): 
                $can_watch = ($active['cctv_state'] === 'LIVE');
                $is_standby = ($active['cctv_state'] === 'STANDBY');
                $days_total = max(1, round((strtotime($active['end_date']) - strtotime($active['appointment_date'])) / 86400));
            ?>
            <div class="active-stay-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 56px; height: 56px; border-radius: 16px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 26px; flex-shrink: 0;">
                            <i class="fa fa-hotel"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <h4 class="fw-bold m-0" style="color: #0f172a; font-size: 20px;">
                                    <?= htmlspecialchars($active['pet_name']) ?> is Currently Boarding
                                </h4>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1 fw-bold" style="font-size: 11px;">
                                    <i class="fa fa-circle me-1" style="font-size: 8px;"></i> Active Stay
                                </span>
                            </div>
                            <p class="text-muted mb-0" style="font-size: 13.5px;">
                                Cage Unit <strong>#<?= htmlspecialchars($active['cage_number']) ?></strong> &middot; 
                                Check-in: <?= date("M j, Y", strtotime($active['appointment_date'])) ?> &middot; 
                                Check-out: <strong><?= date("M j, Y", strtotime($active['end_date'])) ?></strong> 
                                (<?= $days_total ?> day stay)
                            </p>
                        </div>
                    </div>

                    <!-- CCTV Trigger -->
                    <div>
                        <?php if ($can_watch): ?>
                            <button class="btn-watch-live" onclick="openSecureCctvModal('<?= htmlspecialchars($active['cctv_link']) ?>', '<?= htmlspecialchars($active['pet_name']) ?>', '<?= $active['id'] ?>', false)">
                                <i class="fa fa-video"></i> Watch <?= htmlspecialchars($active['pet_name']) ?> Live Cam
                            </button>
                        <?php elseif ($is_standby): ?>
                            <div class="bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-3 px-3 py-2 text-center" style="font-size: 12px; font-weight: 600;">
                                <i class="fa fa-pause-circle me-1"></i> Camera feed on standby by caretakers
                            </div>
                        <?php else: ?>
                            <div class="bg-light text-muted border rounded-3 px-3 py-2 text-center" style="font-size: 12px; font-weight: 600;">
                                <i class="fa fa-video-slash me-1"></i> Camera not configured yet
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- ── ACTION REQUIRED / RESCHEDULE PROPOSALS ── -->
        <?php if (!empty($pending_stays)): ?>
            <div class="bg-white rounded-4 border p-4 mb-4 shadow-sm" style="border-color: #fde68a !important; border-left: 5px solid #f59e0b !important;">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                        <i class="fa fa-exclamation-circle text-warning"></i> Action Required: Pending Boarding Notices
                    </h5>
                    <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold">
                        <?= count($pending_stays) ?> Notice<?= count($pending_stays) > 1 ? 's' : '' ?>
                    </span>
                </div>

                <div class="d-flex flex-column gap-3">
                    <?php foreach ($pending_stays as $pend): ?>
                        <div class="p-3 bg-light rounded-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <div class="fw-bold text-dark" style="font-size: 15px;">
                                    <?= htmlspecialchars($pend['pet_name']) ?> (Cage #<?= htmlspecialchars($pend['cage_number']) ?>)
                                </div>
                                <div class="text-muted small">
                                    Requested: <?= date("M j, Y", strtotime($pend['appointment_date'])) ?> to <?= date("M j, Y", strtotime($pend['end_date'])) ?>
                                </div>
                                <?php if ($pend['status'] === 'Reschedule Proposed'): ?>
                                    <div class="text-warning fw-semibold small mt-1">
                                        <i class="fa fa-clock-rotate-left me-1"></i> Clinic proposed: <?= date("M j, Y", strtotime($pend['proposed_reschedule_date'])) ?> to <?= date("M j, Y", strtotime($pend['proposed_reschedule_end_date'])) ?>
                                        &mdash; <em><?= htmlspecialchars($pend['admin_message'] ?? 'Clinic date adjustment') ?></em>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex gap-2">
                                <?php if ($pend['status'] === 'Reschedule Proposed'): ?>
                                    <!-- Action sent as a hidden field: the site's confirm popup submits via form.submit(), which drops the clicked button's name -->
                                    <form method="POST" class="m-0" onsubmit="return confirm('Accept the updated dates?');">
                                        <input type="hidden" name="reschedule_apt_id" value="<?= $pend['id'] ?>">
                                        <input type="hidden" name="accept_reschedule_boarding" value="1">
                                        <button type="submit" class="btn btn-sm btn-success fw-bold px-3 rounded-pill">
                                            <i class="fa fa-check me-1"></i> Accept
                                        </button>
                                    </form>
                                    <form method="POST" class="m-0" onsubmit="return confirm('Decline and cancel request?');">
                                        <input type="hidden" name="reschedule_apt_id" value="<?= $pend['id'] ?>">
                                        <input type="hidden" name="decline_reschedule_boarding" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-danger fw-bold px-3 rounded-pill">
                                            <i class="fa fa-times me-1"></i> Decline
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="m-0" onsubmit='return pvNoRefundDialog(this, <?= htmlspecialchars(json_encode(["kind" => "boarding", "what" => $pend["pet_name"] . " · " . date("M j", strtotime($pend["appointment_date"])) . " – " . date("M j, Y", strtotime($pend["end_date"])), "paid" => $paid_map[(int)$pend["id"]] ?? 0]), ENT_QUOTES) ?>)'>
                                        <input type="hidden" name="apt_id" value="<?= $pend['id'] ?>">
                                        <input type="hidden" name="cancel_boarding" value="1">
                                        <button type="submit" name="cancel_boarding" class="btn btn-sm btn-outline-danger fw-bold px-3 rounded-pill">
                                            <i class="fa fa-trash me-1"></i> Cancel Request
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── TABBED SECTION: CAGE MATRIX & MY RESERVATIONS ── -->
        <div class="nav-tabs-custom">
            <button class="tab-btn active" onclick="switchBoardingTab('cages', this)">
                <i class="fa fa-border-all me-1"></i> Cage Availability Matrix
            </button>
            <button class="tab-btn" onclick="switchBoardingTab('my-stays', this)">
                <i class="fa fa-list-check me-1"></i> My Boarding Reservations (<?= count($my_all_res) ?>)
            </button>
        </div>

        <!-- TAB 1: CAGE MATRIX -->
        <div id="tabCagesContent">
            <div class="row g-4 mb-5">
                <?php
                $today_str  = date("Y-m-d");
                $today_time = strtotime($today_str);

                for ($i = 1; $i <= $cage_count; $i++):
                    $currently_occupied_by = null;
                    if (!empty($cages[$i])) {
                        foreach ($cages[$i] as $b) {
                            if ($b['status'] === 'Checked In') {
                                $currently_occupied_by = [
                                    'pet_name'     => $b['pet_name'],
                                    'user_id'      => (int)$b['user_id'],
                                    'res_id'       => (int)$b['id'],
                                    'cctv_link'    => $b['cctv_link'] ?? '',
                                    'cctv_enabled' => (int)($b['cctv_enabled'] ?? 0),
                                    'status'       => $b['status']
                                ];
                                break;
                            }
                        }
                    }
                    $is_my_pet_in_cage = ($currently_occupied_by && $currently_occupied_by['user_id'] === $user_id);

                    // Detect maintenance lock
                    $is_under_maintenance = false;
                    if (!empty($cages[$i])) {
                        foreach ($cages[$i] as $b) {
                            if ($b['status'] === 'Maintenance') {
                                $is_under_maintenance = true;
                                break;
                            }
                        }
                    }
                ?>
                <div class="col-xl-4 col-md-6">
                    <div class="cage-card" style="background: #ffffff; border: 1px solid <?= $is_under_maintenance ? '#fde68a' : '#e2e8f0' ?>; border-radius: 18px; padding: 22px; box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04); transition: all 0.2s ease; <?= $is_under_maintenance ? 'opacity:0.85;' : '' ?>">
                        <div>
                            <!-- Header -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <div style="width: 40px; height: 40px; border-radius: 12px; background: <?= $is_under_maintenance ? '#fef3c7' : '#eff6ff' ?>; color: <?= $is_under_maintenance ? '#d97706' : '#2563eb' ?>; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; font-weight: 700;">
                                        <i class="fa <?= $is_under_maintenance ? 'fa-wrench' : 'fa-hotel' ?>"></i>
                                    </div>
                                    <div>
                                        <h5 class="fw-bold mb-0 text-dark" style="font-size: 17px; letter-spacing: -0.3px;">
                                            Cage Unit 0<?= $i ?>
                                        </h5>
                                        <span class="text-muted" style="font-size: 12px; font-weight: 500;">Standard Suite &bull; <strong style="color:#2563eb;">₱800</strong>/day</span>
                                    </div>
                                </div>

                                <?php if ($is_under_maintenance): ?>
                                    <!-- Maintenance: disabled Reserve button -->
                                    <button class="btn btn-sm fw-bold px-3 py-1.5" disabled
                                        title="This cage is currently under maintenance"
                                        style="background: #e2e8f0; color: #94a3b8; border-radius: 10px; font-size: 13px; border: none; cursor: not-allowed;">
                                        <i class="fa fa-wrench me-1"></i> Maintenance
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm fw-bold px-3 py-1.5" data-bs-toggle="modal" data-bs-target="#bookCageModal" data-cage="<?= $i ?>" style="background: #2563eb; color: #ffffff; border-radius: 10px; font-size: 13px; box-shadow: 0 2px 8px rgba(37,99,235,0.25); border: none;">
                                        <i class="fa fa-calendar-plus me-1"></i> Reserve
                                    </button>
                                <?php endif; ?>
                            </div>

                            <!-- Occupancy Banner -->
                            <div class="mb-3">
                                <?php if ($is_under_maintenance): ?>
                                    <!-- Maintenance Banner -->
                                    <div class="p-3 rounded-3 text-center" style="background: #fffbeb; border: 1px solid #fde68a;">
                                        <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                                            <i class="fa fa-wrench text-warning" style="font-size: 14px;"></i>
                                            <span class="fw-bold text-uppercase" style="font-size: 12px; letter-spacing: 0.5px; color: #92400e;">Under Maintenance</span>
                                        </div>
                                        <div style="font-size: 11.5px; color: #78350f; opacity: 0.9;">Temporarily unavailable for reservations</div>
                                    </div>
                                <?php elseif ($currently_occupied_by): ?>
                                    <?php if ($is_my_pet_in_cage): ?>
                                        <div class="p-3 rounded-3 text-center" style="background: #eff6ff; border: 1px solid #bfdbfe;">
                                            <div class="fw-bold text-primary mb-1" style="font-size: 13.5px;">
                                                <i class="fa fa-paw me-1"></i> <?= htmlspecialchars($currently_occupied_by['pet_name']) ?> (Your Pet)
                                            </div>
                                            <div class="text-muted small mb-2" style="font-size: 11.5px;">Currently boarding in this suite</div>
                                            <?php if (!empty($currently_occupied_by['cctv_link']) && $currently_occupied_by['cctv_enabled'] == 1): ?>
                                                <button class="btn btn-sm btn-danger px-3 py-1 rounded-pill fw-bold shadow-sm" style="font-size: 11.5px;" onclick="openSecureCctvModal('<?= htmlspecialchars($currently_occupied_by['cctv_link']) ?>', '<?= htmlspecialchars($currently_occupied_by['pet_name']) ?>', '<?= $currently_occupied_by['res_id'] ?>', false)">
                                                    <i class="fa fa-video me-1"></i> Watch Live Cam
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <!-- Locked / Privacy Protected: Never expose another owner's pet or feed -->
                                        <div class="p-3 rounded-3 text-center" style="background: #f8fafc; border: 1px solid #e2e8f0; color: #64748b;">
                                            <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                                                <i class="fa fa-lock text-secondary" style="font-size: 14px;"></i>
                                                <span class="fw-bold text-uppercase" style="font-size: 12px; letter-spacing: 0.5px; color: #475569;">Occupied by Guest</span>
                                            </div>
                                            <div style="font-size: 11.5px; opacity: 0.85;">Private Guest checked in</div>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="p-3 rounded-3 text-center" style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a;">
                                        <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                                            <i class="fa fa-door-open" style="font-size: 15px;"></i>
                                            <span class="fw-bold text-uppercase" style="font-size: 12px; letter-spacing: 0.5px;">Available Today</span>
                                        </div>
                                        <div style="font-size: 11.5px; opacity: 0.85;">Ready for instant drop-off</div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Schedule timeline for cage -->
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="fw-bold text-uppercase" style="font-size: 11px; letter-spacing: 0.5px; color: #64748b;">
                                    <i class="fa fa-calendar-alt me-1 text-primary"></i> Active & Upcoming Bookings
                                </span>
                            </div>

                            <div class="d-flex flex-column gap-2">
                                <?php
                                $has_schedule = false;
                                if (!empty($cages[$i])):
                                    foreach ($cages[$i] as $b):
                                        if (strtotime($b['end_date']) > $today_time):
                                            $has_schedule = true;
                                            $mine = ((int)$b['user_id'] === $user_id);
                                            $st = $b['status'];
                                            if ($st === 'Approved') {
                                                $st_badge = '<span class="badge" style="background:#dcfce7; color:#15803d; font-weight:700; font-size:10.5px; padding:4px 10px; border-radius:20px; border:1px solid #bbf7d0;"><i class="fa fa-circle-check me-1" style="font-size:9px;"></i> Confirmed</span>';
                                            } elseif ($st === 'Pending') {
                                                $st_badge = '<span class="badge" style="background:#fef3c7; color:#b45309; font-weight:700; font-size:10.5px; padding:4px 10px; border-radius:20px; border:1px solid #fde68a;"><i class="fa fa-clock me-1" style="font-size:9px;"></i> Pending</span>';
                                            } elseif ($st === 'Reschedule Proposed') {
                                                $st_badge = '<span class="badge" style="background:#ffedd5; color:#c2410c; font-weight:700; font-size:10.5px; padding:4px 10px; border-radius:20px; border:1px solid #fed7aa;"><i class="fa fa-arrows-rotate me-1" style="font-size:9px;"></i> Rescheduled</span>';
                                            } else {
                                                $st_badge = '<span class="badge" style="background:#f1f5f9; color:#475569; font-weight:600; font-size:10.5px; padding:4px 10px; border-radius:20px; border:1px solid #e2e8f0;">' . htmlspecialchars($st) . '</span>';
                                            }
                                ?>
                                    <div class="p-2.5 rounded-3 d-flex justify-content-between align-items-center" style="background: <?= $mine ? '#f0f6ff' : '#f8fafc' ?>; border: 1px solid <?= $mine ? '#bfdbfe' : '#e2e8f0' ?>; margin-bottom: 6px;">
                                        <div>
                                            <div class="fw-bold" style="font-size: 12.5px; color: <?= $mine ? '#1d4ed8' : '#1e293b' ?>;">
                                                <i class="fa fa-paw me-1 text-primary"></i> <?= $mine ? htmlspecialchars($b['pet_name']) . ' <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 ms-1" style="font-size:9.5px; padding: 2px 6px;">Yours</span>' : 'Reserved Stay' ?>
                                            </div>
                                            <div class="text-muted" style="font-size: 11px; margin-top: 1px;">
                                                <?= date("M d", strtotime($b['appointment_date'])) ?> &mdash; <?= date("M d, Y", strtotime($b['end_date'])) ?>
                                            </div>
                                        </div>
                                        <div>
                                            <?= $st_badge ?>
                                        </div>
                                    </div>
                                <?php 
                                        endif;
                                    endforeach;
                                endif;

                                if (!$has_schedule): ?>
                                    <div class="text-center p-3 rounded-3 text-muted" style="background: #f8fafc; border: 1px dashed #cbd5e1; font-size: 12px;">
                                        <i class="fa fa-circle-check text-success me-1"></i> No upcoming bookings on this suite
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- TAB 2: MY BOARDING RESERVATIONS & CCTV ACCESS -->
        <div id="tabMyStaysContent" style="display: none;">
            <div class="bg-white rounded-4 border p-4 mb-4 shadow-sm" style="border-color: #eef2f6 !important;">
                <h5 class="fw-bold mb-3" style="color: #0f172a;">All My Boarding Reservations</h5>

                <?php if (empty($my_all_res)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa fa-hotel fa-3x mb-2 opacity-50"></i>
                        <p>You haven't made any boarding reservations yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
                            <thead class="table-light">
                                <tr>
                                    <th>Pet Name</th>
                                    <th>Cage</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Status</th>
                                    <th>Live CCTV Access</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($my_all_res as $r): 
                                    $cctv_st = $r['cctv_state'];
                                ?>
                                <tr>
                                    <td class="fw-bold text-dark">
                                        <i class="fa fa-paw text-primary me-2"></i><?= htmlspecialchars($r['pet_name']) ?>
                                    </td>
                                    <td>Cage #<?= htmlspecialchars($r['cage_number'] ?: 'TBD') ?></td>
                                    <td><?= date("M d, Y", strtotime($r['appointment_date'])) ?></td>
                                    <td><?= date("M d, Y", strtotime($r['end_date'])) ?></td>
                                    <td>
                                        <span class="badge rounded-pill bg-<?= $r['status'] === 'Approved' ? 'success' : ($r['status'] === 'Pending' ? 'warning' : 'secondary') ?>" style="font-size: 11px;">
                                            <?= htmlspecialchars($r['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($cctv_st === 'LIVE'): ?>
                                            <button class="btn btn-sm btn-danger rounded-pill px-3 fw-bold" onclick="openSecureCctvModal('<?= htmlspecialchars($r['cctv_link']) ?>', '<?= htmlspecialchars($r['pet_name']) ?>', '<?= $r['id'] ?>', false)">
                                                <i class="fa fa-video me-1"></i> Watch Live
                                            </button>
                                        <?php elseif ($cctv_st === 'STANDBY'): ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-pill px-3 py-1">
                                                <i class="fa fa-pause-circle me-1"></i> Standby
                                            </span>
                                        <?php elseif ($cctv_st === 'EXPIRED'): ?>
                                            <span class="badge bg-light text-muted border rounded-pill px-3 py-1" title="Access expired after check-out">
                                                <i class="fa fa-video-slash me-1"></i> Expired
                                            </span>
                                        <?php elseif ($cctv_st === 'UPCOMING'): ?>
                                            <span class="badge bg-light text-primary border rounded-pill px-3 py-1">
                                                <i class="fa fa-clock me-1"></i> Unlocks on Check-in
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['status'] === 'Pending'): ?>
                                            <form method="POST" class="m-0" onsubmit='return pvNoRefundDialog(this, <?= htmlspecialchars(json_encode(["kind" => "boarding", "what" => $r["pet_name"] . " · " . date("M j", strtotime($r["appointment_date"])) . " – " . date("M j, Y", strtotime($r["end_date"])), "paid" => $paid_map[(int)$r["id"]] ?? 0]), ENT_QUOTES) ?>)'>
                                                <input type="hidden" name="apt_id" value="<?= $r['id'] ?>">
                                                <input type="hidden" name="cancel_boarding" value="1">
                                                <button type="submit" name="cancel_boarding" class="btn btn-sm btn-outline-danger">Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            <a href="billing.php" class="btn btn-sm btn-outline-primary">Invoice</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- ====== SECURE CCTV MODAL ====== -->
<div class="modal fade" id="cctvModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden; background: #ffffff;">
            <div class="modal-header border-0 pb-2 px-4 pt-4" style="background: #182032;">
                <div class="d-flex align-items-center gap-2">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #ef4444; display: inline-block; animation: pulseCam 1.5s infinite;"></span>
                    <h5 class="modal-title fw-bold text-white mb-0" id="cctvModalTitle" style="font-size: 18px;">
                        Live Pet Cam Feed
                    </h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="closeCctvModal()"></button>
            </div>
            
            <div class="modal-body p-3">
                <div id="cctvStreamContainer" style="background: #000; min-height: 420px; border-radius: 14px; overflow: hidden; display: flex; align-items: center; justify-content: center; position: relative;">
                    <!-- Stream or Expired Notice injected via JS -->
                </div>
            </div>

            <div class="modal-footer border-0 p-3 pt-0 d-flex justify-content-between">
                <span class="text-muted small">
                    <i class="fa fa-lock text-success me-1"></i> Private Stream &middot; Client Encrypted &middot; Expires on Check-out
                </span>
                <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill fw-semibold" data-bs-dismiss="modal" onclick="closeCctvModal()">
                    Close Feed
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ====== BOOK CAGE MODAL WITH DYNAMIC LIVE CALCULATOR ====== -->
<div class="modal fade" id="bookCageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header border-0 pt-4 px-4 pb-2" style="background: #2563eb; color: #ffffff;">
                <div>
                    <h5 class="fw-bold mb-0 text-white d-flex align-items-center gap-2">
                        <i class="fa fa-hotel"></i> Reserve Pet Boarding Suite
                    </h5>
                    <div class="text-white opacity-75 small mt-1">₱<?= number_format($boarding_rate, 0) ?> / night &middot; Safe & Monitored</div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4" style="max-height: 75vh; overflow-y: auto;">
                <form method="POST" id="bookForm" action="boarding.php">
                    <input type="hidden" name="cage_number" id="modalCageId" value="1">
                    <input type="hidden" name="payment_type" id="paymentType" value="downpayment">

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Assigned Suite</label>
                        <div class="p-3 bg-light rounded-3 fw-bold text-dark" id="displayCageTitle">
                            Cage Suite #01
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Select Pet</label>
                        <select name="pet_name" class="form-select p-3 rounded-3" required>
                            <option value="">Choose pet...</option>
                            <?php
                            $pets_result->data_seek(0);
                            while ($p = $pets_result->fetch_assoc()) {
                                echo "<option value=\"" . htmlspecialchars($p['name']) . "\">" . htmlspecialchars($p['name']) . " (" . htmlspecialchars($p['species']) . ")</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label text-muted small fw-bold text-uppercase">Check-In Date</label>
                            <input type="text" name="appointment_date" id="checkinDate" class="form-control p-3 rounded-3" required placeholder="Select check-in">
                        </div>
                        <div class="col-6">
                            <label class="form-label text-muted small fw-bold text-uppercase">Check-Out Date</label>
                            <input type="text" name="end_date" id="checkoutDate" class="form-control p-3 rounded-3" required placeholder="Select check-out">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Special Care / Feeding Notes</label>
                        <textarea name="special_instructions" class="form-control p-3 rounded-3" rows="2" placeholder="e.g. Dry kibble 2x daily, allergic to chicken..."></textarea>
                    </div>

                    <!-- DYNAMIC PRICE CALCULATOR BOX -->
                    <div id="priceBox" class="rounded-3 p-3 mb-4" style="background: #f0f6ff; border: 1px solid #bfdbfe; display: none;">
                        <div class="d-flex justify-content-between mb-1 small">
                            <span class="text-muted">Total Nights:</span>
                            <span class="fw-bold" id="calcDays">&mdash;</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1 small">
                            <span class="text-muted">Rate per Night:</span>
                            <span class="fw-bold">₱<?= number_format($boarding_rate, 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-1 small">
                            <span class="text-muted">Total Boarding Fee:</span>
                            <span class="fw-bold text-dark" id="calcTotal">₱0.00</span>
                        </div>
                        <hr class="my-2" style="border-color: #cbd5e1;">
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">30% Required Deposit:</span>
                            <span class="fw-bold text-primary" id="calcDP">₱0.00</span>
                        </div>
                    </div>

                    <!-- PAYMENT BUTTONS -->
                    <div id="payButtons" style="display: none;">
                        <div class="d-grid gap-2">
                            <button type="submit" name="book_cage" onclick="document.getElementById('paymentType').value='downpayment';" class="btn btn-primary p-3 rounded-3 fw-bold" style="background: #2563eb; border: none;">
                                <i class="fa fa-shield-halved me-1"></i> Pay 30% Deposit (<span id="btnDpAmt">₱0.00</span>)
                            </button>
                            <button type="submit" name="book_cage" onclick="document.getElementById('paymentType').value='full';" class="btn btn-outline-primary p-2 rounded-3 fw-bold">
                                Pay Full Stay (<span id="btnFullAmt">₱0.00</span>)
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
// Tab switcher
function switchBoardingTab(tab, btn) {
    document.querySelectorAll('.nav-tabs-custom .tab-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    if (tab === 'cages') {
        document.getElementById('tabCagesContent').style.display = 'block';
        document.getElementById('tabMyStaysContent').style.display = 'none';
    } else {
        document.getElementById('tabCagesContent').style.display = 'none';
        document.getElementById('tabMyStaysContent').style.display = 'block';
    }
}

// Secure CCTV Modal Launcher
var cctvModalInstance = null;
function openSecureCctvModal(link, petName, resId, isExpired) {
    document.getElementById('cctvModalTitle').innerText = 'Live Feed: ' + petName;
    const container = document.getElementById('cctvStreamContainer');

    if (isExpired) {
        container.innerHTML = `
            <div class="text-center text-white p-5">
                <i class="fa fa-video-slash fa-3x text-muted mb-3"></i>
                <h5 class="fw-bold text-danger">CCTV Access Expired</h5>
                <p class="text-muted small">CCTV video streaming is only granted while your pet is actively boarding. This stay has concluded.</p>
            </div>
        `;
    } else {
        if (/:(8080|4747|8000|8888|1234)\/?$/.test(link)) {
            link = link.replace(/\/?$/, '/video');
        }

        const isMjpeg = link.indexOf('/video') !== -1 || link.indexOf('.mjpg') !== -1 || link.indexOf('videofeed') !== -1;
        let streamHtml = '';

        if (isMjpeg) {
            streamHtml = '<img src="' + link + '" alt="Live Camera Feed" style="display:block;width:100%;height:100%;max-height:500px;object-fit:contain;">';
        } else {
            streamHtml = '<iframe src="' + link + '" frameborder="0" allow="autoplay; fullscreen" style="width:100%;height:450px;border:none;"></iframe>';
        }

        container.innerHTML = streamHtml;
    }

    if (!cctvModalInstance) {
        cctvModalInstance = new bootstrap.Modal(document.getElementById('cctvModal'));
    }
    cctvModalInstance.show();
}

function closeCctvModal() {
    const container = document.getElementById('cctvStreamContainer');
    if (container) container.innerHTML = '';
}

const flatpickrDisable = <?= json_encode($flatpickr_disable) ?>;

// Flatpickr & Live Price Calculation for Booking Modal
document.addEventListener('DOMContentLoaded', function() {
    const cageModalEl = document.getElementById('bookCageModal');
    if (cageModalEl) {
        cageModalEl.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const cageNum = button ? button.getAttribute('data-cage') : '1';
            document.getElementById('modalCageId').value = cageNum;
            document.getElementById('displayCageTitle').innerText = 'Cage Suite #' + String(cageNum).padStart(2, '0');
            
            // Apply disabled dates for this specific cage
            const disabledDates = flatpickrDisable[cageNum] || [];
            fpIn.set('disable', disabledDates);
            fpOut.set('disable', disabledDates);
            
            // Reset dates when switching cages
            fpIn.clear();
            fpOut.clear();
            document.getElementById('boardingTotalCalc').innerText = '₱0.00';
            document.getElementById('totalAmountInput').value = '0';
        });
    }

    const minDate = new Date();
    minDate.setDate(minDate.getDate() + 1);

    const fpIn = flatpickr("#checkinDate", {
        minDate: minDate,
        dateFormat: "Y-m-d",
        onChange: function(selectedDates, dateStr) {
            if (selectedDates[0]) {
                const nextDay = new Date(selectedDates[0]);
                nextDay.setDate(nextDay.getDate() + 1);
                fpOut.set('minDate', nextDay);
                calculateBoardingPrice();
            }
        }
    });

    const fpOut = flatpickr("#checkoutDate", {
        minDate: minDate,
        dateFormat: "Y-m-d",
        onChange: function() {
            calculateBoardingPrice();
        }
    });

    function calculateBoardingPrice() {
        const inVal = document.getElementById('checkinDate').value;
        const outVal = document.getElementById('checkoutDate').value;

        if (inVal && outVal) {
            const d1 = new Date(inVal);
            const d2 = new Date(outVal);
            const diffTime = d2 - d1;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

            if (diffDays > 0) {
                const total = diffDays * <?= json_encode((float)$boarding_rate) ?>;
                const dp = total * 0.30;

                document.getElementById('calcDays').innerText = diffDays + ' night' + (diffDays > 1 ? 's' : '');
                document.getElementById('calcTotal').innerText = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
                document.getElementById('calcDP').innerText = '₱' + dp.toLocaleString('en-US', {minimumFractionDigits: 2});
                document.getElementById('btnDpAmt').innerText = '₱' + dp.toLocaleString('en-US', {minimumFractionDigits: 2});
                document.getElementById('btnFullAmt').innerText = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2});

                document.getElementById('priceBox').style.display = 'block';
                document.getElementById('payButtons').style.display = 'block';
            } else {
                document.getElementById('priceBox').style.display = 'none';
                document.getElementById('payButtons').style.display = 'none';
            }
        }
    }
});
</script>

<?php include("chat_widget.php"); ?>
<?php include_once("includes/reminder_popup.php"); ?>
<?php include_once("includes/cancel_policy_dialog.php"); ?>
</body>
</html>