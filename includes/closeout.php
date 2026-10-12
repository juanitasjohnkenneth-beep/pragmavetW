<?php
/**
 * Automatic close-out of bookings whose date passed without anyone acting on them.
 * Without it, old "Approved"/"Pending" bookings stay active forever: they keep showing Cancel/Reschedule
 * buttons and the Billing page keeps charging for visits that never happened.
 *
 * Staff get one full day of grace (only bookings dated 2+ days ago are closed). Runs at most once an hour.
 * Checked-in visits and stays are never touched — staff must complete or check those out.
 */
/** Once a day: tell the admin and clinic owner about low, out-of-stock and soon-to-expire items. */
function pv_inventory_alert($conn) {
    $flag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pragmavet_inv_alert.txt';
    $today = $conn->query("SELECT CURDATE() d")->fetch_assoc()['d'];
    if (@file_get_contents($flag) === $today) return;
    @file_put_contents($flag, $today);
    $r = $conn->query("SELECT
            SUM(quantity <= 0) out_n,
            SUM(quantity > 0 AND quantity <= COALESCE(reorder_level, 10)) low_n,
            SUM(expiry_date IS NOT NULL AND expiry_date <> '1970-01-01' AND expiry_date < CURDATE() AND quantity > 0) exp_n,
            SUM(expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= CURDATE() + INTERVAL 60 DAY AND quantity > 0) soon_n
        FROM inventory")->fetch_assoc();
    $parts = [];
    if ((int)$r['out_n'])  $parts[] = $r['out_n'] . " out of stock";
    if ((int)$r['low_n'])  $parts[] = $r['low_n'] . " low on stock";
    if ((int)$r['exp_n'])  $parts[] = $r['exp_n'] . " expired";
    if ((int)$r['soon_n']) $parts[] = $r['soon_n'] . " expiring within 60 days";
    if (!$parts) return;
    $msg = "Inventory check: " . implode(', ', $parts) . ". Tap to review.";
    $link = 'admin_inventory.php';
    $ins = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at, link) VALUES (?, ?, 0, NOW(), ?)");
    $u = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'clinic_owner')");
    if ($ins && $u) while ($row = $u->fetch_assoc()) { $id = (int)$row['id']; $ins->bind_param("iss", $id, $msg, $link); $ins->execute(); }
}

function pv_closeout_stale($conn, $restore_actor = null) {
    $flag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pragmavet_closeout.txt';
    if (is_file($flag) && time() - (int)@file_get_contents($flag) < 3600) return;
    @file_put_contents($flag, (string)time());

    $conn->query("SET @pv_actor = 'System (automatic close-out)'");
    $note = function ($text) {
        return "admin_message = CONCAT(IF(COALESCE(admin_message, '') = '', '', CONCAT(admin_message, '\\n')), '" . $text . "')";
    };

    // Approved appointment, day passed, never checked in → No Show
    $conn->query("UPDATE appointments SET status = 'No Show', client_action_needed = 0, " . $note('Automatically marked No Show: the appointment date passed without check-in.') . "
                  WHERE status = 'Approved' AND appointment_date < CURDATE() - INTERVAL 1 DAY");

    // Request never confirmed before its date (and no newer date still pending) → Cancelled
    $conn->query("UPDATE appointments SET status = 'Cancelled', client_action_needed = 0, " . $note('Automatically cancelled: the request was not confirmed before the appointment date.') . "
                  WHERE status IN ('Pending', 'Reschedule Requested', 'Reschedule Proposed') AND appointment_date < CURDATE() - INTERVAL 1 DAY
                    AND (proposed_reschedule_date IS NULL OR proposed_reschedule_date < CURDATE() - INTERVAL 1 DAY)");

    // Boarding stay that was never checked in by its check-out date → Cancelled
    $conn->query("UPDATE boarding_reservations SET status = 'Cancelled', client_action_needed = 0, " . $note('Automatically cancelled: the pet was not checked in before the check-out date.') . "
                  WHERE status IN ('Pending', 'Approved') AND end_date < CURDATE() - INTERVAL 1 DAY");

    pv_inventory_alert($conn);

    if ($restore_actor !== null) $conn->query("SET @pv_actor = '" . $conn->real_escape_string($restore_actor) . "'");
}
