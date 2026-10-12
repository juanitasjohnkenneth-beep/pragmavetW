<?php
session_start();
include("dbconnection.php");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$step    = $_SESSION['recovery_step'] ?? 1;
$error   = '';
$success = '';

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// STEP 1 â€” Receive email, generate OTP, send it
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_POST['request_code'])) {
    $email = trim($_POST['email']);
    $stmt  = $conn->prepare("SELECT id, fullname FROM users WHERE email=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Generate a 6-digit OTP
        $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // Store in DB (reuse password_resets, store OTP as token)
        $conn->query("DELETE FROM password_resets WHERE email='$email'");
        $ins = $conn->prepare("INSERT INTO password_resets(email, token, expires_at) VALUES(?,?,?)");
        $ins->bind_param("sss", $email, $otp, $expires);

        if ($ins->execute()) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'timferrer1@gmail.com';
                $mail->Password   = 'azrpmoxxzvhucetu';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('timferrer1@gmail.com', 'PragmaVet Security');
                $mail->addAddress($email, $user['fullname']);
                $mail->isHTML(true);
                $mail->Subject = 'PragmaVet â€” Your Verification Code';
                $mail->Body = "
                    <div style='font-family:Inter,Arial,sans-serif;max-width:560px;margin:auto;padding:40px 32px;background:#ffffff;border-radius:16px;border:1px solid #e2e8f0;'>
                        <div style='text-align:center;margin-bottom:28px;'>
                            <div style='display:inline-block;background:#EFF6FF;border-radius:50%;width:64px;height:64px;line-height:64px;font-size:28px;'>ðŸ”</div>
                        </div>
                        <h2 style='color:#0f172a;font-size:20px;margin:0 0 8px;text-align:center;'>Account Recovery Code</h2>
                        <p style='color:#64748b;font-size:14px;text-align:center;margin:0 0 32px;'>Hi <strong>" . htmlspecialchars($user['fullname']) . "</strong>, use the code below to recover your account. It expires in <strong>15 minutes</strong>.</p>
                        <div style='text-align:center;margin:0 0 32px;'>
                            <span style='display:inline-block;background:#2563eb;color:#fff;font-size:36px;font-weight:800;letter-spacing:12px;padding:18px 32px;border-radius:14px;'>$otp</span>
                        </div>
                        <p style='color:#94a3b8;font-size:12px;text-align:center;margin:0;'>If you did not request this, you can safely ignore this email. Do not share this code with anyone.</p>
                    </div>
                ";
                $mail->send();

                // Save state in session
                $_SESSION['recovery_step']  = 2;
                $_SESSION['recovery_email'] = $email;
                $step = 2;
                $success = "A 6-digit code has been sent to <strong>$email</strong>. Check your inbox.";

            } catch (Exception $e) {
                $error = "Mail error: " . $mail->ErrorInfo;
            }
        }
    } else {
        // Prevent email enumeration â€” always show the same success message
        $_SESSION['recovery_step']  = 2;
        $_SESSION['recovery_email'] = $email;
        $step = 2;
        $success = "If that email is registered, a code has been sent to your inbox.";
    }
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// STEP 2 â€” Verify the 6-digit OTP
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_POST['verify_code'])) {
    $entered_otp = trim($_POST['otp']);
    $email       = $_SESSION['recovery_email'] ?? '';

    $stmt = $conn->prepare("SELECT token, expires_at FROM password_resets WHERE email=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        if (strtotime($row['expires_at']) < time()) {
            $error = "This code has expired. Please start over.";
            unset($_SESSION['recovery_step'], $_SESSION['recovery_email']);
            $step = 1;
        } elseif ($row['token'] !== $entered_otp) {
            $error = "Incorrect code. Please check your email and try again.";
            $step  = 2;
        } else {
            // Code is valid â€” move to password reset
            $_SESSION['recovery_step']     = 3;
            $_SESSION['recovery_verified'] = true;
            $step = 3;
        }
    } else {
        $error = "No active recovery request found. Please start over.";
        unset($_SESSION['recovery_step'], $_SESSION['recovery_email']);
        $step = 1;
    }
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// STEP 3 â€” Change the password
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_POST['change_password'])) {
    if (!($_SESSION['recovery_verified'] ?? false)) {
        header("Location: forgot_password.php");
        exit();
    }

    $email       = $_SESSION['recovery_email'] ?? '';
    $new_pass    = $_POST['new_pass'];
    $confirm_pass = $_POST['confirm_pass'];

    if ($new_pass !== $confirm_pass) {
        $error = "Passwords do not match. Please try again.";
        $step  = 3;
    } elseif (!preg_match('/^[a-z0-9]{8,}$/', $new_pass)) {
        $error = "Password must be at least 8 characters and use only lowercase letters and numbers.";
        $step  = 3;
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password=? WHERE email=?");
        $update->bind_param("ss", $hashed, $email);

        if ($update->execute()) {
            $conn->query("DELETE FROM password_resets WHERE email='$email'");
            $conn->query("UPDATE users SET failed_attempts=0, locked_until=NULL WHERE email='$email'");
            unset($_SESSION['recovery_step'], $_SESSION['recovery_email'], $_SESSION['recovery_verified']);

            $_SESSION['message']  = "Password changed successfully. You can now log in.";
            $_SESSION['msg_type'] = "success";
            header("Location: index.php");
            exit();
        } else {
            $error = "Something went wrong. Please try again.";
            $step  = 3;
        }
    }
}

