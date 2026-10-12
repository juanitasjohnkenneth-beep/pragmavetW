<?php
session_start();
include_once('assets/saas_calendar_component.php');
include("dbconnection.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// PHPMailer Setup
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// Handle Action (Advanced Manage Events)
if (isset($_POST['admin_manage_appt']) && isset($_POST['apt_id'])) {
    $apt_id = $_POST['apt_id'];
    $cmd = $_POST['admin_manage_appt'];

    // Fetch details for email
    $apt_q = $conn->query("SELECT a.user_id, a.pet_name, a.appointment_date, u.email, u.fullname FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.id=$apt_id");
    if ($apt_q && $apt_q->num_rows > 0) {
        $apt_data = $apt_q->fetch_assoc();
        $uid = $apt_data['user_id'];
        $pname = $apt_data['pet_name'];
        $email = $apt_data['email'];
        $fullname = $apt_data['fullname'];
        $old_date = date("M d, Y", strtotime($apt_data['appointment_date']));

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'timferrer1@gmail.com';
            $mail->Password = 'azrpmoxxzvhucetu';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->setFrom('timferrer1@gmail.com', 'PragmaVet Clinic');
            $mail->addAddress($email, $fullname);
            $mail->isHTML(true);

            if ($cmd === 'cancel') {
                $reason = $_POST['admin_message'] ?? 'No reason provided limit.';
                $stmt = $conn->prepare("UPDATE appointments SET status='Cancelled', admin_message=? WHERE id=?");
                $stmt->bind_param("si", $reason, $apt_id);
                $stmt->execute();

                $notif_msg = "Your confirmed appointment for $pname on $old_date was Cancelled. Reason: $reason";
                $mail->Subject = 'Critical Update: Appointment Cancelled';
                $mail->Body = "<div style='font-family: Arial, sans-serif; max-width: 600px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 10px;'><h2 style='color: #2563eb;'>Hello, $fullname!</h2><p style='color: #444; font-size: 15px;'>This is an urgent update regarding your veterinary appointment.</p><div style='background: #fdf5f5; padding: 20px; border-radius: 8px; border-left: 4px solid #c92a2a; margin: 20px 0;'><p style='margin: 0;'>$notif_msg</p></div><p style='color: #777; font-size: 13px;'>If you have any questions, please contact the clinic.</p></div>";

                // Add automated chat message
                $chat_msg = "Admin Notice: Your appointment for $pname on $old_date was Cancelled. Reason: $reason";
                $ins_c = $conn->prepare("INSERT INTO chat_messages(sender_id, receiver_id, message) VALUES((SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1), ?, ?)");
                $ins_c->bind_param("is", $uid, $chat_msg);
                $ins_c->execute();

                $_SESSION['message'] = "Appointment safely cancelled.";

            } elseif ($cmd === 'reschedule') {
                $datetime_parts = explode(" ", $_POST['new_date']);
                $new_date_raw = $datetime_parts[0];
                $new_time_raw = isset($datetime_parts[1]) ? date("H:i", strtotime($datetime_parts[1])) : '08:00';

                $reason = $_POST['admin_message'] ?? 'We proposed shifting your appointment date.';
                $new_date_f = date("M d, Y", strtotime($new_date_raw)) . " at " . date("h:i A", strtotime($new_time_raw));

                $stmt = $conn->prepare("UPDATE appointments SET status='Reschedule Proposed', proposed_reschedule_date=?, proposed_reschedule_time=?, admin_message=?, client_action_needed=1 WHERE id=?");
                $stmt->bind_param("sssi", $new_date_raw, $new_time_raw, $reason, $apt_id);
                $stmt->execute();

                $notif_msg = "The clinic proposed shifting your appointment for $pname. Old Date: $old_date -> New Date & Time: <b>$new_date_f</b>. Reason: $reason. <a href='appointments.php' class='text-primary fw-bold text-decoration-underline'>Click here to Review & Accept.</a>";
                $mail->Subject = 'Appointment Reschedule Proposal | PragmaVet';
                $mail->Body = "<div style='font-family: Arial, sans-serif; max-width: 600px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 10px;'><h2 style='color: #2563eb;'>Hello, $fullname!</h2><p style='color: #444; font-size: 15px;'>We need to reschedule your veterinary appointment.</p><div style='background: #E6EEF8; padding: 20px; border-radius: 8px; border-left: 4px solid #3b82f6; margin: 20px 0;'><p style='margin: 0;'>$notif_msg</p></div><p style='color: #777; font-size: 13px;'>Log into the platform to officially Accept or Decline.</p></div>";

                // Automated chat message
                $chat_msg = "Admin Notice: Reschedule proposed for $pname. New Date: $new_date_f. Reason: $reason. <br><br><a href=\"appointments.php\" class=\"btn btn-sm btn-primary text-white text-decoration-none px-3 py-1 rounded\">Review Proposal</a>";
                $ins_c = $conn->prepare("INSERT INTO chat_messages(sender_id, receiver_id, message) VALUES((SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1), ?, ?)");
                $ins_c->bind_param("is", $uid, $chat_msg);
                $ins_c->execute();

                $_SESSION['message'] = "Reschedule Proposal sent to client.";
            } elseif ($cmd === 'complete') {
                $stmt = $conn->prepare("UPDATE appointments SET status='Completed' WHERE id=?");
                $stmt->bind_param("i", $apt_id);
                $stmt->execute();

                $notif_msg = "Your veterinary appointment for $pname on $old_date has been marked as Completed. Thank you for visiting PragmaVet!";
                $mail->Subject = 'Appointment Completed | PragmaVet';
                $mail->Body = "<div style='font-family: Arial, sans-serif; max-width: 600px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 10px;'><h2 style='color: #2563eb;'>Hello, $fullname!</h2><p style='color: #444; font-size: 15px;'>This is a formal update regarding your veterinary visit.</p><div style='background: #e6fcf5; padding: 20px; border-radius: 8px; border-left: 4px solid #38A169; margin: 20px 0;'><p style='margin: 0;'>$notif_msg</p></div><p style='color: #777; font-size: 13px;'>If you need anything else, please contact the clinic.</p></div>";
                $_SESSION['message'] = "Appointment securely marked as Completed.";
            } elseif ($cmd === 'accept_client_resched') {
                $stmt = $conn->prepare("UPDATE appointments SET appointment_date=proposed_reschedule_date, appointment_time=proposed_reschedule_time, status='Approved', proposed_reschedule_date=NULL, proposed_reschedule_time=NULL, client_action_needed=0 WHERE id=?");
                $stmt->bind_param("i", $apt_id);
                $stmt->execute();

                $notif_msg = "Clinic accepted your reschedule request for $pname. New schedule confirmed.";
                $mail->Subject = 'Reschedule Request Accepted | PragmaVet';
                $mail->Body = "<div style='font-family: Arial, sans-serif; max-width: 600px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 10px;'><h2 style='color: #2563eb;'>Hello, $fullname!</h2><p style='color: #444; font-size: 15px;'>We have accepted your request to shift your appointment schedule.</p><div style='background: #e6fcf5; padding: 20px; border-radius: 8px; border-left: 4px solid #38A169; margin: 20px 0;'><p style='margin: 0;'>$notif_msg</p></div></div>";
                $_SESSION['message'] = "Client reschedule accepted!";
            } elseif ($cmd === 'decline_client_resched') {
                $stmt = $conn->prepare("UPDATE appointments SET status='Approved', proposed_reschedule_date=NULL, proposed_reschedule_time=NULL, client_action_needed=0 WHERE id=?");
                $stmt->bind_param("i", $apt_id);
                $stmt->execute();

                $notif_msg = "Clinic declined your reschedule request for $pname. Appointment remains at original date.";
                $mail->Subject = 'Reschedule Request Declined | PragmaVet';
                $mail->Body = "<div style='font-family: Arial, sans-serif; max-width: 600px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 10px;'><h2 style='color: #2563eb;'>Hello, $fullname!</h2><p style='color: #444; font-size: 15px;'>We could not accommodate your reschedule request at this time.</p><div style='background: #fdf5f5; padding: 20px; border-radius: 8px; border-left: 4px solid #c92a2a; margin: 20px 0;'><p style='margin: 0;'>$notif_msg</p></div></div>";
                $_SESSION['message'] = "Client reschedule declined.";
            }

            // Notification table insert
            $ins_n = $conn->prepare("INSERT INTO notifications(user_id, message) VALUES(?, ?)");
            $ins_n->bind_param("is", $uid, $notif_msg);
            $ins_n->execute();

            $mail->send();
        } catch (Exception $e) {
        }
    }
    $_SESSION['msg_type'] = "success";
    header("Location: admin.php");
    exit();
}

