<?php
/**
 * Smart Scheduling Engine
 * Comprehensive conflict detection, availability computation, and slot generation
 */

/**
 * Get service duration from services table
 */
function get_service_duration($conn, $service_name) {
    $stmt = $conn->prepare("SELECT duration_minutes FROM services WHERE name = ?");
    $stmt->bind_param("s", $service_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return (int)$row['duration_minutes'];
    }
    // Fallback to service_durations table
    $stmt2 = $conn->prepare("SELECT duration_minutes FROM service_durations WHERE service_type = ?");
    $stmt2->bind_param("s", $service_name);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    return $result2 && ($row = $result2->fetch_assoc()) ? (int)$row['duration_minutes'] : 30;
}

/**
 * Get clinic settings
 */
function get_clinic_settings($conn) {
    static $settings = null;
    if ($settings !== null) return $settings;
    
    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM clinic_settings");
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

/**
 * Validate an appointment slot with comprehensive rules
 * Returns ['valid' => bool, 'message' => string, 'data' => array]
 */
function validate_schedule_slot($conn, $params) {
    $doctor_id = $params['doctor_id'] ?? null;
    $staff_id = $params['staff_id'] ?? null;
    $date = $params['date'] ?? '';
    $time = $params['time'] ?? '';
    $service = $params['service'] ?? '';
    $duration = $params['duration'] ?? 30;
    $appointment_id = $params['appointment_id'] ?? null; // For rescheduling
    $client_id = $params['client_id'] ?? null;
    $pet_id = $params['pet_id'] ?? null;
    
    $result = [
        'valid' => false,
        'message' => '',
        'data' => [],
        'conflicts' => [],
        'suggestions' => []
    ];
    
    // Use staff_id as doctor_id if provided
    $target_id = $doctor_id ?: $staff_id;
    if ($target_id) $doctor_id = $target_id;
    
    $settings = get_clinic_settings($conn);
    
    // ========== 1. Date/Time Format Validation ==========
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $result['message'] = 'Invalid date format';
        return $result;
    }
    
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $time)) {
        $result['message'] = 'Invalid time format';
        return $result;
    }
    
    $time = date('H:i:s', strtotime($time));
    $time_obj = strtotime($time);
    $end_time = date('H:i:s', $time_obj + ($duration * 60));
    $end_time_obj = strtotime($end_time);
    
    $result['data'] = [
        'start_time' => $time,
        'end_time' => $end_time,
        'duration_minutes' => $duration
    ];
    
    // ========== 2. Past Date Validation ==========
    $today = date('Y-m-d');
    if ($date < $today) {
        $result['message'] = 'Cannot book appointments in the past';
        return $result;
    }
    
    // ========== 3. Same-day booking check ==========
    $allow_same_day = ($settings['allow_same_day_booking'] ?? '0') === '1';
    if ($date == $today && !$allow_same_day) {
        $result['message'] = 'Same-day booking is not allowed. Please book at least one day in advance.';
        return $result;
    }
    
    // ========== 4. Advance booking window ==========
    $advance_hours = (int)($settings['advance_booking_hours'] ?? 24);
    $max_advance_days = (int)($settings['max_advance_booking_days'] ?? 30);
    
    $earliest_date = date('Y-m-d', strtotime("+{$advance_hours} hours"));
    $latest_date = date('Y-m-d', strtotime("+{$max_advance_days} days"));
    
    if ($date < $earliest_date && $date == $today) {
        $result['message'] = "Appointments must be booked at least {$advance_hours} hours in advance";
        $result['data']['earliest_date'] = $earliest_date;
        return $result;
    }
    
    if ($date > $latest_date) {
        $result['message'] = "Cannot book more than {$max_advance_days} days in advance";
        $result['data']['latest_date'] = $latest_date;
        return $result;
    }
    
    // ========== 5. Holiday/Clinic Closure Check ==========
    $stmt = $conn->prepare("SELECT id FROM holidays WHERE date = ? AND is_active = 1");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $result['message'] = 'Clinic is closed on this date (holiday)';
        $result['data']['holiday'] = true;
        return $result;
    }
    
    // ========== 6. Business Hours Validation ==========
    $clinic_open = $settings['working_hours_start'] ?? '08:00';
    $clinic_close = $settings['working_hours_end'] ?? '17:00';
    $buffer_minutes = (int)($settings['appointment_buffer_minutes'] ?? 15);
    
    $open_obj = strtotime($clinic_open);
    $close_obj = strtotime($clinic_close);
    
    if ($time_obj < $open_obj || $end_time_obj > $close_obj) {
        $open_fmt = date('h:i A', $open_obj);
        $close_fmt = date('h:i A', $close_obj);
        $result['message'] = "Appointment must be within clinic hours ({$open_fmt} - {$close_fmt})";
        $result['data']['clinic_hours'] = ['open' => $clinic_open, 'close' => $clinic_close];
        return $result;
    }
    
    // ========== 7. Staff/Doctor Validation ==========
    if (!empty($doctor_id)) {
        // Check staff exists
        $stmt = $conn->prepare("SELECT id, fullname, role FROM users WHERE id = ? AND (role = 'doctor' OR role = 'staff')");
        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        $staff_result = $stmt->get_result();
        
        if ($staff_result->num_rows === 0) {
            $result['message'] = 'Selected staff member not found';
            return $result;
        }
        
        $staff_info = $staff_result->fetch_assoc();
        $result['data']['staff_name'] = $staff_info['fullname'];
        
        // ========== 8. Check Staff Working Schedule ==========
        $day_of_week = (int)date('w', strtotime($date));
        
        $stmt = $conn->prepare("SELECT start_time, end_time FROM schedule_availability WHERE staff_id = ? AND day_of_week = ? AND is_available = 1");
        $stmt->bind_param("ii", $doctor_id, $day_of_week);
        $stmt->execute();
        $schedule = $stmt->get_result()->fetch_assoc();
        
        if (!$schedule) {
            $result['message'] = 'Staff member is not available on this day of the week';
            $result['data']['day_of_week'] = $day_of_week;
            return $result;
        }
        
        $staff_start = strtotime($schedule['start_time']);
        $staff_end = strtotime($schedule['end_time']);
        
        if ($time_obj < $staff_start || $end_time_obj > $staff_end) {
            $result['message'] = 'Time is outside staff\'s working schedule (' . 
                date('h:i A', $staff_start) . ' - ' . date('h:i A', $staff_end) . ')';
            $result['data']['staff_hours'] = ['start' => $schedule['start_time'], 'end' => $schedule['end_time']];
            return $result;
        }
        
        // ========== 9. Staff Leave/Unavailability Check ==========
        $stmt = $conn->prepare("SELECT id, reason FROM staff_unavailability WHERE staff_id = ? AND start_date <= ? AND end_date >= ? AND is_approved = 1");
        $stmt->bind_param("iss", $doctor_id, $date, $date);
        $stmt->execute();
        $leaves = $stmt->get_result();
        
        if ($leaves->num_rows > 0) {
            $leave = $leaves->fetch_assoc();
            $result['message'] = 'Staff member is on leave on this date' . ($leave['reason'] ? " ({$leave['reason']})" : '');
            $result['data']['leave_info'] = $leave;
            $result['data']['conflicts'][] = 'Staff leave';
            return $result;
        }
        
        // ========== 10. Double Booking / Overlapping Check ==========
        $exclude = $appointment_id ? "AND id != ?" : "";
        $conflict_sql = "SELECT a.id, a.appointment_time, a.duration_minutes, a.service_type, s.name as service_name,
                                TIMESTAMP(a.appointment_date, a.appointment_time) as appt_start,
                                TIMESTAMP(a.appointment_date, ADDTIME(a.appointment_time, SEC_TO_TIME(a.duration_minutes * 60))) as appt_end
                         FROM appointments a
                         LEFT JOIN services s ON a.service_id = s.id
                         WHERE a.doctor_id = ? AND a.appointment_date = ? 
                           AND a.status NOT IN ('cancelled', 'rejected', 'no_show', 'completed')
                           {$exclude}
                         ORDER BY a.appointment_time";
        
        if ($appointment_id) {
            $stmt = $conn->prepare($conflict_sql);
            $stmt->bind_param("isi", $doctor_id, $date, $appointment_id);
        } else {
            $stmt = $conn->prepare($conflict_sql);
            $stmt->bind_param("is", $doctor_id, $date);
        }
        $stmt->execute();
        $existing_appts = $stmt->get_result();
        
        $conflicts = [];
        while ($existing = $existing_appts->fetch_assoc()) {
            $existing_start = strtotime($existing['appt_start']);
            $existing_end = strtotime($existing['appt_end']);
            
            // Add buffer time
            $buffer_seconds = $buffer_minutes * 60;
            $check_start = $existing_start - $buffer_seconds;
            $check_end = $existing_end + $buffer_seconds;
            
            // Check overlap
            if (!($end_time_obj <= $check_start || $time_obj >= $check_end)) {
                $conflicts[] = [
                    'appointment_id' => $existing['id'],
                    'existing_time' => $existing['appointment_time'],
                    'existing_service' => $existing['service_name'] ?? $existing['service_type'],
                    'overlap_start' => date('H:i', max($time_obj, $check_start)),
                    'overlap_end' => date('H:i', min($end_time_obj, $check_end))
                ];
            }
        }
        
        if (!empty($conflicts)) {
            $next_available = $conflicts[0]['existing_time'];
            $next_ts = strtotime($next_available) + (get_service_duration($conn, $conflicts[0]['existing_service'] ?? 'General') * 60) + ($buffer_minutes * 60);
            
            $result['valid'] = false;
            $result['message'] = 'Time slot conflicts with existing appointment';
            $result['conflicts'] = $conflicts;
            $result['data']['conflicts'] = $conflicts;
            $result['data']['suggested_next_time'] = date('h:i A', $next_ts);
            $result['suggestions'][] = date('H:i', $next_ts);
            
            // Generate alternative suggestions
            $result['suggestions'] = find_alternative_slots($conn, $doctor_id, $date, $duration, $buffer_minutes, $open_obj, $close_obj, $appointment_id);
            
            return $result;
        }
        
        // ========== 11. Daily Capacity Check ==========
        $max_per_day = (int)$settings['max_appointments_per_day'] ?? 10;
        // Also check staff settings
        $stmt = $conn->prepare("SELECT max_appointments_per_day FROM staff WHERE user_id = ?");
        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        $staff_capacity = $stmt->get_result()->fetch_assoc();
        if ($staff_capacity && $staff_capacity['max_appointments_per_day']) {
            $max_per_day = (int)$staff_capacity['max_appointments_per_day'];
        }
        
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND status NOT IN ('cancelled', 'rejected', 'no_show')");
        $stmt->bind_param("is", $doctor_id, $date);
        $stmt->execute();
        $day_count = $stmt->get_result()->fetch_assoc()['count'];
        
        if ($day_count >= $max_per_day) {
            $result['message'] = "Staff member has reached maximum daily capacity ({$max_per_day} appointments)";
            $result['data']['capacity'] = ['current' => $day_count, 'max' => $max_per_day];
            $result['suggestions'] = find_alternative_slots($conn, $doctor_id, date('Y-m-d', strtotime($date . ' +1 day')), $duration, $buffer_minutes, $open_obj, $close_obj);
            return $result;
        }
        
        $result['data']['capacity'] = [
            'current' => (int)$day_count,
            'max' => $max_per_day,
            'remaining' => $max_per_day - $day_count
        ];
        
        // ========== 12. Duplicate Appointment Check ==========
        if ($client_id && $pet_id) {
            $stmt = $conn->prepare("SELECT id FROM appointments WHERE user_id = ? AND pet_name = ? AND appointment_date = ? AND appointment_time = ? AND status NOT IN ('cancelled', 'rejected')");
            $stmt->bind_param("isss", $client_id, $pet_id, $date, $time);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $result['message'] = 'Duplicate appointment: You already have an appointment for this pet at this time';
                return $result;
            }
        }
    }
    
    // ========== All validations passed ==========
    $result['valid'] = true;
    $result['message'] = 'Slot is available';
    return $result;
}

