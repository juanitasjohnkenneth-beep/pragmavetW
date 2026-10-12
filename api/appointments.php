<?php
/**
 * Appointments REST API
 * Complete CRUD + status management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../dbconnection.php';
require_once '../includes/security.php';
require_once '../includes/scheduling_engine.php';

// Require authentication
require_auth();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'client';
$method = $_SERVER['REQUEST_METHOD'];

// Parse request path
$request_uri = $_SERVER['REQUEST_URI'];
$path_parts = explode('/', trim(parse_url($request_uri, PHP_URL_PATH), '/'));
$api_index = array_search('api', $path_parts);
$action = $path_parts[$api_index + 2] ?? '';
$resource_id = $path_parts[$api_index + 3] ?? null;

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $action, $resource_id, $user_id, $user_role);
            break;
        case 'POST':
            // Validate CSRF for mutations
            if ($action !== 'check_slot' && $action !== 'available_slots') {
                require_csrf_token();
            }
            handlePost($conn, $action, $resource_id, $input, $user_id, $user_role);
            break;
        case 'PUT':
            require_csrf_token();
            handlePut($conn, $action, $resource_id, $input, $user_id, $user_role);
            break;
        case 'DELETE':
            require_csrf_token();
            handleDelete($conn, $action, $resource_id, $user_id, $user_role);
            break;
        default:
            json_response(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    json_response(['error' => 'Internal server error'], 500);
}

/**
 * Handle GET requests
 */
