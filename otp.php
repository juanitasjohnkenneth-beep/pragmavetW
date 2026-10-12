<?php
require_once __DIR__ . '/includes/mail_config.php';
session_start();
include("dbconnection.php");

// Import PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

if(!isset($_GET['email'])){
    header("Location: index.php");
    exit();
}
$email = $_GET['email'];
// Only the account that just entered its correct password (index.php) may use this page
if (empty($_SESSION['otp_email']) || strcasecmp($_SESSION['otp_email'], $email) !== 0) {
    $_SESSION['message'] = "Please log in to verify your account.";
    $_SESSION['msg_type'] = "warning";
    header("Location: index.php");
    exit();
}
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS otp_attempts TINYINT NOT NULL DEFAULT 0");

if(isset($_POST['resend_otp'])) {
    // At most one new code per minute (a code is valid for 15 minutes)
    $recent = $conn->prepare("SELECT 1 FROM users WHERE email=? AND is_verified=0 AND token_expires_at > NOW() + INTERVAL 14 MINUTE");
    $recent->bind_param("s", $email);
    $recent->execute();
    $too_soon = $recent->get_result()->num_rows > 0;
    // Generate new OTP
    $otp_code = (string)random_int(100000, 999999);
    $update = $conn->prepare("UPDATE users SET verification_token=?, token_expires_at=NOW() + INTERVAL 15 MINUTE, otp_attempts=0 WHERE email=? AND is_verified=0");
    $update->bind_param("ss", $otp_code, $email);
    if ($too_soon) {
        $_SESSION['message'] = "A code was sent less than a minute ago. Please check your inbox, or wait a minute and try again.";
        $_SESSION['msg_type'] = "warning";
    } elseif($update->execute() && $update->affected_rows > 0) {
        
        // Dispatch new 2FA Code via SMTP
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = PV_SMTP_USER; 
            $mail->Password   = PV_SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom(PV_SMTP_USER, 'PragmaVet Security');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'Your New 2FA Security Code';
            
            $mail->Body = "
                <div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 30px; text-align: center; border: 1px solid #e1e8ed; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); background: #ffffff;'>
                    <h2 style='color: #2563eb; margin-bottom: 5px;'>PragmaVet Accounts</h2>
                    <h3 style='color: #333; font-weight: 500;'>Security Alert for: $email</h3>
                    <p style='color: #666; font-size: 15px; line-height: 1.6;'>You requested a new Two-Factor Authentication code. Please enter the following 6-digit code to verify your identity:</p>
                    
                    <div style='background: #F4F9FB; padding: 25px; margin: 30px 0; border-radius: 10px; border-left: 5px solid #3b82f6;'>
                        <h1 style='margin: 0; font-size: 38px; font-weight: bold; letter-spacing: 8px; color: #2563eb;'>$otp_code</h1>
                    </div>
                    
                    <p style='color: #888; font-size: 13px;'>This code will expire shortly. Do not share your 6-digit code with anyone.</p>
                </div>
            ";
            $mail->send();
            
            $_SESSION['message'] = "A new security code has been securely sent to your inbox.";
            $_SESSION['msg_type'] = "info";
        } catch (Exception $e) {
            $_SESSION['message'] = "Failed to resend the code due to a mailer error: " . $mail->ErrorInfo;
            $_SESSION['msg_type'] = "danger";
        }
    }
}

