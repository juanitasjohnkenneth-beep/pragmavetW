<?php
/**
 * Smart Slot Availability API
 * Returns available appointment slots based on date, doctor, and service type
 */
session_start();
header('Content-Type: application/json');
include("dbconnection.php");
include_once("includes/rbac_guard.php");

// RBAC: Requires authenticated session (any role may check slot availability)
rbac_api_require_role(['user', 'client', 'staff', 'admin', 'clinic_owner', 'doctor']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request method']);
    exit();
}

$date = isset($_GET['date']) ? $_GET['date'] : null;
$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : null;
$service_type = isset($_GET['service_type']) ? $_GET['service_type'] : null;

if (!$date || !$service_type) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required parameters: date, service_type',
        'reason' => 'Please choose a service type and appointment date before checking slots.'
    ]);
    exit();
}

// Validate date is not in the past
$today = date('Y-m-d');
if ($date < $today) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Cannot book appointments in the past',
        'reason' => 'Selected date is already in the past. Please choose a future date.'
    ]);
    exit();
}

// Validate date is not same day (same-day booking not allowed - at least 24 hours)
$tomorrow = date('Y-m-d', strtotime('+1 day'));
if ($date < $tomorrow) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Appointments must be booked at least 24 hours in advance',
        'reason' => 'Today is not available. Please choose a date from ' . $tomorrow . ' or later.',
        'earliest_date' => $tomorrow
    ]);
    exit();
}

