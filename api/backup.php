<?php
/**
 * Backup & Recovery API
 * Database backup, restore, and management
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

require_auth();
require_role('admin');

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$method = $_SERVER['REQUEST_METHOD'];

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $user_id);
            break;
        case 'POST':
            require_csrf_token();
            handlePost($conn, $user_id, $input);
            break;
        default:
            json_response(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Backup API Error: " . $e->getMessage());
    json_response(['error' => 'Internal server error: ' . $e->getMessage()], 500);
}

function handleGet($conn, $user_id) {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            listBackups($conn);
            break;
        case 'info':
            getBackupInfo($conn);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

function handlePost($conn, $user_id, $input) {
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create':
            createBackup($conn, $user_id);
            break;
        case 'restore':
            restoreBackup($conn, $user_id, $input);
            break;
        case 'delete':
            deleteBackup($conn, $user_id, $input);
            break;
        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

/**
 * List all backups
 */
function listBackups($conn) {
    $stmt = $conn->query("
        SELECT bh.*, u.fullname as created_by_name, r.fullname as restored_by_name
        FROM backup_history bh
        LEFT JOIN users u ON bh.created_by = u.id
        LEFT JOIN users r ON bh.restored_by = r.id
        ORDER BY bh.created_at DESC
    ");
    $backups = $stmt->fetch_all(MYSQLI_ASSOC);
    
    json_response(['backups' => $backups]);
}

/**
 * Get backup storage info
 */
function getBackupInfo($conn) {
    // Get database size
    $stmt = $conn->query("
        SELECT table_schema as 'database_name',
               ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb,
               COUNT(*) as table_count
        FROM information_schema.tables
        WHERE table_schema = 'vet_system'
        GROUP BY table_schema
    ");
    $db_info = $stmt->fetch_assoc();
    
    // Count backups
    $stmt = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(file_size), 0) as total_size FROM backup_history");
    $backup_stats = $stmt->fetch_assoc();
    
    // Latest backup
    $stmt = $conn->query("SELECT created_at FROM backup_history WHERE status = 'success' ORDER BY created_at DESC LIMIT 1");
    $latest = $stmt->fetch_assoc();
    
    json_response([
        'database' => $db_info ?: ['database_name' => 'vet_system', 'size_mb' => 0, 'table_count' => 0],
        'backups' => [
            'count' => (int)($backup_stats['count'] ?? 0),
            'total_size' => (float)($backup_stats['total_size'] ?? 0)
        ],
        'latest_backup' => $latest ? $latest['created_at'] : null,
        'backup_directory' => realpath(__DIR__ . '/../backups') ?: 'backups/'
    ]);
}

/**
 * Create database backup
 */
function createBackup($conn, $user_id) {
    $backup_dir = __DIR__ . '/../backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "vet_system_backup_{$timestamp}.sql";
    $filepath = "{$backup_dir}/{$filename}";
    
    // Create backup record
    $stmt = $conn->prepare("INSERT INTO backup_history (file_name, file_path, type, status, created_by) VALUES (?, ?, 'manual', 'in_progress', ?)");
    $stmt->bind_param("ssi", $filename, $filepath, $user_id);
    $stmt->execute();
    $backup_id = $stmt->insert_id;
    
    try {
        // Get database credentials
        $db_host = 'localhost';
        $db_user = 'root';
        $db_pass = '';
        $db_name = 'vet_system';
        
        // Use mysqldump
        $command = "\"C:\\xampp\\mysql\\bin\\mysqldump\" --host={$db_host} --user={$db_user} --password={$db_pass} {$db_name} > \"{$filepath}\" 2>&1";
        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);
        
        if ($return_var === 0 && file_exists($filepath)) {
            $file_size = filesize($filepath);
            
            // Update backup record
            $stmt = $conn->prepare("UPDATE backup_history SET status = 'success', file_size = ? WHERE id = ?");
            $stmt->bind_param("ii", $file_size, $backup_id);
            $stmt->execute();
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_role, action, module, description, ip_address, user_agent) VALUES (?, ?, 'backup_created', 'backup', ?, ?, ?)");
            $desc = "Created database backup: {$filename} (" . formatFileSize($file_size) . ")";
            $stmt->bind_param("issss", $user_id, $_SESSION['user_role'], $desc, $ip, $ua);
            $stmt->execute();
            
            json_response([
                'message' => 'Backup created successfully',
                'backup_id' => $backup_id,
                'filename' => $filename,
                'file_size' => $file_size,
                'file_size_formatted' => formatFileSize($file_size),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            // Update backup record as failed
            $error_msg = implode("\n", $output);
            $stmt = $conn->prepare("UPDATE backup_history SET status = 'failed', notes = ? WHERE id = ?");
            $stmt->bind_param("si", $error_msg, $backup_id);
            $stmt->execute();
            
            json_response(['error' => 'Backup failed: ' . $error_msg], 500);
        }
    } catch (Exception $e) {
        $stmt = $conn->prepare("UPDATE backup_history SET status = 'failed', notes = ? WHERE id = ?");
        $err = $e->getMessage();
        $stmt->bind_param("si", $err, $backup_id);
        $stmt->execute();
        
        json_response(['error' => 'Backup failed: ' . $e->getMessage()], 500);
    }
}

/**
 * Restore database from backup
 */
function restoreBackup($conn, $user_id, $input) {
    $backup_id = $input['backup_id'] ?? null;
    
    if (!$backup_id) {
        json_response(['error' => 'Backup ID required'], 422);
    }
    
    // Get backup info
    $stmt = $conn->prepare("SELECT * FROM backup_history WHERE id = ? AND status = 'success'");
    $stmt->bind_param("i", $backup_id);
    $stmt->execute();
    $backup = $stmt->get_result()->fetch_assoc();
    
    if (!$backup) {
        json_response(['error' => 'Backup not found or not ready'], 404);
    }
    
    $filepath = $backup['file_path'];
    if (!file_exists($filepath)) {
        json_response(['error' => 'Backup file not found on disk'], 404);
    }
    
    // Confirm restore
    if (empty($input['confirmed'])) {
        json_response([
            'confirmation_required' => true,
            'message' => 'Restoring will overwrite all current data. Are you sure?',
            'backup' => $backup
        ], 200);
        return;
    }
    
    try {
        $db_host = 'localhost';
        $db_user = 'root';
        $db_pass = '';
        $db_name = 'vet_system';
        
        // Use mysql to restore
        $command = "\"C:\\xampp\\mysql\\bin\\mysql\" --host={$db_host} --user={$db_user} --password={$db_pass} {$db_name} < \"{$filepath}\" 2>&1";
        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);
        
        if ($return_var === 0) {
            // Update restore info
            $stmt = $conn->prepare("UPDATE backup_history SET restored_at = NOW(), restored_by = ? WHERE id = ?");
            $stmt->bind_param("ii", $user_id, $backup_id);
            $stmt->execute();
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_role, action, module, description, ip_address, user_agent) VALUES (?, ?, 'database_restored', 'backup', ?, ?, ?)");
            $desc = "Restored database from backup: {$backup['file_name']}";
            $stmt->bind_param("issss", $user_id, $_SESSION['user_role'], $desc, $ip, $ua);
            $stmt->execute();
            
            json_response(['message' => 'Database restored successfully', 'backup_id' => $backup_id]);
        } else {
            $error_msg = implode("\n", $output);
            json_response(['error' => 'Restore failed: ' . $error_msg], 500);
        }
    } catch (Exception $e) {
        json_response(['error' => 'Restore failed: ' . $e->getMessage()], 500);
    }
}

