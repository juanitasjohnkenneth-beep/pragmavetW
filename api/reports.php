<?php
/**
 * Reports API
 * Generate, view, and export reports
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../dbconnection.php';
require_once '../includes/security.php';
require_once '../includes/scheduling_engine.php';

require_auth();
require_role(['admin', 'doctor', 'staff']);

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$method = $_SERVER['REQUEST_METHOD'];

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $user_id, $user_role);
            break;
        case 'POST':
            require_csrf_token();
            handlePost($conn, $user_id, $user_role, $input);
            break;
        default:
            json_response(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Reports API Error: " . $e->getMessage());
    json_response(['error' => 'Internal server error'], 500);
}

function handleGet($conn, $user_id, $user_role) {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            listReports($conn, $user_id);
            break;
        case 'view':
            viewReport($conn, $_GET['id'] ?? null);
            break;
        case 'daily':
            generateDailyReport($conn, $_GET['date'] ?? date('Y-m-d'));
            break;
        case 'weekly':
            generateWeeklyReport($conn, $_GET['date'] ?? date('Y-m-d'));
            break;
        case 'monthly':
            generateMonthlyReport($conn, $_GET['year'] ?? date('Y'), $_GET['month'] ?? date('m'));
            break;
        case 'annual':
            generateAnnualReport($conn, $_GET['year'] ?? date('Y'));
            break;
        case 'staff_performance':
            generateStaffPerformance($conn, $_GET);
            break;
        case 'service_utilization':
            generateServiceUtilization($conn, $_GET);
            break;
        case 'cancellation':
            generateCancellationReport($conn, $_GET);
            break;
        case 'no_show':
            generateNoShowReport($conn, $_GET);
            break;
        case 'client_activity':
            generateClientActivity($conn, $_GET);
            break;
        case 'audit_logs':
            generateAuditLogs($conn, $_GET);
            break;
        case 'analytics':
            getAnalytics($conn);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

function handlePost($conn, $user_id, $user_role, $input) {
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'generate':
            generateReport($conn, $user_id, $input);
            break;
        case 'export_pdf':
            exportPDF($conn, $input);
            break;
        case 'export_excel':
            exportExcel($conn, $input);
            break;
        case 'delete':
            deleteReport($conn, $user_id, $input);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

/**
 * List generated reports
 */
function listReports($conn, $user_id) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    $stmt = $conn->prepare("
        SELECT r.*, u.fullname as generated_by_name
        FROM reports r
        LEFT JOIN users u ON r.generated_by = u.id
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("ii", $per_page, $offset);
    $stmt->execute();
    $reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $stmt = $conn->query("SELECT COUNT(*) as total FROM reports");
    $total = (int)$stmt->fetch_assoc()['total'];
    
    json_response([
        'reports' => $reports,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => ceil($total / $per_page)
        ]
    ]);
}

/**
 * View a specific report
 */
function viewReport($conn, $id) {
    if (!$id) json_response(['error' => 'Report ID required'], 422);
    
    $stmt = $conn->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    
    if (!$report) json_response(['error' => 'Report not found'], 404);
    
    $report['parameters'] = json_decode($report['parameters'], true);
    
    json_response(['report' => $report]);
}

/**
 * Generate daily report
 */
