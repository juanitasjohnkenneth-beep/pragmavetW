<?php
session_start();
include("dbconnection.php");

if(isset($_GET['token'])) {
    $token = $_GET['token'];
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE verification_token=? AND is_verified=0 LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        
        // Mark account as verified and clear the token to prevent reuse
        $update = $conn->prepare("UPDATE users SET is_verified=1, verification_token=NULL WHERE id=?");
        $update->bind_param("i", $row['id']);
        
        if($update->execute()) {
            $_SESSION['message'] = "Your email address has been securely verified! You may now log in.";
            $_SESSION['msg_type'] = "success";
        }
    } else {
        $_SESSION['message'] = "This verification link is invalid, expired, or the account is already verified.";
        $_SESSION['msg_type'] = "danger";
    }
} else {
    header("Location: index.php");
    exit();
}

// Redirect back to the landing/login page to show the success message
header("Location: index.php");
exit();
?>
