<?php
/**
 * Notifications API
 * In-app notification management
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

require_auth();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'client';
$method = $_SERVER['REQUEST_METHOD'];

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $user_id, $user_role);
            break;
        case 'POST':
            require_csrf_token();
            handlePost($conn, $user_id, $input);
            break;
        case 'PUT':
            require_csrf_token();
            handlePut($conn, $user_id, $input);
            break;
        default:
            json_response(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    json_response(['error' => 'Internal server error'], 500);
}

function handleGet($conn, $user_id, $user_role) {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            listNotifications($conn, $user_id);
            break;
        case 'unread_count':
            getUnreadCount($conn, $user_id);
            break;
        case 'templates':
            getTemplates($conn);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

function handlePost($conn, $user_id, $input) {
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'mark_read':
            markAsRead($conn, $user_id, $input);
            break;
        case 'mark_all_read':
            markAllAsRead($conn, $user_id);
            break;
        case 'send':
            sendNotification($conn, $user_id, $input);
            break;
        case 'delete':
            deleteNotification($conn, $user_id, $input);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

function handlePut($conn, $user_id, $input) {
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update_preferences':
            updatePreferences($conn, $user_id, $input);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

/**
 * List notifications with pagination
 */
function listNotifications($conn, $user_id) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(50, max(5, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $per_page;
    $type = $_GET['type'] ?? '';
    
    $where = "WHERE n.user_id = ?";
    $params = [$user_id];
    $types = "i";
    
    if (!empty($type)) {
        $where .= " AND n.type = ?";
        $params[] = $type;
        $types .= "s";
    }
    
    // Count
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications n {$where}");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    
    // Get data
    $stmt = $conn->prepare("
        SELECT n.*, 
               CASE WHEN n.is_read = 0 THEN 'unread' ELSE 'read' END as read_status
        FROM notifications n
        {$where}
        ORDER BY n.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Format time
    foreach ($notifications as &$notif) {
        $notif['time_ago'] = timeAgo($notif['created_at']);
        $notif['created_at_fmt'] = date('M j, Y g:i A', strtotime($notif['created_at']));
    }
    
    json_response([
        'notifications' => $notifications,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => ceil($total / $per_page)
        ]
    ]);
}

/**
 * Get unread count
 */
function getUnreadCount($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['count'];
    
    json_response(['unread_count' => $count]);
}

/**
 * Mark notification as read
 */
function markAsRead($conn, $user_id, $input) {
    $id = $input['id'] ?? null;
    if (!$id) {
        json_response(['error' => 'Notification ID required'], 422);
    }
    
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    
    json_response(['message' => 'Marked as read']);
}

/**
 * Mark all notifications as read
 */
function markAllAsRead($conn, $user_id) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    json_response(['message' => 'All notifications marked as read', 'affected' => $stmt->affected_rows]);
}

/**
 * Send notification (admin only)
 */
function sendNotification($conn, $user_id, $input) {
    $target_user = $input['target_user_id'] ?? null;
    $title = sanitize_input($input['title'] ?? '');
    $message = sanitize_input($input['message'] ?? '');
    $type = sanitize_input($input['type'] ?? 'info');
    $link = sanitize_input($input['link'] ?? '');
    
    if (empty($message)) {
        json_response(['error' => 'Message is required'], 422);
    }
    
    // If no target, send to all users
    if ($target_user) {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, sent_via) VALUES (?, ?, ?, ?, ?, '[\"in_app\"]')");
        $stmt->bind_param("issss", $target_user, $type, $title, $message, $link);
        $stmt->execute();
        $count = 1;
    } else {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, sent_via) SELECT id, ?, ?, ?, ?, '[\"in_app\"]' FROM users WHERE is_active = 1");
        $stmt->bind_param("ssss", $type, $title, $message, $link);
        $stmt->execute();
        $count = $stmt->affected_rows;
    }
    
    json_response(['message' => "Notification sent to {$count} user(s)", 'count' => $count]);
}

/**
 * Delete notification
 */
function deleteNotification($conn, $user_id, $input) {
    $id = $input['id'] ?? null;
    if (!$id) {
        json_response(['error' => 'Notification ID required'], 422);
    }
    
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    
    json_response(['message' => 'Notification deleted']);
}

/**
 * Update notification preferences
 */
function updatePreferences($conn, $user_id, $input) {
    $prefs = $input['preferences'] ?? [];
    $prefs_json = json_encode($prefs);
    
    $stmt = $conn->prepare("UPDATE users SET notification_preferences = ? WHERE id = ?");
    $stmt->bind_param("si", $prefs_json, $user_id);
    $stmt->execute();
    
    json_response(['message' => 'Preferences updated', 'preferences' => $prefs]);
}

/**
 * Get notification templates
 */
function getTemplates($conn) {
    $stmt = $conn->query("SELECT * FROM notification_templates WHERE is_active = 1 ORDER BY event");
    json_response(['templates' => $stmt->fetch_all(MYSQLI_ASSOC)]);
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
    return date('M j', $time);
}