/**
 * Find alternative available slots
 */
function find_alternative_slots($conn, $staff_id, $date, $duration, $buffer_minutes, $work_start = null, $work_end = null, $exclude_id = null, $max_suggestions = 5) {
    if (!$work_start) $work_start = strtotime('08:00');
    if (!$work_end) $work_end = strtotime('17:00');
    
    $settings = get_clinic_settings($conn);
    $start_time = $settings['working_hours_start'] ?? '08:00';
    $end_time = $settings['working_hours_end'] ?? '17:00';
    $interval = 30; // 30-minute intervals
    
    $work_start = strtotime($start_time);
    $work_end = strtotime($end_time);
    
    // Get existing bookings for this date
    $exclude_sql = $exclude_id ? "AND a.id != ?" : "";
    $stmt = $conn->prepare("
        SELECT a.appointment_time, a.duration_minutes,
               TIMESTAMP(a.appointment_date, a.appointment_time) as appt_start,
               TIMESTAMP(a.appointment_date, ADDTIME(a.appointment_time, SEC_TO_TIME(a.duration_minutes * 60))) as appt_end
        FROM appointments a
        WHERE a.doctor_id = ? AND a.appointment_date = ?
          AND a.status NOT IN ('cancelled', 'rejected', 'no_show', 'completed')
          {$exclude_sql}
        ORDER BY a.appointment_time
    ");
    if ($exclude_id) {
        $stmt->bind_param("isi", $staff_id, $date, $exclude_id);
    } else {
        $stmt->bind_param("is", $staff_id, $date);
    }
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Generate time slots
    $suggestions = [];
    $current = $work_start;
    
    while ($current < $work_end && count($suggestions) < $max_suggestions) {
        $slot_start = $current;
        $slot_end = $current + ($duration * 60);
        
        if ($slot_end > $work_end) break;
        
        $is_available = true;
        foreach ($bookings as $booking) {
            $existing_start = strtotime($booking['appt_start']);
            $existing_end = strtotime($booking['appt_end']);
            $buffer_secs = $buffer_minutes * 60;
            
            if (!($slot_end <= ($existing_start - $buffer_secs) || $slot_start >= ($existing_end + $buffer_secs))) {
                $is_available = false;
                break;
            }
        }
        
        if ($is_available) {
            $suggestions[] = date('H:i', $slot_start);
        }
        
        $current += ($interval * 60);
    }
    
    return $suggestions;
}

/**
 * Generate available time slots for a given staff member and date
 */
function generate_available_slots($conn, $staff_id, $date, $service_name = null, $duration = null) {
    if (!$duration) {
        $duration = $service_name ? get_service_duration($conn, $service_name) : 30;
    }
    
    $settings = get_clinic_settings($conn);
    $buffer_minutes = (int)($settings['appointment_buffer_minutes'] ?? 15);
    $interval = 30;
    
    // Check if holiday
    $stmt = $conn->prepare("SELECT id FROM holidays WHERE date = ? AND is_active = 1");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return ['slots' => [], 'message' => 'Clinic is closed (holiday)', 'available' => false];
    }
    
    // Get staff schedule for this day
    $day_of_week = (int)date('w', strtotime($date));
    $stmt = $conn->prepare("SELECT start_time, end_time FROM schedule_availability WHERE staff_id = ? AND day_of_week = ? AND is_available = 1");
    $stmt->bind_param("ii", $staff_id, $day_of_week);
    $stmt->execute();
    $schedule = $stmt->get_result()->fetch_assoc();
    
    $work_start = $schedule ? strtotime($schedule['start_time']) : strtotime($settings['working_hours_start'] ?? '08:00');
    $work_end = $schedule ? strtotime($schedule['end_time']) : strtotime($settings['working_hours_end'] ?? '17:00');
    
    // Check staff unavailability
    $stmt = $conn->prepare("SELECT id FROM staff_unavailability WHERE staff_id = ? AND start_date <= ? AND end_date >= ? AND is_approved = 1");
    $stmt->bind_param("iss", $staff_id, $date, $date);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return ['slots' => [], 'message' => 'Staff is on leave', 'available' => false];
    }
    
    // Get existing appointments
    $stmt = $conn->prepare("
        SELECT appointment_time, duration_minutes 
        FROM appointments 
        WHERE doctor_id = ? AND appointment_date = ? 
          AND status NOT IN ('cancelled', 'rejected', 'no_show', 'completed')
        ORDER BY appointment_time
    ");
    $stmt->bind_param("is", $staff_id, $date);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Check daily capacity
    $max_per_day = (int)($settings['max_appointments_per_day'] ?? 10);
    $stmt2 = $conn->prepare("SELECT max_appointments_per_day FROM staff WHERE user_id = ?");
    $stmt2->bind_param("i", $staff_id);
    $stmt2->execute();
    $staff_cap = $stmt2->get_result()->fetch_assoc();
    if ($staff_cap && $staff_cap['max_appointments_per_day']) {
        $max_per_day = (int)$staff_cap['max_appointments_per_day'];
    }
    
    if (count($bookings) >= $max_per_day) {
        return ['slots' => [], 'message' => 'Maximum daily capacity reached', 'available' => false, 'capacity' => count($bookings) . '/' . $max_per_day];
    }
    
    // Generate slots
    $slots = [];
    $current = $work_start;
    $now_timestamp = time();
    $today = date('Y-m-d');
    
    while ($current < $work_end) {
        $slot_start = $current;
        $slot_end = $current + ($duration * 60);
        
        if ($slot_end > $work_end) break;
        
        // Skip past slots for today
        if ($date == $today) {
            $current_hour = (int)date('H', $current);
            $current_min = (int)date('i', $current);
            $now_hour = (int)date('H', $now_timestamp);
            $now_min = (int)date('i', $now_timestamp);
            
            if ($current_hour < $now_hour || ($current_hour == $now_hour && $current_min <= $now_min + 30)) {
                $current += ($interval * 60);
                continue;
            }
        }
        
        $is_available = true;
        foreach ($bookings as $booking) {
            $existing_start = strtotime($booking['appointment_time']);
            $existing_end = $existing_start + (($booking['duration_minutes'] ?? 30) * 60);
            $buffer_secs = $buffer_minutes * 60;
            
            if (!($slot_end <= ($existing_start - $buffer_secs) || $slot_start >= ($existing_end + $buffer_secs))) {
                $is_available = false;
                break;
            }
        }
        
        if ($is_available) {
            $time_label = date('h:i A', $slot_start);
            $time_value = date('H:i', $slot_start);
            $end_label = date('h:i A', $slot_end);
            
            $slots[] = [
                'time' => $time_value,
                'time_label' => $time_label,
                'end_time' => date('H:i', $slot_end),
                'end_label' => $end_label,
                'duration' => $duration,
                'available' => true
            ];
        }
        
        $current += ($interval * 60);
    }
    
    return [
        'slots' => $slots,
        'date' => $date,
        'staff_id' => $staff_id,
        'available' => !empty($slots),
        'count' => count($slots),
        'message' => empty($slots) ? 'No available slots for this date' : count($slots) . ' slots available'
    ];
}

