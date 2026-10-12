<?php
/**
 * Automatic close-out of bookings whose date passed without anyone acting on them.
 * Without it, old "Approved"/"Pending" bookings stay active forever: they keep showing Cancel/Reschedule
 * buttons and the Billing page keeps charging for visits that never happened.
 *
 * Staff get one full day of grace (only bookings dated 2+ days ago are closed). Runs at most once an hour.
 * Checked-in visits and stays are never touched — staff must complete or check those out.
 */
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

    if ($restore_actor !== null) $conn->query("SET @pv_actor = '" . $conn->real_escape_string($restore_actor) . "'");
}
