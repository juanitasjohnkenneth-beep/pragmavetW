<?php
/**
 * Shared schedule rules for staff actions (reschedule proposals, vet assignment).
 * Same rules the client booking/reschedule uses: clinic closed on Sundays and holidays,
 * vet must be working that weekday (weekly schedule), not on leave, within hours and outside the break,
 * and not already booked at that time.
 * Each function returns '' when OK, otherwise a short reason a person can read.
 */
include_once __DIR__ . '/doctor_weekly_schedule.php';

const SCHED_SLOT_MINUTES = 30;
const SCHED_BUSY_STATUSES = "'Pending','Approved','Checked In','In Progress','Reschedule Proposed'";

/** Clinic-level date checks (no vet involved). $today comes from the database clock. */
function sched_date_problem($conn, $date, $today) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) return 'Pick a valid date.';
    if ($date < $today) return 'That date has already passed.';
    if ((int)date('w', strtotime($date)) === 0) return 'The clinic is closed on Sundays.';
    $h = $conn->prepare("SELECT holiday_name FROM clinic_holidays WHERE holiday_date = ? AND is_active = 1 AND holiday_type <> 'Special Working' LIMIT 1");
    $h->bind_param("s", $date);
    $h->execute();
    if ($row = $h->get_result()->fetch_assoc()) return 'That date is a clinic holiday (' . $row['holiday_name'] . ').';
    return '';
}

/**
 * Can this vet take an appointment at $date $time?
 * $exclude_id: the appointment being moved/assigned (so it does not clash with itself).
 */
function sched_vet_problem($conn, $doctor_id, $date, $time, $exclude_id = 0) {
    $doctor_id = (int)$doctor_id;
    $time = substr((string)$time, 0, 5);
    $day = dws_get_day($conn, $doctor_id, $date);
    if (empty($day['is_working'])) return 'off on ' . date('l', strtotime($date)) . 's';

    $lv = $conn->prepare("SELECT reason FROM doctor_unavailability WHERE doctor_id = ? AND unavailable_date = ? LIMIT 1");
    $lv->bind_param("is", $doctor_id, $date);
    $lv->execute();
    if ($lv->get_result()->num_rows) return 'on leave that day';

    $end = date('H:i', strtotime($time . ' +' . SCHED_SLOT_MINUTES . ' minutes'));
    if ($time < $day['work_start'] || $end > $day['work_end']) {
        return 'only available ' . date('g:i A', strtotime($day['work_start'])) . '–' . date('g:i A', strtotime($day['work_end']));
    }
    if (!empty($day['break_start']) && !empty($day['break_end']) && $time < $day['break_end'] && $end > $day['break_start']) {
        return 'on break ' . date('g:i A', strtotime($day['break_start'])) . '–' . date('g:i A', strtotime($day['break_end']));
    }

    // Another appointment within the same 30-minute slot
    $t = $time . ':00';
    $cf = $conn->prepare("SELECT pet_name, appointment_time FROM appointments
                          WHERE doctor_id = ? AND appointment_date = ? AND id <> ? AND is_archived = 0
                            AND status IN (" . SCHED_BUSY_STATUSES . ")
                            AND ABS(TIME_TO_SEC(TIMEDIFF(appointment_time, ?))) < ? LIMIT 1");
    $win = SCHED_SLOT_MINUTES * 60;
    $exclude_id = (int)$exclude_id;
    $cf->bind_param("isisi", $doctor_id, $date, $exclude_id, $t, $win);
    $cf->execute();
    if ($b = $cf->get_result()->fetch_assoc()) {
        return 'already booked at ' . date('g:i A', strtotime($b['appointment_time'])) . ' (' . $b['pet_name'] . ')';
    }
    return '';
}

/** Availability of every vet for one slot: [doctor_id => '' | reason]. */
function sched_vets_for_slot($conn, $date, $time, $exclude_id = 0) {
    $out = [];
    $r = $conn->query("SELECT id FROM users WHERE role = 'doctor'");
    if ($r) while ($d = $r->fetch_assoc()) $out[(int)$d['id']] = sched_vet_problem($conn, (int)$d['id'], $date, $time, $exclude_id);
    return $out;
}