// Handle Action
if (isset($_POST['action']) && isset($_POST['apt_id'])) {
    $apt_id = $_POST['apt_id'];
    $action = $_POST['action'];
    $new_status = ($action === 'approve') ? 'Approved' : 'Rejected';

    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE appointments SET status=?, approved_at=NOW() WHERE id=?");
    } else {
        $stmt = $conn->prepare("UPDATE appointments SET status=? WHERE id=?");
    }
    $stmt->bind_param("si", $new_status, $apt_id);
    if ($stmt->execute()) {
        // Fetch appointment details to notify client
        $apt_q = $conn->query("SELECT a.user_id, a.pet_name, a.appointment_date, u.email, u.fullname FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.id=$apt_id");
        if ($apt_q && $apt_q->num_rows > 0) {
            $apt_data = $apt_q->fetch_assoc();
            $uid = $apt_data['user_id'];
            $pname = $apt_data['pet_name'];
            $adate = date("M d, Y", strtotime($apt_data['appointment_date']));
            $email = $apt_data['email'];
            $fullname = $apt_data['fullname'];
            $notif_msg = "Your appointment for $pname on $adate has been <b>$new_status</b>.";

            $ins_n = $conn->prepare("INSERT INTO notifications(user_id, message) VALUES(?, ?)");
            $ins_n->bind_param("is", $uid, $notif_msg);
            $ins_n->execute();

            // Dispatch Automailer
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'timferrer1@gmail.com';
                $mail->Password = 'azrpmoxxzvhucetu';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->setFrom('timferrer1@gmail.com', 'PragmaVet Clinic');
                $mail->addAddress($email, $fullname);
                $mail->isHTML(true);
                $mail->Subject = 'Appointment Update | PragmaVet';

                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 10px;'>
                        <h2 style='color: #2563eb;'>Hello, $fullname!</h2>
                        <p style='color: #444; font-size: 15px;'>This is a formal update regarding your requested veterinary service.</p>
                        <div style='background: #E6EEF8; padding: 20px; border-radius: 8px; border-left: 4px solid #3b82f6; margin: 20px 0;'>
                            <p style='margin: 0;'>$notif_msg</p>
                        </div>
                        <p style='color: #777; font-size: 13px;'>If you have any questions, please contact the clinic.</p>
                    </div>";
                $mail->send();
            } catch (Exception $e) {
            }
        }

        $_SESSION['message'] = "Appointment #$apt_id successfully marked as $new_status.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin.php");
    exit();
}

$pending_query = "SELECT a.*, u.fullname as client_name FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.status='Pending' OR a.status='Reschedule Requested' ORDER BY a.appointment_date ASC, a.appointment_time ASC";
$pending_apts = $conn->query($pending_query);

$all_approved = $conn->query("SELECT a.*, u.fullname as client_name FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.status='Approved' OR a.status='Completed' OR a.status='Pending'");
$all_leaves = $conn->query("SELECT dl.*, u.fullname AS doctor_name FROM doctor_unavailability dl JOIN users u ON dl.doctor_id = u.id");

