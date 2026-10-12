<?php
session_start();
include("dbconnection.php");

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])){
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'fetch';

if ($action === 'fetch') {
    // Count unread
    $c = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE user_id=$user_id AND is_read=0")->fetch_assoc();
    
    // Fetch latest 10
    $q = $conn->query("SELECT * FROM notifications WHERE user_id=$user_id ORDER BY created_at DESC LIMIT 10");
    $notifs = [];
    while($row = $q->fetch_assoc()) {
        $notifs[] = $row;
    }
    
    echo json_encode([
        "unread_count" => $c['unread'],
        "notifications" => $notifs
    ]);
} elseif ($action === 'mark_one') {
    // Mark a single notification as read (when the user opens it)
    $nid = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
    $st->bind_param("ii", $nid, $user_id);
    $st->execute();
    echo json_encode(["status" => "success"]);
} elseif ($action === 'mark_read') {
    $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$user_id AND is_read=0");
    echo json_encode(["status" => "success"]);
}
?>
