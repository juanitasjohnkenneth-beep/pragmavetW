<?php
/**
 * PragmaVet Intelligent Reminder Pop-Up Component
 * Automatically detects upcoming appointments, overdue vaccinations,
 * and active boarding stays, presenting a clean, non-intrusive popup modal.
 */

if (!isset($_SESSION['user_id']) || !isset($conn)) {
    return;
}

$remind_user_id = (int)$_SESSION['user_id'];
$reminders = [];

// 1. Check for Urgent Reschedule Proposals requiring client action
$stmt_resched = $conn->prepare("
    SELECT id, pet_name, service_type, appointment_date, appointment_time, 
           proposed_reschedule_date, proposed_reschedule_time, admin_message
    FROM appointments 
    WHERE user_id = ? AND client_action_needed = 1 AND status LIKE '%Reschedule%'
    LIMIT 3
");
if ($stmt_resched) {
    $stmt_resched->bind_param("i", $remind_user_id);
    $stmt_resched->execute();
    $res = $stmt_resched->get_result();
    while ($row = $res->fetch_assoc()) {
        $reminders[] = [
            'type'        => 'reschedule',
            'severity'    => 'warning',
            'badge'       => 'Action Required',
            'badge_color' => '#f59e0b',
            'icon'        => 'fa-clock-rotate-left',
            'title'       => 'Reschedule Proposal: ' . htmlspecialchars($row['pet_name']),
            'description' => 'The clinic proposed rescheduling your ' . htmlspecialchars($row['service_type']) . 
                             ' to ' . date('M d, Y', strtotime($row['proposed_reschedule_date'])) . 
                             ' at ' . date('h:i A', strtotime($row['proposed_reschedule_time'] ?? '08:00:00')) . '.',
            'link'        => 'appointments.php',
            'action_text' => 'Review & Confirm'
        ];
    }
}

// 2. Check for Upcoming Appointments (Today, Tomorrow, or within 3 days)
$stmt_appts = $conn->prepare("
    SELECT a.id, a.pet_name, a.service_type, a.appointment_date, a.appointment_time, 
           COALESCE(u.fullname, 'Attending Vet') as doctor_name
    FROM appointments a
    LEFT JOIN users u ON a.doctor_id = u.id
    WHERE a.user_id = ? 
      AND a.status IN ('Approved', 'Scheduled', 'approved', 'scheduled')
      AND a.appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 3
");
if ($stmt_appts) {
    $stmt_appts->bind_param("i", $remind_user_id);
    $stmt_appts->execute();
    $res = $stmt_appts->get_result();
    while ($row = $res->fetch_assoc()) {
        $appt_date = $row['appointment_date'];
        $is_today = ($appt_date === date('Y-m-d'));
        $is_tomorrow = ($appt_date === date('Y-m-d', strtotime('+1 day')));
        
        $badge_text = $is_today ? 'Today' : ($is_tomorrow ? 'Tomorrow' : date('M d', strtotime($appt_date)));
        $badge_color = $is_today ? '#2563eb' : '#059669';
        
        $reminders[] = [
            'type'        => 'appointment',
            'severity'    => 'primary',
            'badge'       => $badge_text,
            'badge_color' => $badge_color,
            'icon'        => 'fa-calendar-check',
            'title'       => 'Upcoming Appointment: ' . htmlspecialchars($row['pet_name']),
            'description' => htmlspecialchars($row['service_type']) . ' scheduled for ' . 
                             ($is_today ? 'Today' : date('M d, Y', strtotime($appt_date))) . 
                             ' at ' . date('h:i A', strtotime($row['appointment_time'])) . 
                             ' with ' . htmlspecialchars($row['doctor_name']) . '.',
            'link'        => 'appointments.php',
            'action_text' => 'View Appointment'
        ];
    }
}

// 3. Check for Overdue or Due-Soon Vaccinations
$stmt_vax = $conn->prepare("
    SELECT vr.id, vr.vaccine_name, vr.next_due, vr.status, p.name as pet_name
    FROM vaccination_records vr
    JOIN pets p ON vr.pet_id = p.id
    WHERE p.user_id = ?
      AND (
          vr.status = 'Overdue' 
          OR vr.status = 'Due soon'
          OR (vr.next_due IS NOT NULL AND vr.next_due <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
      )
    ORDER BY vr.next_due ASC
    LIMIT 3
");
if ($stmt_vax) {
    $stmt_vax->bind_param("i", $remind_user_id);
    $stmt_vax->execute();
    $res = $stmt_vax->get_result();
    while ($row = $res->fetch_assoc()) {
        $is_overdue = (strtotime($row['next_due']) < time());
        $reminders[] = [
            'type'        => 'vaccination',
            'severity'    => $is_overdue ? 'danger' : 'warning',
            'badge'       => $is_overdue ? 'Vaccine Overdue' : 'Vaccine Due Soon',
            'badge_color' => $is_overdue ? '#dc2626' : '#d97706',
            'icon'        => 'fa-syringe',
            'title'       => htmlspecialchars($row['pet_name']) . ' - ' . htmlspecialchars($row['vaccine_name']),
            'description' => (strtotime($row['next_due']) < strtotime(date('Y-m-d')) ? 'Target due date was ' : 'Due on ') . date('M d, Y', strtotime($row['next_due'])) . '. Please schedule a booster dose.',
            'link'        => 'appointments.php',
            'action_text' => 'Book Vaccine Visit'
        ];
    }
}

// 4. Check for Pet Boarding Check-in or Check-out (Today or Tomorrow)
$stmt_board = $conn->prepare("
    SELECT id, pet_name, appointment_date, end_date, cage_number
    FROM boarding_reservations
    WHERE user_id = ? 
      AND status = 'Approved'
      AND (
          appointment_date = CURDATE() OR appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
          OR end_date = CURDATE() OR end_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
      )
    LIMIT 2
");
if ($stmt_board) {
    $stmt_board->bind_param("i", $remind_user_id);
    $stmt_board->execute();
    $res = $stmt_board->get_result();
    while ($row = $res->fetch_assoc()) {
        $is_checkout = ($row['end_date'] === date('Y-m-d') || $row['end_date'] === date('Y-m-d', strtotime('+1 day')));
        $reminders[] = [
            'type'        => 'boarding',
            'severity'    => 'info',
            'badge'       => $is_checkout ? 'Boarding Pick-Up' : 'Boarding Check-In',
            'badge_color' => '#7c3aed',
            'icon'        => 'fa-hotel',
            'title'       => 'Pet Hotel: ' . htmlspecialchars($row['pet_name']),
            'description' => $is_checkout 
                ? 'Your pet boarding concludes on ' . date('M d, Y', strtotime($row['end_date'])) . '. Pick-up before 5:00 PM.'
                : 'Check-in is on ' . date('M d, Y', strtotime($row['appointment_date'])) . ' (Cage #' . ($row['cage_number'] ?: 'TBD') . ').',
            'link'        => 'boarding.php',
            'action_text' => 'View Stay Details'
        ];
    }
}

// If no active reminders, do not output anything
if (empty($reminders)) {
    return;
}
?>

<!-- ========================================== -->
<!-- PRAGMAVET REMINDERS POP-UP MODAL COMPONENT -->
<!-- ========================================== -->
<div class="modal fade" id="pragmaReminderModal" tabindex="-1" aria-labelledby="pragmaReminderModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
        <div class="modal-content" style="border-radius: 20px; border: 1px solid #eef2f6; box-shadow: 0 20px 45px rgba(15, 23, 42, 0.18); overflow: hidden; background: #ffffff;">
            
            <!-- Modal Header -->
            <div class="modal-header border-0 pb-0" style="padding: 24px 24px 12px 24px;">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 13px; background: #2563eb; display: flex; align-items: center; justify-content: center; color: #ffffff; font-size: 19px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28); flex-shrink: 0;">
                        <i class="fa fa-bell"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="pragmaReminderModalLabel" style="color: #182032; font-size: 20px; letter-spacing: -0.3px;">
                            Clinic Reminders
                        </h5>
                        <p class="text-muted mb-0" style="font-size: 13px;">
                            You have <?php echo count($reminders); ?> upcoming pet care notice<?php echo count($reminders) > 1 ? 's' : ''; ?>
                        </p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="dismissPragmaReminder();"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body" style="padding: 16px 24px 20px 24px; max-height: 480px; overflow-y: auto;">
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($reminders as $idx => $r): ?>
                        <div style="background: #f8fafc; border: 1px solid #eef2f6; border-left: 4px solid <?php echo $r['badge_color']; ?>; border-radius: 14px; padding: 16px; transition: all 0.2s ease;">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span style="font-size: 14px; color: <?php echo $r['badge_color']; ?>;">
                                        <i class="fa <?php echo $r['icon']; ?>"></i>
                                    </span>
                                    <span class="fw-bold" style="color: #0f172a; font-size: 15px;">
                                        <?php echo $r['title']; ?>
                                    </span>
                                </div>
                                <span class="badge" style="background: <?php echo $r['badge_color']; ?>15; color: <?php echo $r['badge_color']; ?>; border: 1px solid <?php echo $r['badge_color']; ?>30; font-size: 11px; font-weight: 700; border-radius: 20px; padding: 4px 10px;">
                                    <?php echo $r['badge']; ?>
                                </span>
                            </div>
                            
                            <p class="mb-3" style="color: #475569; font-size: 13.5px; line-height: 1.45;">
                                <?php echo $r['description']; ?>
                            </p>
                            
                            <div class="d-flex justify-content-end">
                                <a href="<?php echo $r['link']; ?>" class="btn btn-sm" style="background: #ffffff; color: #2563eb; border: 1px solid #cbd5e1; font-weight: 600; font-size: 12.5px; border-radius: 8px; padding: 6px 14px; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background='#2563eb';this.style.color='#ffffff';" onmouseout="this.style.background='#ffffff';this.style.color='#2563eb';">
                                    <?php echo $r['action_text']; ?> <i class="fa fa-arrow-right ms-1" style="font-size: 11px;"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer border-0 pt-0" style="padding: 12px 24px 24px 24px; background: #ffffff;">
                <div class="d-flex justify-content-between align-items-center w-100 flex-wrap gap-2">
                    <div class="form-check" style="font-size: 13px; color: #64748b;">
                        <input class="form-check-input" type="checkbox" id="dontShowAgainCheck" style="cursor: pointer;">
                        <label class="form-check-label" for="dontShowAgainCheck" style="cursor: pointer; user-select: none;">
                            Don't show again today
                        </label>
                    </div>
                    <button type="button" class="btn" onclick="dismissPragmaReminder();" style="background: #2563eb; color: #ffffff; font-weight: 700; font-size: 14px; border-radius: 12px; padding: 10px 22px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25); border: none;">
                        Got it, thanks!
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
(function() {
    function launchReminderModal() {
        const todayStr = new Date().toISOString().slice(0, 10);
        const storedDate = localStorage.getItem('pragma_reminder_dismissed_date');
        const sessionDismissed = sessionStorage.getItem('pragma_reminder_session_dismissed');

        if (storedDate === todayStr || sessionDismissed === 'true') {
            return;
        }

        setTimeout(function() {
            var modalEl = document.getElementById('pragmaReminderModal');
            if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        }, 700);
    }

    window.openPragmaReminders = function() {
        var modalEl = document.getElementById('pragmaReminderModal');
        if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }
    };

    window.dismissPragmaReminder = function() {
        const check = document.getElementById('dontShowAgainCheck');
        if (check && check.checked) {
            const todayStr = new Date().toISOString().slice(0, 10);
            localStorage.setItem('pragma_reminder_dismissed_date', todayStr);
        }
        sessionStorage.setItem('pragma_reminder_session_dismissed', 'true');
        
        var modalEl = document.getElementById('pragmaReminderModal');
        if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        }
    };

    function ensureBootstrapAndInit() {
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            var bsScript = document.createElement('script');
            bsScript.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js';
            bsScript.onload = function() {
                launchReminderModal();
            };
            document.body.appendChild(bsScript);
        } else {
            launchReminderModal();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ensureBootstrapAndInit);
    } else {
        ensureBootstrapAndInit();
    }
})();
</script>
