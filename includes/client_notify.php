<?php
/**
 * Notices between the pet owner side and the rest of the clinic.
 * Messages are shown as HTML (client bell, vet/staff bell), so callers pass plain text and it is escaped here.
 */
include_once __DIR__ . '/staff_notify.php';

/** One notification for one account (client, vet, ...). */
function notify_user($conn, $user_id, $message, $link = null) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return;
    $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $ins = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at, link) VALUES (?, ?, 0, NOW(), ?)");
    if (!$ins) return;
    $ins->bind_param("iss", $user_id, $msg, $link);
    $ins->execute();
}

/** Tell the veterinarian assigned to an appointment (if any). */
function notify_appt_vet($conn, $appt_id, $message) {
    $appt_id = (int)$appt_id;
    $r = $conn->query("SELECT a.doctor_id FROM appointments a JOIN users u ON u.id = a.doctor_id AND u.role = 'doctor' WHERE a.id = $appt_id");
    $row = $r ? $r->fetch_assoc() : null;
    if ($row) notify_user($conn, $row['doctor_id'], $message, 'doctor.php');
}

/** A client change to an appointment or boarding stay: front desk always, plus the assigned vet for appointments. */
function notify_clinic_appt($conn, $message, $type, $id) {
    notify_staff($conn, $message, staff_link_for($type, $id));
    if ($type !== 'Boarding') notify_appt_vet($conn, $id, $message);
}

/** Pet owner roles: accounts are stored as either 'client' or 'user'. */
function is_pet_owner_role($role) {
    return in_array($role, ['client', 'user'], true);
}

/** Why a client may not cancel this boarding stay (null = allowed). Same rule on every page. */
function board_cancel_problem($r) {
    if (!$r) return "Reservation not found.";
    if (!in_array($r['status'], ['Pending', 'Approved'], true)) return "This reservation can no longer be cancelled (it is " . strtolower($r['status']) . ").";
    if (strtotime($r['appointment_date']) - time() < 86400) return "Cancellation failed: Must be completed at least 24 hours prior to check-in.";
    return null;
}

/** The client account an invoice belongs to (0 for walk-ins). */
function bill_owner_id($conn, $bill) {
    $r = null;
    if (!empty($bill['user_id'])) {
        $r = ['user_id' => $bill['user_id']];
    } elseif (!empty($bill['is_boarding'])) {
        $rid = (int)($bill['boarding_res_id'] ?: $bill['appointment_id']);
        $r = $conn->query("SELECT user_id FROM boarding_reservations WHERE id = $rid")->fetch_assoc();
    } elseif (!empty($bill['appointment_id'])) {
        $r = $conn->query("SELECT user_id FROM appointments WHERE id = " . (int)$bill['appointment_id'])->fetch_assoc();
    }
    $uid = (int)($r['user_id'] ?? 0);
    if (!$uid) return 0;
    $role = $conn->query("SELECT role FROM users WHERE id = $uid")->fetch_assoc()['role'] ?? '';
    return is_pet_owner_role($role) ? $uid : 0;
}
