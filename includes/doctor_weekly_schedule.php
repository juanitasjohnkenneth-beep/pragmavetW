<?php
/**
 * Doctor Weekly Schedule helpers
 *
 * Each doctor can set, per weekday (0=Sun .. 6=Sat), whether they work and their
 * hours / break. Days without a saved row fall back to the doctor's general
 * schedule in `doctor_schedules` (or clinic defaults), working Mon-Sat.
 * The clinic is open every day; Sunday is a day off by default until the vet turns it on in their Weekly Schedule.
 */

const DWS_DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function dws_ensure_table($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("
        CREATE TABLE IF NOT EXISTS doctor_weekly_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doctor_id INT NOT NULL,
            day_of_week TINYINT NOT NULL,
            is_working TINYINT(1) NOT NULL DEFAULT 1,
            work_start TIME NOT NULL DEFAULT '08:00:00',
            work_end TIME NOT NULL DEFAULT '17:00:00',
            break_start TIME DEFAULT '12:00:00',
            break_end TIME DEFAULT '13:00:00',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_doc_day (doctor_id, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/** General (non-weekday) schedule for a doctor, or clinic defaults. */
function dws_base_schedule($conn, $doctor_id) {
    $base = ['work_start' => '08:00', 'work_end' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'];
    $stmt = $conn->prepare("SELECT TIME_FORMAT(work_start,'%H:%i') ws, TIME_FORMAT(work_end,'%H:%i') we,
                                   TIME_FORMAT(break_start,'%H:%i') bs, TIME_FORMAT(break_end,'%H:%i') be
                            FROM doctor_schedules WHERE doctor_id = ? AND is_active = 1");
    if ($stmt) {
        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        if ($r = $stmt->get_result()->fetch_assoc()) {
            $base = ['work_start' => $r['ws'], 'work_end' => $r['we'], 'break_start' => $r['bs'], 'break_end' => $r['be']];
        }
    }
    return $base;
}

/**
 * Full week for one doctor: [dow => ['is_working'=>bool,'work_start'=>'HH:MM','work_end'=>..,'break_start'=>..|null,'break_end'=>..|null]]
 */
function dws_get_week($conn, $doctor_id) {
    dws_ensure_table($conn);
    $base = dws_base_schedule($conn, $doctor_id);
    $week = [];
    for ($d = 0; $d <= 6; $d++) {
        $week[$d] = ['is_working' => $d !== 0] + $base;
    }
    $stmt = $conn->prepare("SELECT day_of_week, is_working, TIME_FORMAT(work_start,'%H:%i') ws, TIME_FORMAT(work_end,'%H:%i') we,
                                   TIME_FORMAT(break_start,'%H:%i') bs, TIME_FORMAT(break_end,'%H:%i') be
                            FROM doctor_weekly_schedule WHERE doctor_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $d = (int)$r['day_of_week'];
            $week[$d] = [
                'is_working'  => (bool)$r['is_working'],
                'work_start'  => $r['ws'],
                'work_end'    => $r['we'],
                'break_start' => $r['bs'],
                'break_end'   => $r['be'],
            ];
        }
    }
    return $week;
}

/** Schedule for a doctor on a specific date (Y-m-d). */
function dws_get_day($conn, $doctor_id, $date) {
    $dow = (int)date('w', strtotime($date));
    $week = dws_get_week($conn, $doctor_id);
    return $week[$dow];
}

/** Weeks for every doctor, keyed by doctor id (for passing to JS). */
function dws_get_all($conn) {
    $all = [];
    $r = $conn->query("SELECT id FROM users WHERE role = 'doctor'");
    if ($r) while ($d = $r->fetch_assoc()) {
        $all[(int)$d['id']] = dws_get_week($conn, (int)$d['id']);
    }
    return $all;
}
