<?php
/**
 * Security Middleware
 * CSRF Protection, Input Sanitization, XSS Prevention
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validate_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require valid CSRF token or die
 */
function require_csrf_token() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validate_csrf_token($token)) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid CSRF token']));
    }
}

/**
 * Sanitize input string
 */
function sanitize_input($data, $type = 'string') {
    if (is_array($data)) {
        return array_map(function($item) use ($type) {
            return sanitize_input($item, $type);
        }, $data);
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    
    switch ($type) {
        case 'email':
            return filter_var($data, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($data, FILTER_SANITIZE_URL);
        case 'int':
            return filter_var($data, FILTER_SANITIZE_NUMBER_INT);
        case 'float':
            return filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'html':
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        case 'sql':
            // For SQL, we rely on prepared statements, but strip dangerous chars
            return preg_replace('/[;\\\\\'"]/', '', $data);
        default:
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Escape output for HTML
 */
function e($data) {
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Philippine format)
 */
function validate_phone($phone) {
    return preg_match('/^(09|\+639)\d{9}$/', $phone);
}

/**
 * Validate date format
 */
function validate_date($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Validate time format
 */
function validate_time($time) {
    return preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $time);
}

/**
 * Rate limiting by IP
 */
function check_rate_limit($action, $max_attempts = 5, $window_minutes = 15) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = "rate_limit_{$action}_{$ip}";
    $window = $window_minutes * 60;
    
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_attempt' => time()];
    
    // Reset if window expired
    if (time() - $attempts['first_attempt'] > $window) {
        $attempts = ['count' => 0, 'first_attempt' => time()];
    }
    
    $attempts['count']++;
    $_SESSION[$key] = $attempts;
    
    if ($attempts['count'] > $max_attempts) {
        $retry_after = $window - (time() - $attempts['first_attempt']);
        http_response_code(429);
        header('Retry-After: ' . $retry_after);
        die(json_encode([
            'error' => 'Too many attempts. Please try again later.',
            'retry_after' => $retry_after
        ]));
    }
    
    return $attempts['count'];
}

/**
 * Set secure headers
 */
function set_secure_headers() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Content Security Policy
    $csp = "default-src 'self'; " .
           "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com 'unsafe-inline'; " .
           "style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com https://unpkg.com 'unsafe-inline'; " .
           "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
           "img-src 'self' data: blob:; " .
           "connect-src 'self'; " .
           "frame-ancestors 'none';";
    header("Content-Security-Policy: $csp");
}

/**
 * Log security event
 */
function log_security_event($conn, $user_id, $action, $details = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_role, action, module, description, metadata, ip_address, user_agent) VALUES (?, ?, ?, 'security', ?, ?, ?, ?)");
    $role = $_SESSION['user_role'] ?? 'guest';
    $metadata = json_encode(['details' => $details]);
    $stmt->bind_param("isssss", $user_id, $role, $action, $details, $metadata, $ip, $user_agent);
    $stmt->execute();
}

/**
 * Check if user has permission
 */
function has_permission($conn, $user_id, $module, $action = 'can_view') {
    $stmt = $conn->prepare("
        SELECT p.{$action} 
        FROM permissions p 
        JOIN users u ON u.role_id = p.role_id 
        WHERE u.id = ? AND p.module = ?
    ");
    $stmt->bind_param("is", $user_id, $module);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return (bool)$row[$action];
    }
    return false;
}

/**
 * Require authentication
 */
function require_auth() {
    if (!isset($_SESSION['user_id'])) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(401);
            die(json_encode(['error' => 'Unauthorized', 'redirect' => 'index.php']));
        }
        header("Location: index.php");
        exit();
    }
}

/**
 * Require specific role
 */
function require_role($roles) {
    require_auth();
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    $user_role = $_SESSION['user_role'] ?? '';
    if (!in_array($user_role, $roles)) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(403);
            die(json_encode(['error' => 'Forbidden: Insufficient permissions']));
        }
        header("Location: dashboard.php");
        exit();
    }
}

/**
 * JSON response helper
 */
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Error response helper
 */
function error_response($message, $status = 400, $errors = []) {
    $response = ['error' => $message];
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    json_response($response, $status);
}

/**
 * Success response helper
 */
function success_response($data = [], $message = 'Success') {
    json_response([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * Pagination helper
 */
function paginate($conn, $query, $params = [], $per_page = 20) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $per_page;
    
    // Count total
    $count_query = preg_replace('/SELECT.*?FROM/', 'SELECT COUNT(*) as total FROM', $query, 1);
    $count_query = preg_replace('/ORDER BY.*/', '', $count_query);
    $count_query = preg_replace('/LIMIT.*/', '', $count_query);
    
    $stmt = $conn->prepare($count_query);
    if (!empty($params)) {
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    
    // Get page data
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    
    $stmt = $conn->prepare($query);
    $types = str_repeat('s', count($params) - 2) . 'ii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    return [
        'data' => $data,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => (int)$total,
            'total_pages' => ceil($total / $per_page),
            'has_next' => $page < ceil($total / $per_page),
            'has_prev' => $page > 1
        ]
    ];
}