function generateDailyReport($conn, $date) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
               SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show,
               SUM(CASE WHEN status IN ('pending', 'approved', 'scheduled') THEN 1 ELSE 0 END) as active
        FROM appointments WHERE appointment_date = ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    
    // Get detailed appointments
    $stmt = $conn->prepare("
        SELECT a.*, u.fullname as client_name, COALESCE(d.fullname, 'Unassigned') as staff_name,
               s.name as service_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users d ON a.doctor_id = d.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date = ? AND a.deleted_at IS NULL
        ORDER BY a.appointment_time
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Revenue
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(s.price), 0) as revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date = ? AND a.status = 'completed' AND a.deleted_at IS NULL
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $revenue = $stmt->get_result()->fetch_assoc()['revenue'];
    
    json_response([
        'report_type' => 'daily',
        'date' => $date,
        'summary' => $summary,
        'appointments' => $appointments,
        'revenue' => $revenue,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate weekly report
 */
function generateWeeklyReport($conn, $date) {
    $week_start = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
    
    $stmt = $conn->prepare("
        SELECT appointment_date,
               COUNT(*) as total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
               SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show
        FROM appointments 
        WHERE appointment_date BETWEEN ? AND ? AND deleted_at IS NULL
        GROUP BY appointment_date
        ORDER BY appointment_date
    ");
    $stmt->bind_param("ss", $week_start, $week_end);
    $stmt->execute();
    $daily_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Totals
    $total = array_sum(array_column($daily_breakdown, 'total'));
    $completed = array_sum(array_column($daily_breakdown, 'completed'));
    $cancelled = array_sum(array_column($daily_breakdown, 'cancelled'));
    $no_show = array_sum(array_column($daily_breakdown, 'no_show'));
    
    // Revenue
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(s.price), 0) as revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.status = 'completed' AND a.deleted_at IS NULL
    ");
    $stmt->bind_param("ss", $week_start, $week_end);
    $stmt->execute();
    $revenue = $stmt->get_result()->fetch_assoc()['revenue'];
    
    json_response([
        'report_type' => 'weekly',
        'week_start' => $week_start,
        'week_end' => $week_end,
        'summary' => [
            'total' => $total,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'no_show' => $no_show,
            'revenue' => $revenue,
            'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0
        ],
        'daily_breakdown' => $daily_breakdown,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate monthly report
 */
function generateMonthlyReport($conn, $year, $month) {
    $month_start = "{$year}-{$month}-01";
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $stmt = $conn->prepare("
        SELECT DATE(appointment_date) as date,
               COUNT(*) as total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
               SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show
        FROM appointments 
        WHERE appointment_date BETWEEN ? AND ? AND deleted_at IS NULL
        GROUP BY DATE(appointment_date)
        ORDER BY date
    ");
    $stmt->bind_param("ss", $month_start, $month_end);
    $stmt->execute();
    $daily_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Service distribution
    $stmt = $conn->prepare("
        SELECT s.name, COUNT(*) as count, COALESCE(SUM(s.price), 0) as revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.deleted_at IS NULL
        GROUP BY s.name
        ORDER BY count DESC
    ");
    $stmt->bind_param("ss", $month_start, $month_end);
    $stmt->execute();
    $service_dist = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Staff performance
    $stmt = $conn->prepare("
        SELECT COALESCE(d.fullname, 'Unassigned') as staff_name,
               COUNT(*) as total,
               SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM appointments a
        LEFT JOIN users d ON a.doctor_id = d.id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.deleted_at IS NULL
        GROUP BY a.doctor_id
        ORDER BY total DESC
    ");
    $stmt->bind_param("ss", $month_start, $month_end);
    $stmt->execute();
    $staff_perf = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Revenue
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(s.price), 0) as revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.status = 'completed' AND a.deleted_at IS NULL
    ");
    $stmt->bind_param("ss", $month_start, $month_end);
    $stmt->execute();
    $revenue = $stmt->get_result()->fetch_assoc()['revenue'];
    
    $total = array_sum(array_column($daily_breakdown, 'total'));
    $completed = array_sum(array_column($daily_breakdown, 'completed'));
    
    json_response([
        'report_type' => 'monthly',
        'year' => $year,
        'month' => $month,
        'month_name' => date('F', strtotime($month_start)),
        'summary' => [
            'total' => $total,
            'completed' => $completed,
            'revenue' => $revenue,
            'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0,
            'avg_daily' => $total > 0 ? round($total / date('t', strtotime($month_start)), 1) : 0
        ],
        'daily_breakdown' => $daily_breakdown,
        'service_distribution' => $service_dist,
        'staff_performance' => $staff_perf,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate annual report
 */
function generateAnnualReport($conn, $year) {
    $year_start = "{$year}-01-01";
    $year_end = "{$year}-12-31";
    
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(appointment_date, '%Y-%m') as month,
               COUNT(*) as total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
               SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show
        FROM appointments 
        WHERE appointment_date BETWEEN ? AND ? AND deleted_at IS NULL
        GROUP BY DATE_FORMAT(appointment_date, '%Y-%m')
        ORDER BY month
    ");
    $stmt->bind_param("ss", $year_start, $year_end);
    $stmt->execute();
    $monthly_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Revenue by month
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(a.appointment_date, '%Y-%m') as month,
               COALESCE(SUM(s.price), 0) as revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.status = 'completed' AND a.deleted_at IS NULL
        GROUP BY DATE_FORMAT(a.appointment_date, '%Y-%m')
        ORDER BY month
    ");
    $stmt->bind_param("ss", $year_start, $year_end);
    $stmt->execute();
    $revenue_by_month = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $total = array_sum(array_column($monthly_breakdown, 'total'));
    $completed = array_sum(array_column($monthly_breakdown, 'completed'));
    $total_revenue = array_sum(array_column($revenue_by_month, 'revenue'));
    
    json_response([
        'report_type' => 'annual',
        'year' => $year,
        'summary' => [
            'total' => $total,
            'completed' => $completed,
            'total_revenue' => $total_revenue,
            'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0,
            'avg_monthly' => $total > 0 ? round($total / 12, 1) : 0
        ],
        'monthly_breakdown' => $monthly_breakdown,
        'revenue_by_month' => $revenue_by_month,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate staff performance report
 */
function generateStaffPerformance($conn, $params) {
    $date_from = $params['date_from'] ?? date('Y-m-01');
    $date_to = $params['date_to'] ?? date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT COALESCE(d.fullname, 'Unassigned') as staff_name,
               COUNT(*) as total_appointments,
               SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
               SUM(CASE WHEN a.status = 'no_show' THEN 1 ELSE 0 END) as no_show,
               COUNT(DISTINCT a.user_id) as unique_clients,
               COALESCE(SUM(s.price), 0) as revenue_generated
        FROM appointments a
        LEFT JOIN users d ON a.doctor_id = d.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.deleted_at IS NULL
        GROUP BY a.doctor_id
        ORDER BY total_appointments DESC
    ");
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $performance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response([
        'report_type' => 'staff_performance',
        'date_from' => $date_from,
        'date_to' => $date_to,
        'staff' => $performance,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate service utilization report
 */
function generateServiceUtilization($conn, $params) {
    $date_from = $params['date_from'] ?? date('Y-01-01');
    $date_to = $params['date_to'] ?? date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT s.name, s.category, s.duration_minutes, s.price,
               COUNT(a.id) as times_booked,
               SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed,
               COALESCE(SUM(CASE WHEN a.status = 'completed' THEN s.price ELSE 0 END), 0) as revenue
        FROM services s
        LEFT JOIN appointments a ON a.service_id = s.id 
            AND a.appointment_date BETWEEN ? AND ? AND a.deleted_at IS NULL
        GROUP BY s.id
        ORDER BY times_booked DESC
    ");
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response([
        'report_type' => 'service_utilization',
        'date_from' => $date_from,
        'date_to' => $date_to,
        'services' => $services,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate cancellation report
 */
function generateCancellationReport($conn, $params) {
    $date_from = $params['date_from'] ?? date('Y-m-01');
    $date_to = $params['date_to'] ?? date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT a.*, u.fullname as client_name, COALESCE(d.fullname, 'Unassigned') as staff_name,
               s.name as service_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users d ON a.doctor_id = d.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.status = 'cancelled' 
          AND a.appointment_date BETWEEN ? AND ? 
          AND a.deleted_at IS NULL
        ORDER BY a.cancelled_at DESC
    ");
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $cancellations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Cancellation rate
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM appointments 
        WHERE appointment_date BETWEEN ? AND ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc();
    
    $cancellation_rate = $totals['total'] > 0 ? round($totals['cancelled'] / $totals['total'] * 100, 1) : 0;
    
    json_response([
        'report_type' => 'cancellation',
        'date_from' => $date_from,
        'date_to' => $date_to,
        'cancellation_rate' => $cancellation_rate,
        'total_cancellations' => count($cancellations),
        'cancellations' => $cancellations,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate no-show report
 */
function generateNoShowReport($conn, $params) {
    $date_from = $params['date_from'] ?? date('Y-m-01');
    $date_to = $params['date_to'] ?? date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT a.*, u.fullname as client_name, COALESCE(d.fullname, 'Unassigned') as staff_name,
               s.name as service_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users d ON a.doctor_id = d.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.status = 'no_show' 
          AND a.appointment_date BETWEEN ? AND ? 
          AND a.deleted_at IS NULL
        ORDER BY a.appointment_date DESC
    ");
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $no_shows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show_count
        FROM appointments 
        WHERE appointment_date BETWEEN ? AND ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc();
    
    $no_show_rate = $totals['total'] > 0 ? round($totals['no_show_count'] / $totals['total'] * 100, 1) : 0;
    
    json_response([
        'report_type' => 'no_show',
        'date_from' => $date_from,
        'date_to' => $date_to,
        'no_show_rate' => $no_show_rate,
        'total_no_shows' => count($no_shows),
        'no_shows' => $no_shows,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate client activity report
 */
function generateClientActivity($conn, $params) {
    $date_from = $params['date_from'] ?? date('Y-01-01');
    $date_to = $params['date_to'] ?? date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT u.id, u.fullname, u.email, u.phone,
               COUNT(a.id) as total_appointments,
               SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
               SUM(CASE WHEN a.status = 'no_show' THEN 1 ELSE 0 END) as no_show,
               MAX(a.appointment_date) as last_visit,
               COUNT(DISTINCT p.id) as total_pets
        FROM users u
        LEFT JOIN appointments a ON a.user_id = u.id 
            AND a.appointment_date BETWEEN ? AND ? AND a.deleted_at IS NULL
        LEFT JOIN pets p ON p.user_id = u.id
        WHERE u.role = 'client'
        GROUP BY u.id
        ORDER BY total_appointments DESC
        LIMIT 100
    ");
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response([
        'report_type' => 'client_activity',
        'date_from' => $date_from,
        'date_to' => $date_to,
        'clients' => $clients,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate audit logs report
 */
function generateAuditLogs($conn, $params) {
    $date_from = $params['date_from'] ?? date('Y-m-01');
    $date_to = $params['date_to'] ?? date('Y-m-d');
    $module = $params['module'] ?? '';
    $action = $params['action'] ?? '';
    $user = $params['user_id'] ?? '';
    
    $where = "WHERE al.created_at BETWEEN ? AND ?";
    $bind_params = [$date_from, $date_to];
    $types = "ss";
    
    if (!empty($module)) {
        $where .= " AND al.module = ?";
        $bind_params[] = $module;
        $types .= "s";
    }
    if (!empty($action)) {
        $where .= " AND al.action = ?";
        $bind_params[] = $action;
        $types .= "s";
    }
    if (!empty($user)) {
        $where .= " AND al.user_id = ?";
        $bind_params[] = (int)$user;
        $types .= "i";
    }
    
    $stmt = $conn->prepare("
        SELECT al.*, u.fullname as user_name, u.role as user_role
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        {$where}
        ORDER BY al.created_at DESC
        LIMIT 500
    ");
    $stmt->bind_param($types, ...$bind_params);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Summary
    $stmt = $conn->prepare("
        SELECT al.module, al.action, COUNT(*) as count
        FROM activity_logs al
        WHERE al.created_at BETWEEN ? AND ?
        GROUP BY al.module, al.action
        ORDER BY count DESC
    ");
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response([
        'report_type' => 'audit_logs',
        'date_from' => $date_from,
        'date_to' => $date_to,
        'total_entries' => count($logs),
        'summary' => $summary,
        'logs' => $logs,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Get analytics dashboard data
 */
function getAnalytics($conn) {
    // Current period
    $today = date('Y-m-d');
    $month_start = date('Y-m-01');
    $year_start = date('Y-01-01');
    
    // Today's stats
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM appointments WHERE appointment_date = ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $today_stats = $stmt->get_result()->fetch_assoc();
    
    // Monthly stats
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
               SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show
        FROM appointments WHERE appointment_date >= ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("s", $month_start);
    $stmt->execute();
    $monthly_stats = $stmt->get_result()->fetch_assoc();
    
    // Yearly stats
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM appointments WHERE appointment_date >= ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("s", $year_start);
    $stmt->execute();
    $yearly_stats = $stmt->get_result()->fetch_assoc();
    
    // Revenue
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(s.price), 0) as revenue
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date >= ? AND a.status = 'completed' AND a.deleted_at IS NULL
    ");
    $stmt->bind_param("s", $month_start);
    $stmt->execute();
    $monthly_revenue = $stmt->get_result()->fetch_assoc()['revenue'];
    
    // Peak hours
    $stmt = $conn->prepare("
        SELECT HOUR(appointment_time) as hour, COUNT(*) as count
        FROM appointments WHERE appointment_date >= ? AND deleted_at IS NULL
        GROUP BY HOUR(appointment_time)
        ORDER BY count DESC
        LIMIT 5
    ");
    $stmt->bind_param("s", $month_start);
    $stmt->execute();
    $peak_hours = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Top clients
    $stmt = $conn->prepare("
        SELECT u.fullname, COUNT(a.id) as appointments
        FROM appointments a
        JOIN users u ON a.user_id = u.id
        WHERE a.appointment_date >= ? AND a.deleted_at IS NULL
        GROUP BY a.user_id
        ORDER BY appointments DESC
        LIMIT 10
    ");
    $stmt->bind_param("s", $year_start);
    $stmt->execute();
    $top_clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    json_response([
        'today' => $today_stats,
        'monthly' => $monthly_stats,
        'yearly' => $yearly_stats,
        'monthly_revenue' => $monthly_revenue,
        'peak_hours' => $peak_hours,
        'top_clients' => $top_clients,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate and save a report
 */
function generateReport($conn, $user_id, $input) {
    $type = $input['type'] ?? '';
    $params = $input['params'] ?? [];
    
    $valid_types = ['daily', 'weekly', 'monthly', 'annual', 'staff_performance', 'service_utilization', 'cancellation', 'no_show', 'client_activity', 'audit_logs'];
    
    if (!in_array($type, $valid_types)) {
        json_response(['error' => 'Invalid report type'], 422);
    }
    
    // Save report record
    $stmt = $conn->prepare("INSERT INTO reports (name, type, parameters, generated_by, is_ready) VALUES (?, ?, ?, ?, 1)");
    $name = ucfirst($type) . " Report - " . date('Y-m-d H:i');
    $params_json = json_encode($params);
    $stmt->bind_param("sssi", $name, $type, $params_json, $user_id);
    $stmt->execute();
    $report_id = $stmt->insert_id;
    
    json_response([
        'message' => 'Report generated',
        'report_id' => $report_id,
        'name' => $name
    ]);
}

/**
 * Export report as PDF
 */
function exportPDF($conn, $input) {
    $report_id = $input['report_id'] ?? null;
    $type = $input['type'] ?? 'daily';
    $params = $input['params'] ?? [];
    
    // Generate report data
    $data = null;
    switch ($type) {
        case 'daily':
            $data = generateDailyReport($conn, $params['date'] ?? date('Y-m-d'));
            break;
        case 'monthly':
            $data = generateMonthlyReport($conn, $params['year'] ?? date('Y'), $params['month'] ?? date('m'));
            break;
        default:
            json_response(['error' => 'PDF export not available for this report type'], 400);
    }
    
    // For now, return JSON that can be converted to PDF client-side
    json_response([
        'message' => 'PDF export data ready',
        'report_type' => $type,
        'data' => $data,
        'export_format' => 'pdf'
    ]);
}

/**
 * Export report as Excel (CSV)
 */
function exportExcel($conn, $input) {
    $type = $input['type'] ?? 'daily';
    $params = $input['params'] ?? [];
    
    // Generate CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    switch ($type) {
        case 'daily':
            $data = generateDailyReport($conn, $params['date'] ?? date('Y-m-d'));
            fputcsv($output, ['Appointment Report - ' . ($params['date'] ?? date('Y-m-d'))]);
            fputcsv($output, ['ID', 'Pet', 'Client', 'Service', 'Staff', 'Time', 'Status']);
            foreach ($data['appointments'] as $appt) {
                fputcsv($output, [
                    $appt['id'],
                    $appt['pet_name'],
                    $appt['client_name'],
                    $appt['service_name'] ?? $appt['service_type'],
                    $appt['staff_name'],
                    $appt['appointment_time'],
                    $appt['status']
                ]);
            }
            break;
            
        case 'monthly':
            $data = generateMonthlyReport($conn, $params['year'] ?? date('Y'), $params['month'] ?? date('m'));
            fputcsv($output, ['Monthly Report - ' . ($data['month_name'] ?? '') . ' ' . ($params['year'] ?? date('Y'))]);
            fputcsv($output, ['Date', 'Total', 'Completed', 'Cancelled', 'No Show']);
            foreach ($data['daily_breakdown'] as $day) {
                fputcsv($output, [$day['date'], $day['total'], $day['completed'], $day['cancelled'], $day['no_show']]);
            }
            break;
            
        default:
            fputcsv($output, ['Export not available for this report type']);
    }
    
    fclose($output);
    exit();
}

/**
 * Delete a report
 */
function deleteReport($conn, $user_id, $input) {
    $id = $input['id'] ?? null;
    if (!$id) json_response(['error' => 'Report ID required'], 422);
    
    $stmt = $conn->prepare("DELETE FROM reports WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    json_response(['message' => 'Report deleted']);
}