// Metrics queries
$users_count_q = $conn->query("SELECT COUNT(id) AS cnt FROM users WHERE role='user' OR role='client'");
$users_count = $users_count_q ? $users_count_q->fetch_assoc()['cnt'] : 0;

$pets_count_q = $conn->query("SELECT COUNT(id) AS cnt FROM pets");
$pets_count = $pets_count_q ? $pets_count_q->fetch_assoc()['cnt'] : 0;

$today = date('Y-m-d');
$appts_count_q = $conn->query("SELECT COUNT(id) AS cnt FROM appointments WHERE appointment_date >= '$today' AND status='Approved'");
$appts_count = $appts_count_q ? $appts_count_q->fetch_assoc()['cnt'] : 0;

$pending_count_q = $conn->query("SELECT COUNT(id) AS cnt FROM appointments WHERE status='Pending' OR status='Reschedule Requested'");
$pending_count = $pending_count_q ? $pending_count_q->fetch_assoc()['cnt'] : 0;

$doctors_count_q = $conn->query("SELECT COUNT(id) AS cnt FROM users WHERE role='doctor'");
$doctors_count = $doctors_count_q ? $doctors_count_q->fetch_assoc()['cnt'] : 0;
$total_revenue = $conn->query("SELECT IFNULL(SUM(total_amount), 0) as r FROM billing")->fetch_assoc()['r'];

$medical_staff = $conn->query("SELECT id, fullname, email FROM users WHERE role='doctor' ORDER BY fullname ASC");

// Mini stat counts for dashboard cards
$noshow_count    = (int)($conn->query("SELECT COUNT(*) as c FROM appointments WHERE status='No Show'")->fetch_assoc()['c'] ?? 0);
$checkin_count   = (int)($conn->query("SELECT COUNT(*) as c FROM appointments WHERE status IN ('Checked In','In Progress')")->fetch_assoc()['c'] ?? 0);
$completed_count = (int)($conn->query("SELECT COUNT(*) as c FROM appointments WHERE status='Completed'")->fetch_assoc()['c'] ?? 0);
$today_count     = (int)($conn->query("SELECT COUNT(*) as c FROM appointments WHERE appointment_date='" . date('Y-m-d') . "' AND status IN ('Approved','Checked In','In Progress')")->fetch_assoc()['c'] ?? 0);

// Hybrid Analytics Queries
$collected_rev = (float)($conn->query("SELECT IFNULL(SUM(CASE WHEN payment_status='Paid' THEN total_amount ELSE downpayment_amount END), 0) as r FROM billing")->fetch_assoc()['r'] ?? 0);

// Cage Occupancy
$occ_count = (int)($conn->query("SELECT COUNT(DISTINCT cage_number) as occ FROM boarding_reservations WHERE status='Approved' AND appointment_date <= CURDATE() AND end_date >= CURDATE()")->fetch_assoc()['occ'] ?? 0);
$total_cages_q = $conn->query("SELECT setting_value FROM clinic_settings WHERE setting_key = 'boarding_cage_count'");
$total_cages = ($total_cages_q && $total_cages_q->num_rows > 0) ? (int)$total_cages_q->fetch_assoc()['setting_value'] : 5;

