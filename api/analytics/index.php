<?php
/**
 * Analytics API
 * Dashboard metrics, charts data, and business intelligence
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../dbconnection.php';
require_once '../../includes/security.php';
require_once '../../includes/scheduling_engine.php';

require_auth();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $user_id, $user_role);
            break;
        default:
            json_response(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Analytics API Error: " . $e->getMessage());
    json_response(['error' => 'Internal server error'], 500);
}

function handleGet($conn, $user_id, $user_role) {
    $dashboard = $_GET['dashboard'] ?? 'admin';
    
    switch ($dashboard) {
        case 'admin':
            getAdminDashboard($conn, $user_id);
            break;
        case 'staff':
            getStaffDashboard($conn, $user_id);
            break;
        case 'client':
            getClientDashboard($conn, $user_id);
            break;
        default:
            json_response(['error' => 'Unknown dashboard type'], 400);
    }
}

/**
 * Admin Dashboard - Full analytics and metrics
 */
function getAdminDashboard($conn, $user_id) {
    $today = date('Y-m-d');
    $month_start = date('Y-m-01');
    $year_start = date('Y-01-01');
    
    // ===== Stats Cards =====
    $stats = get_appointment_stats($conn);
    
    // Active users
    $stmt = $conn->query("
        SELECT 
            SUM(CASE WHEN role = 'client' THEN 1 ELSE 0 END) as total_clients,
            SUM(CASE WHEN role IN ('doctor', 'staff') THEN 1 ELSE 0 END) as total_staff,
            SUM(CASE WHEN role = 'client' AND is_active = 1 THEN 1 ELSE 0 END) as active_clients,
            SUM(CASE WHEN role IN ('doctor', 'staff') AND is_active = 1 THEN 1 ELSE 0 END) as active_staff
        FROM users
    ");
    $user_counts = $stmt->fetch_assoc();
    
    $stats['total_clients'] = (int)$user_counts['total_clients'];
    $stats['total_staff'] = (int)$user_counts['total_staff'];
    $stats['active_clients'] = (int)$user_counts['active_clients'];
    $stats['active_staff'] = (int)$user_counts['active_staff'];
    
    // ===== Today's Timeline =====
    $stmt = $conn->prepare("
        SELECT a.appointment_time, a.pet_name, a.status,
               u.fullname as client_name, COALESCE(d.fullname, 'Unassigned') as staff_name,
               s.name as service_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users d ON a.doctor_id = d.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date = ? AND a.deleted_at IS NULL
        ORDER BY a.appointment_time
    ");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $today_schedule = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // ===== Upcoming =====
    $stmt = $conn->prepare("
        SELECT a.*, u.fullname as client_name, COALESCE(d.fullname, 'Unassigned') as staff_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users d ON a.doctor_id = d.id
        WHERE a.appointment_date >= ? AND a.status IN ('approved', 'scheduled') AND a.deleted_at IS NULL
        ORDER BY a.appointment_date, a.appointment_time
        LIMIT 10
    ");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $upcoming = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // ===== Pending Approvals =====
    $stmt = $conn->prepare("
        SELECT a.*, u.fullname as client_name, s.name as service_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.status = 'pending' AND a.deleted_at IS NULL
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // ===== Monthly Trend (last 12 months) =====
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(appointment_date, '%Y-%m') as month,
               COUNT(*) as total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM appointments 
        WHERE appointment_date >= DATE_SUH(NOW(), INTERVAL 12 MONTH) AND deleted_at IS NULL
        GROUP BY DATE_FORMAT(appointment_date, '%Y-%m')
        ORDER BY month
    ");
    $stmt->execute();
    $monthly_trend = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // ===== Service Distribution =====
    $stmt = $conn->prepare("
        SELECT s.name, COUNT(*) as count, s.color
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date >= ? AND a.deleted_at IS NULL
        GROUP BY s.name
        ORDER BY count DESC
    ");
    $stmt->bind_param("s", $month_start);
    $stmt->execute();
    $service_dist = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // ===== Staff Workload =====
    $stmt = $conn->prepare("
        SELECT COALESCE(d.fullname, 'Unassigned') as name,
               COUNT(*) as today_count
        FROM appointments a
        LEFT JOIN users d ON a.doctor_id = d.id
        WHERE a.appointment_date = ? AND a.deleted_at IS NULL AND a.status NOT IN ('cancelled', 'rejected')
        GROUP BY a.doctor_id
        ORDER BY today_count DESC
    ");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $staff_load = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // ===== Revenue Stats =====
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(s.price), 0) as today_revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date = ? AND a.status = 'completed' AND a.deleted_at IS NULL
    ");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $today_revenue = $stmt->get_result()->fetch_assoc()['today_revenue'];
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(s.price), 0) as month_revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date >= ? AND a.status = 'completed' AND a.deleted_at IS NULL
    ");
    $stmt->bind_param("s", $month_start);
    $stmt->execute();
    $month_revenue = $stmt->get_result()->fetch_assoc()['month_revenue'];
    
    // ===== Recent Activities =====
    $stmt = $conn->query("
        SELECT al.*, u.fullname as user_name
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $recent_activities = $stmt->fetch_all(MYSQLI_ASSOC);
    
    // ===== Peak Hours =====
    $stmt = $conn->prepare("
        SELECT HOUR(appointment_time) as hour, COUNT(*) as count
        FROM appointments 
        WHERE appointment_date >= ? AND deleted_at IS NULL
        GROUP BY HOUR(appointment_time)
        ORDER BY hour
    ");
    $stmt->bind_param("s", $month_start);
    $stmt->execute();
    $peak_hours = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response([
        'stats' => $stats,
        'today_schedule' => $today_schedule,
        'upcoming_appointments' => $upcoming,
        'pending_approvals' => $pending,
        'monthly_trend' => $monthly_trend,
        'service_distribution' => $service_dist,
        'staff_workload' => $staff_load,
        'revenue' => [
            'today' => $today_revenue,
            'month' => $month_revenue
        ],
        'recent_activities' => $recent_activities,
        'peak_hours' => $peak_hours,
        'date' => $today
    ]);
}

/**
 * Staff Dashboard
 */
function getStaffDashboard($conn, $user_id) {
    $today = date('Y-m-d');
    
    // Staff info
    $stmt = $conn->prepare("
        SELECT u.*, s.specialization, s.max_appointments_per_day
        FROM users u
        JOIN staff s ON s.user_id = u.id
        WHERE u.id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $staff_info = $stmt->get_result()->fetch_assoc();
    
    // Today's schedule
    $stmt = $conn->prepare("
        SELECT a.*, u.fullname as client_name, u.phone as client_phone,
               s.name as service_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.doctor_id = ? AND a.appointment_date = ? AND a.deleted_at IS NULL
        ORDER BY a.appointment_time
    ");
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();
    $today_schedule = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Upcoming
    $stmt = $conn->prepare("
        SELECT a.*, u.fullname as client_name,
               s.name as service_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.doctor_id = ? AND a.appointment_date > ? AND a.status NOT IN ('completed', 'cancelled', 'rejected') AND a.deleted_at IS NULL
        ORDER BY a.appointment_date, a.appointment_time
        LIMIT 10
    ");
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();
    $upcoming = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Stats
    $stats = get_appointment_stats($conn, $user_id, 'doctor');
    
    // Notifications
    $stmt = $conn->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response([
        'staff' => $staff_info,
        'today_schedule' => $today_schedule,
        'upcoming_appointments' => $upcoming,
        'stats' => $stats,
        'notifications' => $notifications
    ]);
}

/**
 * Client Dashboard
 */
function getClientDashboard($conn, $user_id) {
    $today = date('Y-m-d');
    
    // Upcoming appointment
    $stmt = $conn->prepare("
        SELECT a.*, COALESCE(d.fullname, 'Clinic Staff') as staff_name,
               s.name as service_name
        FROM appointments a
        LEFT JOIN users d ON a.doctor_id = d.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.user_id = ? AND a.appointment_date >= ? AND a.status NOT IN ('completed', 'cancelled', 'rejected', 'no_show') AND a.deleted_at IS NULL
        ORDER BY a.appointment_date, a.appointment_time
        LIMIT 1
    ");
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();
    $next_appointment = $stmt->get_result()->fetch_assoc();
    
    // Appointment history
    $stmt = $conn->prepare("
        SELECT a.*, COALESCE(d.fullname, 'Clinic Staff') as staff_name,
               s.name as service_name
        FROM appointments a
        LEFT JOIN users d ON a.doctor_id = d.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.user_id = ? AND a.deleted_at IS NULL
        ORDER BY a.appointment_date DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Stats
    $stats = get_appointment_stats($conn, $user_id, 'client');
    
    // Pets
    $stmt = $conn->prepare("SELECT id, name, species, breed, pet_picture FROM pets WHERE user_id = ? AND is_active = 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Notifications
    $stmt = $conn->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response([
        'next_appointment' => $next_appointment,
        'history' => $history,
        'stats' => $stats,
        'pets' => $pets,
        'notifications' => $notifications
    ]);
}