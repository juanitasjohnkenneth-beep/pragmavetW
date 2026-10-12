<?php
/**
 * Appointment Automation Engine
 * Handles automatic status transitions, reminders, and summaries
 */

/**
 * Process automatic status transitions
 * Call this via cron job or on system access
 */
function process_automations($conn) {
    $results = [
        'approved_to_scheduled' => 0,
        'scheduled_to_no_show' => 0,
        'reminders_sent' => 0,
        'summaries_generated' => 0,
        'archived' => 0,
        'errors' => []
    ];
    
    try {
        // 1. Auto-approve pending appointments if setting enabled
        $settings = get_clinic_settings($conn);
        if (($settings['auto_approve_appointments'] ?? '0') === '1') {
            $stmt = $conn->prepare("
                UPDATE appointments 
                SET status = 'approved', updated_by = 1
                WHERE status = 'pending' 
                  AND appointment_date >= CURDATE()
                  AND (created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR))
            ");
            $stmt->execute();
            $results['approved_count'] = $stmt->affected_rows;
            
            // Record history for auto-approved
            if ($stmt->affected_rows > 0) {
                $stmt2 = $conn->prepare("
                    INSERT INTO appointment_history (appointment_id, user_id, action, from_status, to_status, description)
                    SELECT id, 1, 'auto_approved', 'pending', 'approved', 'Auto-approved by system'
                    FROM appointments 
                    WHERE status = 'approved' 
                      AND id NOT IN (SELECT appointment_id FROM appointment_history WHERE action = 'auto_approved')
                ");
                $stmt2->execute();
            }
        }
        
        // 2. Approve -> Scheduled for today's appointments
        $stmt = $conn->prepare("
            UPDATE appointments 
            SET status = 'scheduled', updated_by = 1
            WHERE status = 'approved' 
              AND appointment_date = CURDATE()
              AND appointment_time <= CURTIME()
        ");
        $stmt->execute();
        $results['approved_to_scheduled'] = $stmt->affected_rows;
        
        // 3. Check for no-shows (scheduled appointments past their time + grace period)
        $grace_period = (int)($settings['no_show_grace_period'] ?? 15);
        $stmt = $conn->prepare("
            UPDATE appointments 
            SET status = 'no_show', updated_by = 1
            WHERE status = 'scheduled' 
              AND appointment_date <= CURDATE()
              AND ADDTIME(CONCAT(appointment_date, ' ', appointment_time), SEC_TO_TIME(? * 60)) < NOW()
              AND check_in_time IS NULL
        ");
        $stmt->bind_param("i", $grace_period);
        $stmt->execute();
        $results['scheduled_to_no_show'] = $stmt->affected_rows;
        
        // 4. Send reminders for upcoming appointments
        $reminder_hours = (int)($settings['reminder_hours_before'] ?? 24);
        $stmt = $conn->prepare("
            SELECT a.*, u.fullname as client_name, u.email as client_email, 
                   u.phone as client_phone, COALESCE(d.fullname, 'Clinic Staff') as staff_name
            FROM appointments a
            JOIN users u ON a.user_id = u.id
            LEFT JOIN users d ON a.doctor_id = d.id
            WHERE a.status IN ('approved', 'scheduled')
              AND a.appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              AND a.reminder_sent = 0
              AND a.deleted_at IS NULL
            LIMIT 50
        ");
        $stmt->execute();
        $upcoming = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($upcoming as $appt) {
            try {
                // Create in-app notification
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, title, message, link, reference_type, reference_id, sent_via)
                    VALUES (?, 'reminder', 'Appointment Reminder', ?, ?, 'appointment', ?, '[\"in_app\"]')
                ");
                $message = "Reminder: You have an appointment for {$appt['pet_name']} tomorrow ({$appt['appointment_date']}) at " . date('h:i A', strtotime($appt['appointment_time']));
                $link = "appointments.php?view={$appt['id']}";
                $stmt->bind_param("issi", $appt['user_id'], $message, $link, $appt['id']);
                $stmt->execute();
                
                // Send email notification if enabled
                if (($settings['enable_email_notifications'] ?? '1') === '1') {
                    send_appointment_email($conn, $appt['id'], 'appointment_reminder');
                }
                
                // Mark reminder as sent
                $stmt = $conn->prepare("UPDATE appointments SET reminder_sent = 1, reminder_sent_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $appt['id']);
                $stmt->execute();
                
                $results['reminders_sent']++;
                
            } catch (Exception $e) {
                $results['errors'][] = "Reminder failed for appointment {$appt['id']}: " . $e->getMessage();
            }
        }
        
        // 5. Archive old appointments based on retention policy
        $retention_months = (int)($settings['retention_period_months'] ?? 12);
        $archive_date = date('Y-m-d', strtotime("-{$retention_months} months"));
        
        // First, get appointments to archive
        $stmt = $conn->prepare("
            SELECT id, appointment_number FROM appointments 
            WHERE status IN ('completed', 'cancelled', 'no_show', 'rejected')
              AND appointment_date < ?
              AND deleted_at IS NULL
            LIMIT 100
        ");
        $stmt->bind_param("s", $archive_date);
        $stmt->execute();
        $to_archive = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($to_archive as $arch) {
            // Soft delete
            $stmt = $conn->prepare("UPDATE appointments SET deleted_at = NOW(), updated_by = 1 WHERE id = ?");
            $stmt->bind_param("i", $arch['id']);
            $stmt->execute();
            $results['archived']++;
        }
        
        // 6. Generate daily summary
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM activity_logs WHERE DATE(created_at) = CURDATE() AND action LIKE 'appointment_%'");
        $stmt->execute();
        $today_activity = $stmt->get_result()->fetch_assoc()['count'];
        
        if ($today_activity > 0) {
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, link, reference_type, reference_id, sent_via)
                VALUES (1, 'summary', 'Daily Appointment Summary', ?, 'appointments.php', 'system', 0, '[\"in_app\"]')
            ");
            $message = "Today's appointment activity: {$today_activity} actions recorded. View the full report for details.";
            $stmt->bind_param("s", $message);
            $stmt->execute();
            $results['summaries_generated']++;
        }
        
        $results['success'] = true;
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['errors'][] = $e->getMessage();
    }
    
    return $results;
}

/**
 * Send appointment email notification via PHPMailer
 */
function send_appointment_email($conn, $appointment_id, $event_type) {
    global $conn;
    
    // Get appointment details
    $stmt = $conn->prepare("
        SELECT a.*, u.email, u.fullname as client_name, 
               COALESCE(d.fullname, 'Clinic Staff') as staff_name,
               s.name as service_name
        FROM appointments a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN users d ON a.doctor_id = d.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.id = ?
    ");
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    
    if (!$appt || empty($appt['email'])) return false;
    
    // Get email template
    $stmt = $conn->prepare("SELECT title_template, message_template FROM notification_templates WHERE event = ?");
    $stmt->bind_param("s", $event_type);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    
    if (!$template) return false;
    
    // Replace placeholders
    $replacements = [
        '{pet_name}' => $appt['pet_name'],
        '{date}' => date('F j, Y', strtotime($appt['appointment_date'])),
        '{time}' => date('h:i A', strtotime($appt['appointment_time'])),
        '{client_name}' => $appt['client_name'],
        '{staff_name}' => $appt['staff_name'],
        '{service_name}' => $appt['service_name'] ?? $appt['service_type'],
        '{appointment_number}' => $appt['appointment_number'] ?? '',
        '{clinic_name}' => 'PragmaVet Animal Clinic',
        '{clinic_phone}' => '046-8851-221',
        '{clinic_address}' => '0234 Unit B Palico St, Palico 4, Imus, Cavite'
    ];
    
    $subject = str_replace(array_keys($replacements), array_values($replacements), $template['title_template']);
    $body = str_replace(array_keys($replacements), array_values($replacements), $template['message_template']);
    
    try {
        require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
        require_once __DIR__ . '/../PHPMailer/src/Exception.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'pragmavet@gmail.com';
        $mail->Password = ''; // App password needed
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('pragmavet@gmail.com', 'PragmaVet Animal Clinic');
        $mail->addAddress($appt['email'], $appt['client_name']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        $html_body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: linear-gradient(135deg, #1E5AA8, #4A7FC4); padding: 30px; text-align: center;'>
                <h1 style='color: #fff; margin: 0; font-size: 24px;'>PragmaVet Animal Clinic</h1>
            </div>
            <div style='padding: 30px; background: #fff; border: 1px solid #e2e8f0;'>
                <p>Dear {$appt['client_name']},</p>
                <p>{$body}</p>
                <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                <p style='color: #64748b; font-size: 12px;'>
                    PragmaVet Animal Clinic<br>
                    0234 Unit B Palico St, Palico 4, Imus, Cavite<br>
                    Tel: 046-8851-221 | Mobile: 0954-285-3053
                </p>
            </div>
        </div>";
        
        $mail->Body = $html_body;
        $mail->AltBody = strip_tags($body);
        $mail->send();
        
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed for appointment {$appointment_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Run automation on page load (lightweight version)
 */
function run_quick_automations($conn) {
    // Only run automations periodically (every 15 minutes)
    $last_run = $_SESSION['last_automation_run'] ?? 0;
    if (time() - $last_run < 900) return; // 15 minutes
    
    $_SESSION['last_automation_run'] = time();
    
    $results = [];
    $settings = get_clinic_settings($conn);
    
    // Quick status transitions
    // Approved -> Scheduled for today
    $stmt = $conn->prepare("
        UPDATE appointments SET status = 'scheduled'
        WHERE status = 'approved' AND appointment_date = CURDATE() AND appointment_time <= CURTIME()
    ");
    $stmt->execute();
    if ($stmt->affected_rows > 0) $results['approved_to_scheduled'] = $stmt->affected_rows;
    
    // No-show check
    $grace = (int)($settings['no_show_grace_period'] ?? 15);
    $stmt = $conn->prepare("
        UPDATE appointments SET status = 'no_show'
        WHERE status = 'scheduled' 
          AND appointment_date <= CURDATE()
          AND ADDTIME(CONCAT(appointment_date, ' ', appointment_time), SEC_TO_TIME(? * 60)) < NOW()
          AND check_in_time IS NULL
    ");
    $stmt->bind_param("i", $grace);
    $stmt->execute();
    if ($stmt->affected_rows > 0) $results['scheduled_to_no_show'] = $stmt->affected_rows;
    
    return $results;
}

/**
 * Get automation status and history
 */
function get_automation_status($conn) {
    $stmt = $conn->query("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_approval,
               SUM(CASE WHEN status = 'approved' AND appointment_date = CURDATE() THEN 1 ELSE 0 END) as to_schedule_today,
               SUM(CASE WHEN status = 'scheduled' AND appointment_date < CURDATE() AND check_in_time IS NULL THEN 1 ELSE 0 END) as potential_no_shows,
               SUM(CASE WHEN reminder_sent = 0 AND status IN ('approved', 'scheduled') AND appointment_date > CURDATE() THEN 1 ELSE 0 END) as pending_reminders
        FROM appointments WHERE deleted_at IS NULL
    ");
    $stats = $stmt->fetch_assoc();
    
    return [
        'stats' => $stats,
        'last_run' => $_SESSION['last_automation_run'] ?? 0,
        'settings' => [
            'auto_approve' => get_clinic_settings($conn)['auto_approve_appointments'] ?? '0',
            'no_show_grace' => get_clinic_settings($conn)['no_show_grace_period'] ?? '15',
            'reminder_hours' => get_clinic_settings($conn)['reminder_hours_before'] ?? '24',
            'retention_months' => get_clinic_settings($conn)['retention_period_months'] ?? '12'
        ]
    ];
}