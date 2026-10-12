<?php
/**
 * Shared boarding rules for every page that approves, registers or checks in a stay.
 * A pet still checked in after its check-out date keeps its cage busy (through at least the day after tomorrow).
 */

/** Another stay already holding this cage for the given dates (null = cage is free). */
function br_cage_conflict($conn, $cage, $start, $end, $exclude_id = 0) {
    $st = $conn->prepare("SELECT id, pet_name, status FROM boarding_reservations
                          WHERE cage_number = ? AND id <> ? AND status NOT IN ('Completed', 'Cancelled', 'Rejected')
                            AND appointment_date < ?
                            AND (CASE WHEN status = 'Checked In' THEN GREATEST(end_date, CURDATE() + INTERVAL 2 DAY) ELSE end_date END) > ?
                          LIMIT 1");
    $cage = (int)$cage; $exclude_id = (int)$exclude_id;
    $st->bind_param("iiss", $cage, $exclude_id, $end, $start);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

/** Why this stay can't be checked in right now (null = OK). */
function br_checkin_problem($conn, $res_id) {
    $res_id = (int)$res_id;
    $r = $conn->query("SELECT id, status, cage_number, appointment_date FROM boarding_reservations WHERE id = $res_id")->fetch_assoc();
    if (!$r) return "Reservation not found.";
    if ($r['status'] === 'Approved' && $r['appointment_date'] > date('Y-m-d')) return "This stay starts on " . date('M j, Y', strtotime($r['appointment_date'])) . ". The pet can be checked in from that day, not before.";
    if ($r['status'] === 'Checked In') return "This pet is already checked in.";
    if ($r['status'] !== 'Approved') return "Only approved reservations can be checked in (this one is " . strtolower($r['status']) . ").";
    if (empty($r['cage_number'])) return "Assign a cage before checking in.";
    $busy = $conn->query("SELECT pet_name FROM boarding_reservations WHERE cage_number = " . (int)$r['cage_number'] . " AND status = 'Checked In' AND id <> $res_id LIMIT 1")->fetch_assoc();
    if ($busy) return "Cage " . str_pad((int)$r['cage_number'], 2, '0', STR_PAD_LEFT) . " is still occupied by {$busy['pet_name']}. Check that pet out first.";
    return null;
}
