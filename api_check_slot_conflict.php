<?php
/**
 * Appointment Conflict Detection API
 * Validates if a proposed appointment slot is available
 */
session_start();
header('Content-Type: application/json');
include("dbconnection.php");
include_once("includes/rbac_guard.php");

// RBAC: Requires authenticated session (any logged-in role may check slot conflicts)
rbac_api_require_role(['user', 'client', 'staff', 'admin', 'clinic_owner', 'doctor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$doctor_id = isset($data['doctor_id']) ? (int)$data['doctor_id'] : null;
$date = isset($data['date']) ? $data['date'] : null;
$time = isset($data['time']) ? $data['time'] : null;
$duration_minutes = isset($data['duration_minutes']) ? (int)$data['duration_minutes'] : 30;

if (!$doctor_id || !$date || !$time) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: doctor_id, date, time']);
    exit();
}

// Validate date is not in the past and not same day
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

if ($date < $tomorrow) {
    http_response_code(400);
    echo json_encode([
        'available' => false,
        'reason' => 'Appointments must be booked at least 24 hours in advance',
        'earliest_date' => $tomorrow
    ]);
    exit();
}

// Check if doctor is on leave
$stmt = $conn->prepare("SELECT id FROM doctor_unavailability WHERE doctor_id = ? AND unavailable_date = ?");
$stmt->bind_param("is", $doctor_id, $date);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    echo json_encode([
        'available' => false,
        'reason' => 'Selected doctor is not available on this date (on leave)'
    ]);
    exit();
}

// Get doctor scheduling info
$stmt = $conn->prepare("SELECT fullname, working_hours_start, working_hours_end, appointment_buffer_minutes, max_appointments_per_day FROM users WHERE id = ? AND role = 'doctor'");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$doctor_result = $stmt->get_result();
if ($doctor_result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['available' => false, 'reason' => 'Doctor not found']);
    exit();
}

$doctor = $doctor_result->fetch_assoc();
$start_time = $doctor['working_hours_start'] ?? '08:00:00';
$end_time = $doctor['working_hours_end'] ?? '17:00:00';
$buffer_mins = $doctor['appointment_buffer_minutes'] ?? 15;
$max_appts = $doctor['max_appointments_per_day'] ?? 10;

// Check if time is within working hours
$slot_time = strtotime($time);
$slot_end = $slot_time + ($duration_minutes * 60);
$working_start = strtotime($start_time);
$working_end = strtotime($end_time);

if ($slot_time < $working_start || $slot_end > $working_end) {
    echo json_encode([
        'available' => false,
        'reason' => 'Requested time is outside doctor working hours (' . $doctor['working_hours_start'] . ' - ' . $doctor['working_hours_end'] . ')'
    ]);
    exit();
}

// Check for conflicts with existing appointments
$stmt = $conn->prepare("
    SELECT appointment_time, service_duration_minutes 
    FROM appointments 
    WHERE doctor_id = ? AND appointment_date = ? AND status IN ('Pending', 'Approved')
");
$stmt->bind_param("is", $doctor_id, $date);
$stmt->execute();
$existing_result = $stmt->get_result();

while ($existing = $existing_result->fetch_assoc()) {
    $existing_start = strtotime($existing['appointment_time']);
    $existing_duration = $existing['service_duration_minutes'] ?? 30;
    $existing_end = $existing_start + ($existing_duration * 60);
    
    // Add buffer on both sides
    $buffer_seconds = $buffer_mins * 60;
    $check_start = $existing_start - $buffer_seconds;
    $check_end = $existing_end + $buffer_seconds;
    
    // Check if proposed slot overlaps with existing appointment + buffer
    if (!($slot_end <= $check_start || $slot_time >= $check_end)) {
        echo json_encode([
            'available' => false,
            'reason' => 'Time slot conflicts with existing appointment',
            'conflicting_time' => $existing['appointment_time'],
            'suggested_earliest' => date('H:i', $existing_end + $buffer_seconds)
        ]);
        exit();
    }
}

// Check doctor capacity for the day
$stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM appointments 
    WHERE doctor_id = ? AND appointment_date = ? AND status IN ('Pending', 'Approved')
");
$stmt->bind_param("is", $doctor_id, $date);
$stmt->execute();
$capacity_result = $stmt->get_result()->fetch_assoc();
$current_count = $capacity_result['count'];

if ($current_count >= $max_appts) {
    echo json_encode([
        'available' => false,
        'reason' => 'Doctor has reached maximum appointments for this day',
        'max_capacity' => $max_appts,
        'current_bookings' => $current_count
    ]);
    exit();
}

// All checks passed - slot is available
echo json_encode([
    'available' => true,
    'doctor_id' => $doctor_id,
    'date' => $date,
    'time' => $time,
    'duration_minutes' => $duration_minutes,
    'end_time' => date('H:i', $slot_end),
    'doctor_capacity' => [
        'current_bookings' => $current_count,
        'max_capacity' => $max_appts,
        'remaining' => $max_appts - $current_count
    ]
]);
?>