// Prescriptive Alerts Counts
$pending_boarding_cnt = (int)($conn->query("SELECT COUNT(*) as c FROM boarding_reservations WHERE status IN ('Pending', 'Reschedule Proposed')")->fetch_assoc()['c'] ?? 0);
$pending_appts_cnt    = (int)($conn->query("SELECT COUNT(*) as c FROM appointments WHERE status IN ('Reschedule Requested', 'Reschedule Proposed')")->fetch_assoc()['c'] ?? 0);
$due_vaccines_cnt     = (int)($conn->query("SELECT COUNT(*) as c FROM pets WHERE next_vaccination IS NOT NULL AND next_vaccination <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'] ?? 0);

// Chart 1: Monthly Revenue Trend
$m_q = $conn->query("SELECT 
    DATE_FORMAT(created_at, '%b %Y') as m_label,
    SUM(CASE WHEN is_boarding = 0 THEN total_amount ELSE 0 END) as clinic_rev,
    SUM(CASE WHEN is_boarding = 1 THEN total_amount ELSE 0 END) as boarding_rev
FROM billing
GROUP BY DATE_FORMAT(created_at, '%Y-%m')
ORDER BY created_at ASC LIMIT 6");

$chart_m_labels = [];
$chart_clinic_rev = [];
$chart_boarding_rev = [];

if ($m_q && $m_q->num_rows > 0) {
    while ($row = $m_q->fetch_assoc()) {
        $chart_m_labels[] = $row['m_label'];
        $chart_clinic_rev[] = (float)$row['clinic_rev'];
        $chart_boarding_rev[] = (float)$row['boarding_rev'];
    }
} else {
    $chart_m_labels = ['May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026'];
    $chart_clinic_rev = [12000, 15500, 18000, 22000, 26000];
    $chart_boarding_rev = [4000, 6500, 8000, 9500, 12000];
}

// Chart 2: Service Distribution
$svc_q = $conn->query("SELECT service_type, COUNT(*) as cnt FROM appointments GROUP BY service_type ORDER BY cnt DESC LIMIT 5");
$chart_svc_labels = [];
$chart_svc_data = [];

if ($svc_q && $svc_q->num_rows > 0) {
    while ($row = $svc_q->fetch_assoc()) {
        $chart_svc_labels[] = $row['service_type'] ?: 'General Visit';
        $chart_svc_data[] = (int)$row['cnt'];
    }
} else {
    $chart_svc_labels = ['Consultation', 'Vaccination', 'Pet Boarding', 'Surgery', 'Grooming'];
    $chart_svc_data = [42, 28, 19, 12, 8];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script>
        window.onerror = function (message, source, lineno, colno, error) {
            if (message.indexOf("Script error") > -1) return;
            const errData = encodeURIComponent(message + " | line: " + lineno + " | col: " + colno);
            const img = new Image();
            img.src = 'log_js_error.php?err=' + errData;
        };
    </script>
    <script src="theme.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="theme.css?v=<?= time() ?>">

    <title>Admin Dashboard | PragmaVet</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" type="text/css" href="https://npmcdn.com/flatpickr/dist/themes/material_blue.css">

    <style>
        :root {
            --primary: #2563eb;
            --secondary: #3b82f6;
            --dark: #1e40af;
            --light-bg: #E6EEF8;
            --sidebar-width: 260px;
        }

        body {
            background: var(--light-bg);
            font-family: 'Inter', sans-serif;
            color: #2D3748;
        }

        .sidebar {
            background: #0f172a;
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            padding: 24px 16px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 5px 0 20px rgba(0, 0, 0, .2);
            overflow-y: auto;
        }

        .sidebar h4 {
            color: #ffffff;
            font-weight: 800;
            font-size: 22px;
            margin-bottom: 30px;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 8px;
            color: #94a3b8;
            text-decoration: none;
            margin-bottom: 4px;
            font-weight: 600;
            font-size: 15px;
            transition: .2s;
        }

        .sidebar a i {
            width: 25px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #2563eb;
            color: #ffffff;
        }

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

        .card-premium {
            background: white;
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 25px;
            margin-bottom: 25px;
        }

        .table-custom thead th {
            background: #F7FAFC;
            color: #4A5568;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #E2E8F0;
        }

        /* FullCalendar Customizations */
        .fc-toolbar-title {
            font-weight: 700 !important;
            color: var(--primary) !important;
            font-size: 1.5rem !important;
        }

        .fc-button-primary {
            background-color: var(--secondary) !important;
            border-color: var(--secondary) !important;
        }

        .fc-event {
            border-radius: 4px;
            border: none;
            padding: 3px 5px;
            font-weight: 500;
            font-size: 13px;
            cursor: pointer;
        }

        .fc-day-today {
            background: rgba(43, 108, 176, 0.05) !important;
        }

        /* Dashboard Liveliness */
        @keyframes slideUpEntrance {
            0% {
                opacity: 0;
                transform: translateY(30px);
            }

            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-premium {
            animation: slideUpEntrance 0.7s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
            opacity: 0;
        }

        .stat-card {
            border: none;
            border-radius: 15px;
            padding: 25px;
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            overflow: hidden;
            position: relative;
            cursor: pointer;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transition: transform 0.5s ease;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
        }

        .stat-card:hover::after {
            transform: scale(1.2);
        }

        .bg-gradient-1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .bg-gradient-2 {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .bg-gradient-3 {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        .bg-gradient-4 {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }

        .stat-card i {
            font-size: 2.5rem;
            opacity: 0.8;
        }

        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 10px 0 0 0;
        }

        .stat-card p {
            font-size: 0.95rem;
            font-weight: 600;
            opacity: 0.9;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ALERT */
        .alert-container {
            position: fixed;
            top: 30px;
            right: 40px;
            z-index: 9999;
            animation: slideDown 0.5s ease-out forwards;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .list-group-item {
            transition: all 0.3s ease;
            border-left: 4px solid #ffeba0 !important;
        }

        .list-group-item:hover {
            transform: translateX(8px);
            border-left-color: var(--secondary) !important;
            background-color: #fff !important;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.06);
        }

        .sidebar::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
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

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert-container">
            <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show shadow-lg text-dark pe-5"
                style="border-radius: 12px; border: none; font-weight: 500;">
                <?php echo $_SESSION['message'];
                unset($_SESSION['message'], $_SESSION['msg_type']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <div class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon"><i class="fa fa-paw"></i></div>
        <span>PragmaVet</span>
    </div>
    <div class="sidebar-user">
        <div class="welcome">Welcome back,</div>
        <div class="uname"><?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Clinic Admin'); ?></div>
    </div>
    <a href="admin.php" class="active"><i class="fa fa-chart-line"></i> <span>Dashboard</span> </a>
    <a href="admin_list.php" class=""><i class="fa fa-calendar-check"></i> <span>Appointments</span> </a>
    <a href="admin_users.php" class=""><i class="fa fa-users"></i> <span>Root Users</span> </a>
    <a href="admin_inventory.php" class=""><i class="fa fa-boxes"></i> <span>Inventory</span> </a>
    <a href="admin_billing.php" class=""><i class="fa fa-file-invoice-dollar"></i> <span>Billing & Payment</span> </a>
    <a href="admin_boarding.php" class=""><i class="fa fa-hotel"></i> <span>Pet Hotel Ops</span> </a>
    <a href="admin_announcements.php" class=""><i class="fa fa-bullhorn"></i> <span>Announcements</span> </a>
    <a href="admin_messages.php" class=""><i class="fa fa-comments"></i> <span>Direct Chat</span> </a>
    <a href="admin_certificates.php" class=""><i class="fa fa-file-contract"></i> <span>Cert Requests</span> </a>
    <a href="admin_emails.php" class=""><i class="fa fa-envelope"></i> <span>Email Dispatch</span> </a>
    <a href="admin_reports.php" class=""><i class="fa fa-chart-pie"></i> <span>Reports & Alerts</span> </a>
    <a href="admin_history.php" class=""><i class="fa fa-archive"></i> <span>Archive</span> </a>
    <div class="sidebar-logout">
        <a href="logout.php"><i class="fa fa-sign-out-alt"></i> <span>Logout</span></a>
    </div>
</div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold m-0" style="color: #0f172a; font-size: 26px; letter-spacing: -0.4px;">Executive Analytics & Schedule Dashboard</h2>
                <p class="text-muted mb-0" style="font-size: 14px;">Real-time clinic financial metrics, prescriptive care alerts, and dynamic schedule management</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2 rounded-pill fw-bold" style="font-size: 12px;">
                    <i class="fa fa-chart-line me-1"></i> Hybrid Analytics Active
                </span>
                <button class="btn btn-sm btn-outline-secondary rounded-circle shadow-sm" id="themeToggle"
                    style="width: 40px; height: 40px; z-index: 100;">
                    <i class="fa fa-moon" id="themeIcon"></i>
                </button>
            </div>
        </div>

        <!-- ── 1. EXECUTIVE KPI STAT CARDS (Descriptive Analytics) ── -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="p-3 bg-white rounded-4 border shadow-sm d-flex align-items-center gap-3" style="border-color: #e2e8f0 !important;">
                    <div style="width: 48px; height: 48px; border-radius: 14px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                        <i class="fa fa-coins"></i>
                    </div>
                    <div>
                        <div class="text-muted fw-semibold" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Total Billed Revenue</div>
                        <div class="fw-bold text-dark" style="font-size: 18px; line-height: 1.2;">₱<?= number_format($total_revenue, 2) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="p-3 bg-white rounded-4 border shadow-sm d-flex align-items-center gap-3" style="border-color: #e2e8f0 !important;">
                    <div style="width: 48px; height: 48px; border-radius: 14px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                        <i class="fa fa-wallet"></i>
                    </div>
                    <div>
                        <div class="text-muted fw-semibold" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Collected Revenue</div>
                        <div class="fw-bold text-dark" style="font-size: 18px; line-height: 1.2;">₱<?= number_format($collected_rev, 2) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="p-3 bg-white rounded-4 border shadow-sm d-flex align-items-center gap-3" style="border-color: #e2e8f0 !important;">
                    <div style="width: 48px; height: 48px; border-radius: 14px; background: #f3e8ff; color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                        <i class="fa fa-hotel"></i>
                    </div>
                    <div>
                        <div class="text-muted fw-semibold" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Boarding Occupancy</div>
                        <div class="fw-bold text-dark" style="font-size: 18px; line-height: 1.2;"><?= $occ_count ?> / <?= $total_cages ?> Cages</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="p-3 bg-white rounded-4 border shadow-sm d-flex align-items-center gap-3" style="border-color: #e2e8f0 !important;">
                    <div style="width: 48px; height: 48px; border-radius: 14px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                        <i class="fa fa-syringe"></i>
                    </div>
                    <div>
                        <div class="text-muted fw-semibold" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Booster Shots Due</div>
                        <div class="fw-bold text-dark" style="font-size: 18px; line-height: 1.2;"><?= $due_vaccines_cnt ?> Pets Due</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 2. PRESCRIPTIVE ACTION CARDS (Smart Action Alerts) ── -->
        <?php if ($pending_appts_cnt > 0 || $due_vaccines_cnt > 0 || $pending_boarding_cnt > 0): ?>
        <div class="row g-3 mb-4">
            <?php if ($pending_appts_cnt > 0): ?>
                <div class="col-md-6">
                    <div class="p-3 rounded-4 border d-flex align-items-center justify-content-between" style="background: #faf5ff; border-color: #e9d5ff !important;">
                        <div class="d-flex align-items-center gap-3">
                            <i class="fa fa-clock-rotate-left text-purple fs-4" style="color: #7c3aed;"></i>
                            <div>
                                <div class="fw-bold text-dark" style="font-size: 14px;"><?= $pending_appts_cnt ?> Reschedule Proposal(s) Awaiting Action</div>
                                <div class="text-muted small" style="font-size: 12px;">Pet owners requested schedule shifts</div>
                            </div>
                        </div>
                        <a href="admin_list.php" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" style="background:#7c3aed; border:none; font-size: 12px;">Review Requests</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($due_vaccines_cnt > 0): ?>
                <div class="col-md-6">
                    <div class="p-3 rounded-4 border d-flex align-items-center justify-content-between" style="background: #fffbeb; border-color: #fde68a !important;">
                        <div class="d-flex align-items-center gap-3">
                            <i class="fa fa-bell text-warning fs-4"></i>
                            <div>
                                <div class="fw-bold text-dark" style="font-size: 14px;"><?= $due_vaccines_cnt ?> Vaccine Booster Reminder(s) Needed</div>
                                <div class="text-muted small" style="font-size: 12px;">Registered pets due for immunization within 7 days</div>
                            </div>
                        </div>
                        <a href="admin_reports.php" class="btn btn-sm btn-warning text-dark rounded-pill px-3 fw-bold" style="font-size: 12px;">Send Recall Notices</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── 3. HYBRID ANALYTICS CHARTS ── -->
        <div class="row g-4 mb-4">
            <!-- Monthly Revenue Line Chart -->
            <div class="col-xl-7">
                <div class="bg-white rounded-4 border p-4 shadow-sm" style="border-color: #eef2f6 !important;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="fw-bold text-dark m-0" style="font-size: 16px;"><i class="fa fa-chart-line text-primary me-2"></i> Monthly Revenue Trend</h6>
                            <span class="text-muted small" style="font-size: 12px;">Comparison: Clinic Visits vs. Pet Hotel Stays</span>
                        </div>
                        <span class="badge bg-light text-muted border px-2.5 py-1 rounded-pill" style="font-size: 11px;">6-Month Overview</span>
                    </div>
                    <div style="height: 230px; position: relative;">
                        <canvas id="monthlyRevChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Service Distribution Doughnut Chart -->
            <div class="col-xl-5">
                <div class="bg-white rounded-4 border p-4 shadow-sm" style="border-color: #eef2f6 !important;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="fw-bold text-dark m-0" style="font-size: 16px;"><i class="fa fa-chart-pie text-success me-2"></i> Service Breakdown</h6>
                            <span class="text-muted small" style="font-size: 12px;">Appointment volume by category</span>
                        </div>
                    </div>
                    <div style="height: 230px; position: relative;">
                        <canvas id="serviceBreakdownChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Appointment Stat Cards ───────────────────────────────────────────── -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;">
            <?php
            $mini_stats = [
                ["Today's Appts", 'fa-calendar-day', '#2563eb', '#eff6ff', $today_count],
                ['Active Now',    'fa-spinner',       '#0e7490', '#ecfeff', $checkin_count],
                ['Completed',     'fa-check-double',  '#15803d', '#f0fdf4', $completed_count],
                ['No Shows',      'fa-user-slash',    '#be123c', '#fff1f2', $noshow_count],
            ];
            foreach ($mini_stats as $ms): ?>
            <div style="background:#fff;border-radius:14px;padding:16px 18px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.04);display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:12px;background:<?=$ms[3]?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa <?=$ms[1]?>" style="color:<?=$ms[2]?>;font-size:17px;"></i>
                </div>
                <div>
                    <div style="font-size:22px;font-weight:800;color:#0f172a;line-height:1;"><?=(int)$ms[4]?></div>
                    <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:3px;"><?=htmlspecialchars($ms[0])?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- ── / Appointment Stat Cards ────────────────────────────────────────── -->

        <div class="row layout-saas">
            <div class="col-xl-12">
                <div class="card-premium p-0"
                    style="background: transparent; box-shadow: none; border: none; height: calc(100vh - 120px); min-height: 750px;">
                    <?php
                    // Fetch ALL appointments for the calendar (all statuses)
                    $calendar_events = [];
                    $all_apts_q = $conn->query("SELECT a.*, u.fullname as client_name, IFNULL(d.fullname,'Unassigned') as doctor_name FROM appointments a JOIN users u ON a.user_id = u.id LEFT JOIN users d ON a.doctor_id = d.id WHERE a.service_type != 'Pet Boarding Reservation' ORDER BY a.appointment_date ASC, a.appointment_time ASC");
                    if ($all_apts_q && $all_apts_q->num_rows > 0) {
                        while ($r = $all_apts_q->fetch_assoc()) {
                            $service = $r['service_type'] ?? 'General Checkup';
                            // Color by service type first
                            $color = 'blue';
                            if (stripos($service, 'vaccin') !== false || stripos($service, 'deworm') !== false) {
                                $color = 'green';
                            } elseif (stripos($service, 'surg') !== false) {
                                $color = 'orange';
                            }
                            // Then override by status
                            if ($r['status'] === 'Completed') {
                                $color = 'green';
                            } elseif ($r['status'] === 'Pending') {
                                $color = 'orange';
                            } elseif ($r['status'] === 'Cancelled') {
                                $color = 'red';
                            } elseif ($r['status'] === 'Reschedule Requested') {
                                $color = 'purple';
                            } elseif ($r['status'] === 'Reschedule Proposed') {
                                $color = 'purple';
                            }

                            $calendar_events[] = [
                                'type' => $service,
                                'color' => $color,
                                'status' => $r['status'],
                                'time' => date("h:i A", strtotime($r['appointment_time'])),
                                'raw_time' => $r['appointment_time'],
                                'title' => $r['client_name'],
                                'pet' => $r['pet_name'],
                                'ownerId' => $r['user_id'],
                                'owner' => $r['client_name'],
                                'doctorId' => $r['doctor_id'],
                                'doctor' => $r['doctor_name'],
                                'raw_date' => $r['appointment_date'],
                                'appt_id' => $r['id'],
                                'location' => $r['service_location'] ?? '',
                                'notes' => $r['notes'] ?? '',
                                'created_at' => $r['created_at'] ?? '',
                                'proposed_date' => $r['proposed_reschedule_date'] ?? null,
                                'proposed_time' => $r['proposed_reschedule_time'] ?? null,
                                'admin_message' => $r['admin_message'] ?? ''
                            ];
                        }
                    }
                    // Doctor leave days
                    $leaves_for_cal = $conn->query("SELECT dl.*, u.fullname AS doctor_name FROM doctor_unavailability dl JOIN users u ON dl.doctor_id = u.id ORDER BY dl.unavailable_date ASC");
                    if ($leaves_for_cal && $leaves_for_cal->num_rows > 0) {
                        while ($l = $leaves_for_cal->fetch_assoc()) {
                            $calendar_events[] = [
                                'type' => 'Leave',
                                'color' => 'red',
                                'status' => 'Leave',
                                'time' => 'All Day',
                                'title' => 'On Leave',
                                'pet' => 'N/A',
                                'ownerId' => null,
                                'owner' => 'N/A',
                                'doctorId' => $l['doctor_id'],
                                'doctor' => $l['doctor_name'],
                                'raw_date' => $l['unavailable_date'],
                                'appt_id' => null
                            ];
                        }
                    }

                    ob_start();
                    ?>
                    <!-- Pending Approvals -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <p class="fw-bold text-uppercase text-secondary m-0"
                                style="font-size:10px;letter-spacing:.1em;">Pending Approvals</p>
                            <a href="#" class="text-muted" style="font-size:10px;" data-bs-toggle="modal"
                                data-bs-target="#viewAllPendingModal">View all</a>
                        </div>
                        <?php if (isset($pending_apts) && $pending_apts->num_rows > 0):
                            $pending_apts->data_seek(0);
                            while ($row = $pending_apts->fetch_assoc()): ?>
                                <div class="p-2 rounded-3 border bg-white mb-2" style="font-size:11px;">
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['pet_name']); ?> <span
                                            class="text-muted fw-normal">Â·
                                            <?php echo htmlspecialchars($row['client_name']); ?></span></div>
                                    <div class="text-muted">
                                        <?php echo date("M d, h:i A", strtotime($row['appointment_date'] . ' ' . $row['appointment_time'])); ?>
                                    </div>
                                    <div class="d-flex gap-1 mt-2">
                                        <form method="POST" class="flex-grow-1">
                                            <input type="hidden" name="apt_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="action" value="approve"
                                                class="btn btn-sm w-100 btn-primary py-1"
                                                style="font-size:10px;border-radius:8px;">Accept</button>
                                        </form>
                                        <form method="POST" class="flex-grow-1">
                                            <input type="hidden" name="apt_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="action" value="reject"
                                                class="btn btn-sm w-100 btn-outline-danger py-1"
                                                style="font-size:10px;border-radius:8px;">Reject</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endwhile; else: ?>
                            <p class="text-muted small"><i class="fa fa-check-circle me-1 opacity-50"></i> All Caught Up</p>
                        <?php endif; ?>
                    </div>

                    <!-- Medical Team Roster -->
                    <div class="mb-4">
                        <p class="fw-bold text-uppercase text-secondary mb-2"
                            style="font-size:10px;letter-spacing:.1em;border-top:2px solid #3b82f6;padding-top:12px;">
                            Medical Team (<?php echo $doctors_count; ?>)</p>
                        <?php if ($medical_staff && $medical_staff->num_rows > 0):
                            $medical_staff->data_seek(0);
                            while ($ms = $medical_staff->fetch_assoc()): ?>
                                <div class="d-flex align-items-center mb-1 p-2 rounded-3 border bg-white"
                                    style="cursor:default;">
                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2"
                                        style="width:24px;height:24px;font-size:10px;">
                                        <i class="fa fa-user-md"></i>
                                    </div>
                                    <div style="line-height:1.2;">
                                        <div class="fw-bold text-dark" style="font-size:11px;">Dr.
                                            <?php echo htmlspecialchars($ms['fullname']); ?></div>
                                        <div class="text-muted" style="font-size:9px;">
                                            <?php echo htmlspecialchars($ms['email']); ?></div>
                                    </div>
                                </div>
                            <?php endwhile; else: ?>
                            <p class="text-muted small">No doctors registered.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Doctors Not Available -->
                    <div>
                        <p class="fw-bold text-uppercase text-secondary mb-2"
                            style="font-size:10px;letter-spacing:.1em;border-top:2px solid #ef4444;padding-top:12px;">
                            Doctors Not Available</p>
                        <?php
                        $now_adm = date('Y-m-d');
                        $admin_leaves2 = $conn->query("SELECT dl.*, u.fullname AS doctor_name FROM doctor_unavailability dl JOIN users u ON dl.doctor_id = u.id WHERE dl.unavailable_date >= '$now_adm' ORDER BY dl.unavailable_date ASC");

                        $raw_leaves = [];
                        if ($admin_leaves2 && $admin_leaves2->num_rows > 0) {
                            while ($ul = $admin_leaves2->fetch_assoc()) {
                                $raw_leaves[] = $ul;
                            }
                        }

                        $doctor_groups = [];
                        foreach ($raw_leaves as $l) {
                            $doctor_groups[$l['doctor_id']][] = $l;
                        }

                        $ranges = [];
                        foreach ($doctor_groups as $doc_id => $leaves) {
                            usort($leaves, function ($a, $b) {
                                return strcmp($a['unavailable_date'], $b['unavailable_date']); });
                            $current_range = null;
                            foreach ($leaves as $l) {
                                if ($current_range === null) {
                                    $current_range = $l;
                                    $current_range['start'] = $l['unavailable_date'];
                                    $current_range['end'] = $l['unavailable_date'];
                                } else {
                                    $expected_next = date('Y-m-d', strtotime($current_range['end'] . ' +1 day'));
                                    if ($l['unavailable_date'] === $expected_next && $l['reason'] === $current_range['reason']) {
                                        $current_range['end'] = $l['unavailable_date'];
                                    } else {
                                        $ranges[] = $current_range;
                                        $current_range = $l;
                                        $current_range['start'] = $l['unavailable_date'];
                                        $current_range['end'] = $l['unavailable_date'];
                                    }
                                }
                            }
                            if ($current_range !== null) {
                                $ranges[] = $current_range;
                            }
                        }

                        usort($ranges, function ($a, $b) {
                            return strcmp($a['start'], $b['start']); });

                        if (count($ranges) > 0):
                            foreach ($ranges as $r):
                                $fmt_start = date("M d", strtotime($r['start']));
                                $fmt_end = date("M d", strtotime($r['end']));
                                if ($r['start'] === $r['end']) {
                                    $display_date = $fmt_start;
                                    $aria_date = "on " . date("F j", strtotime($r['start']));
                                    $tooltip = "On " . date("M d, Y", strtotime($r['start']));
                                } else {
                                    $display_date = $fmt_start . " - " . $fmt_end;
                                    $aria_date = "from " . date("F j", strtotime($r['start'])) . " to " . date("F j", strtotime($r['end']));
                                    $tooltip = "From " . date("M d, Y", strtotime($r['start'])) . " to " . date("M d, Y", strtotime($r['end']));
                                }
                                ?>
                                <div class="d-flex align-items-center mb-2 p-2 rounded-3 border bg-white"
                                    title="<?php echo htmlspecialchars($tooltip); ?>"
                                    aria-label="<?php echo htmlspecialchars($r['doctor_name'] . ' is on ' . strtolower($r['reason']) . ' ' . $aria_date); ?>">
                                    <span class="badge bg-danger rounded-pill me-2"
                                        style="font-size:9px;"><?php echo $display_date; ?></span>
                                    <div>
                                        <div class="fw-bold text-dark" style="font-size:11px;">
                                            <?php echo htmlspecialchars($r['doctor_name']); ?></div>
                                        <div class="text-muted" style="font-size:10px;">
                                            <?php echo htmlspecialchars($r['reason']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; else: ?>
                            <p class="text-muted small">No scheduled leaves.</p>
                        <?php endif; ?>
                    </div>
                    <?php
                    $sidebarHTML = ob_get_clean();
                    echo render_saas_calendar($calendar_events, 'admin', $_SESSION['user_id'], $sidebarHTML);
                    ?>
                </div>
            </div>
        </div>
    </div>


    <!-- View All Pending Approvals Modal -->
    <div class="modal fade" id="viewAllPendingModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
                <div class="modal-header border-0 pb-0 pt-4 px-4">
                    <h5 class="fw-bold text-dark m-0"><i class="fa fa-list text-primary me-2"></i> All Pending Approvals
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <?php if ($pending_apts->num_rows > 0): ?>
                        <div class="list-group list-group-flush border-0 bg-transparent">
                            <?php
                            $pending_apts->data_seek(0);
                            while ($row = $pending_apts->fetch_assoc()): ?>
                                <div
                                    class="list-group-item bg-white p-3 mb-3 border rounded shadow-sm d-flex align-items-center justify-content-between">
                                    <div class="w-100">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <strong
                                                class="fs-6 text-dark"><?php echo htmlspecialchars($row['pet_name']); ?></strong>
                                            <span class="badge bg-warning text-dark"><i
                                                    class="far fa-clock me-1"></i><?php echo date("M d, Y h:i A", strtotime($row['appointment_date'] . ' ' . $row['appointment_time'])); ?></span>
                                        </div>
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <div class="small fw-semibold text-muted"><i class="fa fa-user me-1"></i>
                                                <?php echo htmlspecialchars($row['client_name']); ?></div>
                                            <div class="small fw-bold text-primary"><i class="fa fa-stethoscope me-1"></i>
                                                <?php echo htmlspecialchars($row['service_type'] ?: 'General Service'); ?></div>
                                        </div>

                                        <?php if ($row['status'] === 'Reschedule Requested'): ?>
                                            <div
                                                style="background:#f5f3ff; border:1.5px solid #ddd6fe; border-radius:12px; padding:12px; margin:10px 0;">
                                                <div
                                                    style="font-size:10px; font-weight:800; color:#7c3aed; text-transform:uppercase; margin-bottom:4px;">
                                                    <i class="fa fa-exchange-alt me-1"></i> Client Requested Shift</div>
                                                <div style="font-size:13px; font-weight:700; color:#1e1b4b;">Proposed:
                                                    <?php echo date("M d, Y h:i A", strtotime($row['proposed_reschedule_date'] . ' ' . $row['proposed_reschedule_time'])); ?>
                                                </div>
                                                <div style="font-size:12px; color:#4c1d95; margin-top:2px;">Reason:
                                                    <?php echo htmlspecialchars($row['admin_message'] ?: 'No reason provided'); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="d-flex w-100 gap-2 mt-2">
                                            <?php if ($row['status'] === 'Reschedule Requested'): ?>
                                                <form method="POST" class="flex-grow-1">
                                                    <input type="hidden" name="apt_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="admin_manage_appt" value="accept_client_resched"
                                                        class="btn btn-sm btn-success w-100 fw-bold rounded-pill">Accept
                                                        Shift</button>
                                                </form>
                                                <form method="POST" class="flex-grow-1">
                                                    <input type="hidden" name="apt_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="admin_manage_appt" value="decline_client_resched"
                                                        class="btn btn-sm btn-outline-danger w-100 fw-bold rounded-pill">Decline</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" class="flex-grow-1">
                                                    <input type="hidden" name="apt_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="action" value="approve"
                                                        class="btn btn-sm btn-primary w-100 fw-bold rounded-pill">Quick
                                                        Accept</button>
                                                </form>
                                                <form method="POST" class="flex-grow-1">
                                                    <input type="hidden" name="apt_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="action" value="reject"
                                                        class="btn btn-sm btn-outline-danger flex-grow-1 w-100 fw-bold rounded-pill">Reject</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fa fa-check-circle fa-3x text-success opacity-50 mb-3"></i>
                            <h5 class="fw-bold">All Caught Up</h5>
                            <p class="text-muted small">There are no pending approvals at this time.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 p-3 bg-light" style="border-radius: 0 0 15px 15px;">
                    <button type="button" class="btn btn-secondary rounded-pill px-4"
                        data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Auto Dismiss Alerts -->
    <script>
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

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            flatpickr(".flatpickr-shifted-date", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                minDate: "today"
            });
        });
    </script>

    <script src="mousetrail.js"></script>

    <!-- Chart.js Hybrid Analytics Initialization -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // 1. Monthly Revenue Chart
        const ctx1 = document.getElementById('monthlyRevChart');
        if (ctx1) {
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chart_m_labels) ?>,
                    datasets: [
                        {
                            label: 'Clinic Visits (₱)',
                            data: <?= json_encode($chart_clinic_rev) ?>,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.08)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2.5,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Pet Boarding (₱)',
                            data: <?= json_encode($chart_boarding_rev) ?>,
                            borderColor: '#7c3aed',
                            backgroundColor: 'rgba(124, 58, 237, 0.08)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2.5,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { font: { family: 'Plus Jakarta Sans', size: 12, weight: '600' } } }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: { grid: { color: '#f1f5f9' }, ticks: { callback: function(val) { return '₱' + val.toLocaleString(); } } }
                    }
                }
            });
        }

        // 2. Service Breakdown Chart
        const ctx2 = document.getElementById('serviceBreakdownChart');
        if (ctx2) {
            new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($chart_svc_labels) ?>,
                    datasets: [{
                        data: <?= json_encode($chart_svc_data) ?>,
                        backgroundColor: ['#2563eb', '#16a34a', '#7c3aed', '#ea580c', '#06b6d4'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { font: { family: 'Plus Jakarta Sans', size: 11.5, weight: '600' } } }
                    },
                    cutout: '65%'
                }
            });
        }
    });
    </script>
</body>

</html>