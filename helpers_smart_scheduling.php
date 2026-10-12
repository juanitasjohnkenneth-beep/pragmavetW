<?php
/**
 * Smart Scheduling Validation Helper
 * Reusable functions for appointment slot validation
 */

/**
 * Validates if an appointment slot is available
 * Returns ['valid' => true/false, 'message' => 'reason explained', 'data' => [...]]
 */
function validate_appointment_slot($conn, $doctor_id, $date, $time, $service_type, $check_24hour = true) {
    $validation = [
        'valid' => false,
        'message' => '',
        'data' => []
    ];
    
    // 1. Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $validation['message'] = 'Invalid date format';
        return $validation;
    }
    
    // 2. Validate time format
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        $validation['message'] = 'Invalid time format';
        return $validation;
    }
    
    // 3. Check date is not in the past
    $today = date('Y-m-d');
    if ($date < $today) {
        $validation['message'] = 'Cannot book appointments in the past';
        return $validation;
    }
    
    // 4. Check 24-hour advance booking requirement (if enabled)
    if ($check_24hour) {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        if ($date < $tomorrow) {
            $validation['message'] = 'Appointments must be booked at least 24 hours in advance';
            $validation['data']['earliest_date'] = $tomorrow;
            return $validation;
        }
    }
    
    // 5. Get service duration
    $duration_minutes = 30;
    $stmt_duration = $conn->prepare("SELECT duration_minutes FROM service_durations WHERE service_type = ?");
    if ($stmt_duration) {
        $stmt_duration->bind_param("s", $service_type);
        $stmt_duration->execute();
        $service_result = $stmt_duration->get_result();
        $service_data = $service_result ? $service_result->fetch_assoc() : null;
        $duration_minutes = $service_data ? $service_data['duration_minutes'] : 30;
    } else {
        error_log('helpers_smart_scheduling.php: failed to prepare service duration query - ' . $conn->error);
    }
    $validation['data']['duration_minutes'] = $duration_minutes;
    
    // 6. Calculate appointment end time
    $appointment_end_time = date('H:i:s', strtotime($time . ' +' . $duration_minutes . ' minutes'));
    $validation['data']['end_time'] = $appointment_end_time;
    
    // If no doctor specified, validation passes (will be assigned by system)
    if (empty($doctor_id)) {
        $validation['valid'] = true;
        $validation['message'] = 'Valid slot - no doctor assigned yet';
        return $validation;
    }
    
    // 7. Check doctor exists and is a doctor
    $doc_stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ? AND role = 'doctor'");
    if (!$doc_stmt) {
        error_log('helpers_smart_scheduling.php: prepare failed on users lookup - ' . $conn->error);
        $validation['message'] = 'Database error while validating doctor';
        return $validation;
    }
    $doc_stmt->bind_param("i", $doctor_id);
    $doc_stmt->execute();
    $doc_result = $doc_stmt->get_result();
    if ($doc_result->num_rows === 0) {
        $validation['message'] = 'Doctor not found or invalid';
        return $validation;
    }
    
    $doctor_info = $doc_result->fetch_assoc();
    $doc_start_time = '08:00:00';
    $doc_end_time = '17:00:00';
    $buffer_mins = 15;
    $max_appts = 10;
    
    $validation['data']['doctor_name'] = $doctor_info['fullname'];
    $validation['data']['doctor_hours'] = substr($doc_start_time, 0, 5) . ' - ' . substr($doc_end_time, 0, 5);
    
    // 8. Check if doctor is on leave
    $chk = $conn->prepare("SELECT id FROM doctor_unavailability WHERE doctor_id = ? AND unavailable_date = ?");
    $chk->bind_param("is", $doctor_id, $date);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $validation['message'] = 'Doctor is not available on this date (on leave)';
        return $validation;
    }
    
    // 9. Validate time is within working hours
    $slot_time = strtotime($time);
    $slot_end = strtotime($appointment_end_time);
    $working_start = strtotime($doc_start_time);
    $working_end = strtotime($doc_end_time);
    
    if ($slot_time < $working_start || $slot_end > $working_end) {
        $validation['message'] = 'Time is outside doctor\'s working hours (' . substr($doc_start_time, 0, 5) . ' - ' . substr($doc_end_time, 0, 5) . ')';
        return $validation;
    }
    
    // 10. Check for slot conflicts (prevent double-booking)
    $conflict_stmt = $conn->prepare("
        SELECT id, appointment_time, service_duration_minutes 
        FROM appointments 
        WHERE doctor_id = ? AND appointment_date = ? AND status IN ('Pending', 'Approved')
    ");
    $conflict_stmt->bind_param("is", $doctor_id, $date);
    $conflict_stmt->execute();
    $conflict_result = $conflict_stmt->get_result();
    
    while ($existing = $conflict_result->fetch_assoc()) {
        $existing_start = strtotime($existing['appointment_time']);
        $existing_duration = $existing['service_duration_minutes'] ?? 30;
        $existing_end = $existing_start + ($existing_duration * 60);
        
        // Add buffer on both sides
        $buffer_seconds = $buffer_mins * 60;
        $check_start = $existing_start - $buffer_seconds;
        $check_end = $existing_end + $buffer_seconds;
        
        // Check if proposed slot overlaps with existing appointment + buffer
        if (!($slot_end <= $check_start || $slot_time >= $check_end)) {
            $next_available = date('H:i', $existing_end + $buffer_seconds);
            $validation['message'] = 'Time slot conflicts with existing appointment. Next available: ' . $next_available;
            $validation['data']['conflicting_time'] = $existing['appointment_time'];
            $validation['data']['suggested_next_time'] = $next_available;
            return $validation;
        }
    }
    
    // 11. Check doctor capacity
    $capacity_stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM appointments 
        WHERE doctor_id = ? AND appointment_date = ? AND status IN ('Pending', 'Approved')
    ");
    $capacity_stmt->bind_param("is", $doctor_id, $date);
    $capacity_stmt->execute();
    $capacity_result = $capacity_stmt->get_result()->fetch_assoc();
    $current_count = $capacity_result['count'];
    
    if ($current_count >= $max_appts) {
        $validation['message'] = 'Doctor is at maximum capacity for this date (' . $current_count . '/' . $max_appts . ')';
        $validation['data']['capacity'] = ['current' => $current_count, 'max' => $max_appts];
        return $validation;
    }
    
    $validation['data']['capacity'] = ['current' => $current_count, 'max' => $max_appts, 'remaining' => $max_appts - $current_count];
    
    // All checks passed
    $validation['valid'] = true;
    $validation['message'] = 'Slot is available';
    return $validation;
}

