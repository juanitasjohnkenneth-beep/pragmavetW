<?php
/**
 * Activity Logs API
 * View, filter, and export system activity logs
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../dbconnection.php';
require_once '../includes/security.php';

require_auth();
require_role(['admin', 'doctor', 'staff']);

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($conn);
            break;
        default:
            json_response(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Activity Logs API Error: " . $e->getMessage());
    json_response(['error' => 'Internal server error'], 500);
}

function handleGet($conn) {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            listLogs($conn);
            break;
        case 'modules':
            getModules($conn);
            break;
        case 'actions':
            getActions($conn);
            break;
        case 'summary':
            getSummary($conn);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

/**
 * List activity logs with filtering and pagination
 */
function listLogs($conn) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $per_page;
    
    $where = "WHERE 1=1";
    $params = [];
    $types = "";
    
    // Filters
    $filters = [
        'module' => 'al.module =',
        'action' => 'al.action =',
        'user_id' => 'al.user_id =',
        'date_from' => 'DATE(al.created_at) >=',
        'date_to' => 'DATE(al.created_at) <=',
        'search' => '(al.description LIKE OR u.fullname LIKE)'
    ];
    
    foreach ($filters as $key => $condition) {
        if (!empty($_GET[$key])) {
            $val = sanitize_input($_GET[$key]);
            if ($key === 'search') {
                $where .= " AND (al.description LIKE ? OR u.fullname LIKE ?)";
                $search_term = "%{$val}%";
                $params[] = $search_term;
                $params[] = $search_term;
                $types .= "ss";
            } elseif (in_array($key, ['user_id'])) {
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
    
    // Count total
    $count_sql = "SELECT COUNT(*) as total FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id {$where}";
    $stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    
    // Get data
    $sql = "SELECT al.*, u.fullname as user_name, u.role as user_role_name
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            {$where}
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?";
    
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Format
    foreach ($logs as &$log) {
        $log['time_ago'] = timeAgo($log['created_at']);
        $log['metadata'] = $log['metadata'] ? json_decode($log['metadata'], true) : null;
    }
    
    json_response([
        'logs' => $logs,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => ceil($total / $per_page)
        ],
        'filters' => [
            'module' => $_GET['module'] ?? null,
            'action' => $_GET['action'] ?? null,
            'user_id' => $_GET['user_id'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null
        ]
    ]);
}

/**
 * Get distinct modules
 */
function getModules($conn) {
    $stmt = $conn->query("SELECT DISTINCT module FROM activity_logs ORDER BY module");
    $modules = $stmt->fetch_all(MYSQLI_ASSOC);
    json_response(['modules' => array_column($modules, 'module')]);
}

/**
 * Get distinct actions
 */
function getActions($conn) {
    $module = $_GET['module'] ?? '';
    $where = $module ? "WHERE module = ?" : "";
    $stmt = $conn->prepare("SELECT DISTINCT action FROM activity_logs {$where} ORDER BY action");
    if ($module) {
        $stmt->bind_param("s", $module);
    }
    $stmt->execute();
    $actions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    json_response(['actions' => array_column($actions, 'action')]);
}

/**
 * Get activity summary
 */
function getSummary($conn) {
    // Today's activity
    $stmt = $conn->query("
        SELECT module, COUNT(*) as count
        FROM activity_logs
        WHERE DATE(created_at) = CURDATE()
        GROUP BY module
        ORDER BY count DESC
    ");
    $today = $stmt->fetch_all(MYSQLI_ASSOC);
    
    // Activity by hour
    $stmt = $conn->query("
        SELECT HOUR(created_at) as hour, COUNT(*) as count
        FROM activity_logs
        WHERE DATE(created_at) = CURDATE()
        GROUP BY HOUR(created_at)
        ORDER BY hour
    ");
    $hourly = $stmt->fetch_all(MYSQLI_ASSOC);
    
    // Top users
    $stmt = $conn->query("
        SELECT u.fullname, COUNT(*) as count
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        WHERE DATE(al.created_at) = CURDATE()
        GROUP BY al.user_id
        ORDER BY count DESC
        LIMIT 10
    ");
    $top_users = $stmt->fetch_all(MYSQLI_ASSOC);
    
    // Total counts
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week,
            SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as this_month
        FROM activity_logs
    ");
    $counts = $stmt->fetch_assoc();
    
    json_response([
        'counts' => $counts,
        'today_by_module' => $today,
        'hourly_activity' => $hourly,
        'top_users' => $top_users
    ]);
}

/**
 * Helper: time ago
 */
function timeAgo($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}