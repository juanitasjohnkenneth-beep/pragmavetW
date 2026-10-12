<?php
require_once __DIR__ . '/includes/mail_config.php';
session_start();
include("dbconnection.php");
include_once("includes/rbac_guard.php");

// RBAC Access Guard
$user_role = rbac_get_role();
if (!isset($_SESSION['user_id']) || !in_array($user_role, ['admin', 'staff', 'clinic_owner', 'owner'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}

// Boarding is managed by vet staff — admins / owners can only view this page (and its audit trail)
include_once("includes/boarding_audit.php");
ba_ensure($conn);
$pv_view_only = in_array($user_role, ['admin', 'clinic_owner'], true);
if ($pv_view_only && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (isset($_POST['turn_on_cctv_ajax']) || isset($_POST['turn_off_cctv_ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'View only: boarding is managed by vet staff.']);
        exit();
    }
    $_SESSION['message'] = "View only: boarding changes are made by vet staff in Pet Boarding Care.";
    $_SESSION['msg_type'] = "warning";
    header("Location: admin_boarding.php");
    exit();
}

// PHPMailer Setup
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['user'] ?? 'Clinic Admin';

// --- CCTV Management Handlers ---
if (isset($_POST['save_cctv'])) {
    $apt_id = (int)$_POST['apt_id'];
    $link = trim($_POST['cctv_link']);
    $stmt = $conn->prepare("UPDATE boarding_reservations SET cctv_link=? WHERE id=?");
    $stmt->bind_param("si", $link, $apt_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "CCTV Feed Link successfully bound to reservation.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_boarding.php");
    exit();
}

if (isset($_POST['turn_off_cctv_ajax'])) {
    $apt_id = (int)$_POST['apt_id'];
    $conn->query("UPDATE boarding_reservations SET cctv_enabled=0 WHERE id=$apt_id");
    echo json_encode(["status" => "success"]);
    exit();
}

if (isset($_POST['turn_on_cctv_ajax'])) {
    $apt_id = (int)$_POST['apt_id'];
    $conn->query("UPDATE boarding_reservations SET cctv_enabled=1 WHERE id=$apt_id");
    echo json_encode(["status" => "success"]);
    exit();
}

// --- Check-In Pet Handler ---
if (isset($_POST['check_in_pet'])) {
    $apt_id = (int)$_POST['apt_id'];
    $stmt = $conn->prepare("UPDATE boarding_reservations SET status='Checked In' WHERE id=?");
    $stmt->bind_param("i", $apt_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Pet checked-in successfully! Cage is now occupied.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_boarding.php");
    exit();
}

// --- Check-Out / Release Cage Handler ---
if (isset($_POST['release_cage'])) {
    $apt_id = (int)$_POST['apt_id'];
    $stmt = $conn->prepare("UPDATE boarding_reservations SET status='Completed' WHERE id=?");
    $stmt->bind_param("i", $apt_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Boarding stay completed. Pet checked-out and cage released!";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_boarding.php");
    exit();
}

// --- Save Care Notes Handler ---
if (isset($_POST['save_care_notes'])) {
    $apt_id = (int)$_POST['apt_id'];
    $notes = trim($_POST['care_notes']);
    $stmt = $conn->prepare("UPDATE boarding_reservations SET care_notes=? WHERE id=?");
    $stmt->bind_param("si", $notes, $apt_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Care & feeding instructions saved!";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_boarding.php");
    exit();
}

// --- Add Walk-in Boarding Handler ---
if (isset($_POST['add_walkin_boarding'])) {
    $client_name = trim($_POST['client_name']);
    $pet_name    = trim($_POST['pet_name']);
    $cage        = (int)$_POST['cage_number'];
    $start_date  = $_POST['appointment_date'];
    $end_date    = $_POST['end_date'];
    $care_notes  = trim($_POST['care_notes'] ?? '');

    // Check if client exists or use admin ID
    $user_id = $admin_id;
    $chk_u = $conn->prepare("SELECT id FROM users WHERE fullname LIKE ? LIMIT 1");
    $searchTerm = "%$client_name%";
    $chk_u->bind_param("s", $searchTerm);
    $chk_u->execute();
    $res_u = $chk_u->get_result();
    if ($res_u && $res_u->num_rows > 0) {
        $user_id = $res_u->fetch_assoc()['id'];
    }

    $stmt = $conn->prepare("INSERT INTO boarding_reservations (user_id, pet_name, service_type, appointment_date, appointment_time, end_date, cage_number, status, care_notes, approved_at) VALUES (?, ?, 'Pet Boarding Reservation', ?, '09:00:00', ?, ?, 'Checked In', ?, NOW())");
    $stmt->bind_param("isssis", $user_id, $pet_name, $start_date, $end_date, $cage, $care_notes);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Walk-in boarding registered and assigned to <b>Cage 0$cage</b>!";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Failed to register walk-in boarding: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_boarding.php");
    exit();
}

// 1. APPROVE / REJECT PENDING BOARDING
if (isset($_POST['boarding_action']) && isset($_POST['apt_id'])) {
    $apt_id = (int)$_POST['apt_id'];
    $action = $_POST['boarding_action'];
    $new_status = ($action === 'approve') ? 'Approved' : 'Rejected';

    if ($action === 'approve' && isset($_POST['cage_number'])) {
        $cage = (int)$_POST['cage_number'];
        $stmt = $conn->prepare("UPDATE boarding_reservations SET status=?, cage_number=?, approved_at=NOW() WHERE id=?");
        $stmt->bind_param("sii", $new_status, $cage, $apt_id);
    } else {
        $stmt = $conn->prepare("UPDATE boarding_reservations SET status=? WHERE id=?");
        $stmt->bind_param("si", $new_status, $apt_id);
    }

    if ($stmt->execute()) {
        // Fetch appointment details to notify client
        $apt_q = $conn->query("SELECT a.user_id, a.pet_name, a.appointment_date, a.end_date, u.email, u.fullname FROM boarding_reservations a JOIN users u ON a.user_id = u.id WHERE a.id=$apt_id");
        if ($apt_q && $apt_q->num_rows > 0) {
            $apt_data = $apt_q->fetch_assoc();
            $uid = $apt_data['user_id'];
            $pname = $apt_data['pet_name'];
            $adate = date("M d", strtotime($apt_data['appointment_date']));
            $edate = date("M d", strtotime($apt_data['end_date']));
            $email = $apt_data['email'];
            $fullname = $apt_data['fullname'];
            $notif_msg = "Your boarding reservation for $pname ($adate to $edate) has been <b>$new_status</b>.";

            $ins_n = $conn->prepare("INSERT INTO notifications(user_id, message) VALUES(?, ?)");
            $ins_n->bind_param("is", $uid, $notif_msg);
            $ins_n->execute();

            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = PV_SMTP_USER;
                $mail->Password = PV_SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->setFrom(PV_SMTP_USER, 'PragmaVet Clinic');
                $mail->addAddress($email, $fullname);
                $mail->isHTML(true);
                $mail->Subject = 'Boarding Reservation Update | PragmaVet';

                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 10px;'>
                        <h2 style='color: #2563eb;'>Hello, $fullname!</h2>
                        <p style='color: #444; font-size: 15px;'>This is a formal update regarding your requested Pet Boarding service.</p>
                        <div style='background: #eff6ff; padding: 20px; border-radius: 8px; border-left: 4px solid #2563eb; margin: 20px 0;'>
                            <p style='margin: 0;'>$notif_msg</p>
                        </div>
                        <p style='color: #777; font-size: 13px;'>If you have any questions, please contact PragmaVet Animal Clinic.</p>
                    </div>";
                $mail->send();
            } catch (Exception $e) {}
        }

        $_SESSION['message'] = "Boarding reservation successfully " . strtolower($new_status) . ".";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_boarding.php");
    exit();
}

// 2. TOGGLE MAINTENANCE (MARK UNAVAILABLE / AVAILABLE)
if (isset($_POST['toggle_maintenance'])) {
    $cage_no = (int)$_POST['cage_number'];
    $is_maintenance = (int)$_POST['is_maintenance'];

    if ($is_maintenance) {
        $del = $conn->prepare("DELETE FROM boarding_reservations WHERE service_type='Maintenance' AND cage_number=? AND status='Maintenance'");
        $del->bind_param("i", $cage_no);
        $del->execute();
        $_SESSION['message'] = "Cage 0$cage_no is now unlocked and Available.";
        $_SESSION['msg_type'] = "success";
    } else {
        $ins = $conn->prepare("INSERT INTO boarding_reservations(user_id, pet_name, service_type, appointment_date, appointment_time, end_date, cage_number, status) 
                               VALUES(?, 'Maintenance Lock', 'Maintenance', CURDATE(), '00:00:00', '2030-12-31', ?, 'Maintenance')");
        $ins->bind_param("ii", $admin_id, $cage_no);
        if ($ins->execute()) {
            $_SESSION['message'] = "Cage 0$cage_no is now marked Unavailable (Maintenance).";
            $_SESSION['msg_type'] = "warning";
        } else {
            $_SESSION['message'] = "DB Error: " . $conn->error;
            $_SESSION['msg_type'] = "danger";
        }
    }
    header("Location: admin_boarding.php");
    exit();
}

// 3. TERMINATE APPROVED RESERVATION OR RESCHEDULE
if (isset($_POST['boarding_manage']) && isset($_POST['apt_id'])) {
    $apt_id = (int)$_POST['apt_id'];
    $cmd = $_POST['boarding_manage'];
    $reason = $_POST['admin_message'] ?? 'No reason provided.';

    $apt_q = $conn->query("SELECT a.user_id, a.pet_name, a.appointment_date, a.end_date, u.email, u.fullname FROM boarding_reservations a JOIN users u ON a.user_id = u.id WHERE a.id=$apt_id");
    if ($apt_q && $apt_q->num_rows > 0) {
        $apt_data = $apt_q->fetch_assoc();
        $uid = $apt_data['user_id'];
        $pname = $apt_data['pet_name'];
        $adate = date("M d", strtotime($apt_data['appointment_date']));
        $edate = date("M d", strtotime($apt_data['end_date']));
        $email = $apt_data['email'];
        $fullname = $apt_data['fullname'];

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = PV_SMTP_USER;
            $mail->Password = PV_SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->setFrom(PV_SMTP_USER, 'PragmaVet Clinic');
            $mail->addAddress($email, $fullname);
            $mail->isHTML(true);

            if ($cmd === 'cancel') {
                $stmt = $conn->prepare("UPDATE boarding_reservations SET status='Cancelled', admin_message=? WHERE id=?");
                $stmt->bind_param("si", $reason, $apt_id);
                $stmt->execute();

                $notif_msg = "Your boarding reservation for $pname ($adate to $edate) has been Terminated. Reason: $reason";
                $mail->Subject = 'Boarding Cancelled | PragmaVet';
                $mail->Body = "<div style='font-family: Arial, sans-serif; max-width: 600px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 10px;'><h2 style='color: #dc2626;'>Hello, $fullname!</h2><p style='color: #444; font-size: 15px;'>Notice regarding your requested Pet Boarding service.</p><div style='background: #fef2f2; padding: 20px; border-radius: 8px; border-left: 4px solid #dc2626; margin: 20px 0;'><p style='margin: 0;'>$notif_msg</p></div></div>";

                $chat_msg = "Admin Notice: Your boarding for $pname was cancelled. Reason: $reason";
                $ins_c = $conn->prepare("INSERT INTO chat_messages(sender_id, receiver_id, message) VALUES((SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1), ?, ?)");
                $ins_c->bind_param("is", $uid, $chat_msg);
                $ins_c->execute();

                $_SESSION['message'] = "Reservation securely terminated.";
                $_SESSION['msg_type'] = "warning";
            } elseif ($cmd === 'reschedule') {
                $new_start_raw = $_POST['new_start_date'];
                $new_end_raw = $_POST['new_end_date'];
                $new_start_f = date("M d, Y", strtotime($new_start_raw));
                $new_end_f = date("M d, Y", strtotime($new_end_raw));

                $stmt = $conn->prepare("UPDATE boarding_reservations SET status='Reschedule Proposed', proposed_reschedule_date=?, proposed_reschedule_end_date=?, admin_message=?, client_action_needed=1 WHERE id=?");
                $stmt->bind_param("sssi", $new_start_raw, $new_end_raw, $reason, $apt_id);
                $stmt->execute();

                $notif_msg = "The clinic proposed shifting your boarding reservation for $pname. New Dates: $new_start_f to $new_end_f. Reason: $reason.";
                $mail->Subject = 'Boarding Reschedule Proposal | PragmaVet';
                $mail->Body = "<div style='font-family: Arial, sans-serif; max-width: 600px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 10px;'><h2 style='color: #2563eb;'>Hello, $fullname!</h2><p style='color: #444; font-size: 15px;'>Reschedule proposal for your pet's boarding.</p><div style='background: #eff6ff; padding: 20px; border-radius: 8px; border-left: 4px solid #2563eb; margin: 20px 0;'><p style='margin: 0;'>$notif_msg</p></div></div>";

                $chat_msg = "Admin Notice: Reschedule proposed for boarding ($pname). New Dates: $new_start_f to $new_end_f. Reason: $reason.";
                $ins_c = $conn->prepare("INSERT INTO chat_messages(sender_id, receiver_id, message) VALUES((SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1), ?, ?)");
                $ins_c->bind_param("is", $uid, $chat_msg);
                $ins_c->execute();

                $_SESSION['message'] = "Reschedule Proposal sent to client.";
                $_SESSION['msg_type'] = "info";
            }

            $ins_n = $conn->prepare("INSERT INTO notifications(user_id, message) VALUES(?, ?)");
            $ins_n->bind_param("is", $uid, $notif_msg);
            $ins_n->execute();

            $mail->send();
        } catch (Exception $e) {}
    }
    header("Location: admin_boarding.php");
    exit();
}

// --- Clinic Settings / Cage Count Management ---
$conn->query("CREATE TABLE IF NOT EXISTS clinic_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT
)");

$check_count = $conn->query("SELECT setting_value FROM clinic_settings WHERE setting_key = 'boarding_cage_count'");
if ($check_count->num_rows == 0) {
    $conn->query("INSERT INTO clinic_settings (setting_key, setting_value) VALUES ('boarding_cage_count', '5')");
    $cage_count = 5;
} else {
    $cage_count = (int)$check_count->fetch_assoc()['setting_value'];
}

// Handle Add/Remove Cage
if (isset($_POST['action_cage'])) {
    if ($_POST['action_cage'] === 'add') {
        $cage_count++;
    } elseif ($_POST['action_cage'] === 'remove' && $cage_count > 1) {
        $last_cage = $cage_count;
        $check_occ = $conn->query("SELECT id FROM boarding_reservations WHERE cage_number=$last_cage AND status NOT IN ('Completed', 'Cancelled', 'Rejected') AND end_date >= CURDATE()");
        if ($check_occ->num_rows > 0) {
            $_SESSION['message'] = "Cannot remove Cage 0$last_cage. It has active or upcoming reservations.";
            $_SESSION['msg_type'] = "danger";
        } else {
            $cage_count--;
        }
    }
    $conn->query("UPDATE clinic_settings SET setting_value = '$cage_count' WHERE setting_key = 'boarding_cage_count'");
    header("Location: admin_boarding.php");
    exit();
}

// Fetch Pending Boarding Reservations
$pending_apts = $conn->query("
    SELECT a.*, u.fullname as client_name, u.contact_number, u.email 
    FROM boarding_reservations a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.status='Pending' AND (a.service_type='Pet Boarding Reservation' OR a.service_type='Pet Boarding')
    ORDER BY a.appointment_date ASC
");

// Fetch all Cage Reservations
$cages = [];
for ($i = 1; $i <= $cage_count; $i++) {
    $cages[$i] = [];
}

$sql = "SELECT a.*, u.fullname as client_name, u.contact_number, u.email 
        FROM boarding_reservations a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.cage_number IS NOT NULL 
        AND a.status NOT IN ('Completed', 'Cancelled', 'Rejected')
        ORDER BY a.appointment_date ASC";
$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $cNo = (int)$row['cage_number'];
        if (isset($cages[$cNo])) {
            $cages[$cNo][] = $row;
        }
    }
}

// Calculate Executive Metrics
$today_time = strtotime(date("Y-m-d"));
$occupied_cnt = 0;
$reserved_cnt = 0;
$maintenance_cnt = 0;
$available_cnt = 0;

for ($i = 1; $i <= $cage_count; $i++) {
    $has_occ = false;
    $has_res = false;
    $has_maint = false;

    if (!empty($cages[$i])) {
        foreach ($cages[$i] as $b) {
            $s_time = strtotime($b['appointment_date']);
            $e_time = strtotime($b['end_date']);
            
            if ($b['status'] === 'Maintenance') {
                $has_maint = true;
            } elseif ($b['status'] === 'Checked In' || ($b['status'] === 'Approved' && $s_time <= $today_time && $e_time >= $today_time)) {
                $has_occ = true;
            } elseif ($b['status'] === 'Approved' && $s_time > $today_time) {
                $has_res = true;
            }
        }
    }

    if ($has_maint) {
        $maintenance_cnt++;
    } elseif ($has_occ) {
        $occupied_cnt++;
    } elseif ($has_res) {
        $reserved_cnt++;
    } else {
        $available_cnt++;
    }
}

$pending_cnt = $pending_apts ? $pending_apts->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script src="theme.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="theme.css?v=<?= time() ?>">

    <title>Pet Hotel Operations | PragmaVet Admin</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #2563eb;
            --secondary: #475569;
            --dark: #0f172a;
            --light-bg: #f8fafc;
            --sidebar-width: 260px;
        }

        body {
            background: #f8fafc !important;
            font-family: 'Inter', sans-serif;
            color: #334155;
            overflow-x: hidden;
        }

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
            .main-content {
                padding: 20px 20px !important;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 16px !important;
            }
            .sidebar {
                position: relative !important;
                width: 100% !important;
                height: auto !important;
            }
        }

        .custom-card {
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            padding: 24px;
        }

        .cage-card {
            background: white;
            border: 1px solid #eef2f6;
            border-radius: 16px;
            padding: 20px;
            transition: all 0.2s ease;
            height: 100%;
            position: relative;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        }

        .cage-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.06);
            border-color: #cbd5e1;
        }

        .schedule-list {
            max-height: 150px;
            overflow-y: auto;
            margin-top: 15px;
            padding-top: 12px;
            border-top: 1px dashed #e2e8f0;
        }

        .booked-slot {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 8px 12px;
            border-radius: 10px;
            margin-bottom: 8px;
            font-size: 12.5px;
            color: #334155;
            display: flex;
            justify-content: space-between;
        }

        .saas-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 6px;
            display: block;
        }

        /* ALERT */
        .alert-container {
            position: fixed;
            top: 25px;
            right: 35px;
            z-index: 9999;
            animation: slideDown 0.4s ease-out forwards;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
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
        /* ── Audit trail ── */
        .ba-timeline { list-style: none; margin: 0; padding: 0; position: relative; }
        .ba-timeline::before { content: ''; position: absolute; left: 15px; top: 6px; bottom: 6px; width: 2px; background: #e2e8f0; }
        .ba-timeline li { position: relative; display: flex; gap: 12px; padding: 6px 0 10px; }
        .ba-dot { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; position: relative; z-index: 1; border: 3px solid #fff; }
        .ba-body { flex: 1; background: #fff; border: 1px solid #eef2f6; border-radius: 12px; padding: 9px 12px; }
        .ba-top { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; font-size: 13px; color: #0f172a; }
        .ba-top span { color: #94a3b8; font-size: 12px; }
        .ba-details { font-size: 12.5px; color: #475569; margin-top: 2px; word-break: break-word; }
        .ba-actor { font-size: 11.5px; color: #94a3b8; margin-top: 3px; }
        .ba-search { position: relative; min-width: 260px; }
        .ba-search i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; }
        .ba-search input { width: 100%; padding: 9px 14px 9px 36px !important; border-radius: 12px !important; border: 1px solid #e2e8f0 !important; background: #f8fafc !important; font-size: 13.5px; }
        .ba-acc .accordion-item { border: 1px solid #eef2f6 !important; border-radius: 14px !important; margin-bottom: 8px; overflow: hidden; }
        .ba-acc .accordion-button { gap: 12px; background: #fff; box-shadow: none !important; padding: 12px 16px; font-size: 14px; }
        .ba-acc .accordion-button:not(.collapsed) { background: #f8fbff; color: #0f172a; }
        .ba-acc .accordion-body { background: #f8fafc; }
        .ba-pet { width: 36px; height: 36px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        /* View only: hide every editing control on this page */
        body.pv-view-only .main-content form[method="POST"] button, body.pv-view-only .main-content form[method="POST"] input[type="submit"],
        body.pv-view-only [onclick*="openWalkinModal"], body.pv-view-only [onclick*="toggleCctv"],
        body.pv-view-only .modal form[method="POST"] button[type="submit"] { display: none !important; }
    </style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>

<body class="<?= $pv_view_only ? 'pv-view-only' : '' ?>">

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert-container">
            <div class="alert alert-<?php echo $_SESSION['msg_type'] ?? 'info'; ?> alert-dismissible fade show shadow-lg text-dark pe-5"
                style="border-radius: 12px; border: none; font-weight: 500;">
                <?php echo $_SESSION['message'];
                unset($_SESSION['message'], $_SESSION['msg_type']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <?php include("includes/admin_sidebar.php"); ?>

    <div class="main-content">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="badge bg-primary bg-opacity-10 text-primary fw-bold px-3 py-1.5 rounded-pill mb-2" style="font-size: 11px;">
                    <i class="fa fa-hotel me-1"></i> BOARDING MONITORING
                </span>
                <h2 class="fw-bold m-0 text-dark">Boarding & Facility Monitoring</h2>
                <p class="text-muted mb-0">Track pet hotel reservation requests, active cage occupancy, live camera links, and boarding history.</p>
            </div>
            <?php if ($pv_view_only): ?>
            <div class="d-flex align-items-center gap-2">
                <span class="badge rounded-pill px-3 py-2 fw-bold" style="background:#f1f5f9;color:#475569;font-size:12.5px;"><i class="fa fa-eye me-1"></i> View only — managed by vet staff</span>
                <a href="#boardingActivity" class="btn btn-primary px-3 py-2 rounded-pill fw-bold shadow-sm"><i class="fa fa-history me-1"></i> Activity Log</a>
            </div>
            <?php else: ?>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-primary px-3 py-2 rounded-pill fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#walkinBoardingModal">
                    <i class="fa fa-plus me-1"></i> Register Walk-in Pet
                </button>
                <div class="d-flex gap-2">
                    <form method="POST" id="removeCageForm" class="m-0">
                        <input type="hidden" name="action_cage" value="remove">
                        <button type="button"
                            class="btn btn-outline-danger px-3 py-2 fw-bold rounded-pill shadow-sm" title="Remove Last Cage"
                            onclick="pragmaConfirm('Remove last cage?', 'This only works if the cage is currently empty.', 'danger', function(){ document.getElementById('removeCageForm').submit(); })">
                            <i class="fa fa-minus-circle me-1"></i> Remove Cage
                        </button>
                    </form>
                    <form method="POST" class="m-0">
                        <button type="submit" name="action_cage" value="add"
                            class="btn btn-primary px-3 py-2 fw-bold rounded-pill shadow-sm">
                            <i class="fa fa-plus-circle me-1"></i> Add Cage
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Executive Metrics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0">
                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="fa fa-hotel fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Total Boarding Cages</div>
                        <h4 class="fw-bold m-0 text-dark"><?= number_format($cage_count) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0">
                    <div class="rounded-circle bg-danger bg-opacity-10 text-danger p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="fa fa-dog fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Currently Occupied</div>
                        <h4 class="fw-bold m-0 text-dark"><?= number_format($occupied_cnt) ?> <span class="fs-6 text-muted fw-normal">cages</span></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0">
                    <div class="rounded-circle bg-info bg-opacity-10 text-info p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="fa fa-calendar-check fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Upcoming Reserved</div>
                        <h4 class="fw-bold m-0 text-dark"><?= number_format($reserved_cnt) ?> <span class="fs-6 text-muted fw-normal">cages</span></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0">
                    <div class="rounded-circle bg-success bg-opacity-10 text-success p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="fa fa-door-open fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Available Free Cages</div>
                        <h4 class="fw-bold m-0 text-dark"><?= number_format($available_cnt) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Boarding Approval Requests -->
        <?php if ($pending_cnt > 0): ?>
        <div class="custom-card mb-4 border-primary border-opacity-25" style="border-left: 4px solid #2563eb !important;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold m-0 text-dark">
                    <i class="fa fa-clock text-warning me-2"></i>Pending Boarding Requests
                    <span class="badge bg-warning text-dark rounded-pill ms-2"><?= $pending_cnt ?></span>
                </h5>
                <p class="text-muted mb-0 small">Assign a cage and approve online client boarding requests</p>
            </div>
            <div class="table-responsive">
                <table class="table table-borderless align-middle mb-0" style="font-size: 13.5px;">
                    <thead class="table-light">
                        <tr>
                            <th>Pet & Owner</th>
                            <th>Stay Dates</th>
                            <th>Duration</th>
                            <th>Assign Cage</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($p_row = $pending_apts->fetch_assoc()): ?>
                        <?php 
                            $start_t = strtotime($p_row['appointment_date']);
                            $end_t   = strtotime($p_row['end_date']);
                            $days    = max(1, round(($end_t - $start_t) / 86400));
                        ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><i class="fa fa-paw text-primary me-1"></i><?= htmlspecialchars($p_row['pet_name']) ?></div>
                                <div class="small text-muted"><i class="fa fa-user me-1 opacity-50"></i><?= htmlspecialchars($p_row['client_name']) ?> (<?= htmlspecialchars($p_row['contact_number'] ?? 'N/A') ?>)</div>
                            </td>
                            <td>
                                <span class="fw-semibold text-primary"><i class="fa fa-calendar me-1 opacity-50"></i><?= date("M d, Y", $start_t) ?></span>
                                <i class="fa fa-arrow-right mx-1 text-muted" style="font-size: 11px;"></i>
                                <span class="fw-semibold text-primary"><?= date("M d, Y", $end_t) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace px-2.5 py-1"><?= $days ?> night<?= $days > 1 ? 's' : '' ?></span>
                            </td>
                            <td>
                                <form method="POST" id="approveForm_<?= $p_row['id'] ?>" class="m-0 d-inline-flex gap-2">
                                    <input type="hidden" name="apt_id" value="<?= $p_row['id'] ?>">
                                    <input type="hidden" name="boarding_action" value="approve">
                                    <select name="cage_number" class="form-select form-select-sm fw-bold border-primary" style="width: 140px;" required>
                                        <option value="" disabled selected>Select Cage...</option>
                                        <?php for ($c = 1; $c <= $cage_count; $c++): ?>
                                            <option value="<?= $c ?>">Cage 0<?= $c ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </form>
                            </td>
                            <td class="text-end">
                                <button type="submit" form="approveForm_<?= $p_row['id'] ?>" class="btn btn-sm btn-primary rounded-pill px-3 py-1 fw-bold me-1">
                                    <i class="fa fa-check me-1"></i> Approve
                                </button>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="apt_id" value="<?= $p_row['id'] ?>">
                                    <input type="hidden" name="boarding_action" value="reject">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 fw-bold" onclick="pragmaConfirm('Reject boarding request?', 'This will reject and cancel the boarding request for this pet.', 'danger', function(){ this.closest('form').submit(); }.bind(document.activeElement))">
                                        <i class="fa fa-times me-1"></i> Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cages Matrix Grid -->
        <div class="row g-4">
            <?php
            for ($i = 1; $i <= $cage_count; $i++):
                $active_occupied_booking = null;
                $upcoming_booking = null;
                $is_maintenance = false;

                if (!empty($cages[$i])) {
                    foreach ($cages[$i] as $b) {
                        if ($b['status'] === 'Maintenance') {
                            $is_maintenance = true;
                            break;
                        } elseif ($b['status'] === 'Checked In') {
                            $active_occupied_booking = $b;
                            break;
                        } elseif ($b['status'] === 'Approved' && !$upcoming_booking) {
                            $upcoming_booking = $b;
                        }
                    }
                }
            ?>
            <div class="col-xl-4 col-md-6">
                <div class="cage-card">
                    <!-- Cage Header -->
                    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                        <span class="fw-bold fs-5 <?= $is_maintenance ? 'text-danger' : 'text-dark'; ?>">
                            <i class="fa fa-hotel text-primary me-2"></i>Cage 0<?= $i; ?>
                        </span>

                        <!-- Maintenance Toggle Form -->
                        <form method="POST" class="m-0">
                            <input type="hidden" name="cage_number" value="<?= $i; ?>">
                            <input type="hidden" name="is_maintenance" value="<?= $is_maintenance ? 1 : 0; ?>">
                            <?php if ($is_maintenance): ?>
                                <button type="submit" name="toggle_maintenance" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1 fw-bold" style="font-size: 11.5px;">
                                    <i class="fa fa-lock-open me-1"></i> Unlock Cage
                                </button>
                            <?php else: ?>
                                <button type="submit" name="toggle_maintenance" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 fw-bold" style="font-size: 11.5px;" onclick="pragmaConfirm('Lock cage for maintenance?', 'This cage will be unavailable for bookings until unlocked.', 'warning', function(){ document.activeElement.closest('form').submit(); })">
                                    <i class="fa fa-wrench me-1"></i> Maintenance
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Main Status Banner -->
                    <?php if ($is_maintenance): ?>
                        <div class="p-3 bg-warning bg-opacity-10 text-dark border border-warning border-opacity-25 rounded-3 text-center mb-3">
                            <i class="fa fa-wrench text-warning me-2 fs-5"></i>
                            <span class="fw-bold">MAINTENANCE LOCK</span>
                            <div class="small text-muted mt-1">Cage unavailable for bookings</div>
                        </div>
                    <?php elseif ($active_occupied_booking): ?>
                        <div class="p-3 bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-3 mb-3">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <span class="badge bg-danger text-white rounded-pill px-2.5 py-1 fw-bold" style="font-size: 10.5px;">
                                    <i class="fa fa-dog me-1"></i>OCCUPIED (CHECKED-IN)
                                </span>
                                <span class="small font-monospace text-muted">ID: #<?= $active_occupied_booking['id'] ?></span>
                            </div>
                            <h5 class="fw-bold text-dark m-0 mt-2"><i class="fa fa-paw text-primary me-1"></i><?= htmlspecialchars($active_occupied_booking['pet_name']) ?></h5>
                            <div class="small text-muted mb-2"><i class="fa fa-user me-1 opacity-50"></i>Owner: <?= htmlspecialchars($active_occupied_booking['client_name'] ?? 'Walk-In Client') ?></div>
                            <div class="small fw-semibold text-danger">
                                <i class="fa fa-calendar me-1"></i>Stay: <?= date("M d", strtotime($active_occupied_booking['appointment_date'])) ?> âž” <?= date("M d, Y", strtotime($active_occupied_booking['end_date'])) ?>
                            </div>

                            <!-- Controls for Occupied Cage -->
                            <div class="d-flex gap-2 mt-3 flex-wrap">
                                <form method="POST" class="m-0 flex-fill" id="checkoutForm_<?= $active_occupied_booking['id'] ?>">
                                    <input type="hidden" name="apt_id" value="<?= $active_occupied_booking['id'] ?>">
                                    <input type="hidden" name="release_cage" value="1">
                                    <button type="button" class="btn btn-sm btn-danger w-100 fw-bold rounded-pill" onclick="pragmaConfirm('Check-out <?= htmlspecialchars($active_occupied_booking['pet_name']) ?> and release Cage 0<?= $i ?>?', 'This action will complete the reservation.', 'danger', function(){ document.getElementById('checkoutForm_<?= $active_occupied_booking['id'] ?>').submit(); })">
                                        <i class="fa fa-sign-out-alt me-1"></i> Check-Out & Release
                                    </button>
                                </form>
                                <button class="btn btn-sm btn-outline-primary rounded-circle" data-bs-toggle="modal" data-bs-target="#careModal_<?= $active_occupied_booking['id'] ?>" title="Care & Feeding Notes" style="width: 32px; height: 32px; padding: 0;">
                                    <i class="fa fa-notes-medical"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-info rounded-circle" data-bs-toggle="modal" data-bs-target="#cctvModal_<?= $active_occupied_booking['id'] ?>" title="CCTV Camera Feed" style="width: 32px; height: 32px; padding: 0;">
                                    <i class="fa fa-video"></i>
                                </button>
                            </div>
                        </div>
                    <?php elseif ($upcoming_booking): ?>
                        <div class="p-3 bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-3 mb-3">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <span class="badge bg-primary text-white rounded-pill px-2.5 py-1 fw-bold" style="font-size: 10.5px;">
                                    <i class="fa fa-calendar-check me-1"></i>RESERVED (APPROVED)
                                </span>
                                <span class="small font-monospace text-muted">ID: #<?= $upcoming_booking['id'] ?></span>
                            </div>
                            <h5 class="fw-bold text-dark m-0 mt-2"><i class="fa fa-paw text-primary me-1"></i><?= htmlspecialchars($upcoming_booking['pet_name']) ?></h5>
                            <div class="small text-muted mb-2"><i class="fa fa-user me-1 opacity-50"></i>Owner: <?= htmlspecialchars($upcoming_booking['client_name']) ?></div>
                            <div class="small fw-semibold text-primary mb-3">
                                <i class="fa fa-clock me-1"></i>Starts: <?= date("M d, Y", strtotime($upcoming_booking['appointment_date'])) ?>
                            </div>

                            <form method="POST" class="m-0">
                                <input type="hidden" name="apt_id" value="<?= $upcoming_booking['id'] ?>">
                                <button type="submit" name="check_in_pet" class="btn btn-sm btn-primary w-100 fw-bold rounded-pill">
                                    <i class="fa fa-sign-in-alt me-1"></i> Check-In Pet Now
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="p-3 bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-3 text-center mb-3">
                            <i class="fa fa-door-open text-success me-2 fs-5"></i>
                            <span class="fw-bold">AVAILABLE</span>
                            <div class="small text-muted mt-1">Cage is empty & ready for occupancy</div>
                            <button class="btn btn-sm btn-outline-success rounded-pill fw-bold px-3 mt-2" onclick="openWalkinModal(<?= $i ?>)">
                                <i class="fa fa-plus me-1"></i> Assign Walk-In Pet
                            </button>
                        </div>
                    <?php endif; ?>

                    <!-- Cage Schedule Timeline -->
                    <div class="schedule-list">
                        <div class="text-muted small fw-bold mb-2"><i class="fa fa-list-ul me-1 opacity-50"></i>Upcoming Schedule Timeline</div>
                        <?php if (empty($cages[$i])): ?>
                            <div class="text-center py-2 text-muted small"><i class="fa fa-check me-1"></i> No upcoming bookings</div>
                        <?php else: ?>
                            <?php foreach ($cages[$i] as $booking): ?>
                                <div class="booked-slot align-items-center <?= ($booking['status'] === 'Maintenance') ? 'border-warning bg-warning bg-opacity-10' : ''; ?>">
                                    <div>
                                        <i class="fa <?= ($booking['status'] === 'Maintenance') ? 'fa-wrench text-warning' : 'fa-lock text-primary'; ?> me-1"></i>
                                        <?= date("M d", strtotime($booking['appointment_date'])) ?>
                                        <i class="fa fa-arrow-right mx-1 opacity-50" style="font-size: 10px;"></i>
                                        <?= ($booking['status'] === 'Maintenance') ? 'Indefinite' : date("M d", strtotime($booking['end_date'])) ?>
                                    </div>
                                    <div class="fw-bold d-flex align-items-center gap-1">
                                        <span><?= htmlspecialchars($booking['pet_name']) ?></span>
                                        <?php if ($booking['status'] !== 'Maintenance'): ?>
                                            <button class="btn btn-sm btn-light py-0 px-1.5 rounded border" data-bs-toggle="modal" data-bs-target="#bookingDetails_<?= $booking['id']; ?>" title="View Details">
                                                <i class="fa fa-eye text-primary" style="font-size: 11px;"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endfor; ?>
        </div>

        <!-- ── Boarding Activity Log (audit trail for every reservation) ── -->
        <?php
        $ba_list = $conn->query("
            SELECT br.id, br.pet_name, br.status, br.appointment_date, br.end_date, br.cage_number, u.fullname AS owner,
                   GREATEST(br.created_at, COALESCE((SELECT MAX(l.created_at) FROM boarding_audit_log l WHERE l.reservation_id = br.id), br.created_at)) AS last_activity
            FROM boarding_reservations br LEFT JOIN users u ON u.id = br.user_id
            WHERE br.status <> 'Maintenance'
            ORDER BY last_activity DESC LIMIT 60
        ");
        ?>
        <div class="card-premium mt-4" id="boardingActivity" style="background:#fff;border-radius:20px;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.04);">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold m-0"><i class="fa fa-history text-primary me-2"></i> Boarding Activity Log</h5>
                    <div class="text-muted small">Every reservation's history — who did what and when. Newest activity first.</div>
                </div>
                <div class="ba-search"><i class="fa fa-search"></i><input type="text" id="baSearch" placeholder="Search pet, owner, status or #ID…" autocomplete="off"></div>
            </div>
            <div class="accordion ba-acc" id="baAcc">
                <?php if ($ba_list && $ba_list->num_rows > 0): while ($ba = $ba_list->fetch_assoc()):
                    $ev = ba_timeline($conn, $ba['id']); $last = end($ev); ?>
                <div class="accordion-item ba-item" data-search="<?= htmlspecialchars(strtolower('#' . $ba['id'] . ' ' . $ba['pet_name'] . ' ' . ($ba['owner'] ?? '') . ' ' . $ba['status'])) ?>">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#baRow<?= (int)$ba['id'] ?>">
                            <span class="ba-pet"><i class="fa fa-paw"></i></span>
                            <span class="flex-grow-1">
                                <b><?= htmlspecialchars($ba['pet_name']) ?></b> <span class="text-muted small">#<?= (int)$ba['id'] ?> · <?= htmlspecialchars($ba['owner'] ?? 'Walk-in') ?></span><br>
                                <span class="text-muted small"><?= date('M d', strtotime($ba['appointment_date'])) ?> → <?= $ba['end_date'] ? date('M d, Y', strtotime($ba['end_date'])) : '?' ?><?= $ba['cage_number'] ? ' · Cage ' . (int)$ba['cage_number'] : '' ?>
                                <?= $last ? ' · Last: ' . htmlspecialchars($last['action']) . ' (' . date('M d, g:i A', strtotime($last['at'])) . ')' : '' ?></span>
                            </span>
                            <span class="badge rounded-pill bg-light text-dark border me-2"><?= htmlspecialchars($ba['status']) ?></span>
                            <span class="badge rounded-pill bg-primary-subtle text-primary me-2"><?= count($ev) ?> event<?= count($ev) === 1 ? '' : 's' ?></span>
                        </button>
                    </h2>
                    <div id="baRow<?= (int)$ba['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#baAcc">
                        <div class="accordion-body"><?= ba_render_timeline($ev) ?></div>
                    </div>
                </div>
                <?php endwhile; else: ?>
                    <div class="text-muted text-center py-4">No boarding reservations yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modals Generator Loop -->
    <?php for ($i = 1; $i <= $cage_count; $i++): ?>
        <?php if (!empty($cages[$i])): ?>
            <?php foreach ($cages[$i] as $b): ?>
                <?php if ($b['status'] !== 'Maintenance'): ?>
                    <!-- Care Notes Modal -->
                    <div class="modal fade" id="careModal_<?= $b['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                                <div class="modal-header border-0 pb-0">
                                    <h5 class="fw-bold"><i class="fa fa-notes-medical text-primary me-2"></i>Care & Feeding Instructions</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <form method="POST">
                                        <input type="hidden" name="apt_id" value="<?= $b['id'] ?>">
                                        <div class="mb-3 p-3 bg-light rounded-3">
                                            <div class="fw-bold text-dark"><i class="fa fa-paw text-primary me-1"></i><?= htmlspecialchars($b['pet_name']) ?> (Owner: <?= htmlspecialchars($b['client_name'] ?? 'N/A') ?>)</div>
                                            <div class="small text-muted">Cage 0<?= $i ?> | Stay: <?= date("M d, Y", strtotime($b['appointment_date'])) ?> to <?= date("M d, Y", strtotime($b['end_date'])) ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="saas-label">Dietary & Care Notes</label>
                                            <textarea name="care_notes" class="form-control" rows="4" placeholder="E.g. Feed 2x daily with kibble, requires eye drops at 9 AM..."><?= htmlspecialchars($b['care_notes'] ?? '') ?></textarea>
                                        </div>
                                        <button type="submit" name="save_care_notes" class="btn btn-primary w-100 fw-bold rounded-pill shadow-sm">
                                            <i class="fa fa-save me-1"></i> Save Instructions
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CCTV Modal -->
                    <div class="modal fade" id="cctvModal_<?= $b['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                                <div class="modal-header border-0 pb-0">
                                    <h5 class="fw-bold"><i class="fa fa-video text-info me-2"></i>CCTV Feed Binding â€” Cage 0<?= $i ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <form method="POST" class="mb-3">
                                        <input type="hidden" name="apt_id" value="<?= $b['id'] ?>">
                                        <div class="mb-3">
                                            <label class="saas-label">CCTV Camera Stream URL</label>
                                            <input type="url" name="cctv_link" class="form-control" value="<?= htmlspecialchars($b['cctv_link'] ?? '') ?>" placeholder="https://camera-stream.example.com/live/cage<?= $i ?>">
                                        </div>
                                        <button type="submit" name="save_cctv" class="btn btn-info text-white w-100 fw-bold rounded-pill shadow-sm mb-2">
                                            <i class="fa fa-save me-1"></i> Save Camera Stream Link
                                        </button>
                                    </form>

                                    <?php if (!empty($b['cctv_link'])): ?>
                                        <div class="p-3 bg-light rounded-3 text-center">
                                            <div class="fw-bold mb-2">Live CCTV Preview / Toggle</div>
                                            <div class="d-flex justify-content-center gap-2">
                                                <button class="btn btn-sm btn-success rounded-pill px-3" onclick="toggleCctv(<?= $b['id'] ?>, 1)">Turn Stream ON</button>
                                                <button class="btn btn-sm btn-danger rounded-pill px-3" onclick="toggleCctv(<?= $b['id'] ?>, 0)">Turn Stream OFF</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Booking Details Modal (View-Only) -->
                    <div class="modal fade" id="bookingDetails_<?= $b['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">

                                <!-- Gradient Header -->
                                <div class="modal-header border-0 text-white py-3 px-4"
                                     style="background: linear-gradient(135deg, #1e3a8a, #2563eb);">
                                    <div>
                                        <h5 class="fw-bold mb-0">
                                            <i class="fa fa-eye me-2"></i>Reservation Details
                                        </h5>
                                        <small class="opacity-75">
                                            #<?= $b['id'] ?> &mdash; Cage 0<?= $i ?> &mdash; <?= htmlspecialchars($b['status']) ?>
                                        </small>
                                    </div>
                                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                                </div>

                                <div class="modal-body p-4">

                                    <!-- Pet & Owner -->
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <div class="p-3 bg-light rounded-3 border h-100">
                                                <div class="text-uppercase fw-bold mb-2" style="font-size:10px; letter-spacing:.6px; color:#64748b;">
                                                    <i class="fa fa-paw me-1"></i> Pet Info
                                                </div>
                                                <div class="fw-bold text-primary fs-6"><?= htmlspecialchars($b['pet_name']) ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="p-3 bg-light rounded-3 border h-100">
                                                <div class="text-uppercase fw-bold mb-2" style="font-size:10px; letter-spacing:.6px; color:#64748b;">
                                                    <i class="fa fa-user me-1"></i> Owner / Client
                                                </div>
                                                <div class="fw-bold mb-1"><?= htmlspecialchars($b['client_name'] ?? 'Walk-In Client') ?></div>
                                                <div class="small text-muted"><i class="fa fa-phone me-1 opacity-50"></i><?= htmlspecialchars($b['contact_number'] ?? 'â€”') ?></div>
                                                <div class="small text-muted"><i class="fa fa-envelope me-1 opacity-50"></i><?= htmlspecialchars($b['email'] ?? 'â€”') ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Stay Info -->
                                    <div class="row g-2 mb-3">
                                        <div class="col-4">
                                            <div class="p-3 bg-light rounded-3 border text-center">
                                                <div class="text-uppercase fw-bold mb-1" style="font-size:10px; letter-spacing:.6px; color:#64748b;">Cage</div>
                                                <div class="fw-bold text-dark">Cage 0<?= $i ?></div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 bg-light rounded-3 border text-center">
                                                <div class="text-uppercase fw-bold mb-1" style="font-size:10px; letter-spacing:.6px; color:#64748b;">Check-In</div>
                                                <div class="fw-bold text-dark"><?= date("M d, Y", strtotime($b['appointment_date'])) ?></div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 bg-light rounded-3 border text-center">
                                                <div class="text-uppercase fw-bold mb-1" style="font-size:10px; letter-spacing:.6px; color:#64748b;">Check-Out</div>
                                                <div class="fw-bold text-dark"><?= date("M d, Y", strtotime($b['end_date'])) ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Status Badge -->
                                    <div class="mb-3">
                                        <?php
                                        $s = $b['status'];
                                        $sbg = match(true) {
                                            $s === 'Checked In'  => ['#fee2e2','#991b1b','fa-dog'],
                                            $s === 'Approved'    => ['#d1fae5','#065f46','fa-calendar-check'],
                                            $s === 'Completed'   => ['#eff6ff','#1e40af','fa-check-circle'],
                                            $s === 'Cancelled'   => ['#f1f5f9','#475569','fa-times-circle'],
                                            str_contains($s,'Reschedule') => ['#fffbeb','#92400e','fa-calendar-alt'],
                                            default              => ['#f1f5f9','#334155','fa-circle'],
                                        };
                                        ?>
                                        <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill fw-bold"
                                             style="background:<?= $sbg[0] ?>; color:<?= $sbg[1] ?>; font-size:12.5px;">
                                            <i class="fa <?= $sbg[2] ?>"></i>
                                            <?= htmlspecialchars($s) ?>
                                        </div>
                                    </div>

                                    <!-- Care Notes -->
                                    <?php if (!empty($b['care_notes'])): ?>
                                    <div class="p-3 rounded-3 border mb-3" style="background:#fffbeb; border-color:#fde68a !important;">
                                        <div class="fw-bold text-dark mb-2" style="font-size:11.5px; text-transform:uppercase; letter-spacing:.5px;">
                                            <i class="fa fa-notes-medical text-warning me-1"></i> Care &amp; Feeding Instructions
                                        </div>
                                        <div class="small text-dark"><?= nl2br(htmlspecialchars($b['care_notes'])) ?></div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Audit Trail -->
                                    <div class="p-3 rounded-3 border" style="background:#f8fafc;">
                                        <div class="fw-bold text-dark mb-2" style="font-size:11.5px; text-transform:uppercase; letter-spacing:.5px;">
                                            <i class="fa fa-history text-info me-1"></i> Audit Trail
                                        </div>
                                        <?= ba_render_timeline(ba_timeline($conn, $b['id'])) ?>
                                    </div>

                                </div>

                                <!-- Footer -->
                                <div class="modal-footer border-0 px-4 pb-4 pt-0">
                                    <button type="button" class="btn btn-light fw-semibold rounded-pill px-4" data-bs-dismiss="modal">
                                        <i class="fa fa-times me-1 opacity-50"></i> Close
                                    </button>
                                </div>

                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endfor; ?>

    <!-- Walk-in Boarding Modal -->
    <div class="modal fade" id="walkinBoardingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold"><i class="fa fa-plus-circle text-primary me-2"></i>Register Walk-in Boarding Pet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST">
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="saas-label">Client Name</label>
                                <input type="text" name="client_name" class="form-control" required placeholder="Owner's full name">
                            </div>
                            <div class="col-md-6">
                                <label class="saas-label">Pet Name</label>
                                <input type="text" name="pet_name" class="form-control" required placeholder="Pet's name">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="saas-label">Assign Boarding Cage</label>
                            <select name="cage_number" id="walkin_cage_select" class="form-select fw-bold" required>
                                <?php for ($c = 1; $c <= $cage_count; $c++): ?>
                                    <option value="<?= $c ?>">Cage 0<?= $c ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="saas-label">Check-In Date</label>
                                <input type="date" name="appointment_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="saas-label">Check-Out Date</label>
                                <input type="date" name="end_date" class="form-control" required value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="saas-label">Dietary & Care Notes (Optional)</label>
                            <textarea name="care_notes" class="form-control" rows="2" placeholder="E.g. Special kibble diet, twice daily..."></textarea>
                        </div>

                        <button type="submit" name="add_walkin_boarding" class="btn btn-primary w-100 fw-bold py-2.5 rounded-pill shadow-sm">
                            <i class="fa fa-check-circle me-1"></i> Register & Check-In Pet
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openWalkinModal(cageNo) {
            document.getElementById('walkin_cage_select').value = cageNo;
            new bootstrap.Modal(document.getElementById('walkinBoardingModal')).show();
        }

        function toggleCctv(aptId, state) {
            var action = state ? 'turn_on_cctv_ajax' : 'turn_off_cctv_ajax';
            $.post('admin_boarding.php', { [action]: 1, apt_id: aptId }, function() {
                alert('CCTV Stream state updated!');
            });
        }

        // Auto Dismiss Alerts
        setTimeout(function () {
            let alerts = document.querySelectorAll('.alert-container');
            alerts.forEach(a => {
                a.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
                a.style.opacity = '0';
                a.style.transform = 'translateY(-20px)';
                setTimeout(() => a.remove(), 500);
    });
}, 2000);
</script>
    <!-- â”€â”€ PRAGMAVET CUSTOM CONFIRM MODAL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <div class="modal fade" id="pragmaConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
            <div class="modal-content border-0 shadow-lg" style="border-radius:20px; overflow:hidden;">

                <!-- Colored top stripe -->
                <div id="pragmaConfirmStripe" style="height:5px; background:linear-gradient(90deg,#dc2626,#ef4444);"></div>

                <div class="modal-body p-4">
                    <div class="d-flex align-items-start gap-3 mb-4">
                        <!-- Icon -->
                        <div id="pragmaConfirmIconWrap"
                             style="width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;background:rgba(220,38,38,.1);color:#dc2626;">
                            <i id="pragmaConfirmIcon" class="fa fa-exclamation-triangle"></i>
                        </div>
                        <div>
                            <h5 id="pragmaConfirmTitle" class="fw-bold mb-1" style="font-size:16px;color:#1e293b;"></h5>
                            <p id="pragmaConfirmMessage" class="text-muted mb-0" style="font-size:13.5px;line-height:1.55;"></p>
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-light fw-semibold px-4 rounded-pill" data-bs-dismiss="modal"
                                style="font-size:13.5px;border:1px solid #e2e8f0;">
                            <i class="fa fa-times me-1 opacity-50"></i> Cancel
                        </button>
                        <button type="button" id="pragmaConfirmOkBtn" class="btn fw-bold px-4 rounded-pill"
                                style="font-size:13.5px;">
                            <i id="pragmaConfirmOkIcon" class="fa fa-check me-1"></i>
                            <span id="pragmaConfirmOkLabel">Confirm</span>
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
    /* â”€â”€ pragmaConfirm() â€” reusable custom confirm dialog â”€â”€
       type: 'danger' | 'warning' | 'info'
    */
    (function() {
        var _callback = null;
        var _modal    = null;

        var palettes = {
            danger:  { stripe:'linear-gradient(90deg,#dc2626,#ef4444)', iconBg:'rgba(220,38,38,.1)',  iconColor:'#dc2626', btnClass:'btn-danger',  icon:'fa-exclamation-triangle', label:'Yes, Confirm' },
            warning: { stripe:'linear-gradient(90deg,#d97706,#f59e0b)', iconBg:'rgba(217,119,6,.1)',  iconColor:'#d97706', btnClass:'btn-warning',  icon:'fa-exclamation-circle',  label:'Yes, Proceed'  },
            info:    { stripe:'linear-gradient(90deg,#2563eb,#3b82f6)', iconBg:'rgba(37,99,235,.1)',  iconColor:'#2563eb', btnClass:'btn-primary',  icon:'fa-info-circle',          label:'OK'            },
        };

        window.pragmaConfirm = function(title, message, type, callback) {
            var p = palettes[type] || palettes.danger;

            document.getElementById('pragmaConfirmStripe').style.background    = p.stripe;
            document.getElementById('pragmaConfirmIconWrap').style.background  = p.iconBg;
            document.getElementById('pragmaConfirmIconWrap').style.color       = p.iconColor;
            document.getElementById('pragmaConfirmIcon').className             = 'fa ' + p.icon;
            document.getElementById('pragmaConfirmTitle').textContent          = title;
            document.getElementById('pragmaConfirmMessage').innerHTML          = message;
            document.getElementById('pragmaConfirmOkLabel').textContent        = p.label;

            var btn = document.getElementById('pragmaConfirmOkBtn');
            btn.className = 'btn fw-bold px-4 rounded-pill ' + p.btnClass;

            _callback = callback;

            if (!_modal) {
                _modal = new bootstrap.Modal(document.getElementById('pragmaConfirmModal'));
            }
            _modal.show();
        };

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('pragmaConfirmOkBtn').addEventListener('click', function() {
                if (_modal) _modal.hide();
                if (typeof _callback === 'function') {
                    setTimeout(_callback, 200); // slight delay so modal closes first
                }
            });
        });
    })();
    </script>

<script>
// Activity log search
document.getElementById('baSearch') && document.getElementById('baSearch').addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.ba-item').forEach(it => { it.style.display = !q || it.dataset.search.includes(q) ? '' : 'none'; });
});
<?php if ($pv_view_only): ?>
// View only: fields can be read but not changed (the server also refuses changes)
document.querySelectorAll('form[method="POST"] input:not([type="hidden"]), form[method="POST"] textarea, form[method="POST"] select').forEach(el => {
    if (el.tagName === 'SELECT') el.disabled = true; else el.readOnly = true;
});
document.querySelectorAll('form[method="POST"]').forEach(f => f.addEventListener('submit', e => e.preventDefault()));
<?php endif; ?>
</script>
</body>

</html>