/**
 * Get next available slots for a doctor on a given date
 */
function get_available_slots($conn, $doctor_id, $date, $service_type, $num_slots = 5) {
    // Get service duration
    $duration_minutes = 30;
    $stmt_duration = $conn->prepare("SELECT duration_minutes FROM service_durations WHERE service_type = ?");
    if ($stmt_duration) {
        $stmt_duration->bind_param("s", $service_type);
        $stmt_duration->execute();
        $service_result = $stmt_duration->get_result();
        $service_data = $service_result ? $service_result->fetch_assoc() : null;
        $duration_minutes = $service_data ? $service_data['duration_minutes'] : 30;
    } else {
        error_log('helpers_smart_scheduling.php: failed to prepare service duration query - ' . $conn->error);
    }
    
    // Get doctor's working hours
    $doc_stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ? AND role = 'doctor'");
    if (!$doc_stmt) {
        error_log('helpers_smart_scheduling.php: prepare failed on doctor availability lookup - ' . $conn->error);
        return [];
    }
    $doc_stmt->bind_param("i", $doctor_id);
    $doc_stmt->execute();
    $doc_result = $doc_stmt->get_result();
    if ($doc_result->num_rows === 0) return [];
    
    $doctor_info = $doc_result->fetch_assoc();
    $start_time = '08:00:00';
    $end_time = '17:00:00';
    $buffer_mins = 15;
    
    // Get existing bookings
    $conflict_stmt = $conn->prepare("
        SELECT appointment_time, service_duration_minutes FROM appointments 
        WHERE doctor_id = ? AND appointment_date = ? AND status IN ('Pending', 'Approved')
        ORDER BY appointment_time ASC
    ");
    $conflict_stmt->bind_param("is", $doctor_id, $date);
    $conflict_stmt->execute();
    $booked_result = $conflict_stmt->get_result();
    
    $booked_slots = [];
    while ($booked = $booked_result->fetch_assoc()) {
        $booked_slots[] = [
            'start' => strtotime($booked['appointment_time']),
            'end' => strtotime($booked['appointment_time']) + (($booked['service_duration_minutes'] ?? 30) * 60)
        ];
    }
    
    // Generate available slots
    $available = [];
    $current_time = strtotime($start_time);
    $end_time_timestamp = strtotime($end_time);
    $slot_interval = 30; // 30-minute intervals
    
    while ($current_time < $end_time_timestamp && count($available) < $num_slots) {
        $slot_time = date('H:i', $current_time);
        $slot_end = $current_time + ($duration_minutes * 60);
        
        // Check if slot is available
        $is_available = true;
        foreach ($booked_slots as $booked) {
            $buffer_seconds = $buffer_mins * 60;
            $check_start = $booked['start'] - $buffer_seconds;
            $check_end = $booked['end'] + $buffer_seconds;
            
            if (!($slot_end <= $check_start || $current_time >= $check_end)) {
                $is_available = false;
                break;
            }
        }
        
        // Ensure slot doesn't exceed working hours
        if ($slot_end > $end_time_timestamp) {
            $is_available = false;
        }
        
        if ($is_available) {
            $available[] = [
                'time' => $slot_time,
                'end_time' => date('H:i', $slot_end)
            ];
        }
        
        $current_time += ($slot_interval * 60);
    }
    
    return $available;
}
?>
