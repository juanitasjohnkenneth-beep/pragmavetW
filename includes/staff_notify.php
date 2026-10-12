<?php
/**
 * Clinic-side notifications: deliver a message to every vet staff account (they run the front desk).
 * Messages are shown as HTML in the bell, so callers pass plain text — it is escaped here.
 */
function notify_staff($conn, $message, $link = null) {
    $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $ins = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at, link) VALUES (?, ?, 0, NOW(), ?)");
    if (!$ins) return;
    $r = $conn->query("SELECT id FROM users WHERE role = 'staff'");
    if ($r) while ($u = $r->fetch_assoc()) {
        $uid = (int)$u['id'];
        $ins->bind_param("iss", $uid, $msg, $link);
        $ins->execute();
    }
}

/** Where a staff member should land for an appointment or a boarding reservation. */
function staff_link_for($type, $id) {
    return $type === 'Boarding' ? 'staff_boarding.php' : 'staff_dashboard.php?tab=appointments&filter=all&open=' . (int)$id;
}
