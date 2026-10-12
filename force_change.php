<?php
session_start();
include("dbconnection.php");

// Only for Users that are logged in
if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user'];

// Ensure they actually require a password change
$stmt = $conn->prepare("SELECT force_password_change FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

if(!$row || $row['force_password_change'] != 1) {
    // If they bypass directly here but dont need changing
    header("Location: dashboard.php");
    exit();
}

// Processing the Forced Change
if(isset($_POST['change_password'])) {
    $p1 = $_POST['new_pwd'];
    $p2 = $_POST['confirm_pwd'];

    if (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}$/', $p1)) {
        $error = "Password must be at least 8 chars long with 1 uppercase, 1 lowercase, 1 number, and 1 special character.";
    } elseif($p1 !== $p2) {
        $error = "The cryptographic verification passwords do not match!";
    } else {
        $hashed = password_hash($p1, PASSWORD_DEFAULT);
        
        $update = $conn->prepare("UPDATE users SET password=?, force_password_change=0 WHERE id=?");
        $update->bind_param("si", $hashed, $user_id);
        
        if($update->execute()) {
            $_SESSION['message'] = "Security Profile updated! Welcome to PragmaVet.";
            $_SESSION['msg_type'] = "success";
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "A fatal database error interrupted the cycle.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Action Required | PragmaVet Secure</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root { --primary: #2563eb; --secondary: #3b82f6; --dark: #1e40af; --light-bg: #E6EEF8; }
body { background: var(--light-bg); font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; overflow: hidden; }

.auth-card {
    background: white;
    border-radius: 20px;
    padding: 50px 40px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    position: relative;
    z-index: 2;
    overflow: hidden;
}

/* Background blob */
.blobs { position: absolute; width: 500px; height: 500px; background: linear-gradient(135deg, rgba(37, 99, 235,0.1) 0%, rgba(74, 127, 196,0.05) 100%); border-radius: 50%; top: -250px; right: -250px; z-index: 1; filter: blur(40px); }

.form-control { border-radius: 12px; padding: 14px 20px; border: 1px solid #dbe5ec; font-size: 15px; background: #fafcff; }
.form-control:focus { outline: none; border-color: var(--secondary); box-shadow: 0 0 0 4px rgba(74, 127, 196,0.1); }
</style>
</head>
<body>
<div class="blobs"></div>

<div class="auth-card">
    <div class="text-center mb-4 pb-2">
        <i class="fa fa-lock fa-3x text-primary mb-3"></i>
        <h3 class="fw-bold" style="color: var(--dark); letter-spacing: -0.5px;">Update Password</h3>
        <p class="text-muted small">For your protection, you must immediately change your clinic-assigned password before accessing your records.</p>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 small fw-bold text-center" style="border-radius: 10px; background: rgba(250,82,82,0.1); color: #c92a2a;">
            <i class="fa fa-exclamation-triangle me-1"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label text-muted small fw-bold">NEW PASSWORD</label>
            <input type="password" name="new_pwd" class="form-control" placeholder="8+ chars, 1 uppercase, 1 number, 1 special sign" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}" title="Must contain at least 8 chars, one uppercase, one lowercase, one number and one special character" required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label text-muted small fw-bold">CONFIRM IDENTITY SEQUENCE</label>
            <input type="password" name="confirm_pwd" class="form-control" placeholder="Verify sequence identically" required>
        </div>
        <button type="submit" name="change_password" class="btn btn-primary w-100 fw-bold shadow-sm rounded-pill" style="padding: 14px;">
            <i class="fa fa-shield-alt me-2"></i> Encrypt & Continue
        </button>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