// Allow going back to step 1
if (isset($_GET['restart'])) {
    unset($_SESSION['recovery_step'], $_SESSION['recovery_email'], $_SESSION['recovery_verified']);
    header("Location: forgot_password.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Account Recovery | PragmaVet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --primary: #2563eb; --secondary: #3b82f6; --dark: #1e40af; --light-bg: #E6EEF8; }
body { background: var(--light-bg); font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.auth-card { background: white; border-radius: 20px; padding: 50px 40px; width: 100%; max-width: 460px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); position: relative; z-index: 2; }
.blobs { position: fixed; width: 600px; height: 600px; background: linear-gradient(135deg, rgba(37,99,235,0.08), rgba(74,127,196,0.04)); border-radius: 50%; top: -250px; right: -250px; z-index: 1; filter: blur(50px); pointer-events: none; }
.form-control { border-radius: 12px; padding: 14px 20px; border: 1px solid #dbe5ec; font-size: 15px; background: #fafcff; transition: .2s; }
.form-control:focus { outline: none; border-color: var(--secondary); box-shadow: 0 0 0 4px rgba(74,127,196,0.12); background: #fff; }

/* OTP input styling */
.otp-input { font-size: 28px; font-weight: 800; letter-spacing: 10px; text-align: center; border-radius: 14px; padding: 18px 20px; border: 2px solid #dbe5ec; background: #f8faff; transition: .2s; }
.otp-input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(37,99,235,0.12); }

/* Progress steps */
.steps { display: flex; justify-content: center; align-items: center; gap: 0; margin-bottom: 30px; }
.step-dot { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; border: 2px solid #e2e8f0; background: #f8faff; color: #94a3b8; transition: .3s; }
.step-dot.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.step-dot.done { background: #22c55e; border-color: #22c55e; color: #fff; }
.step-line { width: 40px; height: 2px; background: #e2e8f0; }
.step-line.done { background: #22c55e; }
</style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>
<div class="blobs"></div>

<div class="auth-card">

    <!-- Header Icon -->
    <div class="text-center mb-4">
        <?php if ($step == 1): ?>
            <i class="fa fa-envelope-open-text fa-3x text-primary mb-3"></i>
            <h3 class="fw-bold" style="color:var(--dark);letter-spacing:-0.5px;">Account Recovery</h3>
            <p class="text-muted small">Enter your registered email and we'll send you a verification code.</p>
        <?php elseif ($step == 2): ?>
            <i class="fa fa-shield-halved fa-3x text-primary mb-3"></i>
            <h3 class="fw-bold" style="color:var(--dark);letter-spacing:-0.5px;">Enter Your Code</h3>
            <p class="text-muted small">We sent a 6-digit code to <strong><?= htmlspecialchars($_SESSION['recovery_email'] ?? '') ?></strong>.</p>
        <?php else: ?>
            <i class="fa fa-key fa-3x text-primary mb-3"></i>
            <h3 class="fw-bold" style="color:var(--dark);letter-spacing:-0.5px;">Set New Password</h3>
            <p class="text-muted small">Choose a strong new password for your account.</p>
        <?php endif; ?>
    </div>

    <!-- Step Indicators -->
    <div class="steps">
        <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">
            <?= $step > 1 ? '<i class="fa fa-check" style="font-size:11px;"></i>' : '1' ?>
        </div>
        <div class="step-line <?= $step > 1 ? 'done' : '' ?>"></div>
        <div class="step-dot <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">
            <?= $step > 2 ? '<i class="fa fa-check" style="font-size:11px;"></i>' : '2' ?>
        </div>
        <div class="step-line <?= $step > 2 ? 'done' : '' ?>"></div>
        <div class="step-dot <?= $step >= 3 ? 'active' : '' ?>">3</div>
    </div>

    <!-- Alerts -->
    <?php if ($error): ?>
        <div class="alert border-0 small fw-bold text-center mb-4 py-3" style="border-radius:10px;background:rgba(250,82,82,0.1);color:#c92a2a;">
            <i class="fa fa-circle-exclamation me-1"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert border-0 small fw-bold text-center mb-4 py-3" style="border-radius:10px;background:rgba(34,197,94,0.1);color:#166534;">
            <i class="fa fa-circle-check me-1"></i> <?= $success ?>
        </div>
    <?php endif; ?>

    <!-- â”€â”€ STEP 1: Email Form â”€â”€ -->
    <?php if ($step == 1): ?>
    <form method="POST">
        <div class="mb-4">
            <label class="form-label text-muted small fw-bold">REGISTERED EMAIL ADDRESS</label>
            <input type="email" name="email" class="form-control" placeholder="name@domain.com" required autofocus>
        </div>
        <button type="submit" name="request_code" class="btn w-100 fw-bold rounded-pill mb-3" style="padding:14px;background:var(--primary);color:#fff;border:none;">
            <i class="fa fa-paper-plane me-2"></i> Send Verification Code
        </button>
        <div class="text-center">
            <a href="index.php" class="text-muted small text-decoration-none"><i class="fa fa-arrow-left me-1"></i> Back to Login</a>
        </div>
    </form>

    <!-- â”€â”€ STEP 2: OTP Verification â”€â”€ -->
    <?php elseif ($step == 2): ?>
    <form method="POST">
        <div class="mb-4">
            <label class="form-label text-muted small fw-bold text-center d-block">6-DIGIT VERIFICATION CODE</label>
            <input type="text" name="otp" class="form-control otp-input" maxlength="6" pattern="\d{6}" placeholder="000000" required autofocus inputmode="numeric">
            <div class="text-center mt-2">
                <span class="text-muted" style="font-size:12px;"><i class="fa fa-clock me-1"></i> Code expires in 15 minutes</span>
            </div>
        </div>
        <button type="submit" name="verify_code" class="btn w-100 fw-bold rounded-pill mb-3" style="padding:14px;background:var(--primary);color:#fff;border:none;">
            <i class="fa fa-check-shield me-2"></i> Verify Code
        </button>
        <div class="text-center">
            <a href="forgot_password.php?restart=1" class="text-muted small text-decoration-none"><i class="fa fa-rotate-left me-1"></i> Use a different email</a>
        </div>
    </form>

    <!-- â”€â”€ STEP 3: New Password â”€â”€ -->
    <?php elseif ($step == 3): ?>
    <form method="POST">
        <div class="mb-3">
            <label class="form-label text-muted small fw-bold">NEW PASSWORD</label>
            <input type="password" name="new_pass" class="form-control" placeholder="8+ characters, lowercase & numbers only" pattern="[a-z0-9]{8,}" title="At least 8 characters using lowercase letters and numbers only" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label text-muted small fw-bold">CONFIRM NEW PASSWORD</label>
            <input type="password" name="confirm_pass" class="form-control" placeholder="Re-enter your new password" required>
        </div>
        <div class="mb-4 p-3 rounded-3" style="background:#f8faff;border:1px solid #e2e8f0;font-size:12px;color:#64748b;">
            <i class="fa fa-circle-info text-primary me-1"></i> Must be at least 8 characters using lowercase letters and numbers only (e.g. mypassword123)
        </div>
        <button type="submit" name="change_password" class="btn w-100 fw-bold rounded-pill mb-3" style="padding:14px;background:var(--primary);color:#fff;border:none;">
            <i class="fa fa-lock me-2"></i> Save New Password
        </button>
    </form>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-focus next input on 6-digit OTP complete
const otpInput = document.querySelector('.otp-input');
if (otpInput) {
    otpInput.addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });
}
</script>
    <script src="mousetrail.js"></script>
</body>
</html>