if(isset($_POST['verify_otp'])) {
    $otp_input = $_POST['otp'];
    
    // Look up the pending code, then check it: must exist, not be expired, and at most 5 wrong tries
    $stmt = $conn->prepare("SELECT id, fullname, role, verification_token, (token_expires_at IS NULL OR token_expires_at < NOW()) AS expired FROM users WHERE email=? AND is_verified=0");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_assoc();
    $code_ok = $pending && (string)$pending['verification_token'] !== '' && !$pending['expired']
               && hash_equals((string)$pending['verification_token'], trim((string)$otp_input));
    if ($pending && !$code_ok && (string)$pending['verification_token'] !== '' && !$pending['expired']) {
        $conn->query("UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = " . (int)$pending['id']);
        $tries = (int)($conn->query("SELECT otp_attempts FROM users WHERE id = " . (int)$pending['id'])->fetch_assoc()['otp_attempts'] ?? 0);
        if ($tries >= 5) $conn->query("UPDATE users SET verification_token = NULL, token_expires_at = NULL, otp_attempts = 0 WHERE id = " . (int)$pending['id']);
    }

    if($code_ok) {
        $row = $pending;
        
        // Correct 2FA! Mark as verified
        $update = $conn->prepare("UPDATE users SET is_verified=1, verification_token=NULL, token_expires_at=NULL, otp_attempts=0 WHERE id=?");
        unset($_SESSION['otp_email']);
        $update->bind_param("i", $row['id']);
        $update->execute();
        
        $_SESSION['message'] = "Authentication successful! Your identity has been verified. You may now log in securely.";
        $_SESSION['msg_type'] = "success";
        
        // Verify Forced Password Change Protocol
        $uid = (int)$row['id'];
        $stmt2 = $conn->prepare("SELECT force_password_change FROM users WHERE id=?");
        $stmt2->bind_param("i", $uid);
        $stmt2->execute();
        $row2 = $stmt2->get_result()->fetch_assoc();
        
        if($row2 && $row2['force_password_change'] == 1) {
            header("Location: force_change.php");
            exit();
        }

        // Verified, not logged in yet: send them back to log in
        if (empty($_SESSION['user_id'])) {
            header("Location: index.php");
        } elseif(($_SESSION['role'] ?? '') === 'admin'){
            header("Location: admin.php");
        } else if (($_SESSION['role'] ?? '') === 'doctor') {
            header("Location: doctor.php");
        } else if (in_array($_SESSION['role'] ?? '', ['clinic_owner', 'owner'], true)) {
            header("Location: owner_dashboard.php");
        } else if (($_SESSION['role'] ?? '') === 'staff') {
            header("Location: staff_dashboard.php");
        } else {
            header("Location: dashboard.php");
        }
        exit();
    } else {
        $error = "The security code you entered is invalid or expired.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>2FA Verification | PragmaVet</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">
<link href="assets/global-style.css" rel="stylesheet">
<style>
body { background: #F4F9FB; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; }
.otp-container { max-width: 450px; width: 100%; background: white; padding: 50px 40px; border-radius: 25px; box-shadow: 0 15px 40px rgba(0,0,0,0.05); text-align: center; border: 1px solid rgba(0,0,0,0.02); }
.form-control { border-radius: 12px; padding: 15px; font-size: 28px; text-align: center; letter-spacing: 10px; font-weight: 800; border: 2px solid #edf2f6; background: #fafcff; }
.form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(74, 127, 196,0.1); background: white; }
.btn-primary { background: #2563eb; border: none; font-weight: 700; transition: 0.3s; }
.btn-primary:hover { background: #1e40af; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
</style>
</head>
<body>

<div class="otp-container">
    
    <div class="mb-4">
        <div class="bg-primary text-white d-inline-block rounded-circle" style="width: 80px; height: 80px; line-height: 80px; font-size: 35px; background: linear-gradient(135deg, #2563eb, #3b82f6) !important;">
            <i class="fa fa-shield-alt"></i>
        </div>
    </div>
    
    <h3 class="fw-bold text-dark mb-2">Two-Factor Authentication</h3>
    <p class="text-muted mb-4 small" style="line-height: 1.6;">
        We have dispatched a secure 6-digit OTP code to <br>
        <strong class="text-dark"><?php echo htmlspecialchars($email); ?></strong>
    </p>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger shadow-sm border-0" style="border-radius: 12px;"><i class="fa fa-times-circle me-1"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    <?php if(isset($_SESSION['message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> shadow-sm border-0" style="border-radius: 12px;"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-4">
            <input type="text" name="otp" class="form-control form-control-lg" placeholder="------" maxlength="6" autocomplete="off" required autofocus>
        </div>
        <button type="submit" name="verify_otp" class="btn btn-primary w-100 py-3 rounded-pill fs-5">
            Verify Identity
        </button>
    </form>
    
    <div class="mt-4 pt-3 border-top">
        <p class="text-muted small mb-1">Didn't receive the code?</p>
        <form method="POST" class="d-inline">
            <button type="submit" name="resend_otp" class="btn btn-link text-decoration-none p-0 fw-bold" style="color: #3b82f6;">Resend Security Code</button>
        </form>
    </div>
</div>

</body>
</html>