/**
 * Delete backup
 */
function deleteBackup($conn, $user_id, $input) {
    $backup_id = $input['backup_id'] ?? null;
    if (!$backup_id) json_response(['error' => 'Backup ID required'], 422);
    
    $stmt = $conn->prepare("SELECT * FROM backup_history WHERE id = ?");
    $stmt->bind_param("i", $backup_id);
    $stmt->execute();
    $backup = $stmt->get_result()->fetch_assoc();
    
    if (!$backup) json_response(['error' => 'Backup not found'], 404);
    
    // Delete file
    if (file_exists($backup['file_path'])) {
        unlink($backup['file_path']);
    }
    
    // Delete record
    $stmt = $conn->prepare("DELETE FROM backup_history WHERE id = ?");
    $stmt->bind_param("i", $backup_id);
    $stmt->execute();
    
    // Log
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, user_role, action, module, description, ip_address, user_agent) VALUES (?, ?, 'backup_deleted', 'backup', ?, ?, ?)");
    $desc = "Deleted backup: {$backup['file_name']}";
    $stmt->bind_param("issss", $user_id, $_SESSION['user_role'], $desc, $ip, $ua);
    $stmt->execute();
    
    json_response(['message' => 'Backup deleted']);
}

/**
 * Helper: Format file size
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}