function handleGet($conn, $action, $id, $user_id, $user_role) {
    switch ($action) {
        case 'list':
            listAppointments($conn, $user_id, $user_role);
            break;
        case 'view':
            viewAppointment($conn, $id, $user_id, $user_role);
            break;
        case 'calendar':
            getCalendarData($conn, $user_id, $user_role);
            break;
        case 'stats':
            getStats($conn, $user_id, $user_role);
            break;
        case 'check_slot':
            checkSlotAvailability($conn, $user_id);
            break;
        case 'available_slots':
            getAvailableSlots($conn, $user_id);
            break;
        case 'history':
            getAppointmentHistory($conn, $id, $user_id, $user_role);
            break;
        case 'statuses':
            getStatuses($conn);
            break;
        case 'services':
            getServices($conn);
            break;
        case 'staff':
            getStaffList($conn);
            break;
        case 'pets':
            getUserPets($conn, $user_id);
            break;
        case 'search':
            searchAppointments($conn, $user_id, $user_role);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

/**
 * Handle POST requests
 */
function handlePost($conn, $action, $id, $input, $user_id, $user_role) {
    switch ($action) {
        case 'create':
            createAppointment($conn, $input, $user_id, $user_role);
            break;
        case 'approve':
            updateAppointmentStatus($conn, $id, 'approved', $user_id, $user_role);
            break;
        case 'reject':
            $reason = $input['reason'] ?? '';
            updateAppointmentStatus($conn, $id, 'rejected', $user_id, $user_role, $reason);
            break;
        case 'cancel':
            $reason = $input['reason'] ?? '';
            updateAppointmentStatus($conn, $id, 'cancelled', $user_id, $user_role, $reason);
            break;
        case 'check_in':
            updateAppointmentStatus($conn, $id, 'checked_in', $user_id, $user_role);
            break;
        case 'start':
            updateAppointmentStatus($conn, $id, 'in_progress', $user_id, $user_role);
            break;
        case 'complete':
            updateAppointmentStatus($conn, $id, 'completed', $user_id, $user_role);
            break;
        case 'no_show':
            updateAppointmentStatus($conn, $id, 'no_show', $user_id, $user_role);
            break;
        case 'reschedule':
            rescheduleAppointment($conn, $id, $input, $user_id, $user_role);
            break;
        case 'calculate_duration':
            $service = $input['service'] ?? '';
            json_response(['duration' => get_service_duration($conn, $service)]);
            break;
        case 'check_slot':
            checkSlotAvailability($conn, $user_id, $input);
            break;
        case 'available_slots':
            getAvailableSlots($conn, $user_id, $input);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

/**
 * Handle PUT requests
 */
function handlePut($conn, $action, $id, $input, $user_id, $user_role) {
    updateAppointment($conn, $id, $input, $user_id, $user_role);
}

/**
 * Handle DELETE requests
 */
function handleDelete($conn, $action, $id, $user_id, $user_role) {
    deleteAppointment($conn, $id, $user_id, $user_role);
}

/**
 * List appointments with filtering and pagination
 */
function listAppointments($conn, $user_id, $user_role) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $per_page;
    
    $where = "WHERE a.deleted_at IS NULL";
    $params = [];
    $types = "";
    
    // Role-based filtering
    if ($user_role === 'client') {
        $where .= " AND a.user_id = ?";
        $params[] = $user_id;
        $types .= "i";
    } elseif ($user_role === 'doctor' || $user_role === 'staff') {
        $where .= " AND (a.doctor_id = ? OR a.doctor_id IS NULL)";
        $params[] = $user_id;
        $types .= "i";
    }
    
    // Filters
    $filters = [
        'status' => 'a.status =',
        'staff_id' => 'a.doctor_id =',
        'client_id' => 'a.user_id =',
        'date' => 'a.appointment_date =',
        'date_from' => 'a.appointment_date >=',
        'date_to' => 'a.appointment_date <=',
        'service' => 'a.service_type LIKE',
        'search' => '(a.pet_name LIKE OR u.fullname LIKE OR a.appointment_number LIKE)'
    ];
    
    foreach ($filters as $key => $condition) {
        if (!empty($_GET[$key])) {
            $val = sanitize_input($_GET[$key]);
            if ($key === 'search') {
                $where .= " AND (a.pet_name LIKE ? OR u.fullname LIKE ? OR a.appointment_number LIKE ?)";
                $search_term = "%{$val}%";
                $params[] = $search_term;
                $params[] = $search_term;
                $params[] = $search_term;
                $types .= "sss";
            } elseif ($key === 'service') {
                $where .= " AND a.service_type LIKE ?";
                $params[] = "%{$val}%";
                $types .= "s";
            } elseif (in_array($key, ['staff_id', 'client_id'])) {
                $where .= " AND {$condition} ?";
                $params[] = (int)$val;
                $types .= "i";
            } else {
                $where .= " AND {$condition} ?";
                $params[] = $val;
                $types .= "s";
            }
        }
    }
    
    // Sorting
    $sort_field = in_array($_GET['sort'] ?? '', ['appointment_date', 'appointment_time', 'status', 'created_at']) ? $_GET['sort'] : 'appointment_date';
    $sort_dir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    
    // Count total
    $count_sql = "SELECT COUNT(*) as total FROM appointments a LEFT JOIN users u ON a.user_id = u.id {$where}";
    $stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    
    // Get data
    $sql = "SELECT a.*, u.fullname as client_name, u.phone as client_phone, u.email as client_email,
                   COALESCE(d.fullname, 'Unassigned') as staff_name,
                   s.name as service_name, s.duration_minutes as service_duration
            FROM appointments a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN users d ON a.doctor_id = d.id
            LEFT JOIN services s ON a.service_id = s.id
            {$where}
            ORDER BY {$sort_field} {$sort_dir}
            LIMIT ? OFFSET ?";
    
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get status colors
    $status_colors = [];
    $sc = $conn->query("SELECT name, color, display_name FROM appointment_statuses WHERE is_active = 1");
    while ($s = $sc->fetch_assoc()) {
        $status_colors[$s['name']] = $s;
    }
    
    // Enrich with status info
    foreach ($appointments as &$appt) {
        $appt['status_info'] = $status_colors[$appt['status']] ?? ['name' => $appt['status'], 'color' => '#6B7280', 'display_name' => ucfirst($appt['status'])];
        
        // Get pets for this appointment
        $pets_stmt = $conn->prepare("SELECT pet_id, pet_name, display_name, species, display_picture FROM appointment_pets WHERE appointment_id = ?");
        $pets_stmt->bind_param("i", $appt['id']);
        $pets_stmt->execute();
        $appt['pets'] = $pets_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    json_response([
        'appointments' => $appointments,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => ceil($total / $per_page)
        ],
        'filters' => [
            'status' => $_GET['status'] ?? null,
            'staff_id' => $_GET['staff_id'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'search' => $_GET['search'] ?? null
        ]
    ]);
}

/**
 * View single appointment
 */
function viewAppointment($conn, $id, $user_id, $user_role) {
    if (!$id) {
        json_response(['error' => 'Appointment ID required'], 400);
    }
    
    $where = "WHERE a.id = ? AND a.deleted_at IS NULL";
    if ($user_role === 'client') {
        $where .= " AND a.user_id = ?";
    }
    
    $sql = "SELECT a.*, u.fullname as client_name, u.email as client_email, u.phone as client_phone,
                   u.address as client_address,
                   COALESCE(d.fullname, 'Unassigned') as staff_name,
                   d.email as staff_email, d.phone as staff_phone,
                   s.name as service_name, s.description as service_description,
                   s.duration_minutes as service_duration, s.price as service_price,
                   s.color as service_color
            FROM appointments a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN users d ON a.doctor_id = d.id
            LEFT JOIN services s ON a.service_id = s.id
            {$where}";
    
    $stmt = $conn->prepare($sql);
    if ($user_role === 'client') {
        $stmt->bind_param("ii", $id, $user_id);
    } else {
        $stmt->bind_param("i", $id);
    }
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    
    if (!$appointment) {
        json_response(['error' => 'Appointment not found'], 404);
    }
    
    // Get status info
    $stmt = $conn->prepare("SELECT * FROM appointment_statuses WHERE name = ?");
    $stmt->bind_param("s", $appointment['status']);
    $stmt->execute();
    $appointment['status_info'] = $stmt->get_result()->fetch_assoc();
    
    // Get pets
    $stmt = $conn->prepare("SELECT * FROM appointment_pets WHERE appointment_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $appointment['pets'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get history
    $stmt = $conn->prepare("SELECT ah.*, u.fullname as performed_by FROM appointment_history ah LEFT JOIN users u ON ah.user_id = u.id WHERE ah.appointment_id = ? ORDER BY ah.created_at DESC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $appointment['history'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get notifications
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE reference_type = 'appointment' AND reference_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $appointment['notifications'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response(['appointment' => $appointment]);
}

/**
 * Create appointment
 */
function createAppointment($conn, $input, $user_id, $user_role) {
    $errors = [];
    
    // Validate required fields
    $required = ['pet_name', 'service_type', 'appointment_date', 'appointment_time'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            $errors[] = "{$field} is required";
        }
    }
    
    if (!empty($errors)) {
        json_response(['error' => 'Validation failed', 'errors' => $errors], 422);
    }
    
    // Sanitize inputs
    $pet_name = sanitize_input($input['pet_name']);
    $service_type = sanitize_input($input['service_type']);
    $appointment_date = sanitize_input($input['appointment_date'], 'sql');
    $appointment_time = sanitize_input($input['appointment_time'], 'sql');
    $doctor_id = !empty($input['doctor_id']) ? (int)$input['doctor_id'] : null;
    $service_location = sanitize_input($input['service_location'] ?? 'On-Site');
    $notes = sanitize_input($input['notes'] ?? '');
    
    // Get service duration
    $duration = get_service_duration($conn, $service_type);
    
    // Validate slot
    $validation = validate_schedule_slot($conn, [
        'doctor_id' => $doctor_id,
        'date' => $appointment_date,
        'time' => $appointment_time,
        'service' => $service_type,
        'duration' => $duration,
        'client_id' => $user_id
    ]);
    
    if (!$validation['valid']) {
        json_response([
            'error' => $validation['message'],
            'suggestions' => $validation['suggestions'] ?? [],
            'conflicts' => $validation['conflicts'] ?? []
        ], 409);
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Generate appointment number
        $appt_number = generate_appointment_number($conn);
        
        // Calculate end time
        $end_time = date('H:i:s', strtotime($appointment_time) + ($duration * 60));
        
        // Determine initial status
        $settings = get_clinic_settings($conn);
        $auto_approve = ($settings['auto_approve_appointments'] ?? '0') === '1';
        $initial_status = $auto_approve ? 'approved' : 'pending';
        
        // Insert appointment
        $stmt = $conn->prepare("
            INSERT INTO appointments (
                appointment_number, user_id, pet_name, service_type, 
                appointment_date, appointment_time, end_time, duration_minutes,
                service_location, doctor_id, notes, status, created_by, 
                appointment_end_time, service_duration_minutes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $appt_end_time_db = $end_time;
        $stmt->bind_param(
            "sisssssiississi",
            $appt_number, $user_id, $pet_name, $service_type,
            $appointment_date, $appointment_time, $end_time, $duration,
            $service_location, $doctor_id, $notes, $initial_status, $user_id,
            $appt_end_time_db, $duration
        );
        $stmt->execute();
        $appointment_id = $stmt->insert_id;
        
        // Insert appointment history
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $conn->prepare("
            INSERT INTO appointment_history (appointment_id, user_id, action, to_status, description, ip_address, user_agent)
            VALUES (?, ?, 'created', ?, 'Appointment created', ?, ?)
        ");
        $stmt->bind_param("iisss", $appointment_id, $user_id, $initial_status, $ip, $ua);
        $stmt->execute();
        
        // Insert into appointment_pets junction
        $pet_ids = $input['pet_ids'] ?? [];
        $pet_names = explode(',', $pet_name);
        foreach ($pet_names as $index => $name) {
            $name = trim($name);
            $pid = $pet_ids[$index] ?? null;
            $stmt = $conn->prepare("
                INSERT INTO appointment_pets (appointment_id, pet_id, pet_name, display_name, species)
                VALUES (?, ?, ?, ?, ?)
            ");
            $species = '';
            if ($pid) {
                $p = $conn->prepare("SELECT name, species FROM pets WHERE id = ?");
                $p->bind_param("i", $pid);
                $p->execute();
                $pd = $p->get_result()->fetch_assoc();
                if ($pd) {
                    $species = $pd['species'] ?? '';
                }
            }
            $stmt->bind_param("iisss", $appointment_id, $pid, $name, $name, $species);
            $stmt->execute();
        }
        
        // Create notification
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, reference_type, reference_id, sent_via)
            VALUES (?, 'appointment', 'Appointment Booked', ?, ?, 'appointment', ?, '[\"in_app\"]')
        ");
        $message = "Your appointment for {$pet_name} has been booked successfully. Reference: {$appt_number}";
        $link = "appointments.php?view={$appointment_id}";
        $stmt->bind_param("issi", $user_id, $message, $link, $appointment_id);
        $stmt->execute();
        
        // Notify admin/staff
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, reference_type, reference_id, sent_via)
            VALUES (1, 'appointment', 'New Appointment', ?, ?, 'appointment', ?, '[\"in_app\"]')
        ");
        $admin_msg = "New appointment booked: {$pet_name} - {$service_type} on {$appointment_date} at {$appointment_time}";
        $stmt->bind_param("ssi", $admin_msg, $link, $appointment_id);
        $stmt->execute();
        
        // Log activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, user_role, action, module, description, ip_address, user_agent)
            VALUES (?, ?, 'appointment_created', 'appointments', ?, ?, ?)
        ");
        $desc = "Created appointment {$appt_number} for {$pet_name}";
        $stmt->bind_param("issss", $user_id, $user_role, $desc, $ip, $ua);
        $stmt->execute();
        
        $conn->commit();
        
        json_response([
            'appointment_id' => $appointment_id,
            'appointment_number' => $appt_number,
            'status' => $initial_status
        ], 201);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Create appointment error: " . $e->getMessage());
        json_response(['error' => 'Failed to create appointment: ' . $e->getMessage()], 500);
    }
}

/**
 * Update appointment status
 */
function updateAppointmentStatus($conn, $id, $new_status, $user_id, $user_role, $reason = '') {
    if (!$id) {
        json_response(['error' => 'Appointment ID required'], 400);
    }
    
    // Validate status transition
    $stmt = $conn->prepare("SELECT status, user_id, doctor_id, appointment_number, pet_name FROM appointments WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    
    if (!$appt) {
        json_response(['error' => 'Appointment not found'], 404);
    }
    
    $current_status = $appt['status'];
    
    // Permission check
    if ($user_role === 'client' && $appt['user_id'] != $user_id) {
        json_response(['error' => 'Unauthorized'], 403);
    }
    
    if ($user_role === 'doctor' && $appt['doctor_id'] != $user_id) {
        json_response(['error' => 'Unauthorized'], 403);
    }
    
    // Validate transitions
    $valid_transitions = [
        'pending' => ['approved', 'cancelled', 'rejected'],
        'waiting_approval' => ['approved', 'cancelled', 'rejected'],
        'approved' => ['scheduled', 'cancelled', 'checked_in'],
        'scheduled' => ['checked_in', 'cancelled', 'no_show'],
        'checked_in' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'rejected' => [],
        'no_show' => ['rescheduled'],
        'rescheduled' => ['approved', 'cancelled']
    ];
    
    if (!in_array($new_status, $valid_transitions[$current_status] ?? [])) {
        json_response(['error' => "Cannot transition from '{$current_status}' to '{$new_status}'"], 422);
    }
    
    $conn->begin_transaction();
    
    try {
        $update_fields = ["status = ?"];
        $params = [$new_status];
        $types = "s";
        
        // Set timestamps based on status
        switch ($new_status) {
            case 'checked_in':
                $update_fields[] = "check_in_time = NOW()";
                break;
            case 'in_progress':
                $update_fields[] = "start_time = NOW()";
                break;
            case 'completed':
                $update_fields[] = "completed_time = NOW()";
                break;
            case 'cancelled':
                $update_fields[] = "cancelled_at = NOW()";
                $update_fields[] = "cancellation_reason = ?";
                $params[] = $reason;
                $types .= "s";
                break;
        }
        
        $update_fields[] = "updated_by = ?";
        $params[] = $user_id;
        $types .= "i";
        
        $params[] = $id;
        $types .= "i";
        
        $sql = "UPDATE appointments SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        // Record history
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $conn->prepare("INSERT INTO appointment_history (appointment_id, user_id, action, from_status, to_status, description, metadata, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $action = "status_{$new_status}";
        $desc = "Status changed from {$current_status} to {$new_status}";
        $meta = json_encode(['reason' => $reason]);
        $stmt->bind_param("iisssssss", $id, $user_id, $action, $current_status, $new_status, $desc, $meta, $ip, $ua);
        $stmt->execute();
        
        // Create notification
        $notify_user = $appt['user_id'];
        $title = "Appointment {$new_status}";
        $message = "Your appointment for {$appt['pet_name']} has been " . ($new_status === 'cancelled' ? "cancelled" : "updated to {$new_status}");
        if ($reason) $message .= ". Reason: {$reason}";
        $link = "appointments.php?view={$id}";
        
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, reference_type, reference_id, sent_via) VALUES (?, 'appointment', ?, ?, ?, 'appointment', ?, '[\"in_app\"]')");
        $stmt->bind_param("isssi", $notify_user, $title, $message, $link, $id);
        $stmt->execute();
        
        // Log activity
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_role, action, module, description, ip_address, user_agent) VALUES (?, ?, ?, 'appointments', ?, ?, ?)");
        $action_log = "appointment_{$new_status}";
        $log_desc = "Appointment #{$appt['appointment_number']} status: {$current_status} -> {$new_status}";
        $stmt->bind_param("isssss", $user_id, $user_role, $action_log, $log_desc, $ip, $ua);
        $stmt->execute();
        
        $conn->commit();
        
        json_response([
            'message' => "Appointment status updated to {$new_status}",
            'appointment_id' => $id,
            'status' => $new_status,
            'previous_status' => $current_status
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        json_response(['error' => 'Failed to update status: ' . $e->getMessage()], 500);
    }
}

/**
 * Reschedule appointment
 */
function rescheduleAppointment($conn, $id, $input, $user_id, $user_role) {
    if (!$id) {
        json_response(['error' => 'Appointment ID required'], 400);
    }
    
    $new_date = $input['new_date'] ?? '';
    $new_time = $input['new_time'] ?? '';
    $new_doctor = $input['new_doctor_id'] ?? null;
    $reason = $input['reason'] ?? '';
    
    if (empty($new_date) || empty($new_time)) {
        json_response(['error' => 'New date and time are required'], 422);
    }
    
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    
    if (!$appt) {
        json_response(['error' => 'Appointment not found'], 404);
    }
    
    // Validate new slot
    $doctor_id = $new_doctor ?: $appt['doctor_id'];
    $validation = validate_schedule_slot($conn, [
        'doctor_id' => $doctor_id,
        'date' => $new_date,
        'time' => $new_time,
        'service' => $appt['service_type'],
        'duration' => $appt['duration_minutes'] ?: 30,
        'appointment_id' => $id,
        'client_id' => $appt['user_id']
    ]);
    
    if (!$validation['valid']) {
        json_response([
            'error' => $validation['message'],
            'suggestions' => $validation['suggestions'] ?? []
        ], 409);
    }
    
    $conn->begin_transaction();
    
    try {
        $old_date = $appt['appointment_date'];
        $old_time = $appt['appointment_time'];
        $old_doctor = $appt['doctor_id'];
        
        // Update appointment
        $stmt = $conn->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, doctor_id = ?, status = 'rescheduled', rescheduled_from = ?, updated_by = ? WHERE id = ?");
        $stmt->bind_param("ssiiii", $new_date, $new_time, $doctor_id, $id, $user_id, $id);
        $stmt->execute();
        
        // Record history
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $conn->prepare("INSERT INTO appointment_history (appointment_id, user_id, action, from_status, to_status, description, metadata, ip_address, user_agent) VALUES (?, ?, 'rescheduled', ?, 'rescheduled', ?, ?, ?, ?)");
        $desc = "Rescheduled from {$old_date} {$old_time} to {$new_date} {$new_time}";
        $meta = json_encode(['old_date' => $old_date, 'old_time' => $old_time, 'new_date' => $new_date, 'new_time' => $new_time, 'reason' => $reason]);
        $stmt->bind_param("iisssss", $id, $user_id, $appt['status'], $desc, $meta, $ip, $ua);
        $stmt->execute();
        
        // Notify
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, reference_type, reference_id, sent_via) VALUES (?, 'appointment', 'Appointment Rescheduled', ?, ?, 'appointment', ?, '[\"in_app\"]')");
        $msg = "Your appointment for {$appt['pet_name']} has been rescheduled to {$new_date} at {$new_time}";
        $link = "appointments.php?view={$id}";
        $stmt->bind_param("issi", $appt['user_id'], $msg, $link, $id);
        $stmt->execute();
        
        // Log
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_role, action, module, description, ip_address, user_agent) VALUES (?, ?, 'appointment_rescheduled', 'appointments', ?, ?, ?)");
        $log_desc = "Appointment #{$appt['appointment_number']} rescheduled from {$old_date} to {$new_date}";
        $stmt->bind_param("issss", $user_id, $user_role, $log_desc, $ip, $ua);
        $stmt->execute();
        
        $conn->commit();
        
        json_response([
            'message' => 'Appointment rescheduled successfully',
            'appointment_id' => $id,
            'old_date' => $old_date,
            'old_time' => $old_time,
            'new_date' => $new_date,
            'new_time' => $new_time
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        json_response(['error' => 'Failed to reschedule: ' . $e->getMessage()], 500);
    }
}

/**
 * Update appointment details
 */
function updateAppointment($conn, $id, $input, $user_id, $user_role) {
    if (!$id) {
        json_response(['error' => 'Appointment ID required'], 400);
    }
    
    $allowed_fields = ['notes', 'internal_notes', 'service_location', 'pet_name'];
    $updates = [];
    $params = [];
    $types = "";
    
    foreach ($allowed_fields as $field) {
        if (isset($input[$field])) {
            $updates[] = "{$field} = ?";
            $params[] = sanitize_input($input[$field]);
            $types .= "s";
        }
    }
    
    if (empty($updates)) {
        json_response(['error' => 'No fields to update'], 422);
    }
    
    $updates[] = "updated_by = ?";
    $params[] = $user_id;
    $types .= "i";
    
    $params[] = $id;
    $types .= "i";
    
    $sql = "UPDATE appointments SET " . implode(', ', $updates) . " WHERE id = ? AND deleted_at IS NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        json_response(['message' => 'Appointment updated successfully']);
    } else {
        json_response(['error' => 'Failed to update appointment'], 500);
    }
}

/**
 * Delete appointment (soft delete)
 */
function deleteAppointment($conn, $id, $user_id, $user_role) {
    if (!$id) {
        json_response(['error' => 'Appointment ID required'], 400);
    }
    
    $stmt = $conn->prepare("UPDATE appointments SET deleted_at = NOW(), updated_by = ? WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("ii", $user_id, $id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        // Log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_role, action, module, description, ip_address, user_agent) VALUES (?, ?, 'appointment_deleted', 'appointments', ?, ?, ?)");
        $desc = "Deleted appointment #{$id}";
        $stmt->bind_param("issss", $user_id, $user_role, $desc, $ip, $ua);
        $stmt->execute();
        
        json_response(['message' => 'Appointment deleted successfully']);
    } else {
        json_response(['error' => 'Appointment not found'], 404);
    }
}

/**
 * Get calendar data
 */
function getCalendarData($conn, $user_id, $user_role) {
    $start = $_GET['start'] ?? date('Y-m-d', strtotime('-1 month'));
    $end = $_GET['end'] ?? date('Y-m-d', strtotime('+1 month'));
    
    $filters = [];
    if ($user_role === 'client') {
        $filters['user_id'] = $user_id;
    } elseif ($user_role === 'doctor' || $user_role === 'staff') {
        $filters['staff_id'] = $user_id;
    }
    
    if (!empty($_GET['staff_id'])) {
        $filters['staff_id'] = (int)$_GET['staff_id'];
    }
    if (!empty($_GET['status'])) {
        $filters['status'] = $_GET['status'];
    }
    
    $events = get_calendar_appointments($conn, $start, $end, $filters);
    
    // Get statuses for legend
    $statuses = [];
    $sc = $conn->query("SELECT name, display_name, color, icon FROM appointment_statuses WHERE is_active = 1 ORDER BY sort_order");
    while ($s = $sc->fetch_assoc()) {
        $statuses[] = $s;
    }
    
    json_response([
        'events' => $events,
        'statuses' => $statuses,
        'date_range' => ['start' => $start, 'end' => $end]
    ]);
}

/**
 * Get appointment statistics
 */
function getStats($conn, $user_id, $user_role) {
    $stats = get_appointment_stats($conn, $user_id, $user_role);
    
    // Get trends (last 30 days vs previous 30 days)
    $period_start = date('Y-m-d', strtotime('-30 days'));
    $period_end = date('Y-m-d');
    $prev_period_start = date('Y-m-d', strtotime('-60 days'));
    $prev_period_end = date('Y-m-d', strtotime('-31 days'));
    
    $where = '';
    $params = [];
    $types = '';
    
    if ($user_role === 'client') {
        $where = "WHERE a.user_id = ?";
        $params[] = $user_id;
        $types .= "i";
    } elseif ($user_role === 'doctor') {
        $where = "WHERE a.doctor_id = ?";
        $params[] = $user_id;
        $types .= "i";
    }
    
    // Current period counts
    $stmt = $conn->prepare("SELECT COUNT(*) as count, DATE(appointment_date) as date FROM appointments a {$where} AND appointment_date BETWEEN ? AND ? GROUP BY DATE(appointment_date) ORDER BY date");
    $combined_params = array_merge($params, [$period_start, $period_end]);
    $combined_types = $types . "ss";
    $stmt->bind_param($combined_types, ...$combined_params);
    $stmt->execute();
    $daily_counts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Previous period
    $prev_params = array_merge($params, [$prev_period_start, $prev_period_end]);
    $stmt->bind_param($types . "ss", ...$prev_params);
    $stmt->execute();
    $prev_counts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Service distribution
    $stmt = $conn->prepare("SELECT s.name, COUNT(*) as count FROM appointments a JOIN services s ON a.service_id = s.id {$where} GROUP BY s.name ORDER BY count DESC");
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $service_dist = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Staff workload
    $stmt = $conn->query("SELECT u.fullname, COUNT(*) as count FROM appointments a JOIN users u ON a.doctor_id = u.id WHERE a.appointment_date >= CURDATE() AND a.status NOT IN ('cancelled', 'rejected', 'completed') GROUP BY a.doctor_id ORDER BY count DESC");
    $staff_workload = $stmt->fetch_all(MYSQLI_ASSOC);
    
    json_response([
        'stats' => $stats,
        'trends' => [
            'daily' => $daily_counts,
            'previous_period' => count($prev_counts),
            'current_period' => count($daily_counts),
            'change_percent' => count($prev_counts) > 0 
                ? round((count($daily_counts) - count($prev_counts)) / count($prev_counts) * 100, 1)
                : 0
        ],
        'service_distribution' => $service_dist,
        'staff_workload' => $staff_workload
    ]);
}

/**
 * Check slot availability
 */
function checkSlotAvailability($conn, $user_id, $input = []) {
    $data = $input ?: $_GET;
    
    $result = validate_schedule_slot($conn, [
        'doctor_id' => $data['doctor_id'] ?? null,
        'date' => $data['date'] ?? '',
        'time' => $data['time'] ?? '',
        'service' => $data['service'] ?? '',
        'duration' => $data['duration'] ?? 30,
        'appointment_id' => $data['appointment_id'] ?? null,
        'client_id' => $user_id
    ]);
    
    json_response($result);
}

/**
 * Get available slots
 */
function getAvailableSlots($conn, $user_id, $input = []) {
    $data = $input ?: $_GET;
    $staff_id = $data['staff_id'] ?? null;
    $date = $data['date'] ?? '';
    $service = $data['service'] ?? '';
    
    if (!$staff_id || !$date) {
        json_response(['error' => 'Staff ID and date are required'], 422);
    }
    
    $result = generate_available_slots($conn, $staff_id, $date, $service);
    json_response($result);
}

/**
 * Get appointment history
 */
function getAppointmentHistory($conn, $id, $user_id, $user_role) {
    if (!$id) {
        json_response(['error' => 'Appointment ID required'], 400);
    }
    
    $stmt = $conn->prepare("
        SELECT ah.*, u.fullname as performed_by, u.role as performed_by_role
        FROM appointment_history ah
        LEFT JOIN users u ON ah.user_id = u.id
        WHERE ah.appointment_id = ?
        ORDER BY ah.created_at DESC
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response(['history' => $history]);
}

/**
 * Get all appointment statuses
 */
function getStatuses($conn) {
    $stmt = $conn->query("SELECT * FROM appointment_statuses WHERE is_active = 1 ORDER BY sort_order");
    json_response(['statuses' => $stmt->fetch_all(MYSQLI_ASSOC)]);
}

/**
 * Get all services
 */
function getServices($conn) {
    $stmt = $conn->query("SELECT * FROM services WHERE is_active = 1 ORDER BY name");
    json_response(['services' => $stmt->fetch_all(MYSQLI_ASSOC)]);
}

/**
 * Get staff list
 */
function getStaffList($conn) {
    $stmt = $conn->query("
        SELECT u.id, u.fullname, u.email, s.specialization, s.is_active, s.max_appointments_per_day
        FROM users u
        JOIN staff s ON s.user_id = u.id
        WHERE u.role IN ('doctor', 'staff') AND s.is_active = 1
        ORDER BY u.fullname
    ");
    json_response(['staff' => $stmt->fetch_all(MYSQLI_ASSOC)]);
}

/**
 * Get user's pets
 */
function getUserPets($conn, $user_id) {
    $stmt = $conn->prepare("SELECT id, name, species, breed, pet_picture, date_of_birth FROM pets WHERE user_id = ? AND is_active = 1 ORDER BY name");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    json_response(['pets' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

/**
 * Search appointments
 */
function searchAppointments($conn, $user_id, $user_role) {
    $keyword = $_GET['q'] ?? '';
    if (strlen($keyword) < 2) {
        json_response(['appointments' => []]);
    }
    
    $where = "WHERE (a.pet_name LIKE ? OR a.appointment_number LIKE ? OR u.fullname LIKE ?) AND a.deleted_at IS NULL";
    $params = ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"];
    $types = "sss";
    
    if ($user_role === 'client') {
        $where .= " AND a.user_id = ?";
        $params[] = $user_id;
        $types .= "i";
    }
    
    $stmt = $conn->prepare("
        SELECT a.id, a.appointment_number, a.pet_name, a.appointment_date, a.appointment_time, a.status,
               u.fullname as client_name, COALESCE(d.fullname, 'Unassigned') as staff_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users d ON a.doctor_id = d.id
        {$where}
        ORDER BY a.appointment_date DESC
        LIMIT 20
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    json_response(['appointments' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}