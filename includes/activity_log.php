<?php
/**
 * System-wide activity log (admin "Activity Logs / Audit").
 * Database triggers record changes on the main tables automatically, whichever page made them.
 * dbconnection.php supplies @pv_actor (the logged-in user). Boarding and inventory keep their own
 * logs (boarding_audit_log, inventory_movements); al_query() merges all three into one feed.
 */

const AL_MODULES = [
    'Appointments' => ['fa-calendar-check', '#2563eb'],
    'Boarding'     => ['fa-hotel',          '#0891b2'],
    'Billing'      => ['fa-receipt',        '#16a34a'],
    'Inventory'    => ['fa-boxes-stacked',  '#ea580c'],
    'Medical'      => ['fa-notes-medical',  '#db2777'],
    'Accounts'     => ['fa-user-shield',    '#7c3aed'],
    'Certificates' => ['fa-file-contract',  '#0d9488'],
    'Settings'     => ['fa-sliders',        '#475569'],
];

function al_ensure($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("
        CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module VARCHAR(30) NOT NULL,
            action VARCHAR(60) NOT NULL,
            record_id INT NULL,
            details VARCHAR(500) NULL,
            actor VARCHAR(150) NULL,
            source VARCHAR(10) NOT NULL DEFAULT 'live',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at), INDEX idx_module (module)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $have = [];
    $r = $conn->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg_al_%'");
    if ($r) while ($t = $r->fetch_assoc()) $have[$t['TRIGGER_NAME']] = true;

    $who = "IFNULL(@pv_actor, 'System')";
    $ins = "INSERT INTO activity_log (module, action, record_id, details, actor) VALUES";
    $triggers = [
        // ── Appointments ──
        'trg_al_apt_ai' => "AFTER INSERT ON appointments FOR EACH ROW
            $ins ('Appointments', 'Booked', NEW.id,
                  CONCAT('#', NEW.id, ' ', NEW.pet_name, ' — ', NEW.service_type, ' on ', DATE_FORMAT(NEW.appointment_date, '%b %d, %Y'), ' ', TIME_FORMAT(NEW.appointment_time, '%h:%i %p'), ' (', IFNULL(NEW.status, 'Pending'), ')'), $who)",
        'trg_al_apt_au' => "AFTER UPDATE ON appointments FOR EACH ROW
            BEGIN
                DECLARE ref VARCHAR(160) DEFAULT CONCAT('#', NEW.id, ' ', NEW.pet_name, ' — ', NEW.service_type);
                IF NOT (OLD.status <=> NEW.status) THEN
                    $ins ('Appointments', 'Status changed', NEW.id, CONCAT(ref, ': ', IFNULL(OLD.status, '?'), ' -> ', IFNULL(NEW.status, '?')), $who);
                END IF;
                IF NOT (OLD.appointment_date <=> NEW.appointment_date AND OLD.appointment_time <=> NEW.appointment_time) THEN
                    $ins ('Appointments', 'Rescheduled', NEW.id, CONCAT(ref, ': ', DATE_FORMAT(OLD.appointment_date, '%b %d'), ' ', TIME_FORMAT(OLD.appointment_time, '%h:%i %p'), ' -> ', DATE_FORMAT(NEW.appointment_date, '%b %d, %Y'), ' ', TIME_FORMAT(NEW.appointment_time, '%h:%i %p')), $who);
                END IF;
                IF NEW.proposed_reschedule_date IS NOT NULL AND NOT (OLD.proposed_reschedule_date <=> NEW.proposed_reschedule_date) THEN
                    $ins ('Appointments', 'Reschedule proposed', NEW.id, CONCAT(ref, ': new date ', DATE_FORMAT(NEW.proposed_reschedule_date, '%b %d, %Y'), IFNULL(CONCAT(' ', TIME_FORMAT(NEW.proposed_reschedule_time, '%h:%i %p')), '')), $who);
                END IF;
                IF NOT (OLD.doctor_id <=> NEW.doctor_id) THEN
                    $ins ('Appointments', 'Vet assigned', NEW.id, CONCAT(ref, ': ', IFNULL((SELECT fullname FROM users WHERE id = NEW.doctor_id), 'Unassigned')), $who);
                END IF;
                IF NOT (OLD.downpayment_status <=> NEW.downpayment_status) AND NEW.downpayment_status = 'Paid' THEN
                    $ins ('Appointments', 'Reservation fee paid', NEW.id, CONCAT(ref, ': P', NEW.downpayment_amount), $who);
                END IF;
                IF NOT (OLD.is_archived <=> NEW.is_archived) THEN
                    $ins ('Appointments', IF(NEW.is_archived = 1, 'Archived', 'Restored'), NEW.id, ref, $who);
                END IF;
            END",
        // ── Billing ──
        'trg_al_bill_ai' => "AFTER INSERT ON billing FOR EACH ROW
            $ins ('Billing', 'Invoice issued', NEW.id,
                  CONCAT(IFNULL(NEW.invoice_no, CONCAT('Bill #', NEW.id)), ' · ', IFNULL(NEW.client_name, ''), IF(NEW.pet_name IS NULL OR NEW.pet_name = '', '', CONCAT(' (', NEW.pet_name, ')')), ' · P', NEW.total_amount), $who)",
        'trg_al_bill_au' => "AFTER UPDATE ON billing FOR EACH ROW
            BEGIN
                DECLARE ref VARCHAR(200) DEFAULT CONCAT(IFNULL(NEW.invoice_no, CONCAT('Bill #', NEW.id)), ' · ', IFNULL(NEW.client_name, ''));
                IF NOT (OLD.payment_status <=> NEW.payment_status) THEN
                    IF NEW.payment_status = 'Paid' THEN
                        $ins ('Billing', 'Payment received', NEW.id, CONCAT(ref, ' · P', NEW.total_amount, IFNULL(CONCAT(' via ', NEW.payment_method), '')), $who);
                    ELSE
                        $ins ('Billing', 'Payment status changed', NEW.id, CONCAT(ref, ': ', IFNULL(OLD.payment_status, '?'), ' -> ', IFNULL(NEW.payment_status, '?')), $who);
                    END IF;
                END IF;
                IF NOT (OLD.downpayment_status <=> NEW.downpayment_status) AND NEW.downpayment_status = 'Paid' THEN
                    $ins ('Billing', 'Downpayment received', NEW.id, CONCAT(ref, ' · P', NEW.downpayment_amount), $who);
                END IF;
                IF NOT (OLD.total_amount <=> NEW.total_amount) THEN
                    $ins ('Billing', 'Invoice updated', NEW.id, CONCAT(ref, ': P', OLD.total_amount, ' -> P', NEW.total_amount), $who);
                END IF;
            END",
        // ── Accounts ──
        'trg_al_user_ai' => "AFTER INSERT ON users FOR EACH ROW
            $ins ('Accounts', 'Account created', NEW.id, CONCAT(NEW.fullname, ' · ', NEW.role, ' · ', NEW.email), IFNULL(@pv_actor, CONCAT(NEW.fullname, ' (self sign-up)')))",
        'trg_al_user_au' => "AFTER UPDATE ON users FOR EACH ROW
            BEGIN
                IF NOT (OLD.last_login <=> NEW.last_login) AND NEW.last_login IS NOT NULL THEN
                    $ins ('Accounts', 'Signed in', NEW.id, CONCAT(NEW.fullname, ' · ', NEW.role), IF(LOCATE('(', NEW.fullname) > 0, NEW.fullname, CONCAT(NEW.fullname, ' (', NEW.role, ')')));
                END IF;
                IF NOT (OLD.role <=> NEW.role) THEN
                    $ins ('Accounts', 'Role changed', NEW.id, CONCAT(NEW.fullname, ': ', IFNULL(OLD.role, '?'), ' -> ', NEW.role), $who);
                END IF;
                IF NOT (OLD.password <=> NEW.password) THEN
                    $ins ('Accounts', 'Password changed', NEW.id, NEW.fullname, IFNULL(@pv_actor, IF(LOCATE('(', NEW.fullname) > 0, NEW.fullname, CONCAT(NEW.fullname, ' (', NEW.role, ')'))));
                END IF;
                IF NEW.locked_until IS NOT NULL AND NOT (OLD.locked_until <=> NEW.locked_until) THEN
                    $ins ('Accounts', 'Account locked', NEW.id, CONCAT(NEW.fullname, ' · too many failed sign-ins, until ', DATE_FORMAT(NEW.locked_until, '%b %d %h:%i %p')), 'System');
                END IF;
                IF NOT (OLD.fullname <=> NEW.fullname AND OLD.email <=> NEW.email AND OLD.contact_number <=> NEW.contact_number AND OLD.address <=> NEW.address) THEN
                    $ins ('Accounts', 'Profile updated', NEW.id, NEW.fullname, $who);
                END IF;
            END",
        'trg_al_user_ad' => "AFTER DELETE ON users FOR EACH ROW
            $ins ('Accounts', 'Account deleted', OLD.id, CONCAT(OLD.fullname, ' · ', OLD.role, ' · ', OLD.email), $who)",
        'trg_al_pet_ai' => "AFTER INSERT ON pets FOR EACH ROW
            $ins ('Accounts', 'Pet registered', NEW.id, CONCAT(NEW.name, ' (', IFNULL(NEW.species, '?'), IFNULL(CONCAT(', ', NULLIF(NEW.breed, '')), ''), ') · owner: ', IFNULL((SELECT fullname FROM users WHERE id = NEW.user_id), '?')), $who)",
        // ── Medical ──
        'trg_al_cons_ai' => "AFTER INSERT ON consultations FOR EACH ROW
            $ins ('Medical', 'Consultation recorded', NEW.appointment_id, CONCAT('Appointment #', NEW.appointment_id, IFNULL(CONCAT(' · ', NULLIF(LEFT(NEW.diagnosis, 120), '')), '')), $who)",
        'trg_al_mr_ai' => "AFTER INSERT ON medical_records FOR EACH ROW
            $ins ('Medical', 'Medical record added', NEW.pet_id, CONCAT(IFNULL((SELECT name FROM pets WHERE id = NEW.pet_id), CONCAT('Pet #', NEW.pet_id)), ' · ', IFNULL(NEW.record_type, ''), IFNULL(CONCAT(': ', NULLIF(NEW.title, '')), '')), $who)",
        'trg_al_mr_au' => "AFTER UPDATE ON medical_records FOR EACH ROW
            $ins ('Medical', 'Medical record edited', NEW.pet_id, CONCAT(IFNULL((SELECT name FROM pets WHERE id = NEW.pet_id), CONCAT('Pet #', NEW.pet_id)), ' · ', IFNULL(NEW.record_type, ''), IFNULL(CONCAT(': ', NULLIF(NEW.title, '')), '')), $who)",
        'trg_al_vac_ai' => "AFTER INSERT ON vaccination_records FOR EACH ROW
            $ins ('Medical', 'Vaccination recorded', NEW.pet_id, CONCAT(IFNULL((SELECT name FROM pets WHERE id = NEW.pet_id), CONCAT('Pet #', NEW.pet_id)), ' · ', NEW.vaccine_name, ' on ', DATE_FORMAT(NEW.date_given, '%b %d, %Y')), $who)",
        // ── Certificates ──
        'trg_al_cert_ai' => "AFTER INSERT ON certificate_requests FOR EACH ROW
            $ins ('Certificates', 'Certificate requested', NEW.id, CONCAT('#', NEW.id, ' · ', IFNULL(NEW.purpose, ''), ' · ', IFNULL((SELECT name FROM pets WHERE id = NEW.pet_id), '')), $who)",
        'trg_al_cert_au' => "AFTER UPDATE ON certificate_requests FOR EACH ROW
            BEGIN
                IF NOT (OLD.status <=> NEW.status) THEN
                    $ins ('Certificates', 'Certificate status changed', NEW.id, CONCAT('#', NEW.id, ' · ', IFNULL(NEW.purpose, ''), ': ', IFNULL(OLD.status, '?'), ' -> ', IFNULL(NEW.status, '?')), $who);
                END IF;
            END",
        // ── Settings ──
        'trg_al_sp_au' => "AFTER UPDATE ON service_prices FOR EACH ROW
            BEGIN
                IF NOT (OLD.price <=> NEW.price) THEN
                    $ins ('Settings', 'Service price changed', NEW.id, CONCAT(NEW.service_name, ': P', OLD.price, ' -> P', NEW.price), $who);
                END IF;
            END",
        'trg_al_dws_au' => "AFTER UPDATE ON doctor_weekly_schedule FOR EACH ROW
            BEGIN
                IF NOT (OLD.is_working <=> NEW.is_working AND OLD.work_start <=> NEW.work_start AND OLD.work_end <=> NEW.work_end) THEN
                    $ins ('Settings', 'Vet schedule changed', NEW.doctor_id, CONCAT(IFNULL((SELECT fullname FROM users WHERE id = NEW.doctor_id), 'Vet'), ' · ', ELT(NEW.day_of_week + 1, 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'), ': ',
                           IF(NEW.is_working = 1, CONCAT(TIME_FORMAT(NEW.work_start, '%h:%i %p'), ' - ', TIME_FORMAT(NEW.work_end, '%h:%i %p')), 'day off')), $who);
                END IF;
            END",
        'trg_al_dws_ai' => "AFTER INSERT ON doctor_weekly_schedule FOR EACH ROW
            $ins ('Settings', 'Vet schedule changed', NEW.doctor_id, CONCAT(IFNULL((SELECT fullname FROM users WHERE id = NEW.doctor_id), 'Vet'), ' · ', ELT(NEW.day_of_week + 1, 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'), ': ',
                   IF(NEW.is_working = 1, CONCAT(TIME_FORMAT(NEW.work_start, '%h:%i %p'), ' - ', TIME_FORMAT(NEW.work_end, '%h:%i %p')), 'day off')), $who)",
    ];
    foreach ($triggers as $name => $body) {
        if (empty($have[$name])) $conn->query("CREATE TRIGGER $name $body");
    }

    // First run: add earlier history reconstructed from saved dates, so the log isn't empty
    $hist = $conn->query("SELECT 1 FROM activity_log WHERE source = 'history' LIMIT 1");
    if ($hist && $hist->num_rows === 0) al_backfill($conn);
}

/** One-time: events reconstructed from timestamps already stored in the records. */
function al_backfill($conn) {
    $h = "INSERT INTO activity_log (module, action, record_id, details, actor, source, created_at)";
    $conn->query("$h SELECT 'Appointments', 'Booked', a.id, CONCAT('#', a.id, ' ', a.pet_name, ' — ', a.service_type, ' on ', DATE_FORMAT(a.appointment_date, '%b %d, %Y'), ' ', TIME_FORMAT(a.appointment_time, '%h:%i %p')),
                     IFNULL(IF(LOCATE('(', u.fullname) > 0, u.fullname, CONCAT(u.fullname, ' (', u.role, ')')), 'Client'), 'history', a.created_at FROM appointments a LEFT JOIN users u ON u.id = a.user_id WHERE a.created_at IS NOT NULL");
    $conn->query("$h SELECT 'Appointments', 'Approved', a.id, CONCAT('#', a.id, ' ', a.pet_name, ' — ', a.service_type), 'Clinic staff', 'history', a.approved_at FROM appointments a WHERE a.approved_at IS NOT NULL");
    $conn->query("$h SELECT 'Appointments', 'Reservation fee paid', a.id, CONCAT('#', a.id, ' ', a.pet_name, ' — ', a.service_type, ': P', a.downpayment_amount),
                     IFNULL(IF(LOCATE('(', u.fullname) > 0, u.fullname, CONCAT(u.fullname, ' (', u.role, ')')), 'Client'), 'history', a.downpayment_paid_at FROM appointments a LEFT JOIN users u ON u.id = a.user_id WHERE a.downpayment_paid_at IS NOT NULL");
    $conn->query("$h SELECT 'Billing', 'Invoice issued', b.id, CONCAT(IFNULL(b.invoice_no, CONCAT('Bill #', b.id)), ' · ', IFNULL(b.client_name, ''), ' · P', b.total_amount), 'Clinic staff', 'history', b.created_at FROM billing b WHERE b.created_at IS NOT NULL");
    $conn->query("$h SELECT 'Billing', 'Payment received', b.id, CONCAT(IFNULL(b.invoice_no, CONCAT('Bill #', b.id)), ' · ', IFNULL(b.client_name, ''), ' · P', b.total_amount, IFNULL(CONCAT(' via ', b.payment_method), '')),
                     IF(b.payment_method IN ('Cash', 'Reservation Fee'), 'Clinic staff', IFNULL(b.client_name, 'Client')), 'history', b.paid_at FROM billing b WHERE b.paid_at IS NOT NULL AND b.payment_status = 'Paid'");
    $conn->query("$h SELECT 'Accounts', 'Account created', u.id, CONCAT(u.fullname, ' · ', u.role, ' · ', u.email), IF(LOCATE('(', u.fullname) > 0, u.fullname, CONCAT(u.fullname, ' (', u.role, ')')), 'history', u.created_at FROM users u WHERE u.created_at IS NOT NULL");
    $conn->query("$h SELECT 'Accounts', 'Signed in', u.id, CONCAT(u.fullname, ' · ', u.role), IF(LOCATE('(', u.fullname) > 0, u.fullname, CONCAT(u.fullname, ' (', u.role, ')')), 'history', u.last_login FROM users u WHERE u.last_login IS NOT NULL");
    $conn->query("$h SELECT 'Accounts', 'Pet registered', p.id, CONCAT(p.name, ' (', IFNULL(p.species, '?'), ') · owner: ', IFNULL(u.fullname, '?')), IFNULL(IF(LOCATE('(', u.fullname) > 0, u.fullname, CONCAT(u.fullname, ' (', u.role, ')')), 'Client'), 'history', p.created_at
                     FROM pets p LEFT JOIN users u ON u.id = p.user_id WHERE p.created_at IS NOT NULL");
    $conn->query("$h SELECT 'Medical', 'Consultation recorded', c.appointment_id, CONCAT('Appointment #', c.appointment_id, IFNULL(CONCAT(' · ', NULLIF(LEFT(c.diagnosis, 120), '')), '')), IFNULL(NULLIF(c.vet_name, ''), 'Veterinarian'), 'history', c.created_at FROM consultations c WHERE c.created_at IS NOT NULL");
    $conn->query("$h SELECT 'Medical', 'Medical record added', m.pet_id, CONCAT(IFNULL(p.name, CONCAT('Pet #', m.pet_id)), ' · ', IFNULL(m.record_type, ''), IFNULL(CONCAT(': ', NULLIF(m.title, '')), '')), IFNULL(NULLIF(m.doctor_name, ''), 'Veterinarian'), 'history', m.created_at
                     FROM medical_records m LEFT JOIN pets p ON p.id = m.pet_id WHERE m.created_at IS NOT NULL");
    $conn->query("$h SELECT 'Medical', 'Vaccination recorded', v.pet_id, CONCAT(IFNULL(p.name, CONCAT('Pet #', v.pet_id)), ' · ', v.vaccine_name, ' on ', DATE_FORMAT(v.date_given, '%b %d, %Y')), IFNULL(NULLIF(v.administered_by, ''), 'Veterinarian'), 'history', v.created_at
                     FROM vaccination_records v LEFT JOIN pets p ON p.id = v.pet_id WHERE v.created_at IS NOT NULL");
    $conn->query("$h SELECT 'Certificates', 'Certificate requested', c.id, CONCAT('#', c.id, ' · ', IFNULL(c.purpose, ''), ' · ', IFNULL(p.name, '')), IFNULL(IF(LOCATE('(', u.fullname) > 0, u.fullname, CONCAT(u.fullname, ' (', u.role, ')')), 'Client'), 'history', c.created_at
                     FROM certificate_requests c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN pets p ON p.id = c.pet_id WHERE c.created_at IS NOT NULL");
    // marker so the backfill never runs twice, even on an empty database
    $conn->query("$h VALUES ('Settings', 'Activity log started', NULL, 'Automatic activity logging turned on', 'System', 'history', NOW())");
}

/** Shared SQL for the merged feed (activity_log + boarding + inventory). */
function al_feed_sql() {
    return "SELECT * FROM (
        SELECT created_at, module, action, record_id, details, actor, source FROM activity_log
        UNION ALL
        SELECT created_at, 'Boarding', action, reservation_id, CONCAT('Reservation #', reservation_id, ' · ', IFNULL(details, '')), actor, 'live' FROM boarding_audit_log
        UNION ALL
        SELECT created_at, 'Inventory', movement_type, item_id,
               IF(movement_type = 'Price change', CONCAT(IFNULL(item_name, ''), ': ', IFNULL(reference, '')),
                  CONCAT(IFNULL(item_name, ''), ': ', IF(qty_change > 0, '+', ''), qty_change, ', ', IFNULL(qty_after, 0), ' left', IFNULL(CONCAT(' · ', reference), ''))),
               actor, 'live' FROM inventory_movements
    ) feed";
}

/** Filtered page of events. $f: module, actor, q, from, to. Returns [rows, total]. */
function al_query($conn, $f, $limit = 50, $offset = 0) {
    $w = [];
    if (!empty($f['module']) && isset(AL_MODULES[$f['module']])) $w[] = "module = '" . $conn->real_escape_string($f['module']) . "'";
    if (!empty($f['actor'])) $w[] = "actor = '" . $conn->real_escape_string($f['actor']) . "'";
    if (!empty($f['q'])) {
        $q = $conn->real_escape_string($f['q']);
        $w[] = "(action LIKE '%$q%' OR details LIKE '%$q%' OR actor LIKE '%$q%')";
    }
    if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) $w[] = "created_at >= '{$f['from']} 00:00:00'";
    if (!empty($f['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to']))   $w[] = "created_at <= '{$f['to']} 23:59:59'";
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $total = (int)($conn->query("SELECT COUNT(*) c FROM (" . al_feed_sql() . ") x $where")->fetch_assoc()['c'] ?? 0);
    $rows = [];
    $limit_sql = $limit > 0 ? " LIMIT " . (int)$offset . ", " . (int)$limit : '';
    $r = $conn->query(al_feed_sql() . "$where ORDER BY created_at DESC$limit_sql");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    return [$rows, $total];
}