function get_service_duration($conn, $service_type) {
    $default_duration = 30;
    $stmt = $conn->prepare("SELECT duration_minutes FROM service_durations WHERE service_type = ?");
    if ($stmt) {
        $stmt->bind_param("s", $service_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $service_data = $result ? $result->fetch_assoc() : null;
        return $service_data ? (int) $service_data['duration_minutes'] : $default_duration;
    }

    error_log('api_appointment_slots.php: failed to prepare service duration query - ' . $conn->error);
    return $default_duration;
}

$duration_minutes = get_service_duration($conn, $service_type);

// Fetch available doctors if not specified
if (!$doctor_id) {
    // Get all doctors sorted by availability
    $doctors_query = "SELECT id, fullname FROM users WHERE role='doctor' ORDER BY fullname ASC";
    $doctors_result = $conn->query($doctors_query);
    $doctors = [];
    if ($doctors_result) {
        while ($doc = $doctors_result->fetch_assoc()) {
            $doc['working_hours_start'] = '08:00:00';
            $doc['working_hours_end'] = '17:00:00';
            $doc['appointment_buffer_minutes'] = 15;
            $doc['max_appointments_per_day'] = 10;
            $doctors[] = $doc;
        }
    }
} else {
    // Get specific doctor
    $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE id = ? AND role='doctor'");
    if ($stmt) {
        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $doc = $result ? $result->fetch_assoc() : null;
        if ($doc) {
            $doc['working_hours_start'] = '08:00:00';
            $doc['working_hours_end'] = '17:00:00';
            $doc['appointment_buffer_minutes'] = 15;
            $doc['max_appointments_per_day'] = 10;
            $doctors = [$doc];
        } else {
            $doctors = [];
        }
    } else {
        $doctors = [];
    }
}

if (empty($doctors)) {
    http_response_code(404);
    echo json_encode(['error' => 'No available doctors']);
    exit();
}

// Generate available slots for each doctor
$available_slots = [];

foreach ($doctors as $doctor) {
    $doctor_id_val = $doctor['id'];
    $start_time = $doctor['working_hours_start'] ?? '08:00:00';
    $end_time = $doctor['working_hours_end'] ?? '17:00:00';
    $buffer_mins = $doctor['appointment_buffer_minutes'] ?? 15;
    $max_appts = $doctor['max_appointments_per_day'] ?? 10;
    
    // Check if doctor is on leave this date
    $stmt = $conn->prepare("SELECT id FROM doctor_unavailability WHERE doctor_id = ? AND unavailable_date = ?");
    $is_on_leave = false;
    if ($stmt) {
        $stmt->bind_param("is", $doctor_id_val, $date);
        $stmt->execute();
        $leave_result = $stmt->get_result();
        if ($leave_result && $leave_result->num_rows > 0) {
            $is_on_leave = true;
        }
    } else {
        error_log('api_appointment_slots.php: failed to prepare doctor_unavailability query - ' . $conn->error);
    }
    
    if ($is_on_leave) {
        continue; // Skip this doctor - on leave
    }
    
    // Get existing appointments for this doctor on this date
    $has_end_time = false;
    $has_duration_column = false;
    $columns_result = $conn->query("SHOW COLUMNS FROM appointments LIKE 'appointment_end_time'");
    if ($columns_result && $columns_result->num_rows > 0) {
        $has_end_time = true;
    }
    $columns_result = $conn->query("SHOW COLUMNS FROM appointments LIKE 'service_duration_minutes'");
    if ($columns_result && $columns_result->num_rows > 0) {
        $has_duration_column = true;
    }

    $select_fields = ['appointment_time', 'service_type'];
    if ($has_end_time) {
        $select_fields[] = 'appointment_end_time';
    }
    if ($has_duration_column) {
        $select_fields[] = 'service_duration_minutes';
    }

    $stmt = $conn->prepare("SELECT " . implode(', ', $select_fields) . " FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND status IN ('Pending', 'Approved') ORDER BY appointment_time ASC");
    $booked_slots = [];
    $appointment_count = 0;
    if ($stmt) {
        $stmt->bind_param("is", $doctor_id_val, $date);
        $stmt->execute();
        $booked_result = $stmt->get_result();
        if ($booked_result) {
            while ($booked = $booked_result->fetch_assoc()) {
                $booked_start = strtotime($booked['appointment_time']);
                if ($has_end_time && !empty($booked['appointment_end_time'])) {
                    $booked_end = strtotime($booked['appointment_end_time']);
                } elseif ($has_duration_column && !empty($booked['service_duration_minutes'])) {
                    $booked_end = $booked_start + ((int)$booked['service_duration_minutes'] * 60);
                } else {
                    $booked_duration = get_service_duration($conn, $booked['service_type'] ?? $service_type);
                    $booked_end = $booked_start + ($booked_duration * 60);
                }

                $booked_slots[] = [
                    'start' => date('H:i:s', $booked_start),
                    'end' => date('H:i:s', $booked_end)
                ];
                $appointment_count++;
            }
        }
    } else {
        error_log('api_appointment_slots.php: failed to prepare appointment lookup query - ' . $conn->error);
    }
    
    // Check if doctor is at capacity for this day
    if ($appointment_count >= $max_appts) {
        continue; // This doctor is fully booked for the day
    }
    
    // Generate time slots using 30-minute intervals
    $slots = [];
    $current_time = strtotime($start_time);
    $end_time_timestamp = strtotime($end_time);
    $slot_interval = 30; // Every 30 minutes
    
    while ($current_time < $end_time_timestamp) {
        $slot_time = date('H:i', $current_time);
        $slot_end_time = date('H:i', $current_time + ($duration_minutes * 60));
        
        // Check if slot is available (not overlapping with booked appointments + buffer)
        $slot_start = $current_time;
        $slot_end = $current_time + ($duration_minutes * 60);
        $is_available = true;
        foreach ($booked_slots as $booked) {
            $booked_start = strtotime($booked['start']);
            $booked_end = strtotime($booked['end']);
            
            // Add buffer time
            $check_start = $booked_start - ($buffer_mins * 60);
            $check_end = $booked_end + ($buffer_mins * 60);
            
            // Check overlap
            if (!($slot_end <= $check_start || $slot_start >= $check_end)) {
                $is_available = false;
                break;
            }
        }
        
        // Ensure appointment doesn't exceed doctor's working hours
        if ($slot_end > $end_time_timestamp) {
            $is_available = false;
        }
        
        if ($is_available) {
            $slots[] = [
                'time' => $slot_time,
                'end_time' => $slot_end_time,
                'available' => true
            ];
        }
        
        $current_time += ($slot_interval * 60);
    }
    
    if (!empty($slots)) {
        $available_slots[] = [
            'doctor_id' => $doctor_id_val,
            'doctor_name' => $doctor['fullname'],
            'slots' => $slots,
            'booked_count' => $appointment_count,
            'remaining_capacity' => $max_appts - $appointment_count
        ];
    }
}

if (empty($available_slots)) {
    echo json_encode([
        'date' => $date,
        'service_type' => $service_type,
        'duration_minutes' => $duration_minutes,
        'available_slots' => [],
        'message' => 'No available slots for this date. Please try another date or doctor.',
        'reason' => 'No doctors currently have available time slots for the selected service and date.'
    ]);
} else {
    echo json_encode([
        'date' => $date,
        'service_type' => $service_type,
        'duration_minutes' => $duration_minutes,
        'available_slots' => $available_slots
    ]);
}
?>
