<?php
/**
 * Boarding audit trail.
 * Database triggers on `boarding_reservations` record every create/change automatically — whichever page made it
 * (staff boarding, client boarding, billing, admin lists). dbconnection.php sets @pv_actor to the logged-in user so
 * each entry says who did it. Older reservations also show events reconstructed from their saved timestamps.
 */

function ba_ensure($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("
        CREATE TABLE IF NOT EXISTS boarding_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT NOT NULL,
            action VARCHAR(60) NOT NULL,
            details TEXT NULL,
            actor VARCHAR(150) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_res (reservation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $have = [];
    $r = $conn->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'boarding_reservations'");
    if ($r) while ($t = $r->fetch_assoc()) $have[$t['TRIGGER_NAME']] = true;

    if (empty($have['trg_boarding_audit_ai'])) {
        $conn->query("
            CREATE TRIGGER trg_boarding_audit_ai AFTER INSERT ON boarding_reservations FOR EACH ROW
            INSERT INTO boarding_audit_log (reservation_id, action, details, actor)
            VALUES (NEW.id, 'Created',
                    CONCAT('Reservation created (', IFNULL(NEW.status, 'Pending'), ') for ', NEW.pet_name, ': ', NEW.appointment_date, ' -> ', IFNULL(NEW.end_date, '?'),
                           IF(NEW.cage_number IS NULL, '', CONCAT(', Cage ', NEW.cage_number))),
                    IFNULL(@pv_actor, 'System'))
        ");
    }
    if (empty($have['trg_boarding_audit_au'])) {
        $conn->query("
            CREATE TRIGGER trg_boarding_audit_au AFTER UPDATE ON boarding_reservations FOR EACH ROW
            BEGIN
                DECLARE who VARCHAR(150) DEFAULT IFNULL(@pv_actor, 'System');
                IF NOT (OLD.status <=> NEW.status) THEN
                    INSERT INTO boarding_audit_log (reservation_id, action, details, actor) VALUES (NEW.id, 'Status changed',
                        CONCAT(IFNULL(OLD.status, '?'), ' -> ', IFNULL(NEW.status, '?'),
                               IF(NEW.admin_message IS NULL OR NEW.admin_message <=> OLD.admin_message, '', CONCAT(' | ', LEFT(NEW.admin_message, 300)))), who);
                END IF;
                IF NOT (OLD.appointment_date <=> NEW.appointment_date AND OLD.end_date <=> NEW.end_date) THEN
                    INSERT INTO boarding_audit_log (reservation_id, action, details, actor) VALUES (NEW.id, 'Dates changed',
                        CONCAT(OLD.appointment_date, ' -> ', IFNULL(OLD.end_date, '?'), '  became  ', NEW.appointment_date, ' -> ', IFNULL(NEW.end_date, '?')), who);
                END IF;
                IF NOT (OLD.cage_number <=> NEW.cage_number) THEN
                    INSERT INTO boarding_audit_log (reservation_id, action, details, actor) VALUES (NEW.id, 'Cage assigned',
                        CONCAT(IF(OLD.cage_number IS NULL, 'None', CONCAT('Cage ', OLD.cage_number)), ' -> ', IF(NEW.cage_number IS NULL, 'None', CONCAT('Cage ', NEW.cage_number))), who);
                END IF;
                IF NEW.proposed_reschedule_date IS NOT NULL AND NOT (OLD.proposed_reschedule_date <=> NEW.proposed_reschedule_date) THEN
                    INSERT INTO boarding_audit_log (reservation_id, action, details, actor) VALUES (NEW.id, 'Reschedule proposed',
                        CONCAT('New dates: ', NEW.proposed_reschedule_date, ' -> ', IFNULL(NEW.proposed_reschedule_end_date, '?')), who);
                END IF;
                IF NOT (OLD.cctv_enabled <=> NEW.cctv_enabled) THEN
                    INSERT INTO boarding_audit_log (reservation_id, action, details, actor) VALUES (NEW.id, 'Live camera',
                        IF(NEW.cctv_enabled = 1, 'Live stream turned ON', 'Live stream turned OFF'), who);
                END IF;
                IF NOT (OLD.cctv_link <=> NEW.cctv_link) THEN
                    INSERT INTO boarding_audit_log (reservation_id, action, details, actor) VALUES (NEW.id, 'Live camera', 'Camera link updated', who);
                END IF;
                IF NOT (OLD.care_notes <=> NEW.care_notes) THEN
                    INSERT INTO boarding_audit_log (reservation_id, action, details, actor) VALUES (NEW.id, 'Care notes', 'Care & feeding notes updated', who);
                END IF;
            END
        ");
    }
}

/**
 * Timeline for one reservation (oldest first): logged events + events reconstructed from saved timestamps
 * (booking time, approval time, payments) for history that predates the log.
 */
function ba_timeline($conn, $res_id) {
    $res_id = (int)$res_id;
    $events = [];
    $logged = [];
    $q = $conn->query("SELECT action, details, actor, created_at FROM boarding_audit_log WHERE reservation_id = $res_id ORDER BY created_at, id");
    if ($q) while ($e = $q->fetch_assoc()) {
        $events[] = ['at' => $e['created_at'], 'action' => $e['action'], 'details' => str_replace(' -> ', ' → ', (string)$e['details']), 'actor' => $e['actor'], 'source' => 'log'];
        $logged[$e['action']] = true;
        if ($e['action'] === 'Status changed' && strpos($e['details'], '-> Approved') !== false) $logged['approved'] = true;
    }

    $r = $conn->query("SELECT br.created_at, br.approved_at, br.status, br.pet_name, br.appointment_date, br.end_date, br.cage_number, u.fullname
                       FROM boarding_reservations br LEFT JOIN users u ON u.id = br.user_id WHERE br.id = $res_id")->fetch_assoc();
    if ($r) {
        if (empty($logged['Created']) && $r['created_at']) {
            $events[] = ['at' => $r['created_at'], 'action' => 'Reservation requested',
                         'details' => 'Booked for ' . $r['pet_name'] . ': ' . date('M d', strtotime($r['appointment_date'])) . ' → ' . ($r['end_date'] ? date('M d, Y', strtotime($r['end_date'])) : '?'),
                         'actor' => $r['fullname'] ?: 'Client', 'source' => 'record'];
        }
        if (empty($logged['approved']) && $r['approved_at']) {
            $events[] = ['at' => $r['approved_at'], 'action' => 'Approved', 'details' => $r['cage_number'] ? 'Cage ' . $r['cage_number'] . ' assigned' : '', 'actor' => 'Clinic staff', 'source' => 'record'];
        }
    }
    $b = $conn->query("SELECT invoice_no, total_amount, downpayment_amount, downpayment_status, downpayment_paid_at, payment_status, payment_method, paid_at
                       FROM billing WHERE is_boarding = 1 AND COALESCE(boarding_res_id, appointment_id) = $res_id ORDER BY id DESC LIMIT 1")->fetch_assoc();
    if ($b) {
        if ($b['downpayment_status'] === 'Paid' && $b['downpayment_paid_at']) {
            $events[] = ['at' => $b['downpayment_paid_at'], 'action' => 'Downpayment paid', 'details' => '₱' . number_format((float)$b['downpayment_amount'], 2) . ($b['invoice_no'] ? ' · ' . $b['invoice_no'] : ''), 'actor' => 'Payment', 'source' => 'record'];
        }
        if ($b['payment_status'] === 'Paid' && $b['paid_at']) {
            $events[] = ['at' => $b['paid_at'], 'action' => 'Paid in full', 'details' => '₱' . number_format((float)$b['total_amount'], 2) . ($b['payment_method'] ? ' via ' . $b['payment_method'] : ''), 'actor' => 'Payment', 'source' => 'record'];
        }
    }
    usort($events, fn($x, $y) => strcmp((string)$x['at'], (string)$y['at']));
    return $events;
}

/** Small HTML timeline used on the admin pages. */
function ba_render_timeline($events) {
    if (!$events) return '<div class="text-muted small text-center py-3"><i class="fa fa-history me-1 opacity-50"></i>No activity recorded yet.</div>';
    $icon = ['Created' => ['fa-plus', '#2563eb'], 'Reservation requested' => ['fa-plus', '#2563eb'], 'Status changed' => ['fa-arrows-rotate', '#7c3aed'],
             'Approved' => ['fa-check', '#16a34a'], 'Dates changed' => ['fa-calendar-days', '#ea580c'], 'Cage assigned' => ['fa-hotel', '#0891b2'],
             'Reschedule proposed' => ['fa-clock-rotate-left', '#d97706'], 'Live camera' => ['fa-video', '#0e7490'], 'Care notes' => ['fa-notes-medical', '#db2777'],
             'Downpayment paid' => ['fa-coins', '#16a34a'], 'Paid in full' => ['fa-circle-check', '#16a34a']];
    $h = '<ul class="ba-timeline">';
    foreach (array_reverse($events) as $e) {
        [$ic, $col] = $icon[$e['action']] ?? ['fa-circle', '#64748b'];
        $h .= '<li><span class="ba-dot" style="background:' . $col . '1a;color:' . $col . ';"><i class="fa ' . $ic . '"></i></span>'
            . '<div class="ba-body"><div class="ba-top"><b>' . htmlspecialchars($e['action']) . '</b><span>' . htmlspecialchars(date('M d, Y g:i A', strtotime($e['at']))) . '</span></div>'
            . ($e['details'] !== '' ? '<div class="ba-details">' . htmlspecialchars($e['details']) . '</div>' : '')
            . '<div class="ba-actor"><i class="fa fa-user me-1"></i>' . htmlspecialchars($e['actor'] ?: 'System') . ($e['source'] === 'record' ? ' · <span title="Reconstructed from saved dates">from records</span>' : '') . '</div></div></li>';
    }
    return $h . '</ul>';
}