/**
 * Check if a date is available (not holiday, not in past)
 */
function is_date_available($conn, $date) {
    $today = date('Y-m-d');
    if ($date < $today) return false;
    
    $stmt = $conn->prepare("SELECT id FROM holidays WHERE date = ? AND is_active = 1");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) return false;
    
    return true;
}

/**
 * Get available staff members for a given date and time
 */
function get_available_staff($conn, $date, $time, $duration = 30, $exclude_service = null) {
    $day_of_week = (int)date('w', strtotime($date));
    $time_obj = strtotime($time);
    $end_time_obj = $time_obj + ($duration * 60);
    $buffer_minutes = 15;
    
    $stmt = $conn->prepare("
        SELECT u.id, u.fullname, s.specialization, s.max_appointments_per_day
        FROM users u
        JOIN staff s ON s.user_id = u.id
        WHERE u.role IN ('doctor', 'staff') AND s.is_active = 1
        ORDER BY u.fullname
    ");
    $stmt->execute();
    $all_staff = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $available = [];
    foreach ($all_staff as $staff) {
        // Check schedule
        $stmt2 = $conn->prepare("SELECT start_time, end_time FROM schedule_availability WHERE staff_id = ? AND day_of_week = ? AND is_available = 1");
        $stmt2->bind_param("ii", $staff['id'], $day_of_week);
        $stmt2->execute();
        $schedule = $stmt2->get_result()->fetch_assoc();
        
        if (!$schedule) continue;
        
        $staff_start = strtotime($schedule['start_time']);
        $staff_end = strtotime($schedule['end_time']);
        
        if ($time_obj < $staff_start || $end_time_obj > $staff_end) continue;
        
        // Check leave
        $stmt3 = $conn->prepare("SELECT id FROM staff_unavailability WHERE staff_id = ? AND start_date <= ? AND end_date >= ? AND is_approved = 1");
        $stmt3->bind_param("iss", $staff['id'], $date, $date);
        $stmt3->execute();
        if ($stmt3->get_result()->num_rows > 0) continue;
        
        // Check conflicts
        $stmt4 = $conn->prepare("
            SELECT COUNT(*) as count FROM appointments 
            WHERE doctor_id = ? AND appointment_date = ? 
              AND status NOT IN ('cancelled', 'rejected', 'no_show')
        ");
        $stmt4->bind_param("is", $staff['id'], $date);
        $stmt4->execute();
        $day_count = $stmt4->get_result()->fetch_assoc()['count'];
        
        $max_cap = $staff['max_appointments_per_day'] ?? 10;
        if ($day_count >= $max_cap) continue;
        
        $available[] = $staff;
    }
    
    return $available;
}

/**
 * Generate appointment number
 */
function generate_appointment_number($conn) {
    $year = date('Y');
    $month = date('m');
    $prefix = "PV-{$year}{$month}-";
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM appointments WHERE appointment_number LIKE ?");
    $like = "{$prefix}%";
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['count'];
    
    return $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
}

/**
 * Get appointment statistics for dashboard
 */
function get_appointment_stats($conn, $user_id = null, $role = null) {
    $where = '';
    $params = [];
    $types = '';
    
    if ($user_id && $role === 'doctor') {
        $where = "WHERE a.doctor_id = ?";
        $params[] = $user_id;
        $types = 'i';
    } elseif ($user_id && $role === 'client') {
        $where = "WHERE a.user_id = ?";
        $params[] = $user_id;
        $types = 'i';
    }
    
    $stats = [];
    $queries = [
        'total' => "SELECT COUNT(*) as count FROM appointments a {$where}",
        'today' => "SELECT COUNT(*) as count FROM appointments a {$where} AND a.appointment_date = CURDATE()",
        'upcoming' => "SELECT COUNT(*) as count FROM appointments a {$where} AND a.appointment_date >= CURDATE() AND a.status NOT IN ('completed', 'cancelled', 'rejected', 'no_show')",
        'pending' => "SELECT COUNT(*) as count FROM appointments a {$where} AND a.status IN ('pending', 'waiting_approval')",
        'completed' => "SELECT COUNT(*) as count FROM appointments a {$where} AND a.status = 'completed'",
        'cancelled' => "SELECT COUNT(*) as count FROM appointments a {$where} AND a.status = 'cancelled'",
        'no_show' => "SELECT COUNT(*) as count FROM appointments a {$where} AND a.status = 'no_show'",
        'in_progress' => "SELECT COUNT(*) as count FROM appointments a {$where} AND a.status IN ('checked_in', 'in_progress')"
    ];
    
    foreach ($queries as $key => $sql) {
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt = $conn->prepare($sql);
        }
        $stmt->execute();
        $stats[$key] = (int)$stmt->get_result()->fetch_assoc()['count'];
    }
    
    return $stats;
}

/**
 * Get appointment calendar data
 */
function get_calendar_appointments($conn, $start_date, $end_date, $filters = []) {
    $where = "WHERE a.appointment_date BETWEEN ? AND ? AND a.deleted_at IS NULL";
    $params = [$start_date, $end_date];
    $types = "ss";
    
    if (!empty($filters['staff_id'])) {
        $where .= " AND a.doctor_id = ?";
        $params[] = $filters['staff_id'];
        $types .= "i";
    }
    
    if (!empty($filters['status'])) {
        $where .= " AND a.status = ?";
        $params[] = $filters['status'];
        $types .= "s";
    }
    
    if (!empty($filters['user_id'])) {
        $where .= " AND a.user_id = ?";
        $params[] = $filters['user_id'];
        $types .= "i";
    }
    
    // Get status colors
    $status_colors = [];
    $sc = $conn->query("SELECT name, color FROM appointment_statuses WHERE is_active = 1");
    while ($s = $sc->fetch_assoc()) {
        $status_colors[$s['name']] = $s['color'];
    }
    
    $sql = "SELECT a.*, u.fullname as client_name, 
                   COALESCE(d.fullname, 'Unassigned') as staff_name,
                   s.name as service_name, s.color as service_color
            FROM appointments a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN users d ON a.doctor_id = d.id
            LEFT JOIN services s ON a.service_id = s.id
            {$where}
            ORDER BY a.appointment_date, a.appointment_time";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $events = [];
    foreach ($appointments as $appt) {
        $events[] = [
            'id' => $appt['id'],
            'title' => $appt['pet_name'] . ' - ' . ($appt['client_name'] ?? 'Unknown'),
            'start' => $appt['appointment_date'] . 'T' . $appt['appointment_time'],
            'end' => $appt['appointment_date'] . 'T' . ($appt['end_time'] ?? date('H:i:s', strtotime($appt['appointment_time'] . ' +30 minutes'))),
            'backgroundColor' => $status_colors[$appt['status']] ?? '#6B7280',
            'borderColor' => $status_colors[$appt['status']] ?? '#6B7280',
            'textColor' => '#ffffff',
            'extendedProps' => [
                'appointment_id' => $appt['id'],
                'appointment_number' => $appt['appointment_number'] ?? '',
                'pet_name' => $appt['pet_name'],
                'client' => $appt['client_name'] ?? '',
                'staff' => $appt['staff_name'] ?? '',
                'service' => $appt['service_name'] ?? $appt['service_type'],
                'status' => $appt['status'],
                'client_id' => $appt['user_id'],
                'staff_id' => $appt['doctor_id'],
                'duration' => $appt['duration_minutes'] ?? 30
            ]
        ];
    }
    
    return $